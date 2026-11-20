<?php
/**
 * What is true of every response this API gives: the mode and version headers, the rate limit's headers, its own code for a bad parameter, and the request log.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Traits\Api;

use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

/**
 * What is true of every response this API gives: the mode and version headers, the rate limit's headers, its own code for a bad parameter, and the request log.
 *
 * @since 1.0.0
 */
trait Responses {

	/**
	 * Whether a request was matched to one of this API's routes.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_ours( WP_REST_Request $request ): bool {
		return ! empty( $request->get_attributes()[ $this->mark() ] );
	}

	/**
	 * What is true of every response this API gives. On `rest_post_dispatch`.
	 *
	 * @param mixed $response The response.
	 * @param mixed $server   The server.
	 * @param mixed $request  The request.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	public function finish( $response, $server, $request ) {
		if ( ! $response instanceof WP_HTTP_Response || ! $request instanceof WP_REST_Request || ! $this->is_ours( $request ) ) {
			return $response;
		}

		// Which of the site's two sets of books this answer is from. An app
		// showing live figures under a "test" banner, or the other way
		// about, is the mistake this header is there to prevent.
		if ( $this->has_modes() ) {
			$response->header( $this->header() . '-Mode', $this->is_test() ? 'test' : 'live' );
		}

		$response->header( $this->header() . '-Version', $this->version() );

		foreach ( $this->rate_limit()->headers() as $name => $value ) {
			$response->header( $name, $value );
		}

		$this->rate_limit()->reset();

		$data = $response->get_data();

		// WordPress refuses a bad parameter before any of the API's code
		// runs, under its own code. Say it under ours, and say which
		// parameter, so a client has one thing to look for.
		$code = is_array( $data ) ? ( $data['code'] ?? '' ) : '';

		if ( $response->is_error() && in_array( $code, self::WORDPRESS_PARAM_ERRORS, true ) ) {
			$params = (array) ( $data['data']['params'] ?? [] );
			$first  = array_key_first( $params );

			// Keyed by the parameter for one that is wrong, and a plain
			// list of names for one that is missing.
			$param = is_string( $first ) ? $first : reset( $params );

			$data['code']          = $this->code( 'invalid_param' );
			$data['data']['param'] = (string) $param;

			$response->set_data( $data );
		}

		if ( $response instanceof WP_REST_Response ) {
			$this->log( $request, $response );
		}

		return $response;
	}

	/**
	 * Tell whoever is listening, and write it down if asked to.
	 *
	 * @param WP_REST_Request  $request  The request.
	 * @param WP_REST_Response $response The response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function log( WP_REST_Request $request, WP_REST_Response $response ): void {
		/**
		 * Fires when the API has answered a request.
		 *
		 * `{prefix}_rest_request`.
		 *
		 * @param WP_REST_Request  $request  The request.
		 * @param WP_REST_Response $response The response.
		 *
		 * @since 1.0.0
		 */
		do_action( $this->hook( 'request' ), $request, $response );

		$write = $this->config['log'] ?? null;

		/**
		 * Filter whether API requests are written to the log.
		 *
		 * `{prefix}_rest_log_requests`. Off unless asked for: a log line a
		 * request is a table that grows with every refresh of an app. On,
		 * each request is one line -- the method, the route, the status and
		 * who -- and never the body, which is where the personal data is.
		 *
		 * @param bool            $log     Whether to log.
		 * @param WP_REST_Request $request The request.
		 *
		 * @since 1.0.0
		 */
		if ( ! is_callable( $write ) || ! apply_filters( $this->hook( 'log_requests' ), false, $request ) ) {
			return;
		}

		$line = sprintf(
			'%s %s %d (%s)',
			$request->get_method(),
			$request->get_route(),
			$response->get_status(),
			$this->rate_limit()->credential()
		);

		$write( $line, $request, $response );
	}
}
