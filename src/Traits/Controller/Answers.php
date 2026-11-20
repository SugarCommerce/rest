<?php
/**
 * Answers: a record, a page of a list, one record, a 304, a plain result.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Traits\Controller;

use SugarCommerce\Dates\Timestamp;
use SugarCommerce\Rest\Conditional;
use SugarCommerce\Rest\Record;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Answers: a record, a page of a list, one record, a 304, a plain result.
 *
 * Each one with the headers WordPress itself sends and the fields add-ons
 * registered.
 *
 * @since 1.0.0
 */
trait Answers {

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
}
