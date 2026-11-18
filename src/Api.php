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

use SugarCommerce\Dates\Timestamp;
use SugarCommerce\Rest\Controllers\Document;
use SugarCommerce\Rest\Controllers\Search;
use SugarCommerce\Rest\Controllers\Sync;
use SugarCommerce\Rest\Idempotency\Store;
use SugarCommerce\Rest\Idempotency\Stores\Table;
use InvalidArgumentException;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

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
 *     Api.php            this: the configuration and the hooks
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

	/**
	 * The codes WordPress refuses a request's parameters under, before any
	 * of the API's code has run.
	 *
	 * @since 1.0.0
	 */
	private const WORDPRESS_PARAM_ERRORS = [ 'rest_invalid_param', 'rest_missing_callback_param' ];

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

	/* ─── What the API is called ────────────────────────────────────── */

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

	/* ─── What the site says ────────────────────────────────────────── */

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

	/* ─── Controllers and resources ─────────────────────────────────── */

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

	/* ─── Every response ────────────────────────────────────────────── */

	/**
	 * Whether a request was matched to one of this API's routes.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_ours( WP_REST_Request $request ): bool {
		return ! empty( $request->get_attributes()[ $this->mark() ] );
	}

	/**
	 * What is true of every response this API gives. On `rest_post_dispatch`.
	 *
	 * @param mixed $response The response.
	 * @param mixed $server   The server.
	 * @param mixed $request  The request.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	public function finish( $response, $server, $request ) {
		if ( ! $response instanceof WP_HTTP_Response || ! $request instanceof WP_REST_Request || ! $this->is_ours( $request ) ) {
			return $response;
		}

		// Which of the site's two sets of books this answer is from. An app
		// showing live figures under a "test" banner, or the other way
		// about, is the mistake this header is there to prevent.
		if ( $this->has_modes() ) {
			$response->header( $this->header() . '-Mode', $this->is_test() ? 'test' : 'live' );
		}

		$response->header( $this->header() . '-Version', $this->version() );

		foreach ( $this->rate_limit()->headers() as $name => $value ) {
			$response->header( $name, $value );
		}

		$this->rate_limit()->reset();

		$data = $response->get_data();

		// WordPress refuses a bad parameter before any of the API's code
		// runs, under its own code. Say it under ours, and say which
		// parameter, so a client has one thing to look for.
		$code = is_array( $data ) ? ( $data['code'] ?? '' ) : '';

		if ( $response->is_error() && in_array( $code, self::WORDPRESS_PARAM_ERRORS, true ) ) {
			$params = (array) ( $data['data']['params'] ?? [] );
			$first  = array_key_first( $params );

			// Keyed by the parameter for one that is wrong, and a plain
			// list of names for one that is missing.
			$param = is_string( $first ) ? $first : reset( $params );

			$data['code']          = $this->code( 'invalid_param' );
			$data['data']['param'] = (string) $param;

			$response->set_data( $data );
		}

		if ( $response instanceof WP_REST_Response ) {
			$this->log( $request, $response );
		}

		return $response;
	}

	/**
	 * Tell whoever is listening, and write it down if asked to.
	 *
	 * @param WP_REST_Request  $request  The request.
	 * @param WP_REST_Response $response The response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function log( WP_REST_Request $request, WP_REST_Response $response ): void {
		/**
		 * Fires when the API has answered a request.
		 *
		 * `{prefix}_rest_request`.
		 *
		 * @param WP_REST_Request  $request  The request.
		 * @param WP_REST_Response $response The response.
		 *
		 * @since 1.0.0
		 */
		do_action( $this->hook( 'request' ), $request, $response );

		$write = $this->config['log'] ?? null;

		/**
		 * Filter whether API requests are written to the log.
		 *
		 * `{prefix}_rest_log_requests`. Off unless asked for: a log line a
		 * request is a table that grows with every refresh of an app. On,
		 * each request is one line -- the method, the route, the status and
		 * who -- and never the body, which is where the personal data is.
		 *
		 * @param bool            $log     Whether to log.
		 * @param WP_REST_Request $request The request.
		 *
		 * @since 1.0.0
		 */
		if ( ! is_callable( $write ) || ! apply_filters( $this->hook( 'log_requests' ), false, $request ) ) {
			return;
		}

		$line = sprintf(
			'%s %s %d (%s)',
			$request->get_method(),
			$request->get_route(),
			$response->get_status(),
			$this->rate_limit()->credential()
		);

		$write( $line, $request, $response );
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
