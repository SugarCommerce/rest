<?php
/**
 * A limit a minute, a credential.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests;

use SugarCommerce\Rest\RateLimit;
use SugarCommerce\Rest\Tests\Support\TestCase;
use SugarCommerce\Rest\Tests\Support\Widgets;
use SugarCommerce\Rest\Tests\Support\WidgetsController;

final class RateLimitTest extends TestCase {

	public function test_too_many_requests_are_refused_with_when_to_come_back(): void {
		$this->api( [ 'rate_limit' => [ 'limits' => [ 'general' => 2 ] ] ] );
		$this->count_in_memory();
		$this->sign_in();

		$first  = $this->served( 'GET', '/acme/v1/widgets' );
		$second = $this->served( 'GET', '/acme/v1/widgets' );
		$third  = $this->served( 'GET', '/acme/v1/widgets' );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( '1', $first->get_headers()['X-RateLimit-Remaining'] );
		$this->assertSame( '2', $first->get_headers()['X-RateLimit-Limit'] );
		$this->assertArrayNotHasKey( 'Retry-After', $first->get_headers() );

		// Told to wait as soon as nothing is left, before it is refused.
		$this->assertSame( 200, $second->get_status() );
		$this->assertArrayHasKey( 'Retry-After', $second->get_headers() );

		$this->assertSame( 429, $third->get_status() );
		$this->assertSame( 'acme_rate_limited', $third->get_data()['code'] );
		$this->assertGreaterThan( 0, $third->get_data()['data']['retry_after'] );
		$this->assertArrayHasKey( 'Retry-After', $third->get_headers() );
	}

	public function test_what_moves_money_counts_twice_and_its_own_bucket_is_smaller(): void {
		$this->api( [ 'rate_limit' => [ 'limits' => [ 'money' => 1 ] ] ] );
		$this->count_in_memory();
		$this->sign_in();
		Widgets::add();

		$headers = [ 'Idempotency-Key' => 'a' ];

		$this->assertSame( 201, $this->served( 'POST', '/acme/v1/widgets/1/refund', [ 'amount' => 1 ], $headers )->get_status() );

		$refused = $this->served( 'POST', '/acme/v1/widgets/1/refund', [ 'amount' => 1 ], [ 'Idempotency-Key' => 'b' ] );

		$this->assertSame( 429, $refused->get_status() );
		$this->assertSame( 1, WidgetsController::$refunds );

		// The general bucket is untouched by that refusal's bucket.
		$this->assertSame( 200, $this->served( 'GET', '/acme/v1/widgets' )->get_status() );
	}

	public function test_what_moves_money_also_counts_as_a_request(): void {
		$this->api( [ 'rate_limit' => [ 'limits' => [ 'general' => 1 ] ] ] );
		$this->count_in_memory();
		$this->sign_in();
		Widgets::add();

		$this->assertSame( 201, $this->served( 'POST', '/acme/v1/widgets/1/refund', [ 'amount' => 1 ], [ 'Idempotency-Key' => 'a' ] )->get_status() );
		$this->assertSame( 429, $this->served( 'GET', '/acme/v1/widgets' )->get_status() );
	}

	public function test_the_tightest_bucket_is_the_one_reported(): void {
		$this->api( [ 'rate_limit' => [ 'limits' => [ 'money' => 5 ] ] ] );
		$this->count_in_memory();
		$this->sign_in();
		Widgets::add();

		$response = $this->served( 'POST', '/acme/v1/widgets/1/refund', [ 'amount' => 1 ], [ 'Idempotency-Key' => 'a' ] );

		$this->assertSame( '5', $response->get_headers()['X-RateLimit-Limit'] );
		$this->assertSame( '4', $response->get_headers()['X-RateLimit-Remaining'] );
	}

	public function test_a_filter_can_change_or_lift_the_limit(): void {
		$this->api( [ 'rate_limit' => [ 'limits' => [ 'general' => 1 ] ] ] );
		$this->count_in_memory();
		$this->sign_in();

		$asked = [];

		add_filter(
			'acme_rest_rate_limit',
			static function ( int $limit, string $bucket, string $credential ) use ( &$asked ): int {
				$asked = [ $limit, $bucket, $credential ];

				return 0;
			},
			10,
			3
		);

		foreach ( range( 1, 3 ) as $n ) {
			$this->assertSame( 200, $this->served( 'GET', '/acme/v1/widgets' )->get_status() );
		}

		$this->assertSame( [ 1, 'general', 'user:' . $this->admin ], $asked );
	}

	public function test_one_request_one_set_of_headers(): void {
		$api = $this->api( [ 'rate_limit' => [] ] );
		$this->count_in_memory();
		$this->sign_in();

		$this->served( 'GET', '/acme/v1/widgets' );

		$this->assertSame( [], $api->rate_limit()->headers(), 'finish() reset the state for the next request.' );
	}

	public function test_no_limit_at_all_when_switched_off(): void {
		$api = $this->api( [ 'rate_limit' => false ] );

		$this->assertTrue( $api->rate_limit()->check( 'money' ) );
		$this->assertSame( [], $api->rate_limit()->headers() );
		$this->assertSame( 'general', $api->rate_limit()->bucket( 'refund' ) );
	}

	public function test_kinds_map_to_buckets(): void {
		$api = $this->api( [ 'rate_limit' => [ 'kinds' => [ 'destroy' => 'danger' ] ] ] );

		$this->assertSame( 'danger', $api->rate_limit()->bucket( 'destroy' ) );
		$this->assertSame( 'general', $api->rate_limit()->bucket( 'refund' ) );
		$this->assertSame( 'general', $this->api( [ 'rate_limit' => [] ] )->rate_limit()->bucket( 'read' ) );
		$this->assertSame( 'money', $this->api( [ 'rate_limit' => [] ] )->rate_limit()->bucket( 'refund' ) );
	}

	public function test_a_public_route_counts_each_connection_on_its_own(): void {
		$api     = $this->api( [ 'rate_limit' => [ 'limits' => [ 'general' => 1 ] ] ] );
		$limiter = $this->count_in_memory();

		$this->assertTrue( $api->rate_limit()->check( 'general', 'storefront:1.1.1.1' ) );
		$this->assertTrue( $api->rate_limit()->check( 'general', 'storefront:2.2.2.2' ) );
		$this->assertWPError( $api->rate_limit()->check( 'general', 'storefront:1.1.1.1' ) );
		$this->assertSame( 2, $limiter->count( 'general|storefront:1.1.1.1', 60 ) );
	}

	public function test_the_credential_is_the_application_password_or_the_user(): void {
		$this->sign_in();

		$this->assertSame( 'user:' . $this->admin, RateLimit::credential() );

		// What WordPress sets once an application password has signed the request in.
		$GLOBALS['wp_rest_application_password_uuid'] = 'phone-uuid';

		try {
			$this->assertSame( 'app:phone-uuid', RateLimit::credential(), 'Each app counts on its own, not as its user.' );
		} finally {
			unset( $GLOBALS['wp_rest_application_password_uuid'] );
		}
	}

	public function test_the_default_counter_is_the_hosts_table(): void {
		global $wpdb;

		$wpdb->query( \SugarCommerce\Security\RateLimit\Stores\Table::schema( $wpdb->prefix . 'acme_rate_limits' ) ); // phpcs:ignore

		$api = $this->api( [ 'rate_limit' => [ 'limits' => [ 'general' => 1 ] ] ] );

		$this->assertTrue( $api->rate_limit()->check() );
		$this->assertWPError( $api->rate_limit()->check() );
		$this->assertSame( '2', $wpdb->get_var( "SELECT hits FROM {$wpdb->prefix}acme_rate_limits LIMIT 1" ) ); // phpcs:ignore
	}
}
