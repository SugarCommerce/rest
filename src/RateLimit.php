<?php
/**
 * How often one credential may ask.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest;

use SugarCommerce\Security\Models\RateLimit\Decision;
use SugarCommerce\Security\RateLimit\Limiter;
use WP_Error;

/**
 * A limit a minute, counted for each application password.
 *
 * By credential and not by user or by address. Somebody with an app on a
 * phone and a tablet has two application passwords, and one of them stuck
 * in a retry loop should not lock the other out; and an address is shared
 * by everybody on the same mobile network. Somebody using the API from
 * wp-admin, with a cookie, is counted as their user.
 *
 * Buckets. Everything counts towards the `general` one; a kind of request
 * named in `kinds` -- by default `refund`, anything that moves money or
 * cannot be taken back -- counts towards a much smaller one as well,
 * because the only thing that refunds ten orders a minute is a bug.
 *
 * Fixed windows, a minute each, and not a sliding one: a client is told
 * exactly when to come back (`Retry-After`, `X-RateLimit-Reset`), and the
 * worst a fixed window allows -- twice the limit across a boundary -- is
 * nothing that matters at these sizes.
 *
 * Counted by sugarcommerce/security's Limiter: in the object cache when the site
 * has a persistent one, in the host's rate-limits table otherwise.
 *
 * @since 1.0.0
 */
final class RateLimit {

	/**
	 * The bucket everything counts towards.
	 *
	 * @since 1.0.0
	 */
	public const string GENERAL = 'general';

	/**
	 * The configuration, filled in.
	 *
	 * @var array{limits: array<string, int>, kinds: array<string, string>, window: int, table: string, group: string}|null
	 */
	private ?array $config;

	/**
	 * The tightest answer this request has had, for the response's headers.
	 *
	 * @var Decision|null
	 */
	private ?Decision $state = null;

	/**
	 * The rate limit of an API.
	 *
	 * @param Api                        $api    The API.
	 * @param array<string, mixed>|false $config {
	 *     The limit, or false for none.
	 *
	 *     @type array<string, int>    $limits Requests a window, by bucket. `general` 120 and `money` 10.
	 *     @type array<string, string> $kinds  Which kinds count towards a bucket besides `general`:
	 *                                         `[ 'refund' => 'money' ]`.
	 *     @type int                   $window The window, in seconds. 60.
	 *     @type string                $table  The rate-limits table the host made, without `$wpdb->prefix`.
	 *                                         `{prefix}_rate_limits`. See sugarcommerce/security's RateLimit\Stores\Table::schema().
	 *     @type string                $group  The object-cache group. `{prefix}_rate_limit`.
	 * }
	 */
	public function __construct( private readonly Api $api, array|false $config = [] ) {
		$this->config = false === $config ? null : [
			'limits' => array_map( 'intval', (array) ( $config['limits'] ?? [] ) ) + [
				self::GENERAL => 120,
				'money'       => 10,
			],
			'kinds'  => array_map( 'strval', (array) ( $config['kinds'] ?? [ 'refund' => 'money' ] ) ),
			'window' => max( 1, (int) ( $config['window'] ?? MINUTE_IN_SECONDS ) ),
			'table'  => (string) ( $config['table'] ?? $api->prefix() . '_rate_limits' ),
			'group'  => (string) ( $config['group'] ?? $api->prefix() . '_rate_limit' ),
		];
	}

	/**
	 * The bucket a kind of request counts towards, besides `general`.
	 *
	 * @param string $kind `read`, `write`, `refund`…
	 *
	 * @return string `general` when it counts towards nothing more.
	 * @since 1.0.0
	 */
	public function bucket( string $kind ): string {
		return null === $this->config ? self::GENERAL : ( $this->config['kinds'][ $kind ] ?? self::GENERAL );
	}

