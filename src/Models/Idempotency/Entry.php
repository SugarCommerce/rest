<?php
/**
 * One idempotency key, as kept.
 *
 * @package     SugarCommerce\Rest\Idempotency
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Models\Idempotency;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Dates\Timestamp;

/**
 * What is kept against a key: who sent it, what the request was, and --
 * once there is one -- the answer.
 *
 * Stored as a row with these columns, which is the shape every Store reads
 * and writes:
 *
 *     key_hash       char(64), UNIQUE   sha256 of the user and the key
 *     user_id        bigint unsigned
 *     route          varchar(200)       `POST /myshop/v1/orders/5/refunds`
 *     fingerprint    char(64)           sha256 of the method, the route and the body
 *     response_code  smallint unsigned  0 while the first request is still running
 *     response       longtext           the answer, as JSON
 *     date_expires   datetime NULL      UTC
 *
 * @since 1.0.0
 */
final class Entry {

	/**
	 * A key.
	 *
	 * @param string   $hash        The key, hashed with who sent it.
	 * @param int      $user_id     Who sent it.
	 * @param string   $route       The method and the route.
	 * @param string   $fingerprint What the request was.
	 * @param int|null $expires     When it is forgotten, as a timestamp.
	 * @param int      $status      The answer's status: 0 while the first request runs.
	 * @param string   $response    The answer, as JSON.
	 * @param int      $id          The row, once stored.
	 */
	public function __construct(
		public readonly string $hash,
		public readonly int $user_id,
		public readonly string $route,
		public readonly string $fingerprint,
		public readonly ?int $expires,
		public readonly int $status = 0,
		public readonly string $response = '',
		public readonly int $id = 0
	) {}

	/**
	 * Whether its day is over.
	 *
	 * A key that was never given an expiry is not expired -- "no expiry"
	 * and "expired" are opposites.
	 *
	 * @param int|null $now The time now.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function expired( ?int $now = null ): bool {
		return null !== $this->expires && $this->expires < ( $now ?? Clock::time() );
	}

	/**
	 * Whether the first request is still being worked on.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function pending(): bool {
		return 0 === $this->status;
	}

	/**
	 * The entry as a row's columns.
	 *
	 * @return array<string, int|string|null>
	 * @since 1.0.0
	 */
	public function to_row(): array {
		return [
			'key_hash'      => $this->hash,
			'user_id'       => $this->user_id,
			'route'         => $this->route,
			'fingerprint'   => $this->fingerprint,
			'response_code' => $this->status,
			'date_expires'  => null === $this->expires ? null : Timestamp::at( $this->expires ),
		];
	}

	/**
	 * An entry from a row.
	 *
	 * @param object|array<string, mixed> $row The row.
	 *
	 * @return self
	 * @since 1.0.0
	 */
	public static function from_row( object|array $row ): self {
		$row = (array) $row;

		return new self(
			(string) ( $row['key_hash'] ?? '' ),
			(int) ( $row['user_id'] ?? 0 ),
			(string) ( $row['route'] ?? '' ),
			(string) ( $row['fingerprint'] ?? '' ),
			Timestamp::to_unix( (string) ( $row['date_expires'] ?? '' ) ),
			(int) ( $row['response_code'] ?? 0 ),
			(string) ( $row['response'] ?? '' ),
			(int) ( $row['id'] ?? 0 )
		);
	}
}
