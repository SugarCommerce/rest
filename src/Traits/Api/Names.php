<?php
/**
 * What the API is called: its id, prefix, namespace, version, codes, hooks and headers.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Traits\Api;

/**
 * What the API is called: its id, prefix, namespace, version, codes, hooks and headers.
 *
 * Everything a client can see that names the plugin is built from the API's
 * prefix, so two plugins using the kit at once never meet.
 *
 * @since 1.0.0
 */
trait Names {

	/**
	 * The API's id.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * What every code, hook and name is built from.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function prefix(): string {
		$prefix = (string) ( $this->config['prefix'] ?? '' );

		return '' !== $prefix ? $prefix : $this->id;
	}

	/**
	 * The REST namespace: `myshop/v1`.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function namespace(): string {
		return trim( (string) $this->config['namespace'], '/' );
	}

	/**
	 * The API's own version, apart from the plugin's.
	 *
	 * Within a namespace's version the middle number goes up when something
	 * is added and the last when something is fixed; nothing is taken away
	 * or renamed.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function version(): string {
		return (string) ( $this->config['version'] ?? '1.0.0' );
	}

	/**
	 * What marks a route as one of this API's, in its handler options.
	 *
	 * @return string `{prefix}_api`.
	 * @since 1.0.0
	 */
	public function mark(): string {
		return $this->prefix() . '_api';
	}

	/**
	 * A key of the API's own in a route's handler options: `{prefix}_kind`.
	 *
	 * @param string $what `kind` or `once`.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function key( string $what ): string {
		return $this->prefix() . '_' . $what;
	}

	/**
	 * One of the API's error codes: `{prefix}_not_found`.
	 *
	 * @param string $name `not_found`, `forbidden`, `invalid_param`, `conflict`,
	 *                     `rate_limited`, `unauthorized` or `failed`.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function code( string $name ): string {
		return $this->prefix() . '_' . $name;
	}

	/**
	 * The name of one of the API's hooks: `{prefix}_rest_{name}`.
	 *
	 * @param string $name The hook's own part: `init`, `request`, `prepare_order`…
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function hook( string $name ): string {
		return $this->prefix() . '_rest_' . $name;
	}

	/**
	 * The name WordPress knows a resource by.
	 *
	 * What register_rest_field() is given, and the title of the resource's
	 * schema: `myshop_order`. Prefixed, because `order` and `product` are
	 * names other plugins use for theirs.
	 *
	 * @param string $resource The resource, singular.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function object_type( string $resource ): string {
		return $this->prefix() . '_' . sanitize_key( $resource );
	}

	/**
	 * The address of a route in the API.
	 *
	 * @param string $path The route below the namespace, such as "orders/12".
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function url( string $path = '' ): string {
		return rest_url( $this->namespace() . '/' . ltrim( $path, '/' ) );
	}

	/**
	 * The start of the response headers' names: `X-MyShop`.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function header(): string {
		$header = (string) ( $this->config['header'] ?? '' );

		return '' !== $header ? $header : 'X-' . ucfirst( $this->prefix() );
	}
}
