<?php
/**
 * /changes: what changed and what went, for every registered resource.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests;

use SugarCommerce\Rest\Controllers\Sync;
use SugarCommerce\Rest\Tests\Support\TestCase;

final class ChangesTest extends TestCase {

	/**
	 * Make a table /changes can read.
	 *
	 * @param string $name Its name, without the prefix.
	 *
	 * @return void
	 */
	private static function table( string $name ): void {
		global $wpdb;

		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$name}" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}{$name} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, date_modified datetime NOT NULL, PRIMARY KEY (id), KEY date_modified (date_modified) )" ); // phpcs:ignore
	}

	/**
	 * Add a row.
	 *
	 * @param string $name     The table.
	 * @param string $modified When it changed.
	 *
	 * @return int
	 */
	private static function row( string $name, string $modified ): int {
		global $wpdb;

		$wpdb->insert( $wpdb->prefix . $name, [ 'date_modified' => $modified ] ); // phpcs:ignore

		return (int) $wpdb->insert_id;
	}

	/**
	 * The fixture API with two resources /changes reads.
	 *
	 * @param array<string, mixed> $config What to change.
	 *
	 * @return \SugarCommerce\Rest\Api
	 */
	private function sync_api( array $config = [] ): \SugarCommerce\Rest\Api {
		self::table( 'acme_widgets' );
		self::table( 'acme_gadgets' );

		return $this->api(
			$config + [
				'area'      => 'widgets',
				'resources' => [
					'widgets' => [
						'table'   => 'acme_widgets',
						'deleted' => 'acme_delete_widget',
					],
					'gadgets' => [ 'table' => 'acme_gadgets' ],
					'notes'   => [ 'deleted' => 'acme_delete_note' ],
				],
			]
		);
	}

	public function test_a_deletion_is_remembered_for_every_resource_that_says_how(): void {
		$api = $this->sync_api();

		do_action( 'acme_delete_widget', 4 );
		do_action( 'acme_delete_note', '9' );
		do_action( 'acme_delete_gadget', 1 );

		$kept = $api->changes()->all();

		$this->assertSame( [ [ 'widgets', 4 ], [ 'notes', 9 ] ], array_map( static fn( array $row ): array => [ $row[0], $row[1] ], $kept ) );
		$this->assertSame( 'acme_api_deleted', $api->changes()->option() );
		$this->assertIsArray( get_option( 'acme_api_deleted' ) );
	}

	public function test_an_add_ons_resource_is_listened_for_once_added(): void {
		$api = $this->sync_api();

		$api->add_resource( 'reviews', [ 'deleted' => 'addon_delete_review' ] );
		$api->add_resource( 'reviews', [ 'deleted' => 'addon_delete_review' ] );

		do_action( 'addon_delete_review', 3 );

		$this->assertCount( 1, $api->changes()->all(), 'Listened for once, not twice.' );
		$this->assertSame( 'reviews', $api->changes()->all()[0][0] );
	}

	public function test_unregistering_stops_the_listening(): void {
		$api = $this->sync_api();

		\SugarCommerce\Rest\Api::unregister( 'acme' );

		do_action( 'acme_delete_widget', 4 );

		$this->assertSame( [], $api->changes()->all() );
	}

	public function test_only_so_many_are_remembered_and_the_answer_says_when_it_is_incomplete(): void {
		$api = $this->sync_api( [ 'deletions' => [ 'kept' => 3, 'option' => 'acme_gone' ] ] );

		update_option( 'acme_gone', [ [ 'widgets', 1, 100 ], 'junk', [ 'widgets', 2, 200 ] ] );

		$this->assertTrue( $api->changes()->since( 50 )['complete'], 'Not full yet: nothing forgotten.' );

		$api->changes()->deleted( 'widgets', 3 );

		$this->assertTrue( $api->changes()->since( 100 )['complete'], 'Full, but it reaches back that far.' );
		$this->assertFalse( $api->changes()->since( 50 )['complete'], 'Full, and older deletions may be forgotten.' );

		$api->changes()->deleted( 'widgets', 4 );

		$this->assertSame( [ 2, 3, 4 ], array_column( $api->changes()->all(), 1 ) );
		$this->assertSame( [ 'widgets' => [ 3, 4 ] ], $api->changes()->since( time() - 5 )['deleted'] );
	}

	public function test_changes_lists_the_ids_changed_since_a_moment_for_each_kind(): void {
		$this->sync_api();
		$this->sign_in();

		self::row( 'acme_widgets', '2026-01-01 00:00:00' );
		$late  = self::row( 'acme_widgets', '2026-03-01 00:00:00' );
		$early = self::row( 'acme_widgets', '2026-02-01 00:00:00' );
		$g     = self::row( 'acme_gadgets', '2026-02-15 00:00:00' );

		do_action( 'acme_delete_widget', 77 );

		$response = $this->request( 'GET', '/acme/v1/changes', [ 'since' => '2026-01-15T00:00:00Z' ] );
		$data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2026-01-15T00:00:00+00:00', $data['since'] );
		$this->assertSame(
			[
				'widgets' => [ $early, $late ],
				'gadgets' => [ $g ],
			],
			$data['changed']
		);
		$this->assertSame( [ 'widgets' => [ 77 ] ], $data['deleted'] );
		$this->assertSame( [], $data['truncated'] );
		$this->assertTrue( $data['complete'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $data['cursor'] );
	}

	public function test_changes_reports_only_the_deletions_since_the_moment_asked_about(): void {
		$this->sync_api();
		$this->sign_in();

		try {
			\SugarCommerce\Dates\Clock::freeze( '2026-01-10 00:00:00' );
			do_action( 'acme_delete_widget', 5 );

			\SugarCommerce\Dates\Clock::freeze( '2026-02-10 00:00:00' );
			do_action( 'acme_delete_widget', 6 );

			$data = json_decode( (string) wp_json_encode( $this->request( 'GET', '/acme/v1/changes', [ 'since' => '2026-02-01T00:00:00Z' ] )->get_data() ), true );
		} finally {
			\SugarCommerce\Dates\Clock::reset();
		}

		$this->assertSame( [ 'widgets' => [ 6 ] ], $data['deleted'], 'The one before the moment is not reported.' );
		$this->assertSame( '2026-02-10T00:00:00+00:00', $data['cursor'], 'The cursor is now, on the same clock.' );
	}

	public function test_changes_can_be_asked_for_some_kinds_only(): void {
		$this->sync_api();
		$this->sign_in();

		self::row( 'acme_gadgets', '2026-02-15 00:00:00' );

		$data = json_decode(
			(string) wp_json_encode(
				$this->request(
					'GET',
					'/acme/v1/changes',
					[
						'since' => '2026-01-01T00:00:00Z',
						'types' => 'gadgets',
					]
				)->get_data()
			),
			true
		);

		$this->assertSame( [ 'gadgets' ], array_keys( $data['changed'] ) );
		$this->assertSame( 400, $this->request( 'GET', '/acme/v1/changes', [ 'since' => '2026-01-01T00:00:00Z', 'types' => 'notes' ] )->get_status() );
	}

	public function test_too_many_changes_of_a_kind_are_cut_and_said_so(): void {
		global $wpdb;

		$this->sync_api();
		$this->sign_in();

		$values = implode( ',', array_fill( 0, Sync::MOST + 1, "('2026-02-01 00:00:00')" ) );

		$wpdb->query( "INSERT INTO {$wpdb->prefix}acme_widgets ( date_modified ) VALUES {$values}" ); // phpcs:ignore

		$data = $this->request( 'GET', '/acme/v1/changes', [ 'since' => '2026-01-01T00:00:00Z' ] )->get_data();

		$this->assertSame( [ 'widgets' ], $data['truncated'] );
		$this->assertCount( Sync::MOST, ( (array) $data['changed'] )['widgets'] );
	}

	public function test_the_route_lists_every_kind_and_takes_the_apis_area(): void {
		$this->sync_api();

		$handler = rest_get_server()->get_routes( 'acme/v1' )['/acme/v1/changes'][0];

		$this->assertSame( [ 'widgets', 'gadgets' ], $handler['args']['types']['items']['enum'] );
		$this->assertSame( [ 'widgets', 'gadgets' ], $handler['args']['types']['default'] );

		$this->sign_in( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$asked = [];

		$this->sync_api(
			[
				'can' => static function ( string $kind, string $area ) use ( &$asked ): bool {
					$asked[] = $area;

					return true;
				},
			]
		);

		$this->request( 'GET', '/acme/v1/changes', [ 'since' => '2026-01-01T00:00:00Z' ] );

		$this->assertSame( [ 'widgets', 'widgets', 'gadgets' ], $asked, 'The route asks about the API\'s area, then each kind about its own.' );
	}

	/**
	 * A kind the user may not read is left out of the answer, changes and
	 * deletions both: the route's own area does not stand in for every
	 * kind's.
	 *
	 * @return void
	 */
	public function test_a_kind_the_user_may_not_read_is_left_out(): void {
		$this->sync_api(
			[
				'can'       => static fn( string $kind, string $area ): bool => 'gadgets' !== $area,
				'resources' => [
					'widgets' => [
						'table'   => 'acme_widgets',
						'deleted' => 'acme_delete_widget',
					],
					'gadgets' => [
						'table'   => 'acme_gadgets',
						'deleted' => 'acme_delete_gadget',
					],
					'secrets' => [
						'table' => 'acme_gadgets',
						'area'  => 'gadgets',
					],
				],
			]
		);
		$this->sign_in();

		$w = self::row( 'acme_widgets', '2026-02-01 00:00:00' );
		self::row( 'acme_gadgets', '2026-02-01 00:00:00' );

		do_action( 'acme_delete_gadget', 9 );

		$data = json_decode( (string) wp_json_encode( $this->request( 'GET', '/acme/v1/changes', [ 'since' => '2026-01-15T00:00:00Z' ] )->get_data() ), true );

		$this->assertSame( [ 'widgets' => [ $w ] ], $data['changed'] );
		$this->assertSame( [], $data['deleted'] );
	}

	public function test_there_is_no_changes_route_without_a_table_to_read(): void {
		$this->api();

		$this->assertArrayNotHasKey( '/acme/v1/changes', rest_get_server()->get_routes( 'acme/v1' ) );

		$this->sync_api( [ 'routes' => [ 'changes' => false ] ] );

		$this->assertArrayNotHasKey( '/acme/v1/changes', rest_get_server()->get_routes( 'acme/v1' ) );
	}
}
