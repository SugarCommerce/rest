<?php
/**
 * What every controller in an API has.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest;

use LogicException;
use SugarCommerce\Dates\Timestamp;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The base a controller extends.
 *
 * It holds everything that would otherwise be written twenty times and
 * drift: how a route is registered and who may call it, what a list takes
 * (`page`, `per_page`, `search`, `orderby`, the date filters, `mode`,
 * `expand`) and how those become a query, the paging headers, the ETag on
 * a single record, and the errors.
 *
 * A controller for a table says what is particular to it -- see spec() --
 * and gets the list and the single-record routes from that. Anything it
 * adds is a method that sanitises, finds the record, calls the function the
 * admin calls, and shapes the answer.
 *
 * Which API a controller belongs to is the plugin's base class's business:
 * it extends this once and names its API, and every controller -- the
 * plugin's and its add-ons' -- extends that.
 *
 *     abstract class Controller extends \SugarCommerce\Rest\Controller {
 *         protected const API = 'myshop';
 *     }
 *
 * @since 1.0.0
 */
abstract class Controller extends WP_REST_Controller {

	use Concerns\Lists;

	/**
	 * The id of the API this controller belongs to.
	 *
	 * @since 1.0.0
	 */
	protected const API = '';

	/**
	 * The API, when it was handed over rather than found by id.
	 *
	 * @var Api|null
	 */
	private ?Api $kit;

	/**
	 * What the routes are about, for the permission check.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected string $area = 'settings';

	/**
	 * The serializer for this controller's records.
	 *
	 * @var class-string<Record>|''
	 * @since 1.0.0
	 */
	protected string $resource = '';

	/**
	 * Make the controller.
	 *
	 * @param Api|null $api The API, when the class does not name one.
	 */
	public function __construct( ?Api $api = null ) {
		$this->kit       = $api;
		$this->namespace = $this->kit()->namespace();
	}

	/**
	 * The API this class of controller belongs to.
	 *
	 * @return Api
	 * @throws LogicException When the class names no registered API.
	 * @since 1.0.0
	 */
	public static function api(): Api {
		$api = Api::get( static::API );

		if ( null === $api ) {
			throw new LogicException( sprintf( '%s names no registered API: set its API constant, and register the API first.', static::class ) );
		}

		return $api;
	}

	/**
	 * The API this controller belongs to.
	 *
	 * @return Api
	 * @since 1.0.0
	 */
	protected function kit(): Api {
		return $this->kit ??= static::api();
	}

	/**
	 * What a list of this controller's records takes and how it is fetched.
	 *
	 * Keys, all optional but the first three:
	 *
	 * - `get`, `list`, `count`: the functions that fetch one row by id, a
	 *   page of rows, and how many rows there are, for query arguments in
	 *   the BerlinDB style (`number`, `offset`, `orderby`, `id__in`,
	 *   `date_created_query`…).
	 * - `sortable`: columns `orderby` may name. `id` and the two dates
	 *   always can.
	 * - `orderby`, `order`: the default sort.
	 * - `filters`: param => [ `column`, `type`, `description`, and
	 *   optionally `enum`, `list` (several values, comma-separated) and
	 *   `sanitize` (a function for each value) ].
	 * - `search`: whether the table has columns to search.
	 * - `mode`: whether rows are test or live: true for an `is_test`
	 *   column, or the column's name.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	protected function spec(): array {
		return [];
	}

	/**
	 * Register a route.
	 *
	 * @param string                           $path     The route below the namespace, with its leading slash.
	 * @param array<int, array<string, mixed>> $handlers What handle() made, a method each.
	 * @param callable|null                    $schema   The response's schema, when it is not this controller's resource.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function route( string $path, array $handlers, ?callable $schema = null ): void {
		$handlers['schema'] = $schema ?? [ $this, 'get_public_item_schema' ];

		register_rest_route( $this->namespace, $path, $handlers );
	}

	/**
	 * One method on a route.
	 *
	 * @param string               $methods  The HTTP method.
	 * @param string               $callback The method on this controller that answers.
	 * @param string               $kind     `read`, `write` or `refund`: who may, and which rate limit.
	 * @param array<string, mixed> $args     The parameters, as register_rest_route() takes them.
	 * @param bool                 $once     Whether a retry must not do it twice: the
	 *                                       request needs an `Idempotency-Key`.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	protected function handle( string $methods, string $callback, string $kind = 'read', array $args = [], bool $once = false ): array {
		$api  = $this->kit();
		$area = $this->area;
		$run  = [ $this, $callback ];

		return [
			'methods'             => $methods,
			'callback'            => static function ( WP_REST_Request $request ) use ( $api, $run, $once, $kind ) {
				// Counted here, as the request is about to be answered, and
				// not in the permission check: see Permissions::check().
				$limited = $api->rate_limit()->check( $api->rate_limit()->bucket( $kind ) );

				if ( is_wp_error( $limited ) ) {
					return $limited;
				}

				return $once ? $api->idempotency()->run( $request, $run ) : $run( $request );
			},
			'permission_callback' => fn( WP_REST_Request $request ) => $this->permitted( $kind, $area, $request ),
			'args'                => $args,
			$api->mark()          => true,
			$api->key( 'kind' )   => $kind,
			$api->key( 'once' )   => $once,
		];
	}

	/**
	 * Whether the request may go ahead.
	 *
	 * A method of its own so a controller with a rule of its own can add
	 * to it.
	 *
	 * @param string          $kind    `read`, `write` or `refund`.
	 * @param string          $area    What it is about.
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	protected function permitted( string $kind, string $area, WP_REST_Request $request ) {
		return $this->kit()->permissions()->check( $kind, $area, $request );
	}

	/**
	 * The id in a route.
	 *
	 * @param string $name What the parameter is called.
	 *
	 * @return string A pattern for register_rest_route().
	 * @since 1.0.0
	 */
	protected static function id( string $name = 'id' ): string {
		return '(?P<' . $name . '>[\d]+)';
	}

