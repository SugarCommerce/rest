<?php
/**
 * Letting a client keep what it already has.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest;

use WP_REST_Request;
use WP_REST_Response;

/**
 * ETag, Last-Modified, Cache-Control, and the 304 that makes a refresh cheap.
 *
 * The one implementation for every route: a controller's single record
 * (Controller::shown() calls this) and a public catalogue alike.
 *
 * The `ETag` is weak and of the answer itself -- and of the query and the
 * URL's parameters, which change what the answer is -- never of a date. A
 * record can read differently without its own row changing: an order's
 * refunded total moves when a refund row is added, a product's price
 * changes in another table. A tag from `date_modified` alone would tell the
 * client nothing had happened.
 *
 * A request that brings the tag back (`If-None-Match`, or `*`) and still
 * matches is answered 304 with no body. A tag that does not match is the
 * answer: a date sent beside it is not looked at (RFC 9110, 13.1.3).
 *
 * `Last-Modified` is sent when the caller knows when the answer last
 * changed. `If-Modified-Since` is honoured only when the caller says that
 * date is the whole truth (`trust_modified`): for one product whose date
 * takes in its prices, yes; for an order whose row date misses its
 * refunds, no -- and never for a list, where an item that left the list
 * changes no date that is still in it.
 *
 * @since 1.0.0
 */
final class Conditional {

	/**
	 * Add the headers, and turn the answer into a 304 when it is one.
	 *
	 * @param WP_REST_Request      $request  The request.
	 * @param WP_REST_Response     $response The answer.
	 * @param array<string, mixed> $args {
	 *     @type int|null $modified       When the answer last changed, as a timestamp: sent as Last-Modified.
	 *     @type bool     $trust_modified Whether that date alone may answer If-Modified-Since. False.
	 *     @type string   $cache_control  A Cache-Control header to send, such as `public, max-age=300`. None.
	 * }
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	public static function apply( WP_REST_Request $request, WP_REST_Response $response, array $args = [] ): WP_REST_Response {
		if ( ! in_array( $request->get_method(), [ 'GET', 'HEAD' ], true ) || 200 !== $response->get_status() ) {
			return $response;
		}

		$modified = isset( $args['modified'] ) ? (int) $args['modified'] : null;
		$control  = (string) ( $args['cache_control'] ?? '' );
		$etag     = self::etag( $request, $response );

		$response->header( 'ETag', $etag );

		if ( '' !== $control ) {
			$response->header( 'Cache-Control', $control );
		}

		if ( null !== $modified ) {
			$response->header( 'Last-Modified', gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' );
		}

		$held = trim( (string) $request->get_header( 'if_none_match' ) );

		if ( '' !== $held ) {
			if ( '*' === $held || in_array( $etag, array_map( 'trim', explode( ',', $held ) ), true ) ) {
				return self::not_modified( $response );
			}

			return $response;
		}

		$since = trim( (string) $request->get_header( 'if_modified_since' ) );
		$since = '' !== $since ? strtotime( $since ) : false;

		if ( ! empty( $args['trust_modified'] ) && null !== $modified && false !== $since && $modified <= $since ) {
			return self::not_modified( $response );
		}

		return $response;
	}

	/**
	 * The tag of an answer to a request.
	 *
	 * @param WP_REST_Request  $request  The request.
	 * @param WP_REST_Response $response The answer.
	 *
	 * @return string A weak ETag: `W/"…"`.
	 * @since 1.0.0
	 */
	public static function etag( WP_REST_Request $request, WP_REST_Response $response ): string {
		return 'W/"' . md5( (string) wp_json_encode( [ $response->get_data(), $request->get_query_params(), $request->get_url_params() ] ) ) . '"';
	}

	/**
	 * The same answer, as "you have it already".
	 *
	 * @param WP_REST_Response $response The answer.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	private static function not_modified( WP_REST_Response $response ): WP_REST_Response {
		$response->set_status( 304 );
		$response->set_data( null );

		return $response;
	}
}
