<?php
/**
 * SugarCart's API as it was, read from the snapshot.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support\SugarCart;

/**
 * The two files tests/fixtures/sugarcart-snapshot.php wrote from a real
 * site: the OpenAPI document SugarCart served, and the route table it was
 * written from (every handler's methods, arguments and marks, every route's
 * schema).
 */
final class Snapshot {

	/**
	 * The route table, read once.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $routes = null;

	/**
	 * The route table.
	 *
	 * @return array{blogname: string, rest_url: string, routes: array<string, array{schema: array|null, handlers: array}>}
	 */
	public static function routes(): array {
		return self::$routes ??= json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/sugarcart-routes.json' ), true );
	}

	/**
	 * The document, as it was served.
	 *
	 * @return string
	 */
	public static function document(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/sugarcart-openapi.json' );
	}

	/**
	 * One route.
	 *
	 * @param string $route Below the namespace: `/search`.
	 *
	 * @return array{schema: array|null, handlers: array}
	 */
	public static function route( string $route ): array {
		return self::routes()['routes'][ '/sugarcart/v1' . $route ];
	}

	/**
	 * SugarCart's configuration of the kit: what keeps its document,
	 * its codes, its hooks and its headers as they were.
	 *
	 * @param array<string, mixed> $config What to change.
	 *
	 * @return array<string, mixed>
	 */
	public static function config( array $config = [] ): array {
		return $config + [
			'namespace'   => 'sugarcart/v1',
			'version'     => '1.0.0',
			'header'      => 'X-SugarCart',
			'area'        => 'orders',
			'rate_limit'  => false,
			'controllers' => [],
			'openapi'     => [
				'title'       => '%s — SugarCart API',
				'description' => 'The shop over REST. Sign in with a WordPress application password (HTTP Basic). Money is integer minor units with a currency and a formatted string; dates are ISO 8601 in UTC. See API.md in the plugin for the conventions.',
				'security'    => [
					'applicationPassword' => [
						'type'        => 'http',
						'scheme'      => 'basic',
						'description' => 'A WordPress username and an application password made on the Connect the App screen.',
					],
				],
			],
			'strings'     => [
				'mode'         => 'Test or live records. The shop’s current mode when left out; `all` for both.',
				'signed_out'   => 'Sign in to use this. The app sends a WordPress application password with every request.',
				'search_query' => 'What to look for: an order number, an email address, a name, a title, a licence key.',
				'search_types' => 'Which kinds of record to look in. All four when left out.',
			],
			'resources'   => [
				'orders'        => [
					'record'  => Order::class,
					'table'   => 'sugarcart_orders',
					'deleted' => 'sugarcart_delete_order',
					'search'  => [
						'list' => '__return_empty_array',
						'find' => '__return_null',
					],
				],
				'customers'     => [
					'record'  => Customer::class,
					'table'   => 'sugarcart_customers',
					'deleted' => 'sugarcart_delete_customer',
					'search'  => [
						'list' => '__return_empty_array',
						'find' => '__return_null',
					],
				],
				'products'      => [
					'record'  => Product::class,
					'table'   => 'sugarcart_products',
					'deleted' => 'sugarcart_delete_product',
					'search'  => [
						'list' => '__return_empty_array',
						'find' => '__return_null',
					],
				],
				'prices'        => [
					'table'   => 'sugarcart_prices',
					'deleted' => 'sugarcart_delete_price',
				],
				'subscriptions' => [
					'table'   => 'sugarcart_subscriptions',
					'deleted' => 'sugarcart_delete_subscription',
				],
				'licenses'      => [
					'record'  => License::class,
					'table'   => 'sugarcart_licenses',
					'deleted' => 'sugarcart_delete_license',
					'search'  => [
						'find'        => '__return_null',
						'description' => 'Matching licences.',
					],
				],
				'discounts'     => [
					'table'   => 'sugarcart_discounts',
					'deleted' => 'sugarcart_delete_discount',
				],
				'blocklist'     => [
					'table'   => 'sugarcart_checkout_rules',
					'deleted' => 'sugarcart_delete_checkout_rule',
				],
			],
		];
	}
}
