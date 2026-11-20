<?php
/**
 * Access: the capability an ability needs, and whether the current user has it.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Traits\Abilities;

use SugarCommerce\Rest\Capabilities;

/**
 * Access: the capability an ability needs, and whether the current user has it.
 *
 * From the ability's own capability, the capability map (a Capabilities set
 * or a callable) for its kind and area, or the set's own.
 *
 * @since 1.0.0
 */
trait Access {

	/**
	 * The capability an ability needs.
	 *
	 * The first of: its own `capability`; what the capability map says for
	 * the kind of thing it does (its `access`, or the one its `kind` maps
	 * to) and its `area`; the set's `capability`.
	 *
	 * @param string                    $name       The ability's full name.
	 * @param array<string, mixed>|null $definition Its definition; looked up when null.
	 *
	 * @return string Empty for an ability whose own `permission` callback decides.
	 * @since 1.0.0
	 */
	public function capability( string $name, ?array $definition = null ): string {
		$definition ??= $this->definitions()[ $name ] ?? null;

		if ( null === $definition ) {
			return $this->capability;
		}

		if ( is_callable( $definition['permission'] ?? null ) ) {
			return '';
		}

		$capability = (string) ( $definition['capability'] ?? '' );

		$map = $this->map();

		if ( '' === $capability && null !== $map ) {
			[ $access, $area ] = $this->asks( $definition );

			$capability = is_object( $map ) && method_exists( $map, 'capability' )
				? (string) $map->capability( $access, $area )
				: (string) call_user_func( $map, $access, $area );
		}

		$capability = '' !== $capability ? $capability : $this->capability;

		/**
		 * Filter the capability one of a plugin's abilities needs.
		 *
		 * @param string               $capability A WordPress capability.
		 * @param string               $name       The ability's full name.
		 * @param array<string, mixed> $definition Its definition.
		 *
		 * @since 1.0.0
		 */
		$capability = (string) apply_filters( $this->id . '_ability_capability', $capability, $name, $definition );

		return '' !== $capability ? $capability : $this->capability;
	}

	/**
	 * Whether the current user may use an ability.
	 *
	 * The permission callback every ability without its own is given.
	 *
	 * @param string                    $name       The ability's full name.
	 * @param mixed                     $input      What it was asked with.
	 * @param array<string, mixed>|null $definition Its definition; looked up when null.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function permitted( string $name, mixed $input = null, ?array $definition = null ): bool {
		$definition ??= $this->definitions()[ $name ] ?? null;

		if ( null !== $definition && is_callable( $definition['permission'] ?? null ) ) {
			return true === call_user_func( $definition['permission'], $input );
		}

		$allowed = current_user_can( $this->capability( $name, $definition ) );
		$map     = $this->map();

		// Asked of a Capabilities set, its filter has the say it has over
		// the REST routes and the admin screens: one rule, everywhere.
		if ( $map instanceof Capabilities && null !== $definition && '' === (string) ( $definition['capability'] ?? '' ) ) {
			[ $access, $area ] = $this->asks( $definition );

			$allowed = $map->filter( $allowed, $access, $area, $input );
		}

		return $allowed;
	}

	/**
	 * What gives the capability for a kind and an area, if anything.
	 *
	 * A set's id is looked up as it is needed, so the set may be
	 * registered after the abilities.
	 *
	 * @return object|callable|null
	 */
	private function map() {
		if ( is_string( $this->capabilities ) && ! is_callable( $this->capabilities ) ) {
			return Capabilities::get( $this->capabilities );
		}

		return $this->capabilities;
	}

	/**
	 * The kind and the area an ability asks a capability map about.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function asks( array $definition ): array {
		return [
			(string) ( $definition['access'] ?? $this->access[ self::kind( $definition ) ] ),
			(string) ( $definition['area'] ?? '' ),
		];
	}
}
