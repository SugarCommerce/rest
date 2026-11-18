<?php
/**
 * A plugin's capabilities.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest;

use InvalidArgumentException;

/**
 * A plugin's own capabilities, and which one each thing it does takes.
 *
 * WordPress's admin screens ask for `manage_options` and nothing finer,
 * which is right for a screen and wrong as soon as somebody else is let in:
 * a shop that hands a member of staff an app wants them to see today's
 * orders without being able to refund one or read the settings. So a plugin
 * asks finer questions -- `myplugin_view_shop`, `myplugin_refund_orders` --
 * and this keeps the list of them.
 *
 * **Nobody has to be given these.** Anyone who has the parent capability
 * (`manage_options` unless told otherwise) has every one of them, worked
 * out as the question is asked (grant() below) and not written into a role:
 * there is nothing to install, nothing to repair after a role editor has
 * been at the administrator role, nothing left behind on uninstall, and what
 * an administrator can do is exactly what it was. A site that wants a
 * read-only user gives a role the read capabilities with any role editor,
 * or with WP_Role::add_cap().
 *
 * Which capability something takes is a map of kind × area: a kind of thing
 * to do (`read`, `write`, `refund`…) and what it is about (`orders`,
 * `customers`, `reports`…). Each kind has a default and, optionally, areas
 * that take something else:
 *
 *     'read'  => [ 'default' => 'myplugin_view_shop', 'areas' => [ 'reports' => 'myplugin_view_reports' ] ],
 *     'write' => [ 'default' => 'myplugin_manage_settings', 'areas' => [ 'orders' => 'myplugin_manage_orders' ] ],
 *
 * An area nobody has heard of -- an add-on's -- takes the kind's default,
 * so make the default the narrowest audience there is for anything that
 * changes something. A kind nobody has heard of is treated as the fallback
 * kind, `write` unless told otherwise.
 *
 * One set per plugin, by id. The id names the filter can() runs, and is how
 * get() finds the set again.
 *
 * @since 1.0.0
 */
final class Capabilities {

	/**
	 * Every registered set, by id.
	 *
	 * @var array<string, self>
	 */
	private static array $sets = [];

	/**
	 * The set's id: usually the plugin's prefix.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * The capability whose holders have every one of these, or '' for none.
	 *
	 * @var string
	 */
	private string $parent;

	/**
	 * Every capability, and what each is for.
	 *
	 * @var array<string, string>
	 */
	private array $capabilities;

	/**
	 * The map: kind => [ default, areas ].
	 *
	 * @var array<string, array{default: string, areas: array<string, string>}>
	 */
	private array $kinds;

	/**
	 * The kind an unknown kind is treated as.
	 *
	 * @var string
	 */
	private string $fallback;

	/**
	 * The filter can() runs, with `{kind}` in it, or '' for none.
	 *
	 * @var string
	 */
	private string $filter;

	/**
	 * Register a plugin's capabilities.
	 *
	 * Registering again under the same id replaces the set, and its grant.
	 *
	 * @param string               $id     The set's id: usually the plugin's prefix.
	 * @param array<string, mixed> $config {
	 *     The set.
	 *
	 *     @type array<int|string, string> $capabilities Every capability, as a list or as capability => what it is
	 *                                                   for. Any the kinds name are added, so this is only needed
	 *                                                   to fix the order or describe them.
	 *     @type string|false              $parent       Whose holders have every one of them. `manage_options`
	 *                                                   by default; false to grant them to nobody automatically.
	 *     @type array<string, array>      $kinds        kind => [ 'default' => capability, 'areas' => [ area => capability ] ].
	 *     @type string                    $fallback     The kind an unknown kind is treated as. `write` by default.
	 *     @type string|false              $filter       The filter can() runs, with `{kind}` where the kind goes.
	 *                                                   `{id}_can_{kind}` by default; false for none.
	 * }
	 *
	 * @return self
	 * @throws InvalidArgumentException When the id is empty.
	 * @since 1.0.0
	 */
	public static function register( string $id, array $config = [] ): self {
		if ( '' === $id ) {
			throw new InvalidArgumentException( 'A set of capabilities needs an id.' );
		}

		self::unregister( $id );

		$set = new self( $id, $config );

		self::$sets[ $id ] = $set;

		add_filter( 'user_has_cap', [ $set, 'grant' ], 10, 2 );

		return $set;
	}

