<?php
/**
 * What the site says: its mode, whether it is ready, a country's name, a date, the kit's words.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Traits\Api;

use SugarCommerce\Dates\Timestamp;

/**
 * What the site says: its mode, whether it is ready, a country's name, a date, the kit's words.
 *
 * @since 1.0.0
 */
trait Site {

	/**
	 * Whether the API knows about test and live modes at all.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function has_modes(): bool {
		return is_callable( $this->config['test_mode'] ?? null );
	}

	/**
	 * Whether the site is in test mode now.
	 *
	 * @return bool False when the API has no modes.
	 * @since 1.0.0
	 */
	public function is_test(): bool {
		return $this->has_modes() && (bool) call_user_func( $this->config['test_mode'] );
	}

	/**
	 * Whether there is anything to serve.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function ready(): bool {
		$ready = $this->config['ready'] ?? null;

		return ! is_callable( $ready ) || (bool) $ready();
	}

	/**
	 * What the kit's own routes are about, for the permission check.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function area(): string {
		return (string) ( $this->config['area'] ?? 'settings' );
	}

	/**
	 * A configuration value, for the parts.
	 *
	 * @param string $key      The key.
	 * @param mixed  $fallback What to answer when it is not set.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	public function config( string $key, mixed $fallback = null ): mixed {
		return $this->config[ $key ] ?? $fallback;
	}

	/**
	 * A country's name, from its code.
	 *
	 * @param string $code ISO 3166 alpha-2.
	 *
	 * @return string The code itself when no `country_name` was configured.
	 * @since 1.0.0
	 */
	public function country_name( string $code ): string {
		$name = $this->config['country_name'] ?? null;

		return is_callable( $name ) ? (string) $name( $code ) : $code;
	}

	/**
	 * A stored UTC datetime as the API gives it: ISO 8601, or null.
	 *
	 * Null and not an empty string, so a client's date type can be optional
	 * without a special case for "". Nothing here is localised: the client
	 * knows its user's timezone and the server does not.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null
	 * @since 1.0.0
	 */
	public function date( mixed $value ): ?string {
		return is_string( $value ) ? Timestamp::parse( $value )?->format( Timestamp::ISO ) : null;
	}

	/**
	 * Some words the kit says, in the plugin's own words when it gave any.
	 *
	 * The keys:
	 *
	 * - `mode`: the description of a list's `mode` parameter.
	 * - `signed_out`: what a request with nobody signed in is told.
	 * - `forbidden_read`, `forbidden_write`: what a user without the capability is told.
	 * - `search_query`, `search_types`: the descriptions of /search's `q` and `types`.
	 * - `changes_types`: the description of /changes' `types`.
	 *
	 * @param string $key Which.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function text( string $key ): string {
		$strings = (array) ( $this->config['strings'] ?? [] );

		if ( isset( $strings[ $key ] ) && is_string( $strings[ $key ] ) ) {
			return $strings[ $key ];
		}

		return match ( $key ) {
			'mode'            => __( 'Test or live records. The site’s current mode when left out; `all` for both.', 'sugarcommerce' ),
			'signed_out'      => __( 'Sign in to use this. Send a WordPress application password with every request.', 'sugarcommerce' ),
			'forbidden_read'  => __( 'You are signed in, but your account is not allowed to see that.', 'sugarcommerce' ),
			'forbidden_write' => __( 'You are signed in, but your account is not allowed to do that.', 'sugarcommerce' ),
			'search_query'    => __( 'What to look for: a number, an email address, a name or a title.', 'sugarcommerce' ),
			'search_types'    => __( 'Which kinds of record to look in. All of them when left out.', 'sugarcommerce' ),
			'changes_types'   => __( 'Which kinds of record to report. All of them when left out.', 'sugarcommerce' ),
			default           => '',
		};
	}
}
