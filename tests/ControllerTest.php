<?php
/**
 * What every controller has: lists, single records, errors.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests;

use SugarCommerce\Rest\Controller;
use SugarCommerce\Rest\Tests\Support\TestCase;
use SugarCommerce\Rest\Tests\Support\WidgetRecord;
use SugarCommerce\Rest\Tests\Support\Widgets;
use SugarCommerce\Rest\Tests\Support\WidgetsController;
use LogicException;
use WP_Error;

final class ControllerTest extends TestCase {

	public function test_a_list_takes_the_common_parameters_and_its_own(): void {
		$this->api();

		$params = ( new WidgetsController() )->get_collection_params();

		$this->assertSame(
			[ 'page', 'per_page', 'orderby', 'order', 'include', 'exclude', 'after', 'before', 'updated_after', 'search', 'mode', 'expand', 'status', 'owner', 'flag', 'code' ],
			array_keys( $params )
		);
		$this->assertSame( [ 'id', 'date_created', 'date_modified', 'name' ], $params['orderby']['enum'] );
		$this->assertSame( 'id', $params['orderby']['default'] );
		$this->assertSame( 'asc', $params['order']['default'] );
		$this->assertSame( 100, $params['per_page']['maximum'] );
		$this->assertSame(
			[
				'description' => 'Only widgets with these statuses.',
				'type'        => 'array',
				'items'       => [
					'type' => 'string',
					'enum' => [ 'active', 'retired' ],
				],
			],
			$params['status']
		);
		$this->assertSame( [ 'owner' ], $params['expand']['items']['enum'] );
		$this->assertStringContainsString( 'Any of: owner.', $params['expand']['description'] );
	}

	public function test_a_list_request_becomes_query_arguments(): void {
		$this->api( [ 'test_mode' => '__return_true' ] );
		$this->sign_in();

		$this->request(
			'GET',
			'/acme/v1/widgets',
			[
				'page'          => 3,
				'per_page'      => 10,
				'orderby'       => 'name',
				'order'         => 'desc',
				'search'        => '  blue ',
				'exclude'       => '4,5',
				'after'         => '2026-01-01T00:00:00+02:00',
				'before'        => '2026-02-01T00:00:00Z',
				'updated_after' => '2026-03-01T12:00:00Z',
				'status'        => 'active,retired',
				'owner'         => '7',
				'flag'          => 'true',
				'code'          => 'abc',
			]
		);

		$this->assertSame(
			[
				'number'              => 10,
				'offset'              => 20,
				'orderby'             => 'name',
				'order'               => 'DESC',
				'search'              => 'blue',
				'id__not_in'          => [ 4, 5 ],
				'date_created_query'  => [
					'after'  => '2025-12-31 22:00:00',
					'before' => '2026-02-01 00:00:00',
				],
				'date_modified_query' => [ 'after' => '2026-03-01 12:00:00' ],
				'is_test'             => 1,
				'status__in'          => [ 'active', 'retired' ],
				'owner_id'            => 7,
				'flag'                => 1,
				'code'                => 'ABC',
			],
			Widgets::$asked[0]
		);
	}

	public function test_a_list_shows_the_current_modes_records_unless_asked(): void {
		$this->api( [ 'test_mode' => '__return_false' ] );
		$this->sign_in();

		$live = Widgets::add( [ 'name' => 'live' ] );
		$test = Widgets::add(
			[
				'name'    => 'test',
				'is_test' => 1,
			]
		);

		$ids = static fn( $response ): array => array_column( $response->get_data(), 'id' );

		$this->assertSame( [ $live->id ], $ids( $this->request( 'GET', '/acme/v1/widgets' ) ) );
		$this->assertSame( [ $test->id ], $ids( $this->request( 'GET', '/acme/v1/widgets', [ 'mode' => 'test' ] ) ) );
		$this->assertSame( [ $live->id, $test->id ], $ids( $this->request( 'GET', '/acme/v1/widgets', [ 'mode' => 'all' ] ) ) );

		// Asking by id is asking for those records, whichever book they are in.
		$this->assertSame( [ $test->id ], $ids( $this->request( 'GET', '/acme/v1/widgets', [ 'include' => (string) $test->id ] ) ) );
	}

	public function test_a_page_says_where_it_is(): void {
		$this->api();
		$this->sign_in();

		foreach ( range( 1, 5 ) as $n ) {
			Widgets::add( [ 'name' => 'w' . $n ] );
		}

		$response = $this->request(
			'GET',
			'/acme/v1/widgets',
			[
				'per_page' => 2,
				'page'     => 2,
				'mode'     => 'all',
			]
		);
		$headers  = $response->get_headers();

		$this->assertCount( 2, $response->get_data() );
		$this->assertSame( '5', $headers['X-WP-Total'] );
		$this->assertSame( '3', $headers['X-WP-TotalPages'] );

		$links = (string) $headers['Link'];

		$this->assertMatchesRegularExpression( '/<[^>]*page=1[^>]*>; rel="prev"/', $links );
		$this->assertMatchesRegularExpression( '/<[^>]*per_page=2[^>]*page=3[^>]*>; rel="next"/', $links );
		$this->assertMatchesRegularExpression( '/<[^>]*page=1[^>]*>; rel="first"/', $links );
		$this->assertMatchesRegularExpression( '/<[^>]*per_page=2[^>]*page=3[^>]*>; rel="last"/', $links );

		$last = $this->request(
			'GET',
			'/acme/v1/widgets',
			[
				'per_page' => 2,
				'page'     => 3,
				'mode'     => 'all',
			]
		);

		$this->assertStringNotContainsString( 'rel="next"', (string) $last->get_headers()['Link'] );
		$this->assertStringNotContainsString( 'rel="last"', (string) $last->get_headers()['Link'] );
		$this->assertMatchesRegularExpression( '/<[^>]*page=1[^>]*>; rel="first"/', (string) $last->get_headers()['Link'] );

		$first = $this->request( 'GET', '/acme/v1/widgets', [ 'per_page' => 2, 'mode' => 'all' ] );

		$this->assertStringNotContainsString( 'rel="prev"', (string) $first->get_headers()['Link'] );
		$this->assertStringNotContainsString( 'rel="first"', (string) $first->get_headers()['Link'] );
		$this->assertMatchesRegularExpression( '/<[^>]*page=3[^>]*>; rel="last"/', (string) $first->get_headers()['Link'] );
	}

	public function test_a_record_leaves_through_its_allow_list(): void {
		$this->api();
		$this->sign_in();

		$owner  = Widgets::add( [ 'name' => 'owner' ] );
		$widget = Widgets::add(
			[
				'name'     => 'blue',
				'owner_id' => $owner->id,
				'country'  => ' gb ',
			]
		);

		$data = $this->request( 'GET', '/acme/v1/widgets/' . $widget->id )->get_data();

		$this->assertArrayNotHasKey( 'secret', $data );
		$this->assertArrayNotHasKey( 'owner', $data );
		$this->assertSame(
			[
				'code' => 'GB',
				'name' => 'United Kingdom',
			],
			$data['country']
		);
		$this->assertSame( '2026-01-02T10:00:00+00:00', $data['date_modified'] );
		$this->assertSame( rest_url( 'acme/v1/widgets/' . $widget->id ), $data['_links']['self'][0]['href'] );
		$this->assertSame( rest_url( 'acme/v1/widgets' ), $data['_links']['collection'][0]['href'] );
		$this->assertSame( rest_url( 'acme/v1/widgets/' . $owner->id ), $data['_links']['owner'][0]['href'] );
	}

	public function test_a_relation_is_included_when_asked_for_and_fetched_once_a_page(): void {
		$this->api();
		$this->sign_in();

		$owner = Widgets::add( [ 'name' => 'owner' ] );

		Widgets::add( [ 'owner_id' => $owner->id ] );
		Widgets::add( [ 'owner_id' => $owner->id ] );

		$data = $this->request(
			'GET',
			'/acme/v1/widgets',
			[
				'expand'  => 'owner',
				'include' => '2,3',
			]
		)->get_data();

		$this->assertSame( 'owner', $data[0]['owner']['name'] );
		$this->assertSame( 'owner', $data[1]['owner']['name'] );
		$this->assertSame( 0, Widgets::$getter_calls, 'The owner was read from what prime() fetched.' );
		$this->assertSame( [], WidgetRecord::$primed, 'What prime() fetched is forgotten after the page.' );

		$one = $this->request( 'GET', '/acme/v1/widgets/2', [ 'expand' => 'owner' ] )->get_data();

		$this->assertSame( 'owner', $one['owner']['name'] );
	}

	public function test_an_unknown_record_is_not_found_under_the_apis_code(): void {
		$this->api();
		$this->sign_in();

		$response = $this->request( 'GET', '/acme/v1/widgets/99' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'acme_not_found', $response->get_data()['code'] );
	}

	public function test_a_record_can_be_filtered_on_its_way_out(): void {
		$this->api();
		$this->sign_in();

		Widgets::add( [ 'name' => 'blue' ] );

		add_filter(
			'acme_rest_prepare_widget',
			static function ( array $data ): array {
				unset( $data['name'] );

				return $data + [ 'extra' => true ];
			}
		);

		$data = $this->request( 'GET', '/acme/v1/widgets/1' )->get_data();

		$this->assertArrayNotHasKey( 'name', $data );
		$this->assertTrue( $data['extra'] );
	}

	public function test_a_field_registered_on_the_object_type_is_added(): void {
		$api = $this->api();

		register_rest_field(
			$api->object_type( 'widget' ),
			'gift',
			[
				'get_callback' => static fn( array $widget ): string => 'for #' . $widget['id'],
				'schema'       => [ 'type' => 'string' ],
			]
		);

		$this->sign_in();
		Widgets::add();

		$this->assertSame( 'for #1', $this->request( 'GET', '/acme/v1/widgets/1' )->get_data()['gift'] );
		$this->assertSame( 'for #1', $this->request( 'GET', '/acme/v1/widgets' )->get_data()[0]['gift'] );
	}

	public function test_the_schema_is_the_resources(): void {
		$this->api();

		$schema = ( new WidgetsController() )->get_item_schema();

		$this->assertSame( 'acme_widget', $schema['title'] );
		$this->assertArrayHasKey( '_links', $schema['properties'] );
		$this->assertSame( 'The status in words, in the site’s language.', $schema['properties']['status_label']['description'] );
	}

	public function test_an_error_from_the_work_is_said_under_one_of_the_apis_codes(): void {
		$this->api();
		$this->sign_in();
		Widgets::add();

		$cases = [
			[ new WP_Error( 'acme_locked', 'Locked.' ), 409, 'acme_conflict' ],
			[ new WP_Error( 'acme_bad', 'Bad.', [ 'field' => 'name' ] ), 400, 'acme_invalid_param' ],
			[ new WP_Error( 'acme_bad', 'Bad.', [ 'status' => 422, 'param' => 'x' ] ), 422, 'acme_invalid_param' ],
			[ new WP_Error( 'acme_gone', 'Gone.', [ 'status' => 404 ] ), 404, 'acme_not_found' ],
			[ new WP_Error( 'acme_who', 'Who?', [ 'status' => 401 ] ), 401, 'acme_unauthorized' ],
			[ new WP_Error( 'acme_no', 'No.', [ 'status' => 403 ] ), 403, 'acme_forbidden' ],
			[ new WP_Error( 'acme_slow', 'Slow.', [ 'status' => 429 ] ), 429, 'acme_rate_limited' ],
			[ new WP_Error( 'stripe_down', 'Down.', [ 'status' => 502 ] ), 502, 'acme_failed' ],
			[ new WP_Error( 'site_down', 'Down.', [ 'status' => 500 ] ), 500, 'acme_failed' ],
			[ new WP_Error( 'acme_teapot', 'Teapot.', [ 'status' => 418 ] ), 418, 'acme_conflict' ],
		];

		foreach ( $cases as [ $error, $status, $code ] ) {
			WidgetsController::$fail = $error;

			$response = $this->request( 'POST', '/acme/v1/widgets/1/rename' );
			$data     = $response->get_data();

			$this->assertSame( $status, $response->get_status(), $error->get_error_code() );
			$this->assertSame( $code, $data['code'] );
			$this->assertSame( $error->get_error_message(), $data['message'] );
			$this->assertSame( $error->get_error_code(), $data['data']['reason'] );
		}

		$this->assertSame( 'x', $data['data']['param'] ?? 'x' );
	}

	public function test_the_field_at_fault_is_named_as_the_param(): void {
		$this->api();
		$this->sign_in();
		Widgets::add();

		WidgetsController::$fail = new WP_Error( 'acme_bad', 'Bad.', [ 'field' => 'name' ] );

		$this->assertSame( 'name', $this->request( 'POST', '/acme/v1/widgets/1/rename' )->get_data()['data']['param'] );

		WidgetsController::$fail = new WP_Error( 'acme_locked', 'Locked.' );

		$this->assertArrayNotHasKey( 'param', $this->request( 'POST', '/acme/v1/widgets/1/rename' )->get_data()['data'] );
	}

	public function test_an_error_already_under_one_of_the_apis_codes_is_passed_on(): void {
		$this->api();
		$this->sign_in();
		Widgets::add();

		WidgetsController::$fail = new WP_Error( 'acme_conflict', 'Not now.', [ 'status' => 409, 'mine' => 1 ] );

		$data = $this->request( 'POST', '/acme/v1/widgets/1/rename' )->get_data();

		$this->assertSame( 1, $data['data']['mine'] );
		$this->assertArrayNotHasKey( 'reason', $data['data'] );
	}

	public function test_a_controller_that_names_no_api_says_so(): void {
		$this->expectException( LogicException::class );

		new class() extends Controller {};
	}
}