	/**
	 * The `id` argument every single-record route declares.
	 *
	 * @param string $description What the id is of.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	protected static function id_arg( string $description = '' ): array {
		return [
			'id' => [
				'description' => '' !== $description ? $description : __( 'The record’s id.', 'sugarcommerce' ),
				'type'        => 'integer',
				'minimum'     => 1,
				'required'    => true,
			],
		];
	}

	/**
	 * The schema of this controller's resource.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$resource = $this->resource;

		$this->schema = '' !== $resource ? $resource::schema() : [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => $this->kit()->object_type( $this->area ),
			'type'       => 'object',
			'properties' => [],
		];

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * A schema for an answer that is not a resource.
	 *
	 * @param string                              $title      What the answer is, as one word.
	 * @param array<string, array<string, mixed>> $properties Its parts.
	 *
	 * @return callable What route() takes as a schema.
	 * @since 1.0.0
	 */
	protected static function answers( string $title, array $properties ): callable {
		$type = static::api()->object_type( $title );

		return static fn(): array => [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => $type,
			'type'       => 'object',
			'properties' => $properties,
		];
	}

	/**
	 * The related records a request asked for.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	protected function expand( WP_REST_Request $request ): array {
		return array_values( array_filter( array_map( 'strval', (array) ( $request['expand'] ?? [] ) ) ) );
	}

	/**
	 * A list of this controller's records.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.0.0
	 */
	public function get_items( $request ) {
		$spec = $this->spec();
		$args = $this->query( $request );

		return $this->listed( $request, $spec['list']( $args ), (int) $spec['count']( $args ) );
	}

	/**
	 * One of this controller's records.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.0.0
	 */
	public function get_item( $request ) {
		$row = $this->record( $request );

		return is_wp_error( $row ) ? $row : $this->shown( $request, $row );
	}

	/**
	 * The record a request names, or why not.
	 *
	 * @param WP_REST_Request $request The request.
	 * @param string          $param   Which parameter holds the id.
	 *
	 * @return object|WP_Error
	 * @since 1.0.0
	 */
	protected function record( WP_REST_Request $request, string $param = 'id' ) {
		$get = $this->spec()['get'] ?? null;
		$row = is_callable( $get ) ? $get( (int) $request[ $param ] ) : null;

		return is_object( $row ) ? $row : static::not_found();
	}

