<?php
/**
 * Idempotency keys in a table, through $wpdb.
 *
 * @package     SugarCommerce\Rest\Idempotency\Stores
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Idempotency\Stores;

use SugarCommerce\Dates\Timestamp;
use SugarCommerce\Rest\Idempotency\Store;
use SugarCommerce\Rest\Models\Idempotency\Entry;

/**
 * Keys in a table the host made, read and written with $wpdb.
 *
 * The default store, for a plugin without query classes of its own. The
 * table is the host's: it creates it -- with a schema class, a migration,
 * or schema() below -- and names it in full, prefix and all. The insert is
 * the race: two requests with the same key both try it, the unique index
 * on `key_hash` lets exactly one in, and the other's duplicate-key error
 * is kept out of the log because it is expected.
 *
 * @since 1.0.0
 */
final class Table implements Store {

	/**
	 * A store.
	 *
	 * @param string $table The table's full name: `$wpdb->prefix . 'myshop_idempotency_keys'`.
	 */
	public function __construct( private readonly string $table ) {}

	/**
	 * The table's name.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function table(): string {
		return $this->table;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function find( string $hash ): ?Entry {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A key is read once, as its request arrives; a cached copy is the wrong answer to a retry.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE key_hash = %s LIMIT 1', $this->table, $hash ), ARRAY_A );

		return is_array( $row ) ? Entry::from_row( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function claim( Entry $entry ): int {
		global $wpdb;

		$now  = Timestamp::now();
		$row  = $entry->to_row() + [
			'response'      => '',
			'date_created'  => $now,
			'date_modified' => $now,
		];
		$down = $wpdb->suppress_errors( true );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The insert is the lock.
			$inserted = $wpdb->insert( $this->table, $row );
		} finally {
			$wpdb->suppress_errors( $down );
		}

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function complete( int $id, int $status, string $response ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Nothing caches these rows.
		return false !== $wpdb->update(
			$this->table,
			[
				'response_code' => $status,
				'response'      => $response,
				'date_modified' => Timestamp::now(),
			],
			[ 'id' => $id ]
		);
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function release( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Nothing caches these rows.
		return (bool) $wpdb->delete( $this->table, [ 'id' => $id ] );
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function clean( int $now ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A sweep of rows by date, on the index.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i
				WHERE date_expires IS NOT NULL
				AND date_expires < %s',
				$this->table,
				Timestamp::at( $now )
			)
		);
	}

	/**
	 * A CREATE TABLE statement for a host that has no table of its own.
	 *
	 * In the shape dbDelta() reads: two spaces before PRIMARY KEY, one
	 * column or key a line.
	 *
	 *     require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	 *     dbDelta( Table::schema( $wpdb->prefix . 'myshop_idempotency_keys', $wpdb->get_charset_collate() ) );
	 *
	 * @param string $table           The table's full name.
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function schema( string $table, string $charset_collate = '' ): string {
		$table = preg_replace( '/[^A-Za-z0-9_$]/', '', $table );

		return trim(
			"CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
key_hash char(64) NOT NULL DEFAULT '',
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
route varchar(200) NOT NULL DEFAULT '',
fingerprint char(64) NOT NULL DEFAULT '',
response_code smallint(6) unsigned NOT NULL DEFAULT 0,
response longtext NOT NULL,
date_expires datetime DEFAULT NULL,
date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
PRIMARY KEY  (id),
UNIQUE KEY key_hash (key_hash),
KEY user_id (user_id),
KEY date_expires (date_expires)
) {$charset_collate}"
		);
	}
}
