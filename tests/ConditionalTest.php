<?php
/**
 * One ETag and 304, for every route.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests;

use SugarCommerce\Rest\Conditional;
use SugarCommerce\Rest\Tests\Support\TestCase;
use SugarCommerce\Rest\Tests\Support\Widgets;
use WP_REST_Request;
use WP_REST_Response;

final class ConditionalTest extends TestCase {

	/**
	 * A request.
	 *
	 * @param array<string, string> $headers Its headers.
	 * @param string                $method  Its method.
	 *
	 * @return WP_REST_Request
	 */
	private static function asking( array $headers = [], string $method = 'GET' ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/acme/v1/things/1' );

		$request->set_query_params( [ 'expand' => 'owner' ] );
		$request->set_url_params( [ 'id' => '1' ] );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return $request;
	}

	public function test_an_answer_is_tagged_by_what_it_says_and_what_was_asked(): void {
		$tag = Conditional::apply( self::asking(), new WP_REST_Response( [ 'a' => 1 ] ) )->get_headers()['ETag'];

		$this->assertMatchesRegularExpression( '/^W\/"[0-9a-f]{32}"$/', $tag );
		$this->assertNotSame( $tag, Conditional::apply( self::asking(), new WP_REST_Response( [ 'a' => 2 ] ) )->get_headers()['ETag'] );

		$other = self::asking();
		$other->set_query_params( [] );

		$this->assertNotSame( $tag, Conditional::apply( $other, new WP_REST_Response( [ 'a' => 1 ] ) )->get_headers()['ETag'] );

		$slug = self::asking();
		$slug->set_url_params( [ 'id' => '2' ] );

		$this->assertNotSame( $tag, Conditional::apply( $slug, new WP_REST_Response( [ 'a' => 1 ] ) )->get_headers()['ETag'] );
	}

	public function test_the_tag_brought_back_is_answered_304_with_no_body(): void {
		$tag = Conditional::apply( self::asking(), new WP_REST_Response( [ 'a' => 1 ] ) )->get_headers()['ETag'];

		$response = Conditional::apply( self::asking( [ 'If-None-Match' => 'W/"other", ' . $tag ] ), new WP_REST_Response( [ 'a' => 1 ] ) );

		$this->assertSame( 304, $response->get_status() );
		$this->assertNull( $response->get_data() );
		$this->assertSame( $tag, $response->get_headers()['ETag'] );
	}

	public function test_a_star_matches_anything(): void {
		$this->assertSame( 304, Conditional::apply( self::asking( [ 'If-None-Match' => '*' ] ), new WP_REST_Response( [ 'a' => 1 ] ) )->get_status() );
	}

	public function test_a_tag_that_does_not_match_is_the_answer_whatever_the_date_says(): void {
		$response = Conditional::apply(
			self::asking(
				[
					'If-None-Match'     => 'W/"old"',
					'If-Modified-Since' => gmdate( 'D, d M Y H:i:s', 2000 ) . ' GMT',
				]
			),
			new WP_REST_Response( [ 'a' => 1 ] ),
			[
				'modified'       => 1000,
				'trust_modified' => true,
			]
		);

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_a_date_answers_only_when_it_is_trusted(): void {
		$since = [ 'If-Modified-Since' => gmdate( 'D, d M Y H:i:s', 2000 ) . ' GMT' ];

		$untrusted = Conditional::apply( self::asking( $since ), new WP_REST_Response( [ 'a' => 1 ] ), [ 'modified' => 1000 ] );

		$this->assertSame( 200, $untrusted->get_status() );
		$this->assertSame( gmdate( 'D, d M Y H:i:s', 1000 ) . ' GMT', $untrusted->get_headers()['Last-Modified'] );

		$trusted = Conditional::apply(
			self::asking( $since ),
			new WP_REST_Response( [ 'a' => 1 ] ),
			[
				'modified'       => 1000,
				'trust_modified' => true,
			]
		);

		$this->assertSame( 304, $trusted->get_status() );

		$newer = Conditional::apply(
			self::asking( $since ),
			new WP_REST_Response( [ 'a' => 1 ] ),
			[
				'modified'       => 3000,
				'trust_modified' => true,
			]
		);

		$this->assertSame( 200, $newer->get_status() );
	}

	public function test_a_cache_control_header_is_sent_when_asked_for(): void {
		$headers = Conditional::apply( self::asking(), new WP_REST_Response( [] ), [ 'cache_control' => 'public, max-age=300' ] )->get_headers();

		$this->assertSame( 'public, max-age=300', $headers['Cache-Control'] );
		$this->assertArrayNotHasKey( 'Cache-Control', Conditional::apply( self::asking(), new WP_REST_Response( [] ) )->get_headers() );
		$this->assertArrayNotHasKey( 'Last-Modified', $headers );
	}

	public function test_only_a_successful_read_is_tagged(): void {
		$this->assertSame( [], Conditional::apply( self::asking( [], 'POST' ), new WP_REST_Response( [ 'a' => 1 ] ) )->get_headers() );
		$this->assertSame( [], Conditional::apply( self::asking(), new WP_REST_Response( [ 'a' => 1 ], 201 ) )->get_headers() );
		$this->assertArrayHasKey( 'ETag', Conditional::apply( self::asking( [], 'HEAD' ), new WP_REST_Response( [ 'a' => 1 ] ) )->get_headers() );
	}

	public function test_a_single_record_is_tagged_and_dated_but_never_answered_on_its_date(): void {
		$this->api();
		$this->sign_in();
		Widgets::add();

		$first = $this->request( 'GET', '/acme/v1/widgets/1' );
		$tag   = $first->get_headers()['ETag'];

		$this->assertSame( 'Fri, 02 Jan 2026 10:00:00 GMT', $first->get_headers()['Last-Modified'] );
		$this->assertSame( 304, $this->request( 'GET', '/acme/v1/widgets/1', [], [ 'If-None-Match' => $tag ] )->get_status() );

		// The row's date is not the whole truth about the answer.
		$this->assertSame( 200, $this->request( 'GET', '/acme/v1/widgets/1', [], [ 'If-Modified-Since' => 'Sat, 03 Jan 2026 10:00:00 GMT' ] )->get_status() );

		Widgets::get( 1 )->name = 'renamed';

		$this->assertSame( 200, $this->request( 'GET', '/acme/v1/widgets/1', [], [ 'If-None-Match' => $tag ] )->get_status() );
	}
}
