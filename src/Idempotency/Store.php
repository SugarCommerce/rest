<?php
/**
 * Where idempotency keys are kept.
 *
 * @package     SugarCommerce\Rest\Idempotency
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Idempotency;

/**
 * Keeps idempotency keys.
 *
 * The one thing a store must get right is claim(): of two requests with
 * the same key arriving together, exactly one may win. A unique index on
 * the key does that; reading first and writing after does not.
 *
 * The table is the host's: it creates it, as it creates its others. See
 * Entry for the columns, and Stores\Table::schema() for a statement.
 *
 * @since 1.0.0
 */
interface Store {

	/**
	 * The entry for a key.
	 *
	 * @param string $hash The key, hashed with who sent it.
	 *
	 * @return Entry|null
	 * @since 1.0.0
	 */
	public function find( string $hash ): ?Entry;

	/**
	 * Take a key, if nobody has.
	 *
	 * @param Entry $entry The key, with no answer yet.
	 *
	 * @return int The new entry's id, or 0 when the key was already taken.
	 * @since 1.0.0
	 */
	public function claim( Entry $entry ): int;

	/**
	 * Keep the answer the first request got.
	 *
	 * @param int    $id       The entry.
	 * @param int    $status   The answer's status.
	 * @param string $response The answer, as JSON.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function complete( int $id, int $status, string $response ): bool;

	/**
	 * Let a key go: the request failed, or its day is over.
	 *
	 * @param int $id The entry.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function release( int $id ): bool;

	/**
	 * Remove every key whose day is over.
	 *
	 * @param int $now The time now.
	 *
	 * @return int How many went.
	 * @since 1.0.0
	 */
	public function clean( int $now ): int;
}
