<?php
/**
 * Idempotency keys in a table of the plugin's own.
 *
 * @package     SugarCommerce\Rest\Idempotency\Stores
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Idempotency\Stores;

use SugarCommerce\Data\Query as Rows;
use SugarCommerce\Dates\Timestamp;
use SugarCommerce\Rest\Idempotency\Entry;
use SugarCommerce\Rest\Idempotency\Store;
use UnexpectedValueException;

/**
 * Keys in the host's own table, through its query class.
 *
 * For a plugin whose tables are sugarcommerce/data components: the rows go
 * through the same query class and the same add/update/delete functions as
 * every other row of the plugin, so its object cache stays right and its
 * hooks fire.
 *
 * The race is Query::claim()'s: it inserts against the unique index on
 * `key_hash`, with the database's error for a duplicate kept out of the
 * log, because losing that race is expected, not a fault. Expired keys go
 * with Query::sweep(), a batch at a time, so a first clean of a big table
 * cannot hold a lock for a minute.
 *
 *     new Query(
 *         query:  fn() => new \MyShop\Database\Queries\IdempotencyKey(),
 *         insert: 'myshop_add_idempotency_key',
 *         update: 'myshop_update_idempotency_key',
 *         delete: 'myshop_delete_idempotency_key',
 *     );
 *
 * @since 1.0.0
 */
final class Query implements Store {

	/**
	 * What makes a query object.
	 *
	 * @var callable(): Rows
	 */
	private $query;

	/**
	 * What inserts a row, when not the query's add_item().
	 *
	 * @var callable|null
	 */
	private $insert;

	/**
	 * What updates a row, when not the query's update_item().
	 *
	 * @var callable|null
	 */
	private $update;

	/**
	 * What deletes a row, when not the query's delete_item().
	 *
	 * @var callable|null
	 */
	private $delete;

	/**
	 * A store.
	 *
	 * @param callable      $query  Makes the table's query object: a SugarCommerce\Data\Query.
	 * @param callable|null $insert The plugin's add function: fn( array $data ): int|false.
	 * @param callable|null $update The plugin's update function: fn( int $id, array $data ): bool.
	 * @param callable|null $delete The plugin's delete function: fn( int $id ): bool.
	 * @param int           $batch  At most this many expired keys go in one clean.
	 */
	public function __construct(
		callable $query,
		?callable $insert = null,
		?callable $update = null,
		?callable $delete = null,
		private readonly int $batch = 1000
	) {
		$this->query  = $query;
		$this->insert = $insert;
		$this->update = $update;
		$this->delete = $delete;
	}

	/**
	 * A query object.
	 *
	 * @return Rows
	 * @throws UnexpectedValueException When what makes it makes something else.
	 * @since 1.0.0
	 */
	private function query(): Rows {
		$query = ( $this->query )();

		if ( ! $query instanceof Rows ) {
			throw new UnexpectedValueException( 'The idempotency store needs a SugarCommerce\Data\Query.' );
		}

		return $query;
	}

	/**
	 * {@inheritDoc}
	 */
	public function find( string $hash ): ?Entry {
		$row = $this->query()->get_item_by( 'key_hash', $hash );

		return is_object( $row ) || is_array( $row ) ? Entry::from_row( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function claim( Entry $entry ): int {
		return (int) ( $this->query()->claim( $entry->to_row(), $this->insert ) ?? 0 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function complete( int $id, int $status, string $response ): bool {
		$data = [
			'response_code' => $status,
			'response'      => $response,
		];

		return (bool) ( null === $this->update ? $this->query()->update_item( $id, $data ) : ( $this->update )( $id, $data ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function release( int $id ): bool {
		return (bool) ( null === $this->delete ? $this->query()->delete_item( $id ) : ( $this->delete )( $id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A key with no expiry is never swept: `NULL < now` matches nothing.
	 */
	public function clean( int $now ): int {
		return $this->query()->sweep( 'date_expires', Timestamp::at( $now ), limit: $this->batch );
	}
}
