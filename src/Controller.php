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
use SugarCommerce\Rest\Traits\Controller\Answers;
use SugarCommerce\Rest\Traits\Controller\Lists;
use SugarCommerce\Rest\Traits\Controller\Refusals;
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

	use Answers;
	use Lists;
	use Refusals;

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
	#[\Override]
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
	#[\Override]
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
	#[\Override]
	public function get_item( $request ) {
		$row = $this->record( $request );

		return is_wp_error( $row ) ? $row : $this->shown( $request, $row );
	}
}