	/**
	 * Count this request, and refuse it if it is one too many.
	 *
	 * @param string $bucket `general`, or a smaller bucket the request also counts towards.
	 * @param string $who    Who to count it against, when not the signed-in
	 *                       credential: a public route counts each connection
	 *                       on its own (`storefront:<ip>`), where every caller
	 *                       would otherwise be `user:0`.
	 *
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	public function check( string $bucket = self::GENERAL, string $who = '' ) {
		if ( null === $this->config ) {
			return true;
		}

		$who = '' !== $who ? $who : self::credential();

		// What moves money is counted twice: as a request, and as one of those.
		$buckets = self::GENERAL === $bucket ? [ self::GENERAL ] : [ self::GENERAL, $bucket ];

		foreach ( $buckets as $name ) {
			// Nought is no limit, for a site that has its own in front.
			$decision = $this->counter()->hit( $name . '|' . $who, $this->limit( $name, $who ), $this->config['window'] );

			// The tighter bucket is the one worth reporting: it is the one
			// the client will run into first.
			$this->state = null === $this->state ? $decision : Decision::tightest( $this->state, $decision );

			if ( $decision->denied() ) {
				$wait = $decision->retry_after();

				return new WP_Error(
					$this->api->code( 'rate_limited' ),
					sprintf(
						/* translators: %d: a number of seconds. */
						_n( 'Too many requests. Try again in %d second.', 'Too many requests. Try again in %d seconds.', $wait, 'sugarcommerce' ),
						$wait
					),
					[
						'status'      => 429,
						'retry_after' => $wait,
					]
				);
			}
		}

		return true;
	}

	/**
	 * The headers the last check asks for.
	 *
	 * @return array<string, string>
	 * @since 1.0.0
	 */
	public function headers(): array {
		// With Retry-After as soon as nothing is left, not only on a
		// refusal: a client is told to wait before it is refused.
		return null === $this->state ? [] : $this->state->headers( Decision::LEGACY, true );
	}

	/**
	 * Forget the last check. One request, one set of headers.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function reset(): void {
		$this->state = null;
	}

	/**
	 * Who is asking, as a counter's key.
	 *
	 * @return string `app:{uuid}` for an application password, `user:{id}` otherwise.
	 * @since 1.0.0
	 */
	public static function credential(): string {
		$password = (string) rest_get_authenticated_app_password();

		return '' !== $password ? 'app:' . $password : 'user:' . get_current_user_id();
	}

	/**
	 * A bucket's limit.
	 *
	 * @param string $bucket     The bucket.
	 * @param string $credential Who is asking.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	private function limit( string $bucket, string $credential ): int {
		$limit = $this->config['limits'][ $bucket ] ?? $this->config['limits'][ self::GENERAL ];

		/**
		 * Filter how many requests a window one credential may make.
		 *
		 * `{prefix}_rest_rate_limit`. Nought switches the limit off.
		 *
		 * @param int    $limit      Requests a window.
		 * @param string $bucket     `general`, or the smaller bucket: `money`.
		 * @param string $credential `app:{uuid}` for an application password, `user:{id}` for a cookie.
		 *
		 * @since 1.0.0
		 */
		return (int) apply_filters( $this->api->hook( 'rate_limit' ), $limit, $bucket, $credential );
	}

	/**
	 * What does the counting.
	 *
	 * @return Limiter
	 * @since 1.0.0
	 */
	private function counter(): Limiter {
		/**
		 * Filter what counts the API's requests.
		 *
		 * `{prefix}_rest_rate_counter`. A Limiter from sugarcommerce/security,
		 * over any Store: Redis of your own, say. Null for the default -- the
		 * object cache when there is a persistent one, the table otherwise.
		 *
		 * @param Limiter|null $counter The counter.
		 *
		 * @since 1.0.0
		 */
		$counter = apply_filters( $this->api->hook( 'rate_counter' ), null );

		if ( $counter instanceof Limiter ) {
			return $counter;
		}

		global $wpdb;

		return Limiter::auto( $wpdb->prefix . $this->config['table'], [ $this->api, 'ready' ], $this->config['group'] );
	}
}
