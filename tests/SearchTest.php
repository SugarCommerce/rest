<?php
/**
 * /search: one box, every registered resource.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests;

use SugarCommerce\Rest\Api;
use SugarCommerce\Rest\Tests\Support\TestCase;
use SugarCommerce\Rest\Tests\Support\WidgetRecord;
use SugarCommerce\Rest\Tests\Support\Widgets;

final class SearchTest extends TestCase {

	/**
	 * A kind the user may not read is not searched, though the route's
	 * own area lets them in: each kind is asked about its own area, its
	 * name unless it says otherwise.
	 *
	 * @return void
	 */
	public function test_a_kind_the_user_may_not_read_is_not_searched(): void {
		$this->search_api( [ 'can' => static fn( string $kind, string $area ): bool => 'keys' !== $area ] );
		$this->sign_in();

		Widgets::add( [ 'name' => 'blue' ] );

		$data = $this->request( 'GET', '/acme/v1/search', [ 'q' => 'KEY-1' ] )->get_data();

		$this->assertArrayHasKey( 'widgets', $data );
		$this->assertArrayNotHasKey( 'keys', $data, 'The licence keys are behind an area this user cannot read.' );
	}

	/**
	 * The fixture API, with widgets searchable and keys found only exactly.
	 *
	 * @param array<string, mixed> $config What to change.
	 *
	 * @return Api
	 */
	private function search_api( array $config = [] ): Api {
		return $this->api(
			$config + [
				'resources' => [
					'widgets' => [
						'record' => WidgetRecord::class,
						'search' => [
							'list' => [ Widgets::class, 'list' ],
							'find' => [ Widgets::class, 'find' ],
						],
					],
					'keys'    => [
						'record' => WidgetRecord::class,
						'search' => [
							'find'        => static fn( string $query ) => 'KEY-1' === $query ? Widgets::get( 1 ) : new \WP_Error( 'nope', 'No.' ),
							'description' => 'Matching licence keys.',
						],
					],
					'hidden'  => [
						'record' => WidgetRecord::class,
						'search' => false,
					],
				],
			]
		);
	}

	public function test_the_exact_match_comes_first_then_the_lists_own_search(): void {
		$this->search_api();
		$this->sign_in();

		Widgets::add( [ 'name' => 'blue one' ] );
		Widgets::add( [ 'name' => 'blue' ] );
		Widgets::add( [ 'name' => 'red' ] );

		$data = $this->request( 'GET', '/acme/v1/search', [ 'q' => 'blue' ] )->get_data();

		$this->assertSame( 'blue', $data['query'] );
		$this->assertSame( [ 2, 1 ], array_column( $data['widgets'], 'id' ) );
		$this->assertSame( [], $data['keys'] );
		$this->assertArrayNotHasKey( 'hidden', $data );
		$this->assertSame(
			[
				'search'  => 'blue',
				'number'  => 5,
				'orderby' => 'id',
				'order'   => 'DESC',
			],
			Widgets::$asked[0]
		);
	}

	public function test_a_resource_with_no_list_is_only_found_exactly(): void {
		$this->search_api();
		$this->sign_in();

		Widgets::add( [ 'name' => 'blue' ] );

		$data = $this->request( 'GET', '/acme/v1/search', [ 'q' => 'KEY-1' ] )->get_data();

		$this->assertSame( [ 1 ], array_column( $data['keys'], 'id' ) );
	}

	public function test_a_resource_can_change_what_its_list_is_asked(): void {
		$this->search_api(
			[
				'resources' => [
					'widgets' => [
						'record' => WidgetRecord::class,
						'search' => [
							'list' => [ Widgets::class, 'list' ],
							'args' => static function ( array $args, string $query ): array {
								unset( $args['search'] );

								return $args + [ 'email' => $query ];
							},
						],
					],
				],
			]
		);
		$this->sign_in();

		$this->request( 'GET', '/acme/v1/search', [ 'q' => 'a@b.co' ] );

		$this->assertSame( 'a@b.co', Widgets::$asked[0]['email'] );
		$this->assertArrayNotHasKey( 'search', Widgets::$asked[0] );
	}

	public function test_kinds_and_how_many_of_each_can_be_asked_for(): void {
		$this->search_api();
		$this->sign_in();

		foreach ( range( 1, 4 ) as $n ) {
			Widgets::add( [ 'name' => 'blue ' . $n ] );
		}

		$data = $this->request(
			'GET',
			'/acme/v1/search',
			[
				'q'        => 'blue',
				'types'    => 'widgets',
				'per_type' => 2,
			]
		)->get_data();

		$this->assertSame( [ 'query', 'widgets' ], array_keys( $data ) );
		$this->assertCount( 2, $data['widgets'] );
	}

	public function test_an_add_ons_resource_is_searched_too(): void {
		add_action(
			'acme_rest_init',
			static function ( Api $api ): void {
				$api->add_resource(
					'gizmos',
					[
						'record' => WidgetRecord::class,
						'search' => [ 'find' => static fn( string $query ) => Widgets::find( $query ) ],
					]
				);
			}
		);

		$this->search_api();
		$this->sign_in();

		Widgets::add( [ 'name' => 'gizmo' ] );

		$handler = rest_get_server()->get_routes( 'acme/v1' )['/acme/v1/search'][0];

		$this->assertSame( [ 'widgets', 'keys', 'gizmos' ], $handler['args']['types']['items']['enum'] );
		$this->assertSame( [ 1 ], array_column( $this->request( 'GET', '/acme/v1/search', [ 'q' => 'gizmo' ] )->get_data()['gizmos'], 'id' ) );
	}

	public function test_the_answer_is_described_kind_by_kind(): void {
		$this->search_api( [ 'strings' => [ 'search_query' => 'A number or a key.' ] ] );

		$server = rest_get_server();
		$args   = $server->get_routes( 'acme/v1' )['/acme/v1/search'][0]['args'];
		$schema = call_user_func( $server->get_route_options( '/acme/v1/search' )['schema'] );

		$this->assertSame( 'acme_search', $schema['title'] );
		$this->assertSame( [ 'query', 'widgets', 'keys' ], array_keys( $schema['properties'] ) );
		$this->assertSame( 'Matching widgets.', $schema['properties']['widgets']['description'] );
		$this->assertSame( 'Matching licence keys.', $schema['properties']['keys']['description'] );
		$this->assertSame( WidgetRecord::whole(), $schema['properties']['widgets']['items']['properties'] );
		$this->assertSame( 'A number or a key.', $args['q']['description'] );
		$this->assertSame( 2, $args['q']['minLength'] );
	}

	public function test_there_is_no_search_without_a_searchable_resource(): void {
		$this->api();

		$this->assertArrayNotHasKey( '/acme/v1/search', rest_get_server()->get_routes( 'acme/v1' ) );
	}
}
