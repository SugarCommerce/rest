<?php
/**
 * A request that is sent twice is done once.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Rest\Idempotency\Store;
use SugarCommerce\Rest\Models\Idempotency\Entry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The `Idempotency-Key` header, honoured for a day.
 *
 * A phone on a train sends a refund, the tunnel takes the connection before
 * the answer comes back, and the app does the only thing it can: it sends
 * the refund again. Without this that is two refunds.
 *
 * So anything that moves money or cannot be taken back has to arrive with a
 * key the client made up -- a UUID -- and what happened is kept against it:
 *
 * - the same key, on the same route with the same body, is given the
 *   answer the first one got, and nothing is done again;
 * - the same key with anything different is a mistake in the client and is
 *   refused with 422, loudly, since quietly answering with somebody else's
 *   refund would be worse;
 * - the same key while the first is still running is told to wait.
 *
 * The record is kept in a Store whose first step only one of two
 * simultaneous requests can win -- an insert into a unique index -- and not
 * in a transient. A key belongs to the user who sent it: two people who
 * happen to send the same key do not meet.
 *
 * An answer of 500 or more is not kept. That is the site or a service it
 * called failing, not a decision about the request, and the retry should be
 * allowed to try.
 *
 * @since 1.0.0
 */
final class Idempotency {

	/**
	 * The longest key accepted.
	 *
	 * @since 1.0.0
	 */
	public const int MAX_LENGTH = 255;

	/**
	 * What makes the store, until it is made.
	 *
	 * @var callable
	 */
	private $make;

	/**
	 * The store.
	 *
	 * @var Store|null
	 */
	private ?Store $store = null;

	/**
	 * The idempotency keys of an API.
	 *
	 * @param Api      $api      The API.
	 * @param callable $store    Makes the store: fn(): Store.
	 * @param int      $lifetime How long a key is remembered, in seconds.
	 */
	public function __construct( private readonly Api $api, callable $store, private readonly int $lifetime = DAY_IN_SECONDS ) {
		$this->make = $store;
	}

	/**
	 * Where the keys are kept.
	 *
	 * @return Store
	 * @since 1.0.0
	 */
	public function store(): Store {
		return $this->store ??= ( $this->make )();
	}

	/**
	 * Run a route's callback once for its key.
	 *
	 * @param WP_REST_Request $request  The request.
	 * @param callable        $callback The route's own callback.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.0.0
	 */
	public function run( WP_REST_Request $request, callable $callback ) {
		$key = trim( (string) $request->get_header( 'idempotency_key' ) );

		if ( '' === $key || strlen( $key ) > self::MAX_LENGTH ) {
			return new WP_Error(
				$this->api->code( 'invalid_param' ),
				'' === $key
					? __( 'This needs an Idempotency-Key header: any value you make up, such as a UUID, sent again unchanged if you have to retry.', 'sugarcommerce' )
					: __( 'That Idempotency-Key is too long. Use 255 characters or fewer.', 'sugarcommerce' ),
				[
					'status' => 400,
					'param'  => 'Idempotency-Key',
				]
			);
		}

		$store       = $this->store();
		$hash        = hash( 'sha256', get_current_user_id() . '|' . $key );
		$fingerprint = self::fingerprint( $request );
		$entry       = $store->find( $hash );

		if ( null !== $entry && $entry->expired() ) {
			$store->release( $entry->id );

			$entry = null;
		}

		if ( null === $entry ) {
			$id = $store->claim(
				new Entry(
					$hash,
					get_current_user_id(),
					$request->get_method() . ' ' . $request->get_route(),
					$fingerprint,
					Clock::time() + $this->lifetime
				)
			);

			if ( $id > 0 ) {
				return $this->first( $id, $request, $callback );
			}

			// Somebody else inserted it between the look and the insert:
			// the same request, arriving twice at once.
			$entry = $store->find( $hash );

			if ( null === $entry ) {
				return new WP_Error( $this->api->code( 'failed' ), __( 'That could not be started. Try again.', 'sugarcommerce' ), [ 'status' => 500 ] );
			}
		}

		if ( ! hash_equals( $entry->fingerprint, $fingerprint ) ) {
			return new WP_Error(
				$this->api->code( 'invalid_param' ),
				__( 'That Idempotency-Key was already used for a different request. Use a new key for a new request.', 'sugarcommerce' ),
				[
					'status' => 422,
					'param'  => 'Idempotency-Key',
				]
			);
		}

		if ( $entry->pending() ) {
			return new WP_Error(
				$this->api->code( 'conflict' ),
				__( 'The first request with that Idempotency-Key is still being worked on. Wait a moment and send it again.', 'sugarcommerce' ),
				[
					'status'      => 409,
					'retry_after' => 2,
				]
			);
		}

		return self::replay( $entry );
	}

	/**
	 * Do the work, and keep the answer.
	 *
	 * @param int             $id       The key's entry.
	 * @param WP_REST_Request $request  The request.
	 * @param callable        $callback The route's own callback.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.0.0
	 */
	private function first( int $id, WP_REST_Request $request, callable $callback ) {
		$result   = $callback( $request );
		$response = rest_ensure_response( $result );
		$status   = is_wp_error( $result )
			? (int) ( ( (array) $result->get_error_data() )['status'] ?? 500 )
			: (int) $response->get_status();

		if ( $status >= 500 ) {
			$this->store()->release( $id );

			return $result;
		}

		$this->store()->complete(
			$id,
			$status,
			(string) wp_json_encode(
				is_wp_error( $result )
					? [
						'error'   => (string) $result->get_error_code(),
						'message' => (string) $result->get_error_message(),
						'data'    => (array) $result->get_error_data(),
					]
					: [ 'body' => $response->get_data() ]
			)
		);

		// Once in a while, clear out the keys nobody can use any more.
		if ( 0 === wp_rand( 0, 19 ) ) {
			$this->clean();
		}

		return $result;
	}

	/**
	 * The answer the first request got.
	 *
	 * @param Entry $entry The key's entry.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.0.0
	 */
	private static function replay( Entry $entry ) {
		$kept = json_decode( $entry->response, true );
		$kept = is_array( $kept ) ? $kept : [];

		if ( isset( $kept['error'] ) ) {
			return new WP_Error(
				(string) $kept['error'],
				(string) ( $kept['message'] ?? '' ),
				[ 'replayed' => true ] + (array) ( $kept['data'] ?? [] )
			);
		}

		$response = new WP_REST_Response( $kept['body'] ?? null, $entry->status );

		$response->header( 'Idempotent-Replayed', 'true' );

		return $response;
	}

	/**
	 * What a request is, as one string: the method, the route and the body.
	 *
	 * The parameters are sorted first, so the same body sent with its keys
	 * in another order -- as a second JSON encoder may well do -- is the
	 * same request.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function fingerprint( WP_REST_Request $request ): string {
		$params = $request->get_params();

		$sort = static function ( array &$value ) use ( &$sort ): void {
			ksort( $value );

			foreach ( $value as &$inner ) {
				if ( is_array( $inner ) ) {
					$sort( $inner );
				}
			}
		};

		$sort( $params );

		return hash( 'sha256', $request->get_method() . ' ' . $request->get_route() . ' ' . wp_json_encode( $params ) );
	}

	/**
	 * Remove the keys whose day is over.
	 *
	 * For a daily job, too: this runs on one write in twenty by itself.
	 *
	 * @return int How many went.
	 * @since 1.0.0
	 */
	public function clean(): int {
		return $this->store()->clean( Clock::time() );
	}
}