	/**
	 * Rows as a page of the list, with the headers that say where it is.
	 *
	 * @param WP_REST_Request           $request  The request.
	 * @param object[]                  $rows     The rows on this page.
	 * @param int                       $total    How many there are on every page.
	 * @param class-string<Record>|null $resource The serializer, when not this controller's.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	protected function listed( WP_REST_Request $request, array $rows, int $total, ?string $resource = null ): WP_REST_Response {
		$resource = $resource ?? $this->resource;
		$own      = $resource === $this->resource;
		$items    = $resource::shapes( $rows, $this->expand( $request ) );

		if ( $own ) {
			$items = array_map( fn( array $item ): array => $this->add_additional_fields_to_object( $item, $request ), $items );
		}

		return $this->paged( $request, $items, $total );
	}

	/**
	 * A page of anything, with the paging headers WordPress itself sends.
	 *
	 * And `first` and `last` in the Link header as well as `prev` and `next`
	 * (RFC 8288), so a client can jump to the end without working out the
	 * address itself. Each is left out where it would be this page.
	 *
	 * @param WP_REST_Request $request The request.
	 * @param array           $items   What is on this page.
	 * @param int             $total   How many there are altogether.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	protected function paged( WP_REST_Request $request, array $items, int $total ): WP_REST_Response {
		$per   = max( 1, (int) ( $request['per_page'] ?? 20 ) );
		$page  = max( 1, (int) ( $request['page'] ?? 1 ) );
		$pages = (int) ceil( $total / $per );

		$response = new WP_REST_Response( array_values( $items ), 200 );

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );

		$base = add_query_arg(
			urlencode_deep( array_diff_key( $request->get_query_params(), [ 'page' => true ] ) ),
			rest_url( ltrim( $request->get_route(), '/' ) )
		);

		if ( $page > 1 ) {
			$response->link_header( 'first', add_query_arg( 'page', 1, $base ) );
			$response->link_header( 'prev', add_query_arg( 'page', min( $page - 1, max( 1, $pages ) ), $base ) );
		}

		if ( $page < $pages ) {
			$response->link_header( 'next', add_query_arg( 'page', $page + 1, $base ) );
			$response->link_header( 'last', add_query_arg( 'page', $pages, $base ) );
		}

		return $response;
	}

	/**
	 * One record as a response, or "you have this already".
	 *
	 * @param WP_REST_Request           $request  The request.
	 * @param object                    $row      The row.
	 * @param int                       $status   201 for one just made.
	 * @param class-string<Record>|null $resource The serializer, when not this controller's.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	protected function shown( WP_REST_Request $request, object $row, int $status = 200, ?string $resource = null ): WP_REST_Response {
		$resource = $resource ?? $this->resource;
		$expand   = $this->expand( $request );

		$resource::prime( [ $row ], array_values( array_intersect( $expand, $resource::expandable() ) ) );

		$data = $resource::shape( $row, $expand );

		$resource::forget();

		if ( $resource === $this->resource ) {
			$data = $this->add_additional_fields_to_object( $data, $request );
		}

		return static::fresh( $request, new WP_REST_Response( $data, $status ), (string) ( $row->date_modified ?? '' ) );
	}

	/**
	 * Answer 304 when the client already holds what would be sent.
	 *
	 * The tag is of the answer itself and not of the row's date: a record
	 * can read differently without its own row changing -- an order's
	 * refunded total moves when a refund row is added -- so the date is
	 * sent as `Last-Modified` but never answers a conditional request on
	 * its own. See Conditional.
	 *
	 * @param WP_REST_Request  $request  The request.
	 * @param WP_REST_Response $response The answer.
	 * @param string           $modified When the record last changed, as stored.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	protected static function fresh( WP_REST_Request $request, WP_REST_Response $response, string $modified = '' ): WP_REST_Response {
		return Conditional::apply( $request, $response, [ 'modified' => Timestamp::to_unix( $modified ) ] );
	}

	/**
	 * A plain answer to an action: what happened, and the record now.
	 *
	 * @param array<string, mixed> $data   The answer.
	 * @param int                  $status The status.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	protected static function done( array $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status );
	}

	/**
	 * There is no such record.
	 *
	 * @param string $message Something more exact than the default.
	 *
	 * @return WP_Error
	 * @since 1.0.0
	 */
	protected static function not_found( string $message = '' ): WP_Error {
		return new WP_Error(
			static::api()->code( 'not_found' ),
			'' !== $message ? $message : __( 'There is no record with that id.', 'sugarcommerce' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * A parameter is wrong.
	 *
	 * @param string $param   Which one.
	 * @param string $message What is wrong with it.
	 * @param int    $status  400, or 422 when it is well-formed and cannot be acted on.
	 *
	 * @return WP_Error
	 * @since 1.0.0
	 */
	protected static function invalid( string $param, string $message, int $status = 400 ): WP_Error {
		return new WP_Error(
			static::api()->code( 'invalid_param' ),
			$message,
			[
				'status' => $status,
				'param'  => $param,
			]
		);
	}

	/**
	 * It cannot be done to this record as it stands.
	 *
	 * @param string $message Why not.
	 *
	 * @return WP_Error
	 * @since 1.0.0
	 */
	protected static function conflict( string $message ): WP_Error {
		return new WP_Error( static::api()->code( 'conflict' ), $message, [ 'status' => 409 ] );
	}

	/**
	 * An error from the function that did the work, under one of the API's codes.
	 *
	 * The plugin's functions answer with codes of their own, a new one for
	 * each thing that can go wrong, and a client cannot be expected to know
	 * them all. The message is kept -- it is the one a person can read --
	 * the original code is kept as `reason`, and the code a client switches
	 * on is one of seven, chosen by the status.
	 *
	 * @param WP_Error $error  What the function answered.
	 * @param int|null $status The status when the error names none. Left out, it
	 *                         is 409 -- what a plugin's functions refuse is nearly
	 *                         always "not to this record, not now" -- or 400 when
	 *                         the error names the field at fault.
	 *
	 * @return WP_Error
	 * @since 1.0.0
	 */
	protected static function refused( WP_Error $error, ?int $status = null ): WP_Error {
		return Errors::refused( static::api(), $error, $status );
	}
}
