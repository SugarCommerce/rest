<?php
/**
 * Idempotency keys in memory.
 *
 * @package     SugarCommerce\Rest\Idempotency\Stores
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Idempotency\Stores;

use SugarCommerce\Rest\Idempotency\Entry;
use SugarCommerce\Rest\Idempotency\Store;

/**
 * Keys for the length of one PHP process.
 *
 * For tests, and for a long-running worker that answers every request
 * itself. Not for a site: the next request is another process, and would
 * find nothing.
 *
 * @since 1.0.0
 */
final class Memory implements Store {

	/**
	 * The entries, by id.
	 *
	 * @var array<int, Entry>
	 */
	private array $entries = [];

	/**
	 * The last id given out.
	 *
	 * @var int
	 */
	private int $last = 0;

	/**
	 * {@inheritDoc}
	 */
	public function find( string $hash ): ?Entry {
		foreach ( $this->entries as $entry ) {
			if ( $entry->hash === $hash ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function claim( Entry $entry ): int {
		if ( null !== $this->find( $entry->hash ) ) {
			return 0;
		}

		$id = ++$this->last;

		$this->entries[ $id ] = new Entry( $entry->hash, $entry->user_id, $entry->route, $entry->fingerprint, $entry->expires, 0, '', $id );

		return $id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function complete( int $id, int $status, string $response ): bool {
		$entry = $this->entries[ $id ] ?? null;

		if ( null === $entry ) {
			return false;
		}

		$this->entries[ $id ] = new Entry( $entry->hash, $entry->user_id, $entry->route, $entry->fingerprint, $entry->expires, $status, $response, $id );

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function release( int $id ): bool {
		if ( ! isset( $this->entries[ $id ] ) ) {
			return false;
		}

		unset( $this->entries[ $id ] );

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function clean( int $now ): int {
		$before = count( $this->entries );

		$this->entries = array_filter( $this->entries, static fn( Entry $entry ): bool => ! $entry->expired( $now ) );

		return $before - count( $this->entries );
	}

	/**
	 * Every entry, for a test to look at.
	 *
	 * @return Entry[]
	 * @since 1.0.0
	 */
	public function all(): array {
		return array_values( $this->entries );
	}
}
