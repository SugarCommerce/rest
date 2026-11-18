<?php
/**
 * Which browsers may read a set of routes' answers.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests;

use SugarCommerce\Rest\Cors;
use SugarCommerce\Rest\Tests\Support\TestCase;
use WP_REST_Request;
use WP_REST_Response;

final class CorsTest extends TestCase {

	/**
	 * A policy for /acme/v1/public/…, allowing one site.
	 *
	 * @param array<string, mixed> $config What to change.
	 *
	 * @return Cors
	 */
	private static function policy( array $config = [] ): Cors {
		return new Cors(
			static fn( WP_REST_Request $request ): bool => str_starts_with( $request->get_route(), '/acme/v1/public' ),
			static fn( string $origin ): string => 'https://shop.example' === rtrim( $origin, '/' ) ? 'https://shop.example' : '',
			$config
		);
	}

	/**
	 * A request to a route.
	 *
	 * @param string $route  The route.
	 * @param string $origin Its Origin.
	 * @param string $method Its method.
	 *
	 * @return WP_REST_Request
	 */
	private static function from( string $route, string $origin, string $method = 'GET' ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );

		$request->set_header( 'Origin', $origin );

		return $request;
	}

	public function test_an_allowed_site_is_echoed_and_told_what_it_may_send_and_read(): void {
		$headers = self::policy( [ 'max_age' => 60 ] )->headers( 'https://shop.example/' );

		$this->assertSame( 'https://shop.example', $headers['Access-Control-Allow-Origin'] );
		$this->assertSame( 'GET, POST, OPTIONS', $headers['Access-Control-Allow-Methods'] );
		$this->assertStringContainsString( 'If-None-Match', $headers['Access-Control-Allow-Headers'] );
		$this->assertStringContainsString( 'ETag', $headers['Access-Control-Expose-Headers'] );
		$this->assertSame( '60', $headers['Access-Control-Max-Age'] );
		$this->assertSame( 'Origin', $headers['Vary'] );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Credentials', $headers );
	}

	public function test_any_other_site_is_sent_nothing_but_vary(): void {
		$policy = self::policy();

		$this->assertSame( [ 'Vary' => 'Origin' ], $policy->headers( 'https://evil.example' ) );
		$this->assertSame( [ 'Vary' => 'Origin' ], $policy->headers( 'null' ) );
		$this->assertSame( [ 'Vary' => 'Origin' ], $policy->headers( '' ) );
	}

	public function test_a_null_origin_is_never_a_site_however_lax_the_policy(): void {
		$lax = new Cors( '__return_true', static fn( string $origin ): string => $origin );

		$this->assertSame( [ 'Vary' => 'Origin' ], $lax->headers( 'null' ) );
		$this->assertSame( 'https://any.example', $lax->headers( ' https://any.example ' )['Access-Control-Allow-Origin'] );
	}

	public function test_the_headers_go_on_the_policys_routes_only(): void {
		$policy = self::policy();

		$ours   = $policy->respond( new WP_REST_Response( [] ), null, self::from( '/acme/v1/public/products', 'https://shop.example' ) );
		$theirs = $policy->respond( new WP_REST_Response( [] ), null, self::from( '/acme/v1/orders', 'https://shop.example' ) );

		$this->assertSame( 'https://shop.example', $ours->get_headers()['Access-Control-Allow-Origin'] );
		$this->assertSame( [], $theirs->get_headers() );
	}

	public function test_a_preflight_is_answered_204_and_empty(): void {
		$response = self::policy()->respond( new WP_REST_Response( [ 'routes' => 'described' ] ), null, self::from( '/acme/v1/public/x', 'https://shop.example', 'OPTIONS' ) );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( $response->get_data() );
		$this->assertSame( 'https://shop.example', $response->get_headers()['Access-Control-Allow-Origin'] );
	}

	public function test_a_rate_limits_headers_ride_along_and_are_reset(): void {
		$api = $this->api( [ 'rate_limit' => [] ] );
		$this->count_in_memory();

		$api->rate_limit()->check( 'general', 'storefront:1.2.3.4' );

		$response = self::policy( [ 'rate_limit' => $api->rate_limit() ] )->respond( new WP_REST_Response( [] ), null, self::from( '/acme/v1/public/x', '' ) );

		$this->assertSame( '119', $response->get_headers()['X-RateLimit-Remaining'] );
		$this->assertSame( [], $api->rate_limit()->headers() );
	}

	public function test_init_hooks_both_halves_and_remove_takes_them_out(): void {
		$policy = self::policy()->init();

		$this->assertSame( 15, has_filter( 'rest_post_dispatch', [ $policy, 'respond' ] ) );
		$this->assertSame( 11, has_filter( 'rest_pre_serve_request', [ $policy, 'send' ] ) );

		$policy->remove();

		$this->assertFalse( has_filter( 'rest_post_dispatch', [ $policy, 'respond' ] ) );
		$this->assertFalse( has_filter( 'rest_pre_serve_request', [ $policy, 'send' ] ) );
	}

	public function test_sending_leaves_other_routes_and_sent_headers_alone(): void {
		$policy = self::policy();

		$this->assertTrue( $policy->send( true, null, self::from( '/acme/v1/orders', 'https://shop.example' ) ) );
		$this->assertFalse( $policy->send( false, null, 'not a request' ) );
	}

	/**
	 * Origins that are not the one on the list, however close.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function strangers(): array {
		return [
			'another site'       => [ 'https://evil.example' ],
			'a suffix'           => [ 'https://evil-shop.example' ],
			'a subdomain'        => [ 'https://a.shop.example' ],
			'another scheme'     => [ 'http://shop.example' ],
			'another port'       => [ 'https://shop.example:8443' ],
			'a user name'        => [ 'https://shop.example@evil.example' ],
			'not http'           => [ 'ftp://shop.example' ],
			'no host'            => [ 'shop.example' ],
			'a sandboxed frame'  => [ 'null' ],
			'nothing'            => [ '' ],
		];
	}

	/**
	 * @dataProvider strangers
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'strangers' )]
	public function test_a_list_of_origins_matches_exactly( string $origin ): void {
		$policy = new Cors( '__return_true', [ 'https://shop.example' ] );

		$this->assertSame( [ 'Vary' => 'Origin' ], $policy->headers( $origin ) );
	}

	public function test_a_list_matches_as_a_browser_compares_case_and_path_aside(): void {
		$policy = new Cors( '__return_true', [ 'HTTPS://Shop.Example/catalogue', 'http://localhost:3000' ] );

		$this->assertSame( 'https://shop.example', $policy->headers( 'https://SHOP.example' )['Access-Control-Allow-Origin'] );
		$this->assertSame( 'http://localhost:3000', $policy->headers( 'http://localhost:3000' )['Access-Control-Allow-Origin'] );
		$this->assertSame( [ 'Vary' => 'Origin' ], $policy->headers( 'http://localhost' ) );
	}

	public function test_an_empty_list_allows_nobody(): void {
		$this->assertSame( [ 'Vary' => 'Origin' ], ( new Cors( '__return_true', [] ) )->headers( 'https://shop.example' ) );
		$this->assertSame( '', Cors::only( [ '', 'not an origin' ] )( 'https://shop.example' ) );
	}

	public function test_an_origin_is_scheme_host_and_port(): void {
		$this->assertSame( 'https://shop.example', Cors::origin( ' https://Shop.Example/a/b?c=d ' ) );
		$this->assertSame( 'http://127.0.0.1:8080', Cors::origin( 'http://127.0.0.1:8080' ) );
		$this->assertSame( '', Cors::origin( 'https://user:pass@shop.example' ) );
		$this->assertSame( '', Cors::origin( 'javascript:alert(1)' ) );
		$this->assertSame( '', Cors::origin( 'ftp://shop.example' ) );
		$this->assertSame( '', Cors::origin( 'chrome-extension://abcdef' ) );
	}
}
