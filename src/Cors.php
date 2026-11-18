<?php
/**
 * Which browsers may read a set of routes' answers.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest;

use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

/**
 * CORS for the routes meant to be called from other sites, and for nothing else.
 *
 * **What WordPress does by default** is echo back whatever `Origin` a
 * request names, with `Access-Control-Allow-Credentials: true`, on every
 * REST route (`rest_send_cors_headers()`). Its cookie check needs a nonce
 * no other site can have, so for a signed-in API that is WordPress's
 * business and is left exactly as it is.
 *
 * **Routes meant for other sites** -- a headless storefront's catalogue,
 * say -- answer differently, and this is how:
 *
 *  - `Access-Control-Allow-Origin` is the caller's origin when the
 *    `allowed` callable accepts it, and is not sent at all otherwise, so a
 *    browser on any other site cannot read the answer. Never `*`.
 *  - No `Access-Control-Allow-Credentials`. These routes are meant to be
 *    stateless: nothing they do is decided by a cookie, so a browser is
 *    never asked to send one. WordPress's own header is taken back out.
 *  - `Vary: Origin` on every answer, allowed or not, so a cache between
 *    here and the browser does not hand one site's answer to another.
 *  - A preflight `OPTIONS` is answered here, 204 and empty, with the
 *    methods and headers the routes take.
 *
 * Which origins may read is the `allowed` callable's to say -- or, for a
 * fixed list, give the list: `new Cors( $matches, [ 'https://shop.example' ] )`.
 * A list matches exactly, as a browser compares origins: scheme, host and
 * port, case aside. A suffix (`evil-shop.example`), a subdomain, the other
 * scheme or another port is a different site.
 *
 * Whether the request is *processed* is not this class's to decide: a
 * build server sends no Origin at all. CORS only decides what a browser
 * lets a page read.
 *
 * The headers go on the response (rest_post_dispatch, where a test can see
 * them) and are then set again after WordPress has sent its own
 * (rest_pre_serve_request, after rest_send_cors_headers() at 10), because
 * WordPress sends its own with header() after the response's are out.
 *
 * @since 1.0.0
 */
final class Cors {

	/**
	 * Which requests are this policy's.
	 *
	 * @var callable
	 */
	private $matches;

	/**
	 * The origin to echo for one asking, or ''.
	 *
	 * @var callable
	 */
	private $allowed;

	/**
	 * The policy, filled in.
	 *
	 * @var array{methods: string, headers: string, expose: string, max_age: int}
	 */
	private array $config;

	/**
	 * The rate limit whose headers these routes carry.
	 *
	 * @var RateLimit|null
	 */
	private ?RateLimit $rate_limit;

	/**
	 * A policy.
	 *
	 * @param callable             $matches Whether a request is one of these routes: fn( WP_REST_Request ): bool.
	 * @param callable|string[]    $allowed The origin to echo for a request's Origin, as a browser
	 *                                      compares it (`https://shop.example`), or '' to send none:
	 *                                      fn( string $origin ): string. Or the origins that may, as a list.
	 * @param array<string, mixed> $config {
	 *     @type string         $methods    The methods the routes take. `GET, POST, OPTIONS`.
	 *     @type string         $headers    The request headers a page may send.
	 *     @type string         $expose     The response headers a page may read.
	 *     @type int            $max_age    How long a browser may remember a preflight. 600.
	 *     @type RateLimit|null $rate_limit A rate limit whose headers these routes carry, and which is
	 *                                      reset once they are on. Its API only adds them to its own routes.
	 * }
	 */
	public function __construct( callable $matches, callable|array $allowed, array $config = [] ) {
		$this->matches = $matches;
		$this->allowed = is_callable( $allowed ) ? $allowed : self::only( $allowed );
		$this->config  = [
			'methods' => (string) ( $config['methods'] ?? 'GET, POST, OPTIONS' ),
			'headers' => (string) ( $config['headers'] ?? 'Accept, Accept-Language, Authorization, Content-Type, If-None-Match, If-Modified-Since' ),
			'expose'  => (string) ( $config['expose'] ?? 'ETag, Last-Modified, Link, Retry-After, X-WP-Total, X-WP-TotalPages, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset' ),
			'max_age' => (int) ( $config['max_age'] ?? 600 ),
		];

		$this->rate_limit = ( $config['rate_limit'] ?? null ) instanceof RateLimit ? $config['rate_limit'] : null;
	}

	/**
	 * Listen.
	 *
	 * @return self
	 * @since 1.0.0
	 */
	public function init(): self {
		add_filter( 'rest_post_dispatch', [ $this, 'respond' ], 15, 3 );
		add_filter( 'rest_pre_serve_request', [ $this, 'send' ], 11, 3 );

		return $this;
	}

