<?php
/**
 * A plugin's REST API.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest;

use InvalidArgumentException;
use SugarCommerce\Rest\Idempotency\Store;
use SugarCommerce\Rest\Idempotency\Stores\Table;
use SugarCommerce\Rest\Traits\Api\Names;
use SugarCommerce\Rest\Traits\Api\Responses;
use SugarCommerce\Rest\Traits\Api\Routes;
use SugarCommerce\Rest\Traits\Api\Site;

/**
 * The front door: which controllers there are, and what is true of every
 * response.
 *
 * A thin layer over a plugin's own functions. A controller reads the
 * request, finds the record, calls the function the admin screen calls, and
 * hands the answer to a serializer -- so a refund from a phone goes through
 * the guards a refund from wp-admin does. Nothing about how the plugin works
 * is decided here.
 *
 * One API a namespace, registered once by id. Everything a client can see
 * that names the plugin is built from the API's `prefix`: the error codes
 * (`{prefix}_not_found`), the hooks (`{prefix}_rest_init`), the names
 * WordPress knows a resource by (`{prefix}_order`), the headers
 * (`X-{Prefix}-Mode`) and the OpenAPI extension (`x-{prefix}`). Two plugins
 * using the kit at once never meet.
 *
 * Laid out as:
 *
 *     Api.php            this: registering, and the parts
 *     Traits/Api/        its names, what the site says, its routes, every response
 *     Controller.php     what every controller has: paging, filters, errors
 *     Record.php         a row as the outside world sees it
 *     Schema.php         money, a date, a status, as JSON Schema
 *     Permissions.php    the check every route makes
 *     RateLimit.php      a limit a minute, a credential
 *     Idempotency.php    a retried POST answers as the first one did
 *     Changes.php        a short memory of deletions, for /changes
 *     OpenApi.php        the routes as an OpenAPI document
 *     Conditional.php    ETag, Last-Modified and the 304
 *     Cors.php           which browsers may read a set of routes
 *
 * Routes that share the namespace but are not this API's -- a webhook, a
 * public endpoint registered the plain WordPress way -- are left exactly as
 * they are: only a route registered through Controller::handle() carries
 * the mark this class looks for.
 *
 * @since 1.0.0
 */
final class Api {

	use Names;
	use Responses;
	use Routes;
	use Site;

	/**
	 * The codes WordPress refuses a request's parameters under, before any
	 * of the API's code has run.
	 *
	 * @since 1.0.0
	 */
	private const array WORDPRESS_PARAM_ERRORS = [ 'rest_invalid_param', 'rest_missing_callback_param' ];

	/**
	 * Every registered API, by id.
	 *
	 * @var array<string, self>
	 */
	private static array $apis = [];

	/**
	 * The configuration, as given.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Controllers handed over with add() since the routes were last registered.
	 *
	 * @var object[]
	 */
	private array $added = [];

	/**
	 * The resources /changes and /search read, by name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $resources = [];

	/**
	 * The parts, made when first asked for.
	 *
	 * @var Permissions|null
	 */
	private ?Permissions $permissions = null;

	/**
	 * The rate limit.
	 *
	 * @var RateLimit|null
	 */
	private ?RateLimit $rate_limit = null;

	/**
	 * The idempotency keys.
	 *
	 * @var Idempotency|null
	 */
	private ?Idempotency $idempotency = null;

	/**
	 * The memory of deletions.
	 *
	 * @var Changes|null
	 */
	private ?Changes $changes = null;