	/**
	 * A registered set.
	 *
	 * @param string $id The set's id.
	 *
	 * @return self|null
	 * @since 1.0.0
	 */
	public static function get( string $id ): ?self {
		return self::$sets[ $id ] ?? null;
	}

	/**
	 * Forget a set, and stop granting its capabilities.
	 *
	 * @param string $id The set's id.
	 *
	 * @return bool Whether there was one.
	 * @since 1.0.0
	 */
	public static function unregister( string $id ): bool {
		if ( ! isset( self::$sets[ $id ] ) ) {
			return false;
		}

		remove_filter( 'user_has_cap', [ self::$sets[ $id ], 'grant' ], 10 );
		unset( self::$sets[ $id ] );

		return true;
	}

	/**
	 * Build the set.
	 *
	 * @param string               $id     The set's id.
	 * @param array<string, mixed> $config See register().
	 */
	private function __construct( string $id, array $config ) {
		$this->id     = $id;
		$this->parent = false === ( $config['parent'] ?? null ) ? '' : (string) ( $config['parent'] ?? 'manage_options' );
		$this->kinds  = [];

		foreach ( (array) ( $config['kinds'] ?? [] ) as $kind => $map ) {
			$map   = is_string( $map ) ? [ 'default' => $map ] : (array) $map;
			$areas = [];

			foreach ( (array) ( $map['areas'] ?? [] ) as $area => $capability ) {
				$areas[ (string) $area ] = (string) $capability;
			}

			$this->kinds[ (string) $kind ] = [
				'default' => (string) ( $map['default'] ?? '' ),
				'areas'   => $areas,
			];
		}

		$fallback       = (string) ( $config['fallback'] ?? 'write' );
		$this->fallback = isset( $this->kinds[ $fallback ] ) ? $fallback : '';

		$filter       = $config['filter'] ?? '{id}_can_{kind}';
		$this->filter = false === $filter ? '' : str_replace( '{id}', $id, (string) $filter );

		$this->capabilities = [];

		foreach ( (array) ( $config['capabilities'] ?? [] ) as $key => $value ) {
			if ( is_int( $key ) ) {
				$this->capabilities[ (string) $value ] = '';
			} else {
				$this->capabilities[ $key ] = (string) $value;
			}
		}

		// Whatever the map names is one of ours, listed or not.
		foreach ( $this->kinds as $map ) {
			foreach ( array_merge( [ $map['default'] ], array_values( $map['areas'] ) ) as $capability ) {
				if ( '' !== $capability && ! isset( $this->capabilities[ $capability ] ) ) {
					$this->capabilities[ $capability ] = '';
				}
			}
		}

		// The parent is WordPress's, not ours: granting it to whoever has
		// it is a no-op, and listing it would offer it to a role editor as
		// though this plugin owned it.
		unset( $this->capabilities[ $this->parent ] );
	}

	/**
	 * The set's id.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The capability whose holders have every one of these.
	 *
	 * @return string Empty when they are granted to nobody automatically.
	 * @since 1.0.0
	 */
	public function parent(): string {
		return $this->parent;
	}

	/**
	 * Every capability, in order.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	public function all(): array {
		return array_keys( $this->capabilities );
	}

	/**
	 * Every capability, and what each is for.
	 *
	 * @return array<string, string>
	 * @since 1.0.0
	 */
	public function labels(): array {
		return $this->capabilities;
	}

	/**
	 * Whether a capability is one of these.
	 *
	 * @param string $capability A capability.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function has( string $capability ): bool {
		return isset( $this->capabilities[ $capability ] );
	}

	/**
	 * The kinds there are.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	public function kinds(): array {
		return array_keys( $this->kinds );
	}

	/**
	 * The kind a kind is treated as: itself if it is known, the fallback if not.
	 *
	 * @param string $kind A kind.
	 *
	 * @return string Empty when it is unknown and there is no fallback.
	 * @since 1.0.0
	 */
	public function kind( string $kind ): string {
		return isset( $this->kinds[ $kind ] ) ? $kind : $this->fallback;
	}

