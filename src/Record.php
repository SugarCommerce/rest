<?php
/**
 * What every resource's public shape has in common.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest;

use LogicException;

/**
 * A row, as the outside world is given it.
 *
 * One class a resource, and it does one job: turn a row object into the
 * array that leaves the building. It knows nothing about requests, so
 * anything can use it -- a REST controller, an ability, a webhook payload --
 * and they all describe a record the same way.
 *
 * The shape is an allow-list. fields() names every key that goes out and
 * where it comes from; a column that is not named there does not leave,
 * which is the only safe default for a table that also holds token hashes
 * and fingerprints. Adding a column to a table never adds it to the API.
 *
 * Related records are not included unless asked for. `expand=customer,items`
 * on a request names the ones wanted; expandable() is the list a resource
 * allows, and prime() fetches each relation for a whole page in one query
 * before any row is shaped, so a page of thirty costs what a page of three
 * does.
 *
 * Which API a record belongs to is said once, by the plugin's base class:
 *
 *     abstract class Record extends \SugarCommerce\Rest\Record {
 *         protected const API = 'myshop';
 *     }
 *
 * @since 1.0.0
 */
abstract class Record {

	/**
	 * The id of the API this record belongs to.
	 *
	 * @since 1.0.0
	 */
	protected const API = '';

	/**
	 * The API this record belongs to.
	 *
	 * @return Api
	 * @throws LogicException When the class names no registered API.
	 * @since 1.0.0
	 */
	public static function api(): Api {
		$api = Api::get( static::API );

		if ( null === $api ) {
			throw new LogicException( sprintf( '%s names no registered API: set its API constant, and register the API first.', static::class ) );
		}

		return $api;
	}

	/**
	 * What the resource is called, singular: `order`.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	abstract public static function name(): string;

	/**
	 * Where its routes are, below the namespace: `orders`.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	abstract public static function base(): string;

	/**
	 * The JSON Schema of every field fields() can produce.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	abstract public static function properties(): array;

	/**
	 * The row as an array: the allow-list.
	 *
	 * @param object $row The row.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	abstract protected static function fields( object $row ): array;

	/**
	 * The related records `expand` may ask for.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	public static function expandable(): array {
		return [];
	}

	/**
	 * Fetch what a page of rows is about to need, a query a relation.
	 *
	 * @param object[] $rows   The rows.
	 * @param string[] $expand The relations asked for.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function prime( array $rows, array $expand = [] ): void {}

	/**
	 * Drop whatever prime() fetched.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function forget(): void {}

	/**
	 * The related records asked for, for one row.
	 *
	 * @param object   $row    The row.
	 * @param string[] $expand The relations asked for, already checked against expandable().
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	protected static function expanded( object $row, array $expand ): array {
		return [];
	}

	/**
	 * The other records this one points at, as routes below the namespace.
	 *
	 * @param object $row The row.
	 *
	 * @return array<string, string> Relation => path, such as `customer` => `customers/4`.
	 * @since 1.0.0
	 */
	protected static function related( object $row ): array {
		return [];
	}