	/**
	 * Register an API.
	 *
	 * Registering again under the same id replaces the API, and its hooks.
	 *
	 * @param string               $id     The API's id: usually the plugin's prefix.
	 * @param array<string, mixed> $config {
	 *     The API. Only `namespace` is required.
	 *
	 *     @type string                    $namespace   The REST namespace: `myshop/v1`.
	 *     @type string                    $prefix      What every code, hook and name is built from. The id by default.
	 *     @type string                    $version     The API's own version, apart from the plugin's. `1.0.0`.
	 *     @type string                    $header      The start of the response headers' names. `X-{Prefix}`.
	 *     @type callable|null             $test_mode   Whether the site is in test mode. Given, every answer says
	 *                                                  which mode it is from, and a list with `mode` in its spec
	 *                                                  shows the current mode's records unless asked otherwise.
	 *     @type callable|null             $ready       Whether there is anything to serve: the tables are installed.
	 *     @type array<int, string|object> $controllers The plugin's controllers: class names or instances.
	 *     @type array<string, array>      $resources   What /changes and /search read: see add_resource().
	 *     @type Capabilities|string|null  $capabilities The Capabilities set (or its id) that decides
	 *                                                  who may do what.
	 *     @type callable|null             $can         Who may do what, instead: fn( $kind, $area, $request ): bool.
	 *     @type string                    $area        What the kit's own routes are about, for the permission check.
	 *     @type array<string, bool>       $routes      The kit's own routes: `changes`, `search`, `openapi`. All on.
	 *     @type array|false               $rate_limit  See RateLimit. False for none.
	 *     @type Store|callable|null       $idempotency The store for idempotency keys, or what makes it. A Table
	 *                                                  store on `{prefix}_idempotency_keys` by default.
	 *     @type int                       $idempotency_lifetime How long a key is remembered, in seconds. A day.
	 *     @type array                     $deletions   `option` and `kept`: see Changes.
	 *     @type array                     $openapi     `title`, `description` and `security`: see OpenApi.
	 *     @type array<string, string>     $strings     Words to use instead of the kit's: see text().
	 *     @type callable|null             $country_name A country's name from its code, for Record::country().
	 *     @type callable|null             $log         Write a request down: fn( $line, $request, $response ).
	 * }
	 *
	 * @return self
	 * @throws InvalidArgumentException When the id or the namespace is empty.
	 * @since 1.0.0
	 */
	public static function register( string $id, array $config ): self {
		if ( '' === $id ) {
			throw new InvalidArgumentException( 'An API needs an id.' );
		}

		if ( '' === trim( (string) ( $config['namespace'] ?? '' ), '/' ) ) {
			throw new InvalidArgumentException( 'An API needs a namespace, such as myplugin/v1.' );
		}

		self::unregister( $id );

		$api = new self( $id, $config );

		self::$apis[ $id ] = $api;

		add_action( 'rest_api_init', [ $api, 'routes' ] );
		add_filter( 'rest_post_dispatch', [ $api, 'finish' ], 20, 3 );

		$api->changes()->listen();

		return $api;
	}

	/**
	 * A registered API.
	 *
	 * @param string $id The API's id.
	 *
	 * @return self|null
	 * @since 1.0.0
	 */
	public static function get( string $id ): ?self {
		return self::$apis[ $id ] ?? null;
	}

	/**
	 * Forget an API, and take its hooks back out.
	 *
	 * Routes already registered on this request stay registered: WordPress
	 * has no way to take one back.
	 *
	 * @param string $id The API's id.
	 *
	 * @return bool Whether there was one.
	 * @since 1.0.0
	 */
	public static function unregister( string $id ): bool {
		$api = self::$apis[ $id ] ?? null;

		if ( null === $api ) {
			return false;
		}

		remove_action( 'rest_api_init', [ $api, 'routes' ] );
		remove_filter( 'rest_post_dispatch', [ $api, 'finish' ], 20 );

		$api->changes()->unlisten();

		unset( self::$apis[ $id ] );

		return true;
	}

	/**
	 * Build the API.
	 *
	 * @param string               $id     The API's id.
	 * @param array<string, mixed> $config See register().
	 */
	private function __construct( private readonly string $id, array $config ) {
		$this->config = $config;

		foreach ( (array) ( $config['resources'] ?? [] ) as $name => $args ) {
			$this->resources[ (string) $name ] = self::resource_args( (array) $args );
		}
	}

	/* ─── The parts ─────────────────────────────────────────────────── */

	/**
	 * Who may do what.
	 *
	 * @return Permissions
	 * @since 1.0.0
	 */
	public function permissions(): Permissions {
		return $this->permissions ??= new Permissions( $this );
	}

	/**
	 * How often one credential may ask.
	 *
	 * @return RateLimit
	 * @since 1.0.0
	 */
	public function rate_limit(): RateLimit {
		$config = $this->config['rate_limit'] ?? [];

		return $this->rate_limit ??= new RateLimit( $this, false === $config ? false : (array) $config );
	}

	/**
	 * The idempotency keys.
	 *
	 * @return Idempotency
	 * @since 1.0.0
	 */
	public function idempotency(): Idempotency {
		return $this->idempotency ??= new Idempotency(
			$this,
			function (): Store {
				$store = $this->config['idempotency'] ?? null;

				if ( is_callable( $store ) ) {
					$store = $store();
				}

				if ( $store instanceof Store ) {
					return $store;
				}

				global $wpdb;

				return new Table( $wpdb->prefix . $this->prefix() . '_idempotency_keys' );
			},
			(int) ( $this->config['idempotency_lifetime'] ?? DAY_IN_SECONDS )
		);
	}

	/**
	 * The memory of deletions.
	 *
	 * @return Changes
	 * @since 1.0.0
	 */
	public function changes(): Changes {
		return $this->changes ??= new Changes( $this, (array) ( $this->config['deletions'] ?? [] ) );
	}

	/**
	 * The routes as an OpenAPI document.
	 *
	 * @return OpenApi
	 * @since 1.0.0
	 */
	public function openapi(): OpenApi {
		return new OpenApi( $this, (array) ( $this->config['openapi'] ?? [] ) );
	}
}
