<?php
/**
 * Shared set-up for the suite.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support;

use SugarCommerce\Security\RateLimit\Limiter;
use SugarCommerce\Security\RateLimit\Stores\Memory as MemoryCounter;
use SugarCommerce\Rest\Api;
use SugarCommerce\Rest\Idempotency\Stores\Memory;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * A real REST server, with the fixture API registered on it.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * The fixture API's idempotency keys.
	 *
	 * @var Memory
	 */
	protected Memory $keys;

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	protected int $admin;

	/**
	 * Off: the WordPress test case reads @expectedDeprecated annotations
	 * through a PHPUnit method that PHPUnit 12 no longer has.
	 *
	 * @return void
	 */
	public function expectDeprecated(): void {}

	/**
	 * Off, with expectDeprecated().
	 *
	 * @return void
	 */
	public function expectedDeprecated(): void {}

	/**
	 * A fresh server and fresh rows, every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Widgets::reset();
		WidgetsController::$refunds = 0;
		WidgetsController::$fail    = null;
		WidgetRecord::$primes       = 0;

		$this->keys  = new Memory();
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * Forget every API a test registered.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( [ 'acme', 'other', 'sugarcart' ] as $id ) {
			Api::unregister( $id );
		}

		$GLOBALS['wp_rest_server'] = null;

		parent::tear_down();
	}

	/**
	 * Register the fixture API, and a server with its routes on.
	 *
	 * @param array<string, mixed> $config What to change from the fixture's configuration.
	 *
	 * @return Api
	 */
	protected function api( array $config = [] ): Api {
		$api = Api::register(
			'acme',
			$config + [
				'namespace'   => 'acme/v1',
				'version'     => '2.3.4',
				'controllers' => [ WidgetsController::class ],
				'rate_limit'  => false,
				'idempotency' => $this->keys,
				'resources'   => [],
				'country_name' => static fn( string $code ): string => 'GB' === $code ? 'United Kingdom' : $code,
			]
		);

		$GLOBALS['wp_rest_server'] = null;

		rest_get_server();

		return $api;
	}

	/**
	 * Count in memory instead of in a table.
	 *
	 * @param string $prefix The API's prefix.
	 *
	 * @return Limiter
	 */
	protected function count_in_memory( string $prefix = 'acme' ): Limiter {
		$limiter = new Limiter( new MemoryCounter(), 'secret' );

		add_filter( $prefix . '_rest_rate_counter', static fn() => $limiter );

		return $limiter;
	}

	/**
	 * Ask the server.
	 *
	 * @param string               $method  The method.
	 * @param string               $route   The route.
	 * @param array<string, mixed> $params  The parameters.
	 * @param array<string, mixed> $headers The headers.
	 *
	 * @return WP_REST_Response
	 */
	protected function request( string $method, string $route, array $params = [], array $headers = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Ask the server as WordPress serves a request: with the post-dispatch filters.
	 *
	 * @param string               $method  The method.
	 * @param string               $route   The route.
	 * @param array<string, mixed> $params  The parameters.
	 * @param array<string, mixed> $headers The headers.
	 *
	 * @return WP_REST_Response
	 */
	protected function served( string $method, string $route, array $params = [], array $headers = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		$response = rest_do_request( $request );

		return apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), rest_get_server(), $request );
	}

	/**
	 * Sign in.
	 *
	 * @param int|null $user Who: the administrator when left out.
	 *
	 * @return void
	 */
	protected function sign_in( ?int $user = null ): void {
		wp_set_current_user( $user ?? $this->admin );
	}
}
