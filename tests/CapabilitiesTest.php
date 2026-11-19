<?php
/**
 * A plugin's own capabilities: who has them, and which one a thing takes.
 *
 * The grant runs on every capability check on the site, so the tests that
 * matter most are the ones that say it changes nothing it was not asked
 * about. The map is the other half: an area nobody has heard of must land
 * on the narrowest capability, never on the widest.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests;

use InvalidArgumentException;
use ReflectionProperty;
use SugarCommerce\Rest\Capabilities;
use SugarCommerce\Rest\Tests\Support\TestCase;

final class CapabilitiesTest extends TestCase {

	/**
	 * A shop's set, shaped like a real one.
	 *
	 * @var array<string, mixed>
	 */
	private const SHOP = [
		'capabilities' => [
			'shop_view'         => 'Read orders, customers and products',
			'shop_view_reports' => 'Read the reports',
		],
		'kinds'        => [
			'read'   => [
				'default' => 'shop_view',
				'areas'   => [
					'reports' => 'shop_view_reports',
					'exports' => 'shop_manage_settings',
				],
			],
			'write'  => [
				'default' => 'shop_manage_settings',
				'areas'   => [
					'orders'    => 'shop_manage_orders',
					'customers' => 'shop_manage_customers',
					'devices'   => 'shop_view',
				],
			],
			'refund' => [ 'default' => 'shop_refund_orders' ],
		],
	];

	/**
	 * Somebody with no part in the shop.
	 *
	 * @var int
	 */
	private int $subscriber;

	public function set_up(): void {
		parent::set_up();

		$this->subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->sign_in();
	}

	public function tear_down(): void {
		foreach ( array_keys( ( new ReflectionProperty( Capabilities::class, 'sets' ) )->getValue() ) as $id ) {
			Capabilities::unregister( (string) $id );
		}

		remove_role( 'shop_viewer' );

		parent::tear_down();
	}

	/**
	 * Register the shop's set.
	 *
	 * @param array<string, mixed> $config Changes to it.
	 *
	 * @return Capabilities
	 */
	private function shop( array $config = [] ): Capabilities {
		return Capabilities::register( 'shop', $config + self::SHOP );
	}

	/**
	 * A user whose role a role editor gave the read capabilities, and nothing else.
	 *
	 * @return int
	 */
	private function read_only_user(): int {
		add_role(
			'shop_viewer',
			'Shop viewer',
			[
				'read'              => true,
				'shop_view'         => true,
				'shop_view_reports' => true,
			]
		);

		return self::factory()->user->create( [ 'role' => 'shop_viewer' ] );
	}

	public function test_an_administrator_has_every_capability(): void {
		$set = $this->shop();

		foreach ( $set->all() as $capability ) {
			$this->assertTrue( current_user_can( $capability ), $capability );
		}

		// Worked out as it is asked, not written into the role or the user.
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'shop_view' ) );
		$this->assertArrayNotHasKey( 'shop_view', wp_get_current_user()->allcaps );
		$this->assertArrayNotHasKey( 'shop_view', get_userdata( $this->admin )->caps );
	}

	public function test_somebody_else_has_none(): void {
		$set = $this->shop();

		$this->sign_in( $this->subscriber );

		foreach ( $set->all() as $capability ) {
			$this->assertFalse( current_user_can( $capability ), $capability );
		}
	}

	public function test_the_grant_leaves_everything_else_alone(): void {
		$set   = $this->shop();
		$admin = [
			'manage_options' => true,
			'read'           => true,
		];

		// Asked about somebody else's capability: nothing added.
		$this->assertSame( $admin, $set->grant( $admin, [ 'edit_posts' ] ) );

		// Asked about one of ours: that one, and not the rest.
		$this->assertSame( $admin + [ 'shop_view' => true ], $set->grant( $admin, [ 'shop_view' ] ) );

		// Asked by somebody without the parent: nothing.
		$this->assertSame( [ 'read' => true ], $set->grant( [ 'read' => true ], [ 'shop_view' ] ) );

		// Whatever another filter has made of the list, it goes back as it came.
		$this->assertNull( $set->grant( null, [ 'shop_view' ] ) );
		$this->assertSame( [ 'manage_options' => true ], $set->grant( [ 'manage_options' => true ], [ 5, null ] ) );

		// And through WordPress: nothing that is not ours, for anybody.
		$this->assertFalse( current_user_can( 'shop_somebody_elses' ) );

		$this->sign_in( $this->subscriber );

		$this->assertFalse( current_user_can( 'edit_posts' ) );
		$this->assertTrue( current_user_can( 'read' ) );
	}

	public function test_the_parent_is_configurable(): void {
		$this->shop( [ 'parent' => 'edit_shop' ] );

		// An administrator without it has none of them.
		$this->assertFalse( current_user_can( 'shop_view' ) );

		get_userdata( $this->subscriber )->add_cap( 'edit_shop' );
		$this->sign_in( $this->subscriber );

		$this->assertTrue( current_user_can( 'shop_refund_orders' ) );

		$set = $this->shop( [ 'parent' => false ] );

		$this->assertSame( '', $set->parent() );
		$this->assertFalse( current_user_can( 'shop_view' ) );

		$this->sign_in();

		$this->assertFalse( current_user_can( 'shop_view' ) );
	}

	public function test_the_map(): void {
		$set = $this->shop();

		$this->assertSame( 'shop_view', $set->capability( 'read', 'orders' ) );
		$this->assertSame( 'shop_view_reports', $set->capability( 'read', 'reports' ) );
		$this->assertSame( 'shop_manage_settings', $set->capability( 'read', 'exports' ) );
		$this->assertSame( 'shop_manage_orders', $set->capability( 'write', 'orders' ) );
		$this->assertSame( 'shop_view', $set->capability( 'write', 'devices' ) );
		$this->assertSame( 'shop_refund_orders', $set->capability( 'refund', 'orders' ) );
		$this->assertSame( 'shop_refund_orders', $set->capability( 'refund', 'subscriptions' ) );
	}

	public function test_unknowns_are_narrow(): void {
		$set = $this->shop();

		$this->assertSame( 'shop_view', $set->capability( 'read', 'affiliates' ) );
		$this->assertSame( 'shop_manage_settings', $set->capability( 'write', 'affiliates' ) );
		$this->assertSame( 'shop_manage_settings', $set->capability( 'delete', 'affiliates' ) );
		$this->assertSame( 'shop_manage_orders', $set->capability( 'delete', 'orders' ) );
		$this->assertSame( 'write', $set->kind( 'delete' ) );
		$this->assertSame( 'refund', $set->kind( 'refund' ) );
	}

	public function test_the_fallback_kind(): void {
		$set = $this->shop( [ 'fallback' => 'refund' ] );

		$this->assertSame( 'shop_refund_orders', $set->capability( 'delete', 'orders' ) );

		$set = $this->shop( [ 'fallback' => 'missing' ] );

		$this->assertSame( '', $set->kind( 'delete' ) );
		$this->assertSame( 'manage_options', $set->capability( 'delete', 'orders' ) );
	}

	public function test_no_map_means_the_parent(): void {
		$set = Capabilities::register( 'bare', [ 'parent' => 'edit_shop' ] );

		$this->assertSame( 'edit_shop', $set->capability( 'read', 'orders' ) );
		$this->assertSame( [], $set->all() );

		$set = Capabilities::register( 'bare', [ 'parent' => false ] );

		$this->assertSame( 'manage_options', $set->capability( 'write' ) );

		$set = Capabilities::register(
			'bare',
			[
				'kinds' => [
					'read'  => 'bare_view',
					'write' => [ 'areas' => [ 'orders' => 'bare_orders' ] ],
				],
			]
		);

		$this->assertSame( 'bare_view', $set->capability( 'read', 'anything' ) );
		$this->assertSame( 'bare_orders', $set->capability( 'write', 'orders' ) );
		$this->assertSame( 'manage_options', $set->capability( 'write', 'customers' ) );
		$this->assertSame( [ 'bare_view', 'bare_orders' ], $set->all() );

		// Never '', which WordPress answers no to for everybody: an
		// administrator can still do what has no capability of its own.
		$this->assertTrue( $set->can( 'write', 'customers' ) );
	}

	public function test_the_list(): void {
		$set = $this->shop();

		$this->assertSame(
			[
				'shop_view',
				'shop_view_reports',
				'shop_manage_settings',
				'shop_manage_orders',
				'shop_manage_customers',
				'shop_refund_orders',
			],
			$set->all()
		);

		$this->assertSame( 'Read the reports', $set->labels()['shop_view_reports'] );
		$this->assertSame( '', $set->labels()['shop_refund_orders'] );
		$this->assertTrue( $set->has( 'shop_refund_orders' ) );
		$this->assertFalse( $set->has( 'manage_options' ) );
		$this->assertSame( [ 'read', 'write', 'refund' ], $set->kinds() );

		$set = Capabilities::register( 'list', [ 'capabilities' => [ 'list_a', 'manage_options' ] ] );

		$this->assertSame( [ 'list_a' ], $set->all() );
	}

	public function test_a_read_only_user(): void {
		$set    = $this->shop();
		$viewer = $this->read_only_user();

		$this->sign_in( $viewer );

		$this->assertTrue( $set->can( 'read', 'orders' ) );
		$this->assertTrue( $set->can( 'read', 'reports' ) );
		$this->assertTrue( $set->can( 'write', 'devices' ) );
		$this->assertFalse( $set->can( 'read', 'exports' ) );
		$this->assertFalse( $set->can( 'write', 'orders' ) );
		$this->assertFalse( $set->can( 'refund', 'orders' ) );
		$this->assertSame( [ 'shop_view', 'shop_view_reports' ], $set->granted() );

		$this->sign_in();

		$this->assertTrue( $set->can( 'refund', 'orders' ) );
		$this->assertSame( $set->all(), $set->granted() );

		// Asked about somebody else, it is that somebody who is asked.
		$this->assertTrue( $set->user_can( $this->admin, 'write', 'orders' ) );
		$this->assertFalse( $set->user_can( get_userdata( $viewer ), 'write', 'orders' ) );
		$this->assertTrue( $set->user_can( get_userdata( $viewer ), 'read', 'orders' ) );
		$this->assertFalse( $set->user_can( $viewer, 'refund', 'orders' ) );
		$this->assertFalse( $set->user_can( $this->subscriber, 'read', 'orders' ) );

		$this->sign_in( $viewer );

		$this->assertTrue( $set->user_can( $this->admin, 'refund', 'orders' ) );
	}

	public function test_the_filter(): void {
		$set  = $this->shop();
		$seen = [];

		add_filter(
			'shop_can_read',
			static function ( bool $allowed, string $area, $context, int $user_id ) use ( &$seen ): bool {
				$seen[] = [ $allowed, $area, $context, $user_id ];

				return 'orders' === $area ? false : $allowed;
			},
			10,
			4
		);

		$this->assertFalse( $set->can( 'read', 'orders', 'request' ) );
		$this->assertTrue( $set->can( 'read', 'customers' ) );
		$this->assertFalse( $set->user_can( $this->admin, 'read', 'orders' ) );
		$this->assertFalse( $set->user_can( get_userdata( $this->subscriber ), 'read', 'customers', 'ability' ) );
		$this->assertSame( [ true, 'orders', 'request', $this->admin ], $seen[0] );
		$this->assertSame( [ true, 'customers', null, $this->admin ], $seen[1] );
		$this->assertSame( [ false, 'customers', 'ability', $this->subscriber ], $seen[3] );
		$this->assertCount( 4, $seen );

		// A write is not a read.
		$this->assertTrue( $set->can( 'write', 'orders' ) );
	}

	public function test_an_unknown_kind_is_filtered_as_its_fallback(): void {
		$set = $this->shop();

		add_filter( 'shop_can_write', '__return_false' );

		$this->assertFalse( $set->can( 'delete', 'orders' ) );

		$set = $this->shop( [ 'fallback' => 'nothing' ] );

		add_filter( 'shop_can_unknown', '__return_false' );

		$this->assertFalse( $set->can( 'delete', 'orders' ) );
	}

	public function test_the_filter_name(): void {
		$set = $this->shop( [ 'filter' => '{id}_rest_can_{kind}' ] );

		add_filter( 'shop_can_refund', '__return_false' );
		add_filter( 'shop_rest_can_read', '__return_false' );

		$this->assertTrue( $set->can( 'refund', 'orders' ) );
		$this->assertFalse( $set->can( 'read', 'orders' ) );
		$this->assertFalse( $set->user_can( $this->admin, 'read', 'orders' ) );

		$set = $this->shop( [ 'filter' => false ] );

		// Not even a filter with no name.
		add_filter( '', '__return_false' );

		$this->assertTrue( $set->can( 'read', 'orders' ) );
	}

	public function test_registering_again_and_unregistering(): void {
		$first  = $this->shop();
		$second = $this->shop();

		$this->assertSame( $second, Capabilities::get( 'shop' ) );
		$this->assertFalse( has_filter( 'user_has_cap', [ $first, 'grant' ] ) );
		$this->assertSame( 10, has_filter( 'user_has_cap', [ $second, 'grant' ] ) );

		$grants = array_filter(
			$GLOBALS['wp_filter']['user_has_cap']->callbacks[10] ?? [],
			static fn( array $hooked ): bool => is_array( $hooked['function'] ) && $hooked['function'][0] instanceof Capabilities
		);

		$this->assertCount( 1, $grants );

		$this->assertTrue( Capabilities::unregister( 'shop' ) );
		$this->assertFalse( Capabilities::unregister( 'shop' ) );
		$this->assertNull( Capabilities::get( 'shop' ) );
		$this->assertFalse( current_user_can( 'shop_view' ) );
	}

	public function test_two_plugins_side_by_side(): void {
		$shop    = $this->shop();
		$members = Capabilities::register( 'members', [ 'kinds' => [ 'read' => 'members_view' ] ] );

		$this->assertSame( 'shop', $shop->id() );
		$this->assertSame( 'manage_options', $shop->parent() );
		$this->assertSame( [ 'members_view' ], $members->all() );
		$this->assertTrue( current_user_can( 'members_view' ) );
		$this->assertTrue( current_user_can( 'shop_view' ) );

		$admin = [ 'manage_options' => true ];

		$this->assertSame( $admin, $members->grant( $admin, [ 'shop_view' ] ) );

		Capabilities::unregister( 'shop' );

		$this->assertFalse( current_user_can( 'shop_view' ) );
		$this->assertTrue( current_user_can( 'members_view' ) );
	}

	public function test_a_set_needs_an_id(): void {
		$this->expectException( InvalidArgumentException::class );

		Capabilities::register( '' );
	}

	public function test_an_addon_adds_a_capability_with_its_areas(): void {
		$set = $this->shop();

		// Before: an add-on's area is written with the narrowest there is.
		$this->assertSame( 'shop_manage_settings', $set->capability( 'write', 'affiliates' ) );

		$this->assertSame(
			$set,
			$set->add(
				'shop_manage_affiliates',
				'Run the affiliate programme',
				[
					'read'  => [ 'affiliates' ],
					'write' => [ 'affiliates', 'payouts' ],
				]
			)
		);

		$this->assertSame( 'shop_manage_affiliates', $set->capability( 'write', 'affiliates' ) );
		$this->assertSame( 'shop_manage_affiliates', $set->capability( 'write', 'payouts' ) );
		$this->assertSame( 'shop_manage_affiliates', $set->capability( 'read', 'affiliates' ) );
		$this->assertTrue( $set->has( 'shop_manage_affiliates' ) );
		$this->assertSame( 'Run the affiliate programme', $set->labels()['shop_manage_affiliates'] );
		$this->assertContains( 'shop_manage_affiliates', $set->all() );

		// Granted to an administrator as it is asked, and to nobody else.
		$this->assertTrue( current_user_can( 'shop_manage_affiliates' ) );
		$this->assertTrue( $set->can( 'write', 'affiliates' ) );

		$this->sign_in( $this->subscriber );

		$this->assertFalse( current_user_can( 'shop_manage_affiliates' ) );
		$this->assertFalse( $set->can( 'write', 'affiliates' ) );

		// A role given only the add-on's capability runs the add-on's area
		// and nothing of the shop's.
		add_role(
			'shop_viewer',
			'Affiliate manager',
			[
				'read'                   => true,
				'shop_manage_affiliates' => true,
			]
		);

		$this->sign_in( self::factory()->user->create( [ 'role' => 'shop_viewer' ] ) );

		$this->assertTrue( $set->can( 'write', 'affiliates' ) );
		$this->assertFalse( $set->can( 'write', 'orders' ) );
		$this->assertFalse( $set->can( 'write', 'settings' ) );
	}

	public function test_an_added_capability_never_takes_an_area_already_mapped(): void {
		$set = $this->shop();

		$set->add( 'shop_loose', '', [ 'write' => [ 'orders' ] ] );

		$this->assertSame( 'shop_manage_orders', $set->capability( 'write', 'orders' ) );
	}

	public function test_adding_again_keeps_the_label_and_adds_areas(): void {
		$set = $this->shop();

		$set->add( 'shop_manage_affiliates', 'Affiliates', [ 'write' => [ 'affiliates' ] ] );
		$set->add( 'shop_manage_affiliates', '', [ 'write' => [ 'payouts' ] ] );

		$this->assertSame( 'Affiliates', $set->labels()['shop_manage_affiliates'] );
		$this->assertSame( 'shop_manage_affiliates', $set->capability( 'write', 'payouts' ) );
	}

	public function test_a_new_kind_has_no_default_of_its_own(): void {
		$set = $this->shop();

		$set->add( 'shop_approve_payouts', '', [ 'approve' => [ 'payouts' ] ] );

		$this->assertSame( 'shop_approve_payouts', $set->capability( 'approve', 'payouts' ) );
		$this->assertSame( 'manage_options', $set->capability( 'approve', 'reviews' ) );
	}

	public function test_an_added_capability_needs_a_name_of_its_own(): void {
		$set = $this->shop();

		$this->expectException( InvalidArgumentException::class );

		$set->add( 'manage_options' );
	}
}
