<?php
/**
 * The front door: registration, routes, and what every response carries.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests;

use SugarCommerce\Rest\Api;
use SugarCommerce\Rest\Tests\Support\TestCase;
use SugarCommerce\Rest\Tests\Support\Widgets;
use SugarCommerce\Rest\Tests\Support\WidgetsController;
use InvalidArgumentException;
use WP_REST_Request;

final class ApiTest extends TestCase {

	public function test_an_api_needs_an_id_and_a_namespace(): void {
		try {
			Api::register( '', [ 'namespace' => 'x/v1' ] );
			$this->fail( 'An empty id was accepted.' );
		} catch ( InvalidArgumentException ) {
			$this->addToAssertionCount( 1 );
		}

		$this->expectException( InvalidArgumentException::class );

		Api::register( 'acme', [ 'namespace' => '/' ] );
	}

	public function test_every_name_a_client_sees_is_built_from_the_prefix(): void {
		$api = Api::register(
			'acme',
			[
				'namespace' => '/acme/v1/',
				'prefix'    => 'acmeshop',
			]
		);

		$this->assertSame( 'acme', $api->id() );
		$this->assertSame( 'acme/v1', $api->namespace() );
		$this->assertSame( 'acmeshop_api', $api->mark() );
		$this->assertSame( 'acmeshop_kind', $api->key( 'kind' ) );
		$this->assertSame( 'acmeshop_not_found', $api->code( 'not_found' ) );
		$this->assertSame( 'acmeshop_rest_init', $api->hook( 'init' ) );
		$this->assertSame( 'acmeshop_order', $api->object_type( 'Order' ) );
		$this->assertSame( 'X-Acmeshop', $api->header() );
		$this->assertSame( rest_url( 'acme/v1/orders/4' ), $api->url( '/orders/4' ) );
		$this->assertSame( '1.0.0', $api->version() );
	}

	public function test_the_prefix_is_the_id_unless_given(): void {
		$api = Api::register( 'acme', [ 'namespace' => 'acme/v1' ] );

		$this->assertSame( 'acme', $api->prefix() );
		$this->assertSame( $api, Api::get( 'acme' ) );
		$this->assertTrue( Api::unregister( 'acme' ) );
		$this->assertNull( Api::get( 'acme' ) );
		$this->assertFalse( Api::unregister( 'acme' ) );
	}

	public function test_registering_again_replaces_the_api_and_its_hooks(): void {
		$first  = Api::register( 'acme', [ 'namespace' => 'acme/v1' ] );
		$second = Api::register( 'acme', [ 'namespace' => 'acme/v2' ] );

		$this->assertFalse( has_action( 'rest_api_init', [ $first, 'routes' ] ) );
		$this->assertSame( 10, has_action( 'rest_api_init', [ $second, 'routes' ] ) );
		$this->assertSame( 20, has_filter( 'rest_post_dispatch', [ $second, 'finish' ] ) );
	}

	public function test_the_routes_are_registered_with_the_apis_mark(): void {
		$this->api();

		$routes = rest_get_server()->get_routes( 'acme/v1' );

		$this->assertArrayHasKey( '/acme/v1/widgets', $routes );
		$this->assertArrayHasKey( '/acme/v1/widgets/(?P<id>[\d]+)/refund', $routes );

		$handler = $routes['/acme/v1/widgets/(?P<id>[\d]+)/refund'][0];

		$this->assertTrue( $handler['acme_api'] );
		$this->assertSame( 'refund', $handler['acme_kind'] );
		$this->assertTrue( $handler['acme_once'] );
	}

	public function test_nothing_is_registered_until_the_api_is_ready(): void {
		$ready = false;

		$this->api( [ 'ready' => static function () use ( &$ready ): bool {
			return $ready;
		} ] );

		$this->assertSame( [], rest_get_server()->get_routes( 'acme/v1' ) );
	}

	public function test_an_add_on_hands_over_a_controller_as_the_routes_are_registered(): void {
		$seen = null;

		add_action(
			'acme_rest_init',
			static function ( Api $api ) use ( &$seen ): void {
				$seen = $api;

				$api->add( new class() {
					public function register_routes(): void {
						register_rest_route( 'acme/v1', '/addon', [ 'methods' => 'GET', 'callback' => '__return_true', 'permission_callback' => '__return_true' ] );
					}
				} );

				// Not a controller: ignored.
				$api->add( new \stdClass() );
			}
		);

		$api = $this->api();

		$this->assertSame( $api, $seen );
		$this->assertArrayHasKey( '/acme/v1/addon', rest_get_server()->get_routes( 'acme/v1' ) );
	}

	public function test_controllers_may_be_given_as_instances(): void {
		Api::register( 'acme', [ 'namespace' => 'acme/v1' ] );

		$this->api( [ 'controllers' => [ new WidgetsController(), 'NoSuchClass' ] ] );

		$this->assertArrayHasKey( '/acme/v1/widgets', rest_get_server()->get_routes( 'acme/v1' ) );
	}

	public function test_every_answer_says_the_version_and_the_mode(): void {
		$test = true;

		$this->api( [ 'test_mode' => static function () use ( &$test ): bool {
			return $test;
		} ] );
		$this->sign_in();

		$response = $this->served( 'GET', '/acme/v1/widgets' );

		$this->assertSame( 'test', $response->get_headers()['X-Acme-Mode'] );
		$this->assertSame( '2.3.4', $response->get_headers()['X-Acme-Version'] );

		$test = false;

		$this->assertSame( 'live', $this->served( 'GET', '/acme/v1/widgets' )->get_headers()['X-Acme-Mode'] );
	}

	public function test_an_api_without_modes_sends_no_mode_header(): void {
		$this->api( [ 'header' => 'X-Acme-Shop' ] );
		$this->sign_in();

		$headers = $this->served( 'GET', '/acme/v1/widgets' )->get_headers();

		$this->assertArrayNotHasKey( 'X-Acme-Shop-Mode', $headers );
		$this->assertSame( '2.3.4', $headers['X-Acme-Shop-Version'] );
	}

	public function test_a_route_in_the_namespace_that_is_not_the_apis_is_left_alone(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					'acme/v1',
					'/webhook',
					[
						'methods'             => 'POST',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
						'args'                => [ 'n' => [ 'type' => 'integer' ] ],
					]
				);
			}
		);

		$this->api();

		$response = $this->served( 'POST', '/acme/v1/webhook', [ 'n' => 'nope' ] );

		$this->assertArrayNotHasKey( 'X-Acme-Version', $response->get_headers() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	public function test_wordpress_refusing_a_parameter_is_said_under_the_apis_code(): void {
		$this->api();
		$this->sign_in();

		$response = $this->served( 'GET', '/acme/v1/widgets', [ 'per_page' => 500 ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'acme_invalid_param', $response->get_data()['code'] );
		$this->assertSame( 'per_page', $response->get_data()['data']['param'] );
	}

	public function test_a_missing_parameter_is_named_too(): void {
		$this->api();
		$this->sign_in();
		Widgets::add();

		$response = $this->served( 'POST', '/acme/v1/widgets/1/refund', [], [ 'Idempotency-Key' => 'k' ] );

		$this->assertSame( 'acme_invalid_param', $response->get_data()['code'] );
		$this->assertSame( 'amount', $response->get_data()['data']['param'] );
	}

	public function test_a_request_is_announced_and_written_down_only_when_asked(): void {
		$lines = [];
		$heard = 0;

		$this->api( [ 'log' => static function ( string $line, WP_REST_Request $request ) use ( &$lines ): void {
			$lines[] = $line;
		} ] );
		$this->sign_in();

		add_action( 'acme_rest_request', static function () use ( &$heard ): void {
			++$heard;
		} );

		$this->served( 'GET', '/acme/v1/widgets' );

		$this->assertSame( 1, $heard );
		$this->assertSame( [], $lines );

		add_filter( 'acme_rest_log_requests', '__return_true' );

		$this->served( 'GET', '/acme/v1/widgets' );

		$this->assertSame( [ 'GET /acme/v1/widgets 200 (user:' . $this->admin . ')' ], $lines );
	}

	public function test_text_can_be_reworded(): void {
		$api = Api::register(
			'acme',
			[
				'namespace' => 'acme/v1',
				'strings'   => [ 'mode' => 'Shop mode.' ],
			]
		);

		$this->assertSame( 'Shop mode.', $api->text( 'mode' ) );
		$this->assertStringContainsString( 'site’s current mode', ( Api::register( 'acme', [ 'namespace' => 'acme/v1' ] ) )->text( 'mode' ) );
		$this->assertSame( '', $api->text( 'nonsense' ) );
	}

	public function test_dates_go_out_as_iso_8601_in_utc_or_null(): void {
		$api = Api::register( 'acme', [ 'namespace' => 'acme/v1' ] );

		$this->assertSame( '2026-11-18T14:53:00+00:00', $api->date( '2026-11-18 14:53:00' ) );
		$this->assertNull( $api->date( '0000-00-00 00:00:00' ) );
		$this->assertNull( $api->date( '' ) );
		$this->assertNull( $api->date( null ) );
	}
}
