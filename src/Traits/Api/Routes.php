<?php
/**
 * Controllers and resources: what the API serves, and registering it.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Traits\Api;

use SugarCommerce\Rest\Api;
use SugarCommerce\Rest\Controllers\Document;
use SugarCommerce\Rest\Controllers\Search;
use SugarCommerce\Rest\Controllers\Sync;
use WP_REST_Request;

/**
 * Controllers and resources: what the API serves, and registering it.
 *
 * The plugin's controllers, an add-on's added ones, and the kit's own
 * /changes, /search and OpenAPI routes.
 *
 * @since 1.0.0
 */
trait Routes {

	/**
	 * Add a controller.
	 *
	 * Called on `{prefix}_rest_init`, which runs as the routes are about to
	 * be registered. A controller is anything with a register_routes()
	 * method, and normally a class extending the plugin's Controller.
	 *
	 * @param object $controller The controller.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add( object $controller ): void {
		if ( method_exists( $controller, 'register_routes' ) ) {
			$this->added[] = $controller;
		}
	}

	/**
	 * Add a resource to the ones /changes and /search read.
	 *
	 * Without this an add-on's records are invisible to an app keeping a
	 * copy: /changes would not report them, a deletion would be forgotten,
	 * and /search would not look in them. Call it as the plugin loads (not
	 * only on `{prefix}_rest_init`), so a deletion made in wp-admin is
	 * remembered too.
	 *
	 * @param string               $name The resource, as /changes and /search name it: its route, `orders`.
	 * @param array<string, mixed> $args {
	 *     The resource.
	 *
	 *     @type string             $record  Its Record class: what /search shapes its rows with.
	 *     @type string             $table   Its table, without `$wpdb->prefix`, for /changes: every row has `id`
	 *                                       and an indexed `date_modified`. Left out, /changes does not report it.
	 *     @type string             $deleted The action fired with the id when one is deleted, for /changes.
	 *     @type string             $area    What reading it is about, for the permission check /changes and
	 *                                       /search make for each kind. Its name by default.
	 *     @type array|false        $search  For /search: `list` (a function taking query arguments: `search`,
	 *                                       `number`, `orderby`, `order`), `find` (a function finding the one
	 *                                       exact match for what was typed), `args` (fn( $args, $query ) to
	 *                                       change the list's arguments) and `description`. False to leave it out.
	 * }
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_resource( string $name, array $args ): void {
		$this->resources[ $name ] = self::resource_args( $args );

		$this->changes()->listen();
	}

	/**
	 * The resources, by name.
	 *
	 * @param string $feature `changes` for those /changes reports, `search` for
	 *                        those /search looks in, or '' for all of them.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	public function resources( string $feature = '' ): array {
		return match ( $feature ) {
			'changes' => array_filter( $this->resources, static fn( array $args ): bool => '' !== $args['table'] ),
			'search'  => array_filter( $this->resources, static fn( array $args ): bool => [] !== $args['search'] && '' !== $args['record'] ),
			default   => $this->resources,
		};
	}

	/**
	 * The resources a feature reads that the current user may read.
	 *
	 * /changes and /search answer for every kind at once, behind the
	 * permission check of the API's own area. Each kind is asked about
	 * again, under its own area -- its name unless it gave one -- so a user
	 * let into the route sees only the kinds they could list one by one.
	 *
	 * @param string               $feature `changes` or `search`.
	 * @param WP_REST_Request|null $request The request, for the permission check.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	public function readable( string $feature, ?WP_REST_Request $request = null ): array {
		return array_filter(
			$this->resources( $feature ),
			fn( array $args, string $name ): bool => $this->permissions()->can( 'read', '' !== $args['area'] ? $args['area'] : $name, $request ),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * A resource's arguments, filled in.
	 *
	 * @param array<string, mixed> $args As given.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private static function resource_args( array $args ): array {
		$search = $args['search'] ?? [];

		return [
			'record'  => (string) ( $args['record'] ?? '' ),
			'table'   => (string) preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $args['table'] ?? '' ) ),
			'deleted' => (string) ( $args['deleted'] ?? '' ),
			'area'    => (string) ( $args['area'] ?? '' ),
			'search'  => is_array( $search ) ? $search : [],
		];
	}

	/**
	 * The controllers the configuration names, made.
	 *
	 * @return object[]
	 * @since 1.0.0
	 */
	public function controllers(): array {
		$made = [];

		foreach ( (array) ( $this->config['controllers'] ?? [] ) as $controller ) {
			if ( is_string( $controller ) && class_exists( $controller ) ) {
				$controller = new $controller();
			}

			if ( is_object( $controller ) && method_exists( $controller, 'register_routes' ) ) {
				$made[] = $controller;
			}
		}

		$routes = (array) ( $this->config['routes'] ?? [] );

		if ( ( $routes['changes'] ?? true ) && [] !== $this->resources( 'changes' ) ) {
			$made[] = new Sync( $this );
		}

		if ( ( $routes['search'] ?? true ) && [] !== $this->resources( 'search' ) ) {
			$made[] = new Search( $this );
		}

		if ( $routes['openapi'] ?? true ) {
			$made[] = new Document( $this );
		}

		return $made;
	}

	/**
	 * Register every route. On `rest_api_init`.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function routes(): void {
		// Nothing to serve until the tables exist; a route that fatals on a
		// half-installed site is worse than one that is not there.
		if ( ! $this->ready() ) {
			return;
		}

		$this->added = [];

		/**
		 * Fires as the API's routes are about to be registered.
		 *
		 * `{prefix}_rest_init`: where an add-on hands over a controller with
		 * add(), a resource with add_resource(), and calls
		 * register_rest_field() with object_type().
		 *
		 * @param Api $api The API.
		 *
		 * @since 1.0.0
		 */
		do_action( $this->hook( 'init' ), $this );

		foreach ( $this->controllers() as $controller ) {
			$controller->register_routes();
		}

		foreach ( $this->added as $controller ) {
			$controller->register_routes();
		}
	}
}