	/**
	 * Stop listening.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function remove(): void {
		remove_filter( 'rest_post_dispatch', [ $this, 'respond' ], 15 );
		remove_filter( 'rest_pre_serve_request', [ $this, 'send' ], 11 );
	}

	/**
	 * Whether a request is one of these routes.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function matches( WP_REST_Request $request ): bool {
		return (bool) ( $this->matches )( $request );
	}

	/**
	 * The CORS headers for an answer to this origin.
	 *
	 * @param string $origin The request's Origin header, or ''.
	 *
	 * @return array<string, string>
	 * @since 1.0.0
	 */
	public function headers( string $origin ): array {
		$headers = [ 'Vary' => 'Origin' ];
		$allowed = $this->allowed( $origin );

		if ( '' === $allowed ) {
			return $headers;
		}

		return [
			'Access-Control-Allow-Origin'   => $allowed,
			'Access-Control-Allow-Methods'  => $this->config['methods'],
			'Access-Control-Allow-Headers'  => $this->config['headers'],
			'Access-Control-Expose-Headers' => $this->config['expose'],
			'Access-Control-Max-Age'        => (string) $this->config['max_age'],
		] + $headers;
	}

	/**
	 * The origin to echo, when it is one that may read the answer.
	 *
	 * @param string $origin The request's Origin header.
	 *
	 * @return string The origin as a browser compares it, or ''.
	 * @since 1.0.0
	 */
	public function allowed( string $origin ): string {
		$origin = trim( $origin );

		// `null` is what a sandboxed frame or a file sends: never a site.
		if ( '' === $origin || 'null' === $origin ) {
			return '';
		}

		return (string) ( $this->allowed )( $origin );
	}

	/**
	 * What may read, from a list of origins: exactly those, and nothing else.
	 *
	 *     new Cors( $matches, Cors::only( [ 'https://shop.example', 'http://localhost:3000' ] ) );
	 *
	 * @param string[] $origins The origins, or URLs on them: a path is ignored.
	 *
	 * @return callable fn( string $origin ): string -- the origin to echo, or ''.
	 * @since 1.0.0
	 */
	public static function only( array $origins ): callable {
		$list = array_map( static fn( $one ): string => self::origin( (string) $one ), $origins );

		return static function ( string $origin ) use ( $list ): string {
			$origin = self::origin( $origin );

			return '' !== $origin && in_array( $origin, $list, true ) ? $origin : '';
		};
	}

	/**
	 * An origin as a browser compares it: `scheme://host[:port]`, lower-cased.
	 *
	 * Only http and https. A URL with a user name in it is refused rather
	 * than read: `https://shop.example@evil.example` is evil.example.
	 *
	 * @param string $url An origin, or a URL on one.
	 *
	 * @return string Empty when it is not one.
	 * @since 1.0.0
	 */
	public static function origin( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );

		if ( ! is_array( $parts ) || isset( $parts['user'] ) || '' === (string) ( $parts['host'] ?? '' ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return '';
		}

		return $scheme . '://' . strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/**
	 * Put the headers on an answer, and answer a preflight. On `rest_post_dispatch`.
	 *
	 * @param mixed $response The answer so far.
	 * @param mixed $server   The server.
	 * @param mixed $request  The request.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	public function respond( $response, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! $response instanceof WP_HTTP_Response || ! $this->matches( $request ) ) {
			return $response;
		}

		// WordPress answers OPTIONS with a description of the route. A
		// preflight only needs the headers.
		if ( 'OPTIONS' === $request->get_method() ) {
			$response = new WP_REST_Response( null, 204 );
		}

		foreach ( $this->headers( (string) $request->get_header( 'origin' ) ) as $name => $value ) {
			$response->header( $name, $value );
		}

		if ( null !== $this->rate_limit ) {
			foreach ( $this->rate_limit->headers() as $name => $value ) {
				$response->header( $name, $value );
			}

			$this->rate_limit->reset();
		}

		return $response;
	}

	/**
	 * Undo WordPress's own CORS headers on an answer. On `rest_pre_serve_request`.
	 *
	 * @param mixed $served  Whether the request has been served.
	 * @param mixed $result  The answer.
	 * @param mixed $request The request.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	public function send( $served, $result, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! $this->matches( $request ) || headers_sent() ) {
			return $served;
		}

		foreach ( [ 'Access-Control-Allow-Origin', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Credentials' ] as $name ) {
			header_remove( $name );
		}

		foreach ( $this->headers( (string) $request->get_header( 'origin' ) ) as $name => $value ) {
			// Vary is added to, not replaced: WordPress may have said more.
			header( $name . ': ' . $value, 'Vary' !== $name );
		}

		return $served;
	}
}