	/**
	 * One row, in its public shape.
	 *
	 * @param object   $row    The row.
	 * @param string[] $expand Related records to include.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	final public static function shape( object $row, array $expand = [] ): array {
		$api    = static::api();
		$expand = array_values( array_intersect( $expand, static::expandable() ) );
		$data   = static::fields( $row ) + static::expanded( $row, $expand );

		$links = [
			'self'       => [ [ 'href' => $api->url( static::base() . '/' . (int) $row->id ) ] ],
			'collection' => [ [ 'href' => $api->url( static::base() ) ] ],
		];

		foreach ( static::related( $row ) as $relation => $path ) {
			$links[ $relation ] = [ [ 'href' => $api->url( $path ) ] ];
		}

		$data['_links'] = $links;

		/**
		 * Filter a record as the API gives it.
		 *
		 * `{prefix}_rest_prepare_order`, `{prefix}_rest_prepare_customer`
		 * and so on, one a resource. To add a field with a schema and a way
		 * of writing it, register_rest_field() with the API's object_type()
		 * is the better tool; this is for changing or removing what is
		 * already there.
		 *
		 * @param array<string, mixed> $data   The record.
		 * @param object               $row    The row it was made from.
		 * @param string[]             $expand The related records included.
		 *
		 * @since 1.0.0
		 */
		return (array) apply_filters( $api->hook( 'prepare_' . static::name() ), $data, $row, $expand );
	}

	/**
	 * A page of rows, in their public shape.
	 *
	 * @param object[] $rows   The rows.
	 * @param string[] $expand Related records to include.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	final public static function shapes( array $rows, array $expand = [] ): array {
		$rows   = array_values( array_filter( $rows, 'is_object' ) );
		$expand = array_values( array_intersect( $expand, static::expandable() ) );

		if ( [] === $rows ) {
			return [];
		}

		static::prime( $rows, $expand );

		$shaped = array_map( static fn( object $row ): array => static::shape( $row, $expand ), $rows );

		// What was fetched was for this page. Kept, it would answer for a
		// record that has changed since -- later in this request, or in the
		// next one where PHP is long-lived.
		static::forget();

		return $shaped;
	}

	/**
	 * Every property a shaped record has: its own, and its links.
	 *
	 * What another resource's schema uses to describe one of these nested
	 * inside it.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	final public static function whole(): array {
		return static::properties() + [ '_links' => Schema::links() ];
	}

	/**
	 * The resource's whole schema.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	final public static function schema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => static::api()->object_type( static::name() ),
			'type'       => 'object',
			'properties' => static::whole(),
		];
	}

	/**
	 * Every row with one of these ids, with one query, grouped.
	 *
	 * What prime() is made of. Asking is also what fills the object cache
	 * the row getters read, so `$order->get_customer()` afterwards costs
	 * nothing.
	 *
	 * @param callable|string      $fetch  The query function for the related table: it takes
	 *                                     `{column}__in` and `number`.
	 * @param string               $column The column on that table to match: `id`, or a foreign key.
	 * @param int[]                $ids    The ids to match.
	 * @param array<string, mixed> $args   Anything else the query needs.
	 *
	 * @return array<int, object[]> Rows grouped by the value of `$column`.
	 * @since 1.0.0
	 */
	final protected static function fetch( callable|string $fetch, string $column, array $ids, array $args = [] ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( [] === $ids || ! is_callable( $fetch ) ) {
			return [];
		}

		$grouped = [];

		foreach ( (array) $fetch(
			[
				$column . '__in' => $ids,
				'number'         => 0,
			] + $args
		) as $row ) {
			$grouped[ (int) $row->{$column} ][] = $row;
		}

		return $grouped;
	}

	/**
	 * A related row in its own public shape, or null when there is none.
	 *
	 * @param object|null          $row      The related row.
	 * @param class-string<Record> $resource Its serializer.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.0.0
	 */
	final protected static function one( ?object $row, string $resource ): ?array {
		return $row ? $resource::shape( $row ) : null;
	}

	/**
	 * A row's one related record, in that record's public shape.
	 *
	 * For a relation named after its column and its getter: `customer` is
	 * `customer_id` and `get_customer()`. Read from what prime() fetched,
	 * and asked of the row when it was not there: see found().
	 *
	 * @param array<string, array<int, object[]>> $primed   What prime() fetched, by relation.
	 * @param object                              $row      The row.
	 * @param string                              $relation The relation: `customer`, `product`…
	 * @param class-string<Record>                $resource The related record's serializer.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.0.0
	 */
	final protected static function related_one( array $primed, object $row, string $relation, string $resource ): ?array {
		$id      = (int) $row->{$relation . '_id'};
		$getter  = 'get_' . $relation;
		$related = self::found( $primed[ $relation ] ?? [], $id ) ?? $row->{$getter}();

		return self::one( $related, $resource );
	}

	/**
	 * The one row fetch() found for an id, if it found one.
	 *
	 * A related row is read from what prime() fetched before the row's own
	 * getter is asked. The getter is right and is the fallback; it is also
	 * a few milliseconds a call even when the answer is cached, which over
	 * a page of thirty is most of the request.
	 *
	 * @param array<int, object[]> $found What fetch() returned.
	 * @param int                  $id    The id wanted.
	 *
	 * @return object|null
	 * @since 1.0.0
	 */
	final protected static function found( array $found, int $id ): ?object {
		return $found[ $id ][0] ?? null;
	}

	/**
	 * One column's values across a page of rows.
	 *
	 * @param object[] $rows   The rows.
	 * @param string   $column The column.
	 *
	 * @return int[]
	 * @since 1.0.0
	 */
	final protected static function pluck( array $rows, string $column ): array {
		return array_map( static fn( object $row ): int => (int) ( $row->{$column} ?? 0 ), $rows );
	}

	/**
	 * A country code with its name.
	 *
	 * @param string $code ISO 3166 alpha-2.
	 *
	 * @return array{code: string, name: string}|null
	 * @since 1.0.0
	 */
	final protected static function country( string $code ): ?array {
		$code = strtoupper( trim( $code ) );

		return '' === $code ? null : [
			'code' => $code,
			'name' => static::api()->country_name( $code ),
		];
	}

	/**
	 * The schema of a country.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	final protected static function country_schema(): array {
		return Schema::object(
			[
				'code' => Schema::string( __( 'ISO 3166 two-letter code.', 'sugarcommerce' ) ),
				'name' => Schema::string( __( 'The country’s name, in the site’s language.', 'sugarcommerce' ) ),
			],
			__( 'The country, or null when it is not known.', 'sugarcommerce' ),
			true
		);
	}
}
