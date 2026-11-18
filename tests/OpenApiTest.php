<?php
/**
 * The routes as an OpenAPI document -- and SugarCart's, reproduced.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests;

use SugarCommerce\Rest\Api;
use SugarCommerce\Rest\OpenApi;
use SugarCommerce\Rest\Tests\Support\SugarCart\ProbeController;
use SugarCommerce\Rest\Tests\Support\SugarCart\ProbeRecord;
use SugarCommerce\Rest\Tests\Support\SugarCart\Snapshot;
use SugarCommerce\Rest\Tests\Support\TestCase;

final class OpenApiTest extends TestCase {

	/**
	 * The parameters every list has, in order, before its own filters.
	 */
	private const COMMON = [ 'page', 'per_page', 'orderby', 'order', 'include', 'exclude', 'after', 'before', 'updated_after', 'search', 'mode', 'expand' ];

	/**
	 * Serve as the snapshot's site did: its name and its address.
	 *
	 * @return void
	 */
	private static function as_the_snapshots_site(): void {
		$base = Snapshot::routes()['rest_url'];

		update_option( 'blogname', Snapshot::routes()['blogname'] );

		add_filter( 'rest_url', static fn( $url, $path ): string => $base . ltrim( (string) $path, '/' ), 10, 2 );
	}

	/**
	 * Register every route of the snapshot, as it was registered.
	 *
	 * @return void
	 */
	private static function register_the_snapshots_routes(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				foreach ( Snapshot::routes()['routes'] as $route => $was ) {
					if ( '/sugarcart/v1' === $route ) {
						continue;
					}

					$handlers = [];

					foreach ( $was['handlers'] as $handler ) {
						$handlers[] = [
							'methods'             => implode( ',', array_keys( array_filter( $handler['methods'] ) ) ),
							'callback'            => '__return_null',
							'permission_callback' => '__return_true',
						] + array_diff_key( $handler, [ 'methods' => 1 ] );
					}

					if ( null !== $was['schema'] ) {
						$schema             = $was['schema'];
						$handlers['schema'] = static fn(): array => $schema;
					}

					register_rest_route( 'sugarcart/v1', substr( $route, strlen( '/sugarcart/v1' ) ), $handlers );
				}
			}
		);
	}

	public function test_sugarcarts_document_is_reproduced_byte_for_byte_from_its_routes(): void {
		self::as_the_snapshots_site();
		self::register_the_snapshots_routes();

		$api = Api::register( 'sugarcart', Snapshot::config( [ 'routes' => [ 'openapi' => false ] ] ) );

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$document = (string) wp_json_encode( $api->openapi()->document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";

		$this->assertSame( Snapshot::document(), $document );
	}

	public function test_the_kits_own_routes_are_sugarcarts_given_its_configuration(): void {
		Api::register( 'sugarcart', Snapshot::config() );

		$GLOBALS['wp_rest_server'] = null;

		$routes = rest_get_server()->get_routes( 'sugarcart/v1' );

		foreach ( [ '/changes', '/search', '/openapi.json' ] as $route ) {
			$was     = Snapshot::route( $route );
			$handler = $routes[ '/sugarcart/v1' . $route ][0];
			$schema  = call_user_func( rest_get_server()->get_route_options( '/sugarcart/v1' . $route )['schema'] );

			$this->assertSame( $was['schema'], $schema, $route . ': its schema' );
			$this->assertSame( $was['handlers'][0]['args'], $handler['args'], $route . ': its arguments' );
			$this->assertSame( $was['handlers'][0]['methods'], $handler['methods'], $route . ': its methods' );

			foreach ( [ 'sugarcart_api', 'sugarcart_kind', 'sugarcart_once' ] as $mark ) {
				$this->assertSame( $was['handlers'][0][ $mark ], $handler[ $mark ], $route . ': ' . $mark );
			}
		}
	}

	public function test_every_sugarcart_list_takes_what_the_kit_gives_a_list(): void {
		Api::register( 'sugarcart', Snapshot::config() );

		$lists = 0;

		foreach ( Snapshot::routes()['routes'] as $route => $was ) {
			foreach ( $was['handlers'] as $handler ) {
				// A list made from a spec. A route that pages something else
				// -- a report, an activity feed -- declares its own.
				if ( empty( $handler['sugarcart_api'] ) || ! isset( $handler['args']['per_page'], $handler['args']['orderby'], $handler['args']['updated_after'] ) ) {
					continue;
				}

				$args = $handler['args'];
				$own  = array_diff_key( $args, array_flip( self::COMMON ) );

				// A filter may take a common parameter's name, and its place:
				// SugarCart's licences filter by `order` (an order id), which
				// leaves that list no sort direction to take.
				if ( [ 'asc', 'desc' ] !== ( $args['order']['enum'] ?? null ) ) {
					$own = [ 'order' => $args['order'] ] + $own;
				}

				ProbeRecord::$expandable = (array) ( $args['expand']['items']['enum'] ?? [] );

				$probe = new ProbeController(
					[
						'sortable' => $args['orderby']['enum'],
						'orderby'  => $args['orderby']['default'],
						'order'    => $args['order']['default'] ?? 'desc',
						'search'   => isset( $args['search'] ),
						'mode'     => isset( $args['mode'] ),
						'filters'  => self::filters( $own ),
					],
					isset( $args['expand'] )
				);

				$this->assertSame( $args, $probe->get_collection_params(), $route );

				++$lists;
			}
		}

		$this->assertGreaterThanOrEqual( 11, $lists, 'The snapshot has SugarCart’s lists in it.' );
	}

	/**
	 * A list route's own parameters as the filters of a spec.
	 *
	 * @param array<string, array<string, mixed>> $args The parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function filters( array $args ): array {
		$filters = [];

		foreach ( $args as $name => $arg ) {
			$list = 'array' === $arg['type'];

			$filters[ $name ] = array_filter(
				[
					'description' => $arg['description'],
					'type'        => $list ? $arg['items']['type'] : $arg['type'],
					'enum'        => $list ? ( $arg['items']['enum'] ?? null ) : ( $arg['enum'] ?? null ),
					'list'        => $list,
				],
				static fn( $value ): bool => null !== $value
			);
		}

		return $filters;
	}

	public function test_an_operation_says_what_the_route_says(): void {
		$this->api( [ 'openapi' => [ 'title' => static fn( string $site ): string => 'Acme for ' . $site ] ] );

		$document  = Api::get( 'acme' )->openapi()->document();
		$refund    = $document['paths']['/widgets/{id}/refund']['post'];
		$list      = $document['paths']['/widgets']['get'];
		$parameter = array_column( $refund['parameters'], null, 'name' );

		$this->assertSame( 'Acme for ' . get_bloginfo( 'name' ), $document['info']['title'] );
		$this->assertSame( '2.3.4', $document['info']['version'] );
		$this->assertSame( [ [ 'applicationPassword' => [] ] ], $document['security'] );
		$this->assertSame( 'postWidgetsIdRefund', $refund['operationId'] );
		$this->assertSame( [ 'widgets' ], $refund['tags'] );
		$this->assertSame(
			[
				'kind'       => 'refund',
				'idempotent' => true,
			],
			$refund['x-acme']
		);
		$this->assertSame( 'path', $parameter['id']['in'] );
		$this->assertSame( 'header', $parameter['Idempotency-Key']['in'] );
		$this->assertTrue( $parameter['Idempotency-Key']['required'] );
		$this->assertSame( [ 'amount' ], $refund['requestBody']['content']['application/json']['schema']['required'] );
		$this->assertSame( '#/components/schemas/acme_refund', $refund['responses']['200']['content']['application/json']['schema']['$ref'] );
		$this->assertSame( 'array', $list['responses']['200']['content']['application/json']['schema']['type'] );
		$this->assertSame( '#/components/schemas/acme_widget', $list['responses']['200']['content']['application/json']['schema']['items']['$ref'] );
		$this->assertStringContainsString( 'acme_unauthorized, acme_forbidden', $document['components']['schemas']['error']['properties']['code']['description'] );
		$this->assertStringEndsWith( 'or acme_failed.', $document['components']['schemas']['error']['properties']['code']['description'] );
		$this->assertArrayHasKey( '/openapi.json', $document['paths'] );
	}

	public function test_the_document_is_served_to_whoever_may_read(): void {
		$this->api();
		$this->sign_in();

		$response = $this->request( 'GET', '/acme/v1/openapi.json' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '3.1.0', $response->get_data()['openapi'] );

		$this->api( [ 'routes' => [ 'openapi' => false ] ] );

		$this->assertArrayNotHasKey( '/acme/v1/openapi.json', rest_get_server()->get_routes( 'acme/v1' ) );
	}

	public function test_a_wordpress_schema_becomes_json_schema(): void {
		$this->assertSame(
			[
				'type'       => 'object',
				'readOnly'   => true,
				'properties' => [
					'a' => [ 'type' => 'string' ],
					'b' => [
						'type'  => 'array',
						'items' => [ 'readOnly' => false ],
					],
				],
				'required'   => [ 'a' ],
			],
			OpenApi::schema(
				[
					'$schema'    => 'http://json-schema.org/draft-04/schema#',
					'type'       => 'object',
					'readonly'   => true,
					'context'    => [ 'view' ],
					'properties' => [
						'a' => [
							'type'     => 'string',
							'required' => true,
						],
						'b' => [
							'type'        => 'array',
							'default'     => [],
							'arg_options' => [],
							'required'    => false,
							'items'       => [ 'readonly' => false ],
						],
					],
				]
			)
		);

		$this->assertSame( '/orders/{id}/notes/{note_id}', OpenApi::path( '/orders/(?P<id>[\d]+)/notes/(?P<note_id>[\d]+)' ) );
		$this->assertSame( '/', OpenApi::path( '' ) );
	}
}