	/**
	 * Give anyone who has the parent capability every one of these.
	 *
	 * Hooked to `user_has_cap`, which runs on every capability check on the
	 * site, so it does nothing unless one of ours is the question.
	 *
	 * @param mixed $allcaps What the user can do.
	 * @param mixed $caps    What is being asked for, as primitive capabilities.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	public function grant( $allcaps, $caps ) {
		// With no parent this asks after a capability called '', which
		// nobody has.
		if ( ! is_array( $allcaps ) || empty( $allcaps[ $this->parent ] ) ) {
			return $allcaps;
		}

		foreach ( (array) $caps as $cap ) {
			if ( is_string( $cap ) && isset( $this->capabilities[ $cap ] ) ) {
				$allcaps[ $cap ] = true;
			}
		}

		return $allcaps;
	}

	/**
	 * The capability something takes.
	 *
	 * @param string $kind What is being done: `read`, `write`…
	 * @param string $area What it is about.
	 *
	 * @return string The parent capability when the map has no answer.
	 * @since 1.0.0
	 */
	public function capability( string $kind, string $area = '' ): string {
		$kind = $this->kind( $kind );

		if ( '' === $kind ) {
			return $this->narrowest();
		}

		$map        = $this->kinds[ $kind ];
		$capability = $map['areas'][ $area ] ?? $map['default'];

		return '' !== $capability ? $capability : $this->narrowest();
	}

	/**
	 * Whether the current user may.
	 *
	 * @param string $kind    What is being done: `read`, `write`…
	 * @param string $area    What it is about.
	 * @param mixed  $context What is asking, passed to the filter: a REST request, an ability's input…
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function can( string $kind, string $area = '', mixed $context = null ): bool {
		return $this->filter( current_user_can( $this->capability( $kind, $area ) ), $kind, $area, $context );
	}

	/**
	 * Whether a given user may.
	 *
	 * @param int|object $user    A user id or WP_User.
	 * @param string     $kind    What is being done.
	 * @param string     $area    What it is about.
	 * @param mixed      $context What is asking, passed to the filter.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function user_can( int|object $user, string $kind, string $area = '', mixed $context = null ): bool {
		$user_id = is_object( $user ) ? (int) ( $user->ID ?? 0 ) : $user;

		return $this->filter( user_can( $user, $this->capability( $kind, $area ) ), $kind, $area, $context, $user_id );
	}

	/**
	 * Every capability the current user has of these.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	public function granted(): array {
		return array_values( array_filter( $this->all(), static fn( string $capability ): bool => current_user_can( $capability ) ) );
	}

	/**
	 * Run the filter over an answer.
	 *
	 * One filter a kind: with the default name, `{id}_can_read`,
	 * `{id}_can_write` and so on. An unknown kind is filtered as the kind it
	 * was treated as, so a typo cannot slip past a filter written for writes.
	 * Abilities asked of this set run it too, with their input as the context.
	 *
	 * @param bool     $allowed Whether the capability says they may.
	 * @param string   $kind    What is being done.
	 * @param string   $area    What it is about.
	 * @param mixed    $context What is asking.
	 * @param int|null $user_id Who is asking: the current user when left out.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function filter( bool $allowed, string $kind, string $area = '', mixed $context = null, ?int $user_id = null ): bool {
		$user_id ??= get_current_user_id();

		if ( '' === $this->filter ) {
			return $allowed;
		}

		$kind = $this->kind( $kind );
		$kind = '' !== $kind ? $kind : 'unknown';

		/**
		 * Filter whether a user may do a kind of thing to an area.
		 *
		 * @param bool   $allowed Whether they may.
		 * @param string $area    What it is about: `orders`, `customers`, `reports`…
		 * @param mixed  $context What is asking: a REST request, an ability's input, or null.
		 * @param int    $user_id Who is asking.
		 *
		 * @since 1.0.0
		 */
		return (bool) apply_filters( str_replace( '{kind}', $kind, $this->filter ), $allowed, $area, $context, $user_id );
	}

	/**
	 * What to ask for when the map has no answer: the parent, or failing
	 * that `manage_options`. Never an empty string, which current_user_can()
	 * would answer no to for everybody, administrators included, with
	 * nothing to say why.
	 *
	 * @return string
	 */
	private function narrowest(): string {
		return '' !== $this->parent ? $this->parent : 'manage_options';
	}
}
