<?php
/**
 * A retried request answers as the first one did.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests;

use SugarCommerce\Rest\Idempotency;
use SugarCommerce\Rest\Idempotency\Entry;
use SugarCommerce\Rest\Idempotency\Store;
use SugarCommerce\Rest\Idempotency\Stores\Memory;
use SugarCommerce\Rest\Idempotency\Stores\Query;
use SugarCommerce\Rest\Idempotency\Stores\Table;
use SugarCommerce\Rest\Tests\Support\Keys\Query as Keys;
use SugarCommerce\Rest\Tests\Support\TestCase;
use SugarCommerce\Rest\Tests\Support\Widgets;
use SugarCommerce\Rest\Tests\Support\WidgetsController;
use WP_Error;

final class IdempotencyTest extends TestCase {

	/**
	 * Refund widget 1.
	 *
	 * @param string               $key    The key.
	 * @param array<string, mixed> $params The body.
	 *
	 * @return \WP_REST_Response
	 */
	private function refund( string $key, array $params = [ 'amount' => 5 ] ): \WP_REST_Response {
		return $this->request( 'POST', '/acme/v1/widgets/1/refund', $params, '' === $key ? [] : [ 'Idempotency-Key' => $key ] );
	}

	public function set_up(): void {
		parent::set_up();

		$this->api();
		$this->sign_in();
		Widgets::add();
	}

	public function test_a_request_that_must_not_happen_twice_needs_a_key(): void {
		$missing = $this->refund( '' );

		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( 'acme_invalid_param', $missing->get_data()['code'] );
		$this->assertSame( 'Idempotency-Key', $missing->get_data()['data']['param'] );

		$this->assertSame( 400, $this->refund( str_repeat( 'k', Idempotency::MAX_LENGTH + 1 ) )->get_status() );
		$this->assertSame( 201, $this->refund( str_repeat( 'k', Idempotency::MAX_LENGTH ) )->get_status() );
		$this->assertSame( 1, WidgetsController::$refunds );
	}

	public function test_the_same_request_again_is_given_the_first_answer_and_nothing_is_done(): void {
		$first  = $this->refund( 'abc' );
		$second = $this->refund( 'abc' );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 201, $second->get_status() );
		$this->assertSame( $first->get_data(), $second->get_data() );
		$this->assertSame( 'true', $second->get_headers()['Idempotent-Replayed'] );
		$this->assertArrayNotHasKey( 'Idempotent-Replayed', $first->get_headers() );
		$this->assertSame( 1, WidgetsController::$refunds );
	}

	public function test_the_body_in_another_order_is_the_same_request(): void {
		$this->refund(
			'abc',
			[
				'amount' => 5,
				'note'   => [ 'b' => 1, 'a' => 2 ],
			]
		);

		$again = $this->refund(
			'abc',
			[
				'note'   => [ 'a' => 2, 'b' => 1 ],
				'amount' => 5,
			]
		);

		$this->assertSame( 201, $again->get_status() );
		$this->assertSame( 1, WidgetsController::$refunds );
	}

	public function test_the_same_key_for_a_different_request_is_refused_loudly(): void {
		$this->refund( 'abc' );

		$other = $this->refund( 'abc', [ 'amount' => 6 ] );

		$this->assertSame( 422, $other->get_status() );
		$this->assertSame( 'acme_invalid_param', $other->get_data()['code'] );
		$this->assertSame( 1, WidgetsController::$refunds );
	}

	public function test_a_key_belongs_to_the_user_who_sent_it(): void {
		$this->refund( 'abc' );

		$this->sign_in( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( 201, $this->refund( 'abc' )->get_status() );
		$this->assertSame( 2, WidgetsController::$refunds );
	}

	public function test_the_same_key_while_the_first_runs_is_told_to_wait(): void {
		$this->refund( 'abc' );

		// As it was while the first request was still being worked on.
		$this->keys->complete( $this->keys->all()[0]->id, 0, '' );

		$waiting = $this->refund( 'abc' );

		$this->assertSame( 409, $waiting->get_status() );
		$this->assertSame( 'acme_conflict', $waiting->get_data()['code'] );
		$this->assertSame( 2, $waiting->get_data()['data']['retry_after'] );
		$this->assertSame( 1, WidgetsController::$refunds );
	}

	public function test_a_refusal_is_kept_and_replayed_as_one(): void {
		WidgetsController::$fail = new WP_Error( 'acme_locked', 'Locked.' );

		$first = $this->refund( 'abc' );

		WidgetsController::$fail = null;

		$again = $this->refund( 'abc' );

		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'acme_conflict', $again->get_data()['code'] );
		$this->assertTrue( $again->get_data()['data']['replayed'] );
		$this->assertSame( $first->get_data()['message'], $again->get_data()['message'] );
		$this->assertSame( 0, WidgetsController::$refunds );
	}

	public function test_a_failure_of_the_site_is_not_kept_so_the_retry_may_try(): void {
		WidgetsController::$fail = new WP_Error( 'stripe_down', 'Down.', [ 'status' => 502 ] );

		$this->assertSame( 502, $this->refund( 'abc' )->get_status() );
		$this->assertSame( [], $this->keys->all() );

		WidgetsController::$fail = null;

		$this->assertSame( 201, $this->refund( 'abc' )->get_status() );
		$this->assertSame( 1, WidgetsController::$refunds );
	}

	public function test_a_key_whose_day_is_over_starts_again(): void {
		$this->refund( 'abc' );

		$entry = $this->keys->all()[0];

		$this->keys->release( $entry->id );
		$this->keys->claim( new Entry( $entry->hash, $entry->user_id, $entry->route, 'stale', time() - 10 ) );

		$this->assertSame( 201, $this->refund( 'abc' )->get_status() );
		$this->assertSame( 2, WidgetsController::$refunds );
	}

	public function test_losing_the_race_answers_as_the_winner_did(): void {
		$keys = $this->keys;

		$this->api(
			[
				'idempotency' => new class( $keys ) implements Store {
					public function __construct( private Memory $inner ) {}
					public function find( string $hash ): ?Entry {
						return $this->inner->find( $hash );
					}
					// Somebody else got there between the look and the insert.
					public function claim( Entry $entry ): int {
						$id = $this->inner->claim( $entry );
						$this->inner->complete( $id, 200, (string) wp_json_encode( [ 'body' => [ 'winner' => true ] ] ) );
						return 0;
					}
					public function complete( int $id, int $status, string $response ): bool {
						return $this->inner->complete( $id, $status, $response );
					}
					public function release( int $id ): bool {
						return $this->inner->release( $id );
					}
					public function clean( int $now ): int {
						return $this->inner->clean( $now );
					}
				},
			]
		);

		$lost = $this->refund( 'abc' );

		$this->assertSame( 200, $lost->get_status() );
		$this->assertSame( [ 'winner' => true ], $lost->get_data() );
		$this->assertSame( 'true', $lost->get_headers()['Idempotent-Replayed'] );
		$this->assertSame( 0, WidgetsController::$refunds );
	}

	public function test_a_store_that_can_neither_claim_nor_find_fails_safely(): void {
		$this->api(
			[
				'idempotency' => new class() implements Store {
					public function find( string $hash ): ?Entry {
						return null;
					}
					public function claim( Entry $entry ): int {
						return 0;
					}
					public function complete( int $id, int $status, string $response ): bool {
						return false;
					}
					public function release( int $id ): bool {
						return false;
					}
					public function clean( int $now ): int {
						return 0;
					}
				},
			]
		);

		$response = $this->refund( 'abc' );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'acme_failed', $response->get_data()['code'] );
		$this->assertSame( 0, WidgetsController::$refunds );
	}

	public function test_the_store_may_be_made_when_first_needed(): void {
		$made = 0;
		$keys = new Memory();

		$api = $this->api(
			[
				'idempotency' => static function () use ( &$made, $keys ): Store {
					++$made;

					return $keys;
				},
			]
		);

		$this->assertSame( 0, $made );

		$this->refund( 'abc' );
		$this->refund( 'def' );

		$this->assertSame( 1, $made );
		$this->assertSame( $keys, $api->idempotency()->store() );
		$this->assertCount( 2, $keys->all() );
	}

	public function test_the_default_store_is_a_table_named_for_the_prefix(): void {
		global $wpdb;

		$api = $this->api( [ 'idempotency' => null ] );

		$this->assertInstanceOf( Table::class, $api->idempotency()->store() );
		$this->assertSame( $wpdb->prefix . 'acme_idempotency_keys', $api->idempotency()->store()->table() );
	}

	public function test_what_is_kept_is_who_what_and_when(): void {
		$this->refund( 'abc' );

		$entry = $this->keys->all()[0];

		$this->assertSame( hash( 'sha256', $this->admin . '|abc' ), $entry->hash );
		$this->assertSame( $this->admin, $entry->user_id );
		$this->assertSame( 'POST /acme/v1/widgets/1/refund', $entry->route );
		$this->assertSame( 201, $entry->status );
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, $entry->expires, 5 );
		$this->assertSame( [ 'body' => [ 'refunded' => 1 ] ], json_decode( $entry->response, true ) );
	}

	/**
	 * Each store, against the same contract.
	 *
	 * @return array<string, array{0: callable(): Store}>
	 */
	public static function stores(): array {
		$table = static function (): string {
			global $wpdb;

			$name = $wpdb->prefix . 'acme_idempotency_keys';

			$wpdb->query( "DROP TABLE IF EXISTS {$name}" ); // phpcs:ignore
			$wpdb->query( Table::schema( $name, $wpdb->get_charset_collate() ) ); // phpcs:ignore

			return $name;
		};

		return [
			'memory'              => [ static fn(): Store => new Memory() ],
			'table'               => [ static fn(): Store => new Table( $table() ) ],
			'query'               => [
				static function () use ( $table ): Store {
					$table();

					return new Query( static fn() => new Keys() );
				},
			],
		];
	}

	/**
	 * @dataProvider stores
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'stores' )]
	public function test_a_store_lets_one_claim_win_and_keeps_the_answer( callable $make ): void {
		$store = $make();
		$entry = new Entry( str_repeat( 'a', 64 ), 3, 'POST /x', str_repeat( 'f', 64 ), time() + 60 );

		$id = $store->claim( $entry );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 0, $store->claim( $entry ), 'The second claim of the same key loses.' );

		$found = $store->find( $entry->hash );

		$this->assertSame( $id, $found->id );
		$this->assertTrue( $found->pending() );
		$this->assertSame( 3, $found->user_id );
		$this->assertSame( 'POST /x', $found->route );
		$this->assertSame( $entry->expires, $found->expires );

		$this->assertTrue( $store->complete( $id, 201, '{"body":1}' ) );

		$done = $store->find( $entry->hash );

		$this->assertSame( 201, $done->status );
		$this->assertSame( '{"body":1}', $done->response );
		$this->assertFalse( $done->pending() );

		$this->assertTrue( $store->release( $id ) );
		$this->assertNull( $store->find( $entry->hash ) );
		$this->assertNull( $store->find( 'nothing' ) );
	}

	/**
	 * @dataProvider stores
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'stores' )]
	public function test_a_store_cleans_only_what_has_expired( callable $make ): void {
		$store = $make();

		$store->claim( new Entry( str_repeat( 'a', 64 ), 1, 'r', 'f', time() - 10 ) );
		$store->claim( new Entry( str_repeat( 'b', 64 ), 1, 'r', 'f', time() + 60 ) );
		$store->claim( new Entry( str_repeat( 'c', 64 ), 1, 'r', 'f', null ) );

		$this->assertSame( 1, $store->clean( time() ) );
		$this->assertNull( $store->find( str_repeat( 'a', 64 ) ) );
		$this->assertNotNull( $store->find( str_repeat( 'b', 64 ) ) );
		$this->assertNotNull( $store->find( str_repeat( 'c', 64 ) ) );
	}

	public function test_the_table_store_dates_what_it_writes_on_the_clock(): void {
		global $wpdb;

		$name = $wpdb->prefix . 'acme_idempotency_keys';

		$wpdb->query( "DROP TABLE IF EXISTS {$name}" ); // phpcs:ignore
		$wpdb->query( Table::schema( $name ) ); // phpcs:ignore

		$store = new Table( $name );

		try {
			\SugarCommerce\Dates\Clock::freeze( '2026-05-01 10:00:00' );
			$id = $store->claim( new Entry( str_repeat( 'a', 64 ), 1, 'r', 'f', null ) );

			\SugarCommerce\Dates\Clock::freeze( '2026-05-01 10:00:30' );
			$store->complete( $id, 200, '{}' );
		} finally {
			\SugarCommerce\Dates\Clock::reset();
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT date_created, date_modified FROM %i WHERE id = %d', $name, $id ), ARRAY_A ); // phpcs:ignore

		$this->assertSame( [ 'date_created' => '2026-05-01 10:00:00', 'date_modified' => '2026-05-01 10:00:30' ], $row );
	}

	public function test_the_query_store_writes_through_the_plugins_functions(): void {
		global $wpdb;

		$name = $wpdb->prefix . 'acme_idempotency_keys';

		$wpdb->query( "DROP TABLE IF EXISTS {$name}" ); // phpcs:ignore
		$wpdb->query( Table::schema( $name ) ); // phpcs:ignore

		$seen  = [];
		$store = new Query(
			static fn() => new Keys(),
			static function ( array $data ) use ( &$seen ) {
				$seen[] = 'insert';

				return ( new Keys() )->add_item( $data );
			},
			static function ( int $id, array $data ) use ( &$seen ): bool {
				$seen[] = 'update';

				return ( new Keys() )->update_item( $id, $data );
			},
			static function ( int $id ) use ( &$seen ): bool {
				$seen[] = 'delete';

				return ( new Keys() )->delete_item( $id );
			}
		);

		Keys::$calls = [];

		$id = $store->claim( new Entry( str_repeat( 'a', 64 ), 1, 'r', 'f', null ) );

		$store->complete( $id, 200, '{}' );
		$store->release( $id );

		$this->assertSame( [ 'insert', 'update', 'delete' ], $seen );
		$this->assertSame( 'claim', Keys::$calls[0][0], 'The query class’s own claim() did the insert.' );
		$this->assertSame(
			[ 'key_hash', 'user_id', 'route', 'fingerprint', 'response_code', 'date_expires' ],
			array_keys( Keys::$calls[0][1] )
		);
	}

	public function test_the_query_store_cleans_through_sweep_a_batch_at_a_time(): void {
		global $wpdb;

		$name = $wpdb->prefix . 'acme_idempotency_keys';

		$wpdb->query( "DROP TABLE IF EXISTS {$name}" ); // phpcs:ignore
		$wpdb->query( Table::schema( $name ) ); // phpcs:ignore

		$store = new Query( static fn() => new Keys(), batch: 2 );

		foreach ( [ 'a', 'b', 'c' ] as $letter ) {
			$store->claim( new Entry( str_repeat( $letter, 64 ), 1, 'r', 'f', time() - 10 ) );
		}

		Keys::$calls = [];

		$this->assertSame( 2, $store->clean( time() ), 'No more than a batch goes at once.' );
		$this->assertSame( [ 'sweep', [ 'date_expires', gmdate( 'Y-m-d H:i:s', time() ), 2 ] ], Keys::$calls[0] );
		$this->assertSame( 1, $store->clean( time() ), 'The rest go next time.' );
	}

	public function test_the_query_store_refuses_a_query_that_is_not_a_data_query(): void {
		$store = new Query( static fn() => new \stdClass() );

		$this->expectException( \UnexpectedValueException::class );

		$store->find( 'anything' );
	}

	public function test_a_completed_request_sometimes_cleans_up(): void {
		$this->keys->claim( new Entry( str_repeat( 'z', 64 ), 1, 'r', 'f', time() - 10 ) );

		foreach ( range( 1, 200 ) as $n ) {
			$this->refund( 'key-' . $n );

			if ( null === $this->keys->find( str_repeat( 'z', 64 ) ) ) {
				break;
			}
		}

		$this->assertNull( $this->keys->find( str_repeat( 'z', 64 ) ), 'One write in twenty sweeps the expired keys.' );
	}
}
