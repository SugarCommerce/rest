<?php
/**
 * The fixture plugin's widgets "table".
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support;

/**
 * Rows in memory, queried the way a BerlinDB query function is: what each
 * call was asked is kept, so a test can say what a request became.
 */
final class Widgets {

	/**
	 * The rows, by id.
	 *
	 * @var array<int, Widget>
	 */
	public static array $rows = [];

	/**
	 * The arguments every list() and count() call was given.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $asked = [];

	/**
	 * How many times a row's own getter was asked.
	 *
	 * @var int
	 */
	public static int $getter_calls = 0;

	/**
	 * Start again.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$rows         = [];
		self::$asked        = [];
		self::$getter_calls = 0;
	}

	/**
	 * Add a row.
	 *
	 * @param array<string, mixed> $data Its columns.
	 *
	 * @return Widget
	 */
	public static function add( array $data = [] ): Widget {
		$row     = new Widget();
		$row->id = [] === self::$rows ? 1 : max( array_keys( self::$rows ) ) + 1;

		$row->date_created  = '2026-01-01 10:00:00';
		$row->date_modified = '2026-01-02 10:00:00';

		foreach ( $data as $column => $value ) {
			$row->{$column} = $value;
		}

		self::$rows[ $row->id ] = $row;

		return $row;
	}

	/**
	 * One row.
	 *
	 * @param int $id The id.
	 *
	 * @return Widget|null
	 */
	public static function get( int $id ): ?Widget {
		return self::$rows[ $id ] ?? null;
	}

	/**
	 * Rows, by query arguments.
	 *
	 * @param array<string, mixed> $args The arguments.
	 *
	 * @return Widget[]
	 */
	public static function list( array $args = [] ): array {
		self::$asked[] = $args;

		$rows = array_values( array_filter( self::$rows, static fn( Widget $row ): bool => self::matches( $row, $args ) ) );

		$number = (int) ( $args['number'] ?? 20 );
		$offset = (int) ( $args['offset'] ?? 0 );

		return 0 === $number ? $rows : array_slice( $rows, $offset, $number );
	}

	/**
	 * How many rows match.
	 *
	 * @param array<string, mixed> $args The arguments.
	 *
	 * @return int
	 */
	public static function count( array $args = [] ): int {
		self::$asked[] = $args;

		return count( array_filter( self::$rows, static fn( Widget $row ): bool => self::matches( $row, $args ) ) );
	}

	/**
	 * The one widget a query names exactly: `#3`, or its name.
	 *
	 * @param string $query What was typed.
	 *
	 * @return Widget|null
	 */
	public static function find( string $query ): ?Widget {
		if ( str_starts_with( $query, '#' ) ) {
			return self::get( (int) substr( $query, 1 ) );
		}

		foreach ( self::$rows as $row ) {
			if ( $row->name === $query ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Whether a row matches.
	 *
	 * @param Widget               $row  The row.
	 * @param array<string, mixed> $args The arguments.
	 *
	 * @return bool
	 */
	private static function matches( Widget $row, array $args ): bool {
		if ( isset( $args['id__in'] ) && ! in_array( $row->id, (array) $args['id__in'], true ) ) {
			return false;
		}

		if ( isset( $args['owner_id__in'] ) && ! in_array( $row->owner_id, (array) $args['owner_id__in'], true ) ) {
			return false;
		}

		if ( isset( $args['is_test'] ) && (int) $args['is_test'] !== $row->is_test ) {
			return false;
		}

		if ( isset( $args['status__in'] ) && ! in_array( $row->status, (array) $args['status__in'], true ) ) {
			return false;
		}

		if ( isset( $args['search'] ) && ! str_contains( $row->name, (string) $args['search'] ) ) {
			return false;
		}

		return true;
	}
}
