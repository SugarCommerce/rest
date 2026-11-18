<?php
/**
 * Who may: signed in, and allowed.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests;

use SugarCommerce\Rest\Capabilities;
use SugarCommerce\Rest\Permissions;
use SugarCommerce\Rest\Tests\Support\TestCase;
use SugarCommerce\Rest\Tests\Support\Widgets;
use WP_REST_Request;

final class PermissionsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		// The suite's site is plain HTTP: serve it over HTTPS, where
		// application passwords work, unless a test says otherwise.
		$_SERVER['HTTPS'] = 'on';
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTPS'] );
		Capabilities::unregister( 'acme' );

		parent::tear_down();
	}

	public function test_nobody_signed_in_is_told_to_sign_in(): void {
		$this->api( [ 'strings' => [ 'signed_out' => 'Connect the app.' ] ] );

		$response = $this->request( 'GET', '/acme/v1/widgets' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'acme_unauthorized', $response->get_data()['code'] );
		$this->assertSame( 'Connect the app.', $response->get_data()['message'] );
	}

	public function test_a_site_without_application_passwords_says_why(): void {
		$this->api();

		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		$this->assertStringContainsString( 'switched off', $this->request( 'GET', '/acme/v1/widgets' )->get_data()['message'] );

		unset( $_SERVER['HTTPS'] );

		$this->assertStringContainsString( 'HTTPS', Permissions::application_passwords_problem() );

		$_SERVER['HTTPS'] = 'on';

		remove_all_filters( 'wp_is_application_passwords_available' );

		$this->assertSame( '', Permissions::application_passwords_problem() );
	}

	public function test_without_a_capability_set_it_takes_manage_options(): void {
		$this->api();
		$this->sign_in( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->request( 'GET', '/acme/v1/widgets' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'acme_forbidden', $response->get_data()['code'] );
		$this->assertStringContainsString( 'see that', $response->get_data()['message'] );

		Widgets::add();

		$this->assertStringContainsString( 'do that', $this->request( 'POST', '/acme/v1/widgets/1/rename' )->get_data()['message'] );
	}

	public function test_a_capability_set_decides_by_kind_and_area(): void {
		Capabilities::register(
			'acme',
			[
				'kinds'  => [
					'read'   => [ 'default' => 'acme_view' ],
					'write'  => [ 'default' => 'acme_manage' ],
					'refund' => [ 'default' => 'acme_refund' ],
				],
				'filter' => 'acme_rest_can_{kind}',
			]
		);

		$this->api( [ 'capabilities' => 'acme' ] );

		$viewer = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		get_userdata( $viewer )->add_cap( 'acme_view' );

		$this->sign_in( $viewer );
		Widgets::add();

		$this->assertSame( 200, $this->request( 'GET', '/acme/v1/widgets' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/acme/v1/widgets/1/rename' )->get_status() );

		// The set's filter is asked with the request.
		$asked = null;

		add_filter(
			'acme_rest_can_write',
			static function ( bool $can, string $area, $request ) use ( &$asked ): bool {
				$asked = [ $area, $request instanceof WP_REST_Request ];

				return true;
			},
			10,
			3
		);

		$this->assertSame( 200, $this->request( 'POST', '/acme/v1/widgets/1/rename' )->get_status() );
		$this->assertSame( [ 'widgets', true ], $asked );

		// An administrator holds every one of them.
		$this->sign_in();

		$this->assertTrue( $this->api( [ 'capabilities' => Capabilities::get( 'acme' ) ] )->permissions()->can( 'refund', 'widgets' ) );
	}

	public function test_a_can_callable_decides_instead(): void {
		$asked = [];

		$api = $this->api(
			[
				'can' => static function ( string $kind, string $area ) use ( &$asked ): bool {
					$asked[] = $kind . ':' . $area;

					return 'read' === $kind;
				},
			]
		);

		$this->sign_in( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( 200, $this->request( 'GET', '/acme/v1/widgets' )->get_status() );
		$this->assertContains( 'read:widgets', $asked );
		$this->assertFalse( $api->permissions()->can( 'write', 'widgets' ) );
	}
}
