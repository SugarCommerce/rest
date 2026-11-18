<?php
/**
 * A plugin's abilities, from its list to what WordPress runs.
 *
 * Three things matter more than the rest. What an ability says it does --
 * read, write, destroy -- is what lets an assistant's client ask a person
 * first, so the annotations must follow the kind and a missing kind must be
 * the cautious one. Who may use it must be the capability the same action
 * takes in the admin, not one capability for everything. And an add-on
 * must be able to put its own in, or every add-on copies the lot.
 *
 * All of it against WordPress's own Abilities API: its registry, its rules
 * for names and categories, and its REST route that runs an ability.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests;

use InvalidArgumentException;
use ReflectionProperty;
use SugarCommerce\Rest\Abilities;
use SugarCommerce\Rest\Capabilities;
use SugarCommerce\Rest\Tests\Support\TestCase;
use WP_Abilities_Registry;
use WP_Ability;
use WP_Ability_Categories_Registry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AbilitiesTest extends TestCase {

	/**
	 * Every _doing_it_wrong() WordPress raised that a test has not looked at.
	 *
	 * @var string[]
	 */
	private array $refusals = [];

	/**
	 * What WordPress was handed for each ability, before it decided.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $handed = [];

	public function set_up(): void {
		parent::set_up();

		self::forget_the_registries();

		// WordPress says what it refuses with _doing_it_wrong(): keep it, so
		// a test can say what was refused and nothing else is.
		add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
		add_action(
			'doing_it_wrong_run',
			function ( string $function_name, string $message ): void {
				$this->refusals[] = $function_name . ': ' . $message;
			},
			10,
			2
		);

		add_filter(
			'wp_register_ability_args',
			function ( array $args, string $name ): array {
				$this->handed[ $name ] = $args;

				return $args;
			},
			10,
			2
		);

		$this->sign_in();
	}

	public function tear_down(): void {
		foreach ( array_keys( ( new ReflectionProperty( Abilities::class, 'sets' ) )->getValue() ) as $id ) {
			Abilities::unregister( (string) $id );
		}

		Capabilities::unregister( 'shop' );
		remove_role( 'shop_viewer' );

		self::forget_the_registries();

		parent::tear_down();
	}

	protected function assert_post_conditions(): void {
		parent::assert_post_conditions();

		$this->assertSame( [], $this->refusals, 'WordPress refused something no test said it would.' );
	}

	/**
	 * Start WordPress's Abilities API afresh on the next use, as on a new request.
	 *
	 * @return void
	 */
	private static function forget_the_registries(): void {
		foreach ( [ WP_Abilities_Registry::class, WP_Ability_Categories_Registry::class ] as $registry ) {
			( new ReflectionProperty( $registry, 'instance' ) )->setValue( null, null );
		}
	}

	/**
	 * Start the Abilities API: both of its actions run, the way WordPress runs them.
	 *
	 * @return void
	 */
	private function start(): void {
		self::forget_the_registries();

		WP_Abilities_Registry::get_instance();
	}

	/**
	 * A shop's abilities, one of each kind.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function shop(): array {
		return [
			'list-orders'  => [
				'label'       => 'List orders',
				'description' => 'Lists recent orders.',
				'kind'        => 'read',
				'area'        => 'orders',
				'input'       => [
					'limit' => [
						'type'    => 'integer',
						'minimum' => 1,
					],
				],
				'execute'     => static fn( $input ): array => [ 'orders' => [] ],
			],
			'resend'       => [
				'label'       => 'Resend a receipt',
				'description' => 'Sends the receipt again.',
				'kind'        => 'write',
				'area'        => 'orders',
				'input'       => [ 'order' => [ 'type' => 'string' ] ],
				'required'    => [ 'order' ],
				'execute'     => static fn( array $input ): string => 'sent ' . $input['order'],
			],
			'refund-order' => [
				'label'       => 'Refund an order',
				'description' => 'Gives the money back.',
				'kind'        => 'destroy',
				'area'        => 'orders',
				'access'      => 'refund',
				'idempotent'  => false,
				'execute'     => static fn( array $input ): array => [ 'refunded' => 1 ],
			],
		];
	}

	/**
	 * Register a set, then start the Abilities API.
	 *
	 * @param array<string, mixed> $config The set.
	 * @param string               $id     Its id.
	 *
	 * @return Abilities
	 */
	private function registered( array $config = [], string $id = 'shop' ): Abilities {
		$set = Abilities::register(
			$id,
			$config + [
				'abilities' => self::shop(),
				'category'  => [ 'description' => 'Orders and refunds.' ],
			]
		);

		$this->start();

		return $set;
	}

	/**
	 * An ability WordPress holds.
	 *
	 * @param string $name Its full name.
	 *
	 * @return WP_Ability
	 */
	private function ability( string $name ): WP_Ability {
		$this->assertTrue( wp_has_ability( $name ), "WordPress does not have {$name}." );

		return wp_get_ability( $name );
	}

	/**
	 * Say WordPress refused something, and why.
	 *
	 * @param string $why Part of its message.
	 *
	 * @return void
	 */
	private function refused( string $why ): void {
		$before         = count( $this->refusals );
		$this->refusals = array_values( array_filter( $this->refusals, static fn( string $refusal ): bool => ! str_contains( $refusal, $why ) ) );

		$this->assertLessThan( $before, count( $this->refusals ), "WordPress did not refuse anything for: {$why}" );
	}

	/**
	 * A capability map that answers `shop_{kind}_{area}`, and keeps what it was asked.
	 *
	 * @return object
	 */
	private static function map(): object {
		return new class() {

			/**
			 * Every question it was asked.
			 *
			 * @var array<int, array{0: string, 1: string}>
			 */
			public array $asked = [];

			public function capability( string $kind, string $area ): string {
				$this->asked[] = [ $kind, $area ];

				return '' === $area ? 'shop_' . $kind : 'shop_' . $kind . '_' . $area;
			}
		};
	}

	/**
	 * A user given capabilities of their own.
	 *
	 * @param string ...$capabilities What they have, besides `read`.
	 *
	 * @return int
	 */
	private function user_with( string ...$capabilities ): int {
		$user = get_userdata( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		foreach ( $capabilities as $capability ) {
			$user->add_cap( $capability );
		}

		return $user->ID;
	}

	/**
	 * Run an ability through WordPress's REST route.
	 *
	 * @param string                    $method GET for a read, POST for a write, DELETE for an idempotent destroy.
	 * @param string                    $name   The ability's full name.
	 * @param array<string, mixed>|null $input  What it is asked with.
	 *
	 * @return WP_REST_Response
	 */
	private function over_rest( string $method, string $name, ?array $input = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wp-abilities/v1/abilities/' . $name . '/run' );

		if ( null !== $input && 'POST' === $method ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( [ 'input' => $input ] ) );
		} elseif ( null !== $input ) {
			$request->set_query_params( [ 'input' => $input ] );
		}

		return rest_get_server()->dispatch( $request );
	}

	public function test_the_category_and_the_abilities_are_registered(): void {
		$abilities = self::shop();
		$set       = $this->registered(
			[
				'abilities' => $abilities,
				'category'  => [
					'label'       => 'Shop',
					'description' => 'Orders and refunds.',
					'meta'        => [ 'icon' => 'cart' ],
				],
			]
		);

		$category = wp_get_ability_category( 'shop' );

		$this->assertSame( 'Shop', $category->get_label() );
		$this->assertSame( 'Orders and refunds.', $category->get_description() );
		$this->assertSame( [ 'icon' => 'cart' ], $category->get_meta() );
		$this->assertSame( [ 'shop/list-orders', 'shop/resend', 'shop/refund-order' ], array_keys( wp_get_abilities( [ 'namespace' => 'shop' ] ) ) );
		$this->assertSame( 'shop', $set->category() );
		$this->assertSame( 'shop', $set->id() );

		$ability = $this->ability( 'shop/list-orders' );

		$this->assertSame( 'List orders', $ability->get_label() );
		$this->assertSame( 'Lists recent orders.', $ability->get_description() );
		$this->assertSame( 'shop', $ability->get_category() );
		$this->assertSame( [ 'orders' => [] ], $ability->execute() );

		// The callback goes to WordPress as it was given.
		$this->assertSame( $abilities['resend']['execute'], $this->handed['shop/resend']['execute_callback'] );
		$this->assertSame( 'sent 7', $this->ability( 'shop/resend' )->execute( [ 'order' => '7' ] ) );
	}

	public function test_names_are_made_slugs(): void {
		$set = $this->registered( [], 'My_Shop' );

		$this->assertTrue( wp_has_ability_category( 'my-shop' ) );
		$this->assertSame( 'My_Shop', wp_get_ability_category( 'my-shop' )->get_label() );
		$this->assertSame( 'my-shop', $this->ability( 'my-shop/list-orders' )->get_category() );
		$this->assertSame( 'my-shop/x', $set->name( 'x' ) );
		$this->assertSame( 'other/x', $set->name( 'other/x' ) );

		$this->registered(
			[
				'namespace' => 'shop-pro',
				'category'  => [
					'slug'        => 'Shop Pro',
					'label'       => 'Shop',
					'description' => 'Orders and refunds.',
				],
			]
		);

		$this->assertTrue( wp_has_ability_category( 'shop-pro' ) );
		$this->assertSame( 'shop-pro', $this->ability( 'shop-pro/resend' )->get_category() );
	}

	public function test_the_default_category_is_the_ids(): void {
		$seen = [];

		add_filter(
			'wp_register_ability_category_args',
			static function ( array $args, string $slug ) use ( &$seen ): array {
				$seen[ $slug ] = $args;

				return $args;
			},
			10,
			2
		);

		Abilities::register( 'My_Shop', [ 'abilities' => self::shop() ] );
		$this->start();

		// WordPress refuses a category without a description, and every
		// ability in it with it, so one left out is the label.
		$this->assertSame(
			[
				'label'       => 'My_Shop',
				'description' => 'My_Shop',
			],
			$seen['my-shop']
		);
		$this->assertTrue( wp_has_ability_category( 'my-shop' ) );
		$this->assertCount( 3, wp_get_abilities() );
	}

	public function test_somebody_elses_category(): void {
		add_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				wp_register_ability_category(
					'commerce',
					[
						'label'       => 'Commerce',
						'description' => 'Theirs.',
					]
				);
			}
		);

		$this->registered( [ 'category' => 'commerce' ] );

		$this->assertSame( [ 'commerce' ], array_keys( wp_get_ability_categories() ) );
		$this->assertSame( 'Commerce', wp_get_ability_category( 'commerce' )->get_label() );
		$this->assertSame( 'commerce', $this->ability( 'shop/resend' )->get_category() );
	}

	public function test_a_category_already_there_is_left_alone(): void {
		add_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				wp_register_ability_category(
					'shop',
					[
						'label'       => 'Theirs',
						'description' => 'Theirs.',
					]
				);
			},
			5
		);

		// Registering it again would be refused, which the post-conditions catch.
		$this->registered(
			[
				'category' => [
					'label'       => 'Ours',
					'description' => 'Ours.',
				],
			]
		);

		$this->assertSame( 'Theirs', wp_get_ability_category( 'shop' )->get_label() );
		$this->assertSame( 'shop', $this->ability( 'shop/resend' )->get_category() );
	}

	public function test_a_category_named_and_not_there_is_not_made_up(): void {
		// It is not ours to describe.
		$this->registered( [ 'category' => 'nowhere' ] );

		$this->refused( 'Ability category "nowhere" is not registered' );
		$this->assertSame( [], wp_get_ability_categories() );
		$this->assertSame( [], wp_get_abilities() );
	}

	public function test_an_ability_can_name_its_own_category(): void {
		add_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				wp_register_ability_category(
					'shop-extras',
					[
						'label'       => 'Extras',
						'description' => 'An add-on’s.',
					]
				);
			}
		);

		$abilities                       = self::shop();
		$abilities['resend']['category'] = 'Shop Extras';

		$this->registered( [ 'abilities' => $abilities ] );

		$this->assertSame( 'shop-extras', $this->ability( 'shop/resend' )->get_category() );
		$this->assertSame( 'shop', $this->ability( 'shop/list-orders' )->get_category() );
	}

	public function test_the_annotations_follow_the_kind(): void {
		$this->registered();

		$this->assertSame(
			[
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			$this->ability( 'shop/list-orders' )->get_meta()['annotations']
		);
		$this->assertSame(
			[
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			],
			$this->ability( 'shop/resend' )->get_meta()['annotations']
		);
		$this->assertSame(
			[
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			],
			$this->ability( 'shop/refund-order' )->get_meta()['annotations']
		);
	}

	public function test_an_unknown_kind_is_destroy(): void {
		$this->registered(
			[
				'abilities' => [
					'missing' => [
						'description' => 'No kind.',
						'execute'     => '__return_true',
					],
					'typo'    => [
						'description' => 'A misspelt kind.',
						'kind'        => 'destory',
						'idempotent'  => true,
						'execute'     => '__return_true',
					],
				],
			]
		);

		$missing = $this->ability( 'shop/missing' )->get_meta()['annotations'];
		$typo    = $this->ability( 'shop/typo' )->get_meta()['annotations'];

		$this->assertTrue( $missing['destructive'] );
		$this->assertFalse( $missing['readonly'] );
		$this->assertFalse( $missing['idempotent'] );
		$this->assertTrue( $typo['destructive'] );
		$this->assertTrue( $typo['idempotent'] );
		$this->assertSame( 'shop/missing', $this->ability( 'shop/missing' )->get_label() );
	}

	public function test_annotations_can_be_changed(): void {
		$abilities                              = self::shop();
		$abilities['resend']['annotations']     = [
			'idempotent' => true,
			'openWorld'  => true,
		];
		$abilities['list-orders']['idempotent'] = false;

		$this->registered( [ 'abilities' => $abilities ] );

		$this->assertTrue( $this->ability( 'shop/resend' )->get_meta()['annotations']['idempotent'] );
		$this->assertTrue( $this->ability( 'shop/resend' )->get_meta()['annotations']['openWorld'] );
		$this->assertFalse( $this->ability( 'shop/resend' )->get_meta()['annotations']['readonly'] );
		$this->assertFalse( $this->ability( 'shop/list-orders' )->get_meta()['annotations']['idempotent'] );
	}

	public function test_the_input_schema(): void {
		$abilities                       = self::shop();
		$abilities['resend']['required'] = [ 2 => 'order' ];
		$abilities['raw']                = [
			'description'  => 'Takes a string.',
			'kind'         => 'read',
			'input_schema' => [ 'type' => 'string' ],
			'execute'      => 'strtoupper',
		];

		$this->registered( [ 'abilities' => $abilities ] );

		$this->assertSame(
			[
				'type'                 => 'object',
				'properties'           => [ 'order' => [ 'type' => 'string' ] ],
				'required'             => [ 'order' ],
				'additionalProperties' => false,
			],
			$this->ability( 'shop/resend' )->get_input_schema()
		);

		// Nothing to take is still an object, so the callbacks are given it.
		$this->assertSame(
			[
				'type'                 => 'object',
				'properties'           => [],
				'required'             => [],
				'additionalProperties' => false,
				'default'              => [],
			],
			$this->ability( 'shop/refund-order' )->get_input_schema()
		);
		$this->assertSame( [], $this->ability( 'shop/list-orders' )->get_input_schema()['default'] );
		$this->assertSame( [ 'type' => 'string' ], $this->ability( 'shop/raw' )->get_input_schema() );

		// Asked with nothing, an ability that needs nothing runs.
		$this->assertSame( [ 'refunded' => 1 ], $this->ability( 'shop/refund-order' )->execute() );
		$this->assertSame( [ 'orders' => [] ], $this->ability( 'shop/list-orders' )->execute() );
		$this->assertSame( 'X', $this->ability( 'shop/raw' )->execute( 'x' ) );

		// One that needs something does not, and nothing it does not name gets in.
		$this->assertSame( 'ability_invalid_input', $this->ability( 'shop/resend' )->execute()->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->ability( 'shop/resend' )->execute( [] )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->ability( 'shop/list-orders' )->execute( [ 'everything' => true ] )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->ability( 'shop/list-orders' )->execute( [ 'limit' => 0 ] )->get_error_code() );

		$this->registered( [ 'strict' => false ] );

		$this->assertTrue( $this->ability( 'shop/resend' )->get_input_schema()['additionalProperties'] );
		$this->assertSame( 'sent 7', $this->ability( 'shop/resend' )->execute( [ 'order' => '7', 'note' => 'hi' ] ) );
	}

	public function test_the_output_schema(): void {
		$abilities                                  = self::shop();
		$abilities['list-orders']['output']         = [ 'orders' => [ 'type' => 'array' ] ];
		$abilities['resend']['output']              = [ 'type' => 'boolean' ];
		$abilities['refund-order']['output_schema'] = [
			'type'       => 'object',
			'properties' => [ 'refunded' => [ 'type' => 'integer' ] ],
		];

		$this->registered( [ 'abilities' => $abilities ] );

		$this->assertSame(
			[
				'type'       => 'object',
				'properties' => [ 'orders' => [ 'type' => 'array' ] ],
			],
			$this->ability( 'shop/list-orders' )->get_output_schema()
		);
		$this->assertSame( [ 'type' => 'boolean' ], $this->ability( 'shop/resend' )->get_output_schema() );
		$this->assertSame( 'integer', $this->ability( 'shop/refund-order' )->get_output_schema()['properties']['refunded']['type'] );

		// WordPress checks the answer against it.
		$this->assertSame( [ 'orders' => [] ], $this->ability( 'shop/list-orders' )->execute() );
		$this->assertSame( 'ability_invalid_output', $this->ability( 'shop/resend' )->execute( [ 'order' => '7' ] )->get_error_code() );

		$this->registered();

		$this->assertArrayNotHasKey( 'output_schema', $this->handed['shop/resend'] );
		$this->assertSame( [], $this->ability( 'shop/resend' )->get_output_schema() );
	}

	public function test_who_is_shown_them(): void {
		$abilities                          = self::shop();
		$abilities['resend']['mcp']         = false;
		$abilities['refund-order']['mcp']   = [ 'type' => 'tool' ];
		$abilities['list-orders']['public'] = false;
		$abilities['list-orders']['meta']   = [
			'mcp'    => [ 'type' => 'resource' ],
			'source' => 'shop',
		];

		$this->registered( [ 'abilities' => $abilities ] );

		$refund = $this->ability( 'shop/refund-order' )->get_meta();

		$this->assertSame(
			[
				'type'   => 'tool',
				'public' => true,
			],
			$refund['mcp']
		);
		$this->assertTrue( $refund['public'] );
		$this->assertTrue( $refund['show_in_rest'] );
		$this->assertSame( [ 'public' => false ], $this->ability( 'shop/resend' )->get_meta()['mcp'] );

		$listed = $this->ability( 'shop/list-orders' )->get_meta();

		$this->assertFalse( $listed['public'] );
		$this->assertTrue( $listed['show_in_rest'] );
		$this->assertSame(
			[
				'public' => true,
				'type'   => 'resource',
			],
			$listed['mcp']
		);
		$this->assertSame( 'shop', $listed['source'] );

		// What the MCP Adapter asks WordPress for.
		$this->assertSame( [ 'shop/list-orders', 'shop/refund-order' ], array_keys( wp_get_abilities( [ 'meta' => [ 'mcp' => [ 'public' => true ] ] ] ) ) );

		$this->registered(
			[
				'public'       => false,
				'show_in_rest' => false,
				'mcp'          => false,
			]
		);

		$meta = $this->ability( 'shop/resend' )->get_meta();

		$this->assertFalse( $meta['public'] );
		$this->assertFalse( $meta['show_in_rest'] );
		$this->assertSame( [ 'public' => false ], $meta['mcp'] );

		// Not over REST: the route says there is no such thing.
		$this->assertSame( 404, $this->over_rest( 'POST', 'shop/resend', [ 'order' => '7' ] )->get_status() );

		$abilities                           = self::shop();
		$abilities['resend']['show_in_rest'] = false;
		$abilities['refund-order']['mcp']    = [ 'type' => 'tool' ];

		$this->registered(
			[
				'abilities' => $abilities,
				'mcp'       => false,
			]
		);

		$this->assertFalse( $this->ability( 'shop/resend' )->get_meta()['show_in_rest'] );
		$this->assertTrue( $this->ability( 'shop/list-orders' )->get_meta()['show_in_rest'] );
		$this->assertSame(
			[
				'type'   => 'tool',
				'public' => false,
			],
			$this->ability( 'shop/refund-order' )->get_meta()['mcp']
		);
	}

	public function test_each_ability_asks_the_map(): void {
		$map = self::map();
		$set = $this->registered( [ 'capabilities' => $map ] );

		$this->assertSame( 'shop_read_orders', $set->capability( 'shop/list-orders' ) );
		$this->assertSame( 'shop_write_orders', $set->capability( 'shop/resend' ) );
		$this->assertSame( 'shop_refund_orders', $set->capability( 'shop/refund-order' ) );

		$this->sign_in( $this->user_with( 'shop_read_orders' ) );

		$this->assertTrue( $this->ability( 'shop/list-orders' )->check_permissions( [] ) );
		$this->assertFalse( $this->ability( 'shop/resend' )->check_permissions( [ 'order' => '7' ] ) );
		$this->assertFalse( $this->ability( 'shop/refund-order' )->check_permissions( [] ) );

		// manage_options alone is no longer enough, nor needed.
		$this->sign_in();

		$this->assertFalse( $set->permitted( 'shop/list-orders' ) );
		$this->assertFalse( $this->ability( 'shop/list-orders' )->check_permissions( [] ) );
		$this->assertContains( [ 'refund', 'orders' ], $map->asked );
	}

	public function test_which_kind_of_the_map_each_kind_asks(): void {
		$abilities = [
			'remove' => [
				'description' => 'Takes one off the blocklist.',
				'kind'        => 'destroy',
				'area'        => 'blocklist',
				'execute'     => '__return_true',
			],
			'stats'  => [
				'description' => 'Counts things.',
				'kind'        => 'read',
				'execute'     => '__return_true',
			],
			'note'   => [
				'description' => 'Adds a note.',
				'kind'        => 'write',
				'area'        => 'orders',
				'execute'     => '__return_true',
			],
		];

		$set = $this->registered(
			[
				'abilities'    => $abilities,
				'capabilities' => self::map(),
			]
		);

		$this->assertSame( 'shop_write_blocklist', $set->capability( 'shop/remove' ) );
		$this->assertSame( 'shop_read', $set->capability( 'shop/stats' ) );
		$this->assertSame( 'shop_write_orders', $set->capability( 'shop/note' ) );

		$set = $this->registered(
			[
				'abilities'    => $abilities,
				'capabilities' => self::map(),
				'access'       => [ 'destroy' => 'delete' ],
			]
		);

		$this->assertSame( 'shop_delete_blocklist', $set->capability( 'shop/remove' ) );
		$this->assertSame( 'shop_read', $set->capability( 'shop/stats' ) );
		$this->assertSame( 'shop_write_orders', $set->capability( 'shop/note' ) );
	}

	public function test_the_map_can_be_a_callable(): void {
		$set = $this->registered( [ 'capabilities' => static fn( string $kind, string $area ): string => "cb_{$kind}_{$area}" ] );

		$this->assertSame( 'cb_refund_orders', $set->capability( 'shop/refund-order' ) );
		$this->assertSame( 'cb_read_orders', $set->capability( 'shop/list-orders' ) );

		// One with nothing to say falls back to the set's capability, and
		// that is what the filter is shown.
		$shown = [];

		add_filter(
			'shop_ability_capability',
			static function ( string $capability ) use ( &$shown ): string {
				$shown[] = $capability;

				return $capability;
			}
		);

		$set = $this->registered(
			[
				'capabilities' => static fn(): string => '',
				'capability'   => 'edit_shop',
			]
		);

		$this->assertSame( 'edit_shop', $set->capability( 'shop/resend' ) );
		$this->assertSame( [ 'edit_shop' ], $shown );
	}

	public function test_an_abilitys_own_capability(): void {
		$abilities                         = self::shop();
		$abilities['resend']['capability'] = 'send_receipts';

		$set = $this->registered(
			[
				'abilities'    => $abilities,
				'capabilities' => self::map(),
			]
		);

		$this->assertSame( 'send_receipts', $set->capability( 'shop/resend' ) );

		$this->sign_in( $this->user_with( 'send_receipts' ) );

		$this->assertTrue( $this->ability( 'shop/resend' )->check_permissions( [ 'order' => '7' ] ) );
		$this->assertSame( 'sent 7', $this->ability( 'shop/resend' )->execute( [ 'order' => '7' ] ) );
	}

	public function test_without_a_map(): void {
		$abilities                         = self::shop();
		$abilities['resend']['capability'] = 'send_receipts';

		$set = $this->registered( [ 'abilities' => $abilities ] );

		$this->assertSame( 'manage_options', $set->capability( 'shop/list-orders' ) );
		$this->assertSame( 'send_receipts', $set->capability( 'shop/resend' ) );
		$this->assertSame( 'manage_options', $set->capability( 'shop/nothing' ) );
		$this->assertTrue( $this->ability( 'shop/refund-order' )->check_permissions( [] ) );

		$this->sign_in( $this->user_with() );

		$this->assertFalse( $this->ability( 'shop/refund-order' )->check_permissions( [] ) );

		$set = $this->registered( [ 'capability' => 'edit_shop' ] );

		$this->assertSame( 'edit_shop', $set->capability( 'shop/list-orders' ) );

		$set = $this->registered( [ 'capability' => '' ] );

		$this->assertSame( 'manage_options', $set->capability( 'shop/list-orders' ) );
	}

	public function test_the_capability_filter(): void {
		$seen = [];

		add_filter(
			'shop_ability_capability',
			static function ( string $capability, string $name, array $definition ) use ( &$seen ): string {
				$seen[] = [ $capability, $name, $definition['kind'] ];

				return 'shop/refund-order' === $name ? 'refund_anything' : ( 'shop/resend' === $name ? '' : $capability );
			},
			10,
			3
		);

		$set = $this->registered( [ 'capabilities' => self::map() ] );

		$this->assertSame( 'refund_anything', $set->capability( 'shop/refund-order' ) );
		$this->assertSame( 'manage_options', $set->capability( 'shop/resend' ) );
		$this->assertSame( 'shop_read_orders', $set->capability( 'shop/list-orders' ) );
		$this->assertSame( [ 'shop_refund_orders', 'shop/refund-order', 'destroy' ], $seen[0] );

		// And it is what the ability is checked against.
		$this->sign_in( $this->user_with( 'refund_anything' ) );

		$this->assertTrue( $this->ability( 'shop/refund-order' )->check_permissions( [] ) );
		$this->assertFalse( $this->ability( 'shop/list-orders' )->check_permissions( [] ) );
	}

	public function test_an_abilitys_own_permission(): void {
		$own                               = static fn( $input ): bool|WP_Error => '7' === ( $input['order'] ?? null ) ? true : new WP_Error( 'nope', 'No.' );
		$abilities                         = self::shop();
		$abilities['resend']['permission'] = $own;
		$abilities['resend']['capability'] = 'ignored';

		$set = $this->registered(
			[
				'abilities'    => $abilities,
				'capabilities' => self::map(),
			]
		);

		$this->assertSame( $own, $this->handed['shop/resend']['permission_callback'] );
		$this->assertSame( '', $set->capability( 'shop/resend' ) );
		$this->assertTrue( $set->permitted( 'shop/resend', [ 'order' => '7' ] ) );

		// An error is a no, wherever the answer is a yes or a no.
		$this->assertFalse( $set->permitted( 'shop/resend', [ 'order' => '8' ] ) );

		// Somebody with no capability at all is let in by it, and only by it.
		$this->sign_in( $this->user_with() );

		$this->assertSame( 'sent 7', $this->ability( 'shop/resend' )->execute( [ 'order' => '7' ] ) );
		$this->assertSame( 'ability_invalid_permissions', $this->ability( 'shop/resend' )->execute( [ 'order' => '8' ] )->get_error_code() );
		$this->refused( 'No.' );
	}

	public function test_a_callable_list_is_made_late(): void {
		$made = 0;

		Abilities::register(
			'shop',
			[
				'category'  => [ 'description' => 'Orders and refunds.' ],
				'abilities' => static function () use ( &$made ): array {
					++$made;

					return self::shop();
				},
			]
		);

		$this->assertSame( 0, $made );

		$this->start();

		$this->assertSame( 1, $made );
		$this->assertCount( 3, wp_get_abilities( [ 'namespace' => 'shop' ] ) );

		// Checking permission does not make the list again.
		$this->ability( 'shop/resend' )->check_permissions( [ 'order' => '7' ] );

		$this->assertSame( 1, $made );
	}

	public function test_an_add_on_adds_abilities(): void {
		$seen = [];

		add_filter(
			'shop_abilities',
			static function ( array $abilities, string $namespace, string $category ) use ( &$seen ): array {
				$seen = [ $namespace, $category ];

				$abilities['list-payouts']               = [
					'description' => 'Lists payouts.',
					'kind'        => 'read',
					'execute'     => '__return_true',
				];
				$abilities['shop-affiliates/add-payout'] = [
					'description' => 'Adds a payout.',
					'kind'        => 'write',
					'execute'     => '__return_true',
				];
				$abilities['broken']                     = 'not a definition';

				return $abilities;
			},
			10,
			3
		);

		$set = $this->registered( [ 'namespace' => 'shopfront' ] );

		$this->assertSame( [ 'shopfront', 'shop' ], $seen );
		$this->assertTrue( wp_has_ability( 'shopfront/list-payouts' ) );
		$this->assertTrue( wp_has_ability( 'shopfront/resend' ) );
		$this->assertSame( 'shop', $this->ability( 'shop-affiliates/add-payout' )->get_category() );
		$this->assertFalse( wp_has_ability( 'shopfront/broken' ) );
		$this->assertArrayHasKey( 'shopfront/list-payouts', $set->definitions() );
		$this->assertArrayNotHasKey( 'shopfront/broken', $set->definitions() );

		// The add-on's ability gets the same permission model.
		$this->sign_in( $this->user_with() );

		$this->assertFalse( $this->ability( 'shopfront/list-payouts' )->check_permissions( [] ) );
	}

	public function test_only_what_lands_is_reported(): void {
		$set = Abilities::register(
			'shop',
			[
				'category'  => [ 'description' => 'Orders and refunds.' ],
				'abilities' => [
					'fine'        => [
						'description' => 'Fine.',
						'execute'     => '__return_true',
					],
					'Not/Allowed' => [
						'description' => 'A name WordPress refuses.',
						'execute'     => '__return_true',
					],
					'mute'        => [ 'execute' => '__return_true' ],
				],
			]
		);

		$landed = null;

		remove_action( 'wp_abilities_api_init', [ $set, 'abilities_api_init' ] );
		add_action(
			'wp_abilities_api_init',
			static function () use ( $set, &$landed ): void {
				$landed = $set->register_abilities();
			}
		);

		$this->start();

		$this->assertSame( [ 'shop/fine', 'shop/mute' ], $landed );
		$this->refused( 'Ability name must be a string containing a namespace prefix' );

		// WordPress refuses an ability without a description, so one left
		// out is its label, or failing that its name.
		$this->assertSame( 'shop/mute', $set->args( 'shop/mute' )['description'] );
		$this->assertSame( 'Fine.', $set->args( 'shop/fine' )['description'] );

		$this->assertNull( $set->args( 'shop/missing' ) );
		$this->assertSame( 'shop/fine', $set->args( 'shop/fine' )['label'] );
	}

	public function test_abilities_are_only_taken_while_wordpress_asks(): void {
		$set = $this->registered();

		// Outside wp_abilities_api_init, WordPress takes nothing.
		$this->assertSame( [], $set->register_abilities() );
		$this->refused( 'Abilities must be registered on the' );
		$this->assertCount( 3, wp_get_abilities() );
	}

	public function test_an_ability_class(): void {
		// A WordPress ability of the plugin's own.
		$class = get_class(
			new class( 'shop/example', [ 'label' => 'x', 'description' => 'x', 'category' => 'x' ] ) extends WP_Ability {}
		);

		$abilities                            = self::shop();
		$abilities['resend']['ability_class'] = $class;

		$this->registered( [ 'abilities' => $abilities ] );

		$this->assertInstanceOf( $class, $this->ability( 'shop/resend' ) );
		$this->assertNotInstanceOf( $class, $this->ability( 'shop/list-orders' ) );
		$this->assertArrayNotHasKey( 'ability_class', $this->handed['shop/list-orders'] );

		// Anything else is refused.
		$abilities['resend']['ability_class'] = 'My_Ability';

		$this->registered( [ 'abilities' => $abilities ] );

		$this->refused( 'valid `ability_class`' );
		$this->assertFalse( wp_has_ability( 'shop/resend' ) );
		$this->assertSame( 'My_Ability', $this->handed['shop/resend']['ability_class'] );
	}

	public function test_registering_again_and_unregistering(): void {
		$first  = Abilities::register( 'shop', [ 'abilities' => self::shop() ] );
		$second = Abilities::register( 'shop', [ 'abilities' => self::shop() ] );

		$this->assertSame( $second, Abilities::get( 'shop' ) );
		$this->assertFalse( has_action( 'wp_abilities_api_init', [ $first, 'abilities_api_init' ] ) );
		$this->assertFalse( has_action( 'wp_abilities_api_categories_init', [ $first, 'register_category' ] ) );
		$this->assertSame( 10, has_action( 'wp_abilities_api_init', [ $second, 'abilities_api_init' ] ) );
		$this->assertSame( 10, has_action( 'wp_abilities_api_categories_init', [ $second, 'register_category' ] ) );

		// No hook names a method after a global function: a build that
		// prefixes functions renames those names in strings as well.
		foreach ( [ 'wp_abilities_api_init', 'wp_abilities_api_categories_init' ] as $hook ) {
			foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $hooked ) {
					if ( is_array( $hooked['function'] ) && $hooked['function'][0] instanceof Abilities ) {
						$this->assertFalse( function_exists( $hooked['function'][1] ), $hooked['function'][1] );
					}
				}
			}
		}

		$this->assertTrue( Abilities::unregister( 'shop' ) );
		$this->assertFalse( Abilities::unregister( 'shop' ) );
		$this->assertNull( Abilities::get( 'shop' ) );

		$this->start();

		$this->assertSame( [], wp_get_abilities() );
		$this->assertSame( [], wp_get_ability_categories() );
	}

	public function test_running_an_ability_over_rest(): void {
		$this->registered();

		// Signed out: told to sign in.
		wp_set_current_user( 0 );

		$response = $this->over_rest( 'GET', 'shop/list-orders' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'rest_ability_cannot_execute', $response->get_data()['code'] );

		// Somebody without the capability: refused.
		$this->sign_in( $this->user_with( 'shop_view' ) );

		$this->assertSame( 403, $this->over_rest( 'GET', 'shop/list-orders' )->get_status() );
		$this->assertSame( 403, $this->over_rest( 'POST', 'shop/resend', [ 'order' => '7' ] )->get_status() );

		// An administrator: a read with no input at all, a write, a destroy.
		$this->sign_in();

		$response = $this->over_rest( 'GET', 'shop/list-orders' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'orders' => [] ], $response->get_data() );
		$this->assertSame( 'sent 7', $this->over_rest( 'POST', 'shop/resend', [ 'order' => '7' ] )->get_data() );
		$this->assertSame( [ 'refunded' => 1 ], $this->over_rest( 'POST', 'shop/refund-order' )->get_data() );

		// The kind decides the method.
		$this->assertSame( 405, $this->over_rest( 'POST', 'shop/list-orders' )->get_status() );
		$this->assertSame( 405, $this->over_rest( 'GET', 'shop/resend', [ 'order' => '7' ] )->get_status() );

		// The schema decides the input.
		$this->assertSame( 400, $this->over_rest( 'POST', 'shop/resend' )->get_status() );
		$this->assertSame( 400, $this->over_rest( 'POST', 'shop/resend', [ 'order' => '7', 'note' => 'hi' ] )->get_status() );
		$this->assertSame( 200, $this->over_rest( 'GET', 'shop/list-orders', [ 'limit' => 5 ] )->get_status() );
	}

	public function test_an_idempotent_destroy_is_run_with_delete(): void {
		$abilities                               = self::shop();
		$abilities['refund-order']['idempotent'] = true;

		$this->registered( [ 'abilities' => $abilities ] );

		$this->assertSame( 405, $this->over_rest( 'POST', 'shop/refund-order' )->get_status() );
		$this->assertSame( [ 'refunded' => 1 ], $this->over_rest( 'DELETE', 'shop/refund-order' )->get_data() );
	}

	public function test_a_capabilities_set_is_the_map(): void {
		// The two halves of the package together: the admin's capabilities
		// decide who may run an ability, and nobody has to be given them.
		$capabilities = Capabilities::register(
			'shop',
			[
				'kinds' => [
					'read'   => [ 'default' => 'shop_view' ],
					'write'  => [
						'default' => 'shop_manage_settings',
						'areas'   => [ 'orders' => 'shop_manage_orders' ],
					],
					'refund' => [ 'default' => 'shop_refund_orders' ],
				],
			]
		);

		$set = $this->registered( [ 'capabilities' => $capabilities ] );

		$this->assertSame( 'shop_view', $set->capability( 'shop/list-orders' ) );
		$this->assertSame( 'shop_manage_orders', $set->capability( 'shop/resend' ) );
		$this->assertSame( 'shop_refund_orders', $set->capability( 'shop/refund-order' ) );

		// A read-only role, made with a role editor.
		add_role(
			'shop_viewer',
			'Shop viewer',
			[
				'read'      => true,
				'shop_view' => true,
			]
		);

		$this->sign_in( self::factory()->user->create( [ 'role' => 'shop_viewer' ] ) );

		$this->assertSame( 200, $this->over_rest( 'GET', 'shop/list-orders' )->get_status() );
		$this->assertSame( 403, $this->over_rest( 'POST', 'shop/resend', [ 'order' => '7' ] )->get_status() );
		$this->assertSame( 403, $this->over_rest( 'POST', 'shop/refund-order' )->get_status() );

		// An administrator has every one, through the parent.
		$this->sign_in();

		$this->assertSame( 200, $this->over_rest( 'GET', 'shop/list-orders' )->get_status() );
		$this->assertSame( 'sent 7', $this->over_rest( 'POST', 'shop/resend', [ 'order' => '7' ] )->get_data() );
		$this->assertSame( [ 'refunded' => 1 ], $this->ability( 'shop/refund-order' )->execute() );

		// Signed out: nobody has anything.
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->over_rest( 'GET', 'shop/list-orders' )->get_status() );
	}

	public function test_a_capabilities_set_may_be_named_by_its_id_and_registered_later(): void {
		$set = $this->registered( [ 'capabilities' => 'shop' ] );

		// No such set yet: the set's own default.
		$this->assertSame( 'manage_options', $set->capability( 'shop/list-orders' ) );

		Capabilities::register( 'shop', [ 'kinds' => [ 'read' => 'shop_view' ] ] );

		$this->assertSame( 'shop_view', $set->capability( 'shop/list-orders' ) );
	}

	public function test_a_capabilities_sets_filter_has_its_say_over_abilities_too(): void {
		Capabilities::register(
			'shop',
			[
				'kinds' => [
					'read'   => 'shop_view',
					'write'  => 'shop_manage',
					'refund' => 'shop_refund',
				],
			]
		);

		$asked = [];

		add_filter(
			'shop_can_read',
			static function ( bool $allowed, string $area, $context, int $user_id ) use ( &$asked ): bool {
				$asked[] = [ $allowed, $area, $context, $user_id ];

				return false;
			},
			10,
			4
		);

		$set   = $this->registered( [ 'capabilities' => 'shop' ] );
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->sign_in( $admin );

		$this->assertFalse( $set->permitted( 'shop/list-orders', [ 'limit' => 2 ] ), 'A filter that refuses reads refuses the read ability.' );
		$this->assertSame( [ [ true, 'orders', [ 'limit' => 2 ], $admin ] ], $asked );
		$this->assertTrue( $set->permitted( 'shop/resend', [ 'order' => '1' ] ), 'Only its own kind.' );
		$this->assertSame( 403, $this->over_rest( 'GET', 'shop/list-orders' )->get_status() );

		// An ability that names its own capability is not the set's to filter.
		$own = Abilities::register(
			'own',
			[
				'capabilities' => 'shop',
				'category'     => 'shop',
				'abilities'    => [
					'look' => [
						'kind'       => 'read',
						'capability' => 'manage_options',
						'execute'    => '__return_true',
					],
				],
			]
		);

		$this->assertTrue( $own->permitted( 'own/look' ) );
	}

	public function test_a_set_needs_an_id(): void {
		$this->expectException( InvalidArgumentException::class );

		Abilities::register( '' );
	}
}
