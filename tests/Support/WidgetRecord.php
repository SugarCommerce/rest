<?php
/**
 * A widget, as the fixture API gives it.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support;

use SugarCommerce\Rest\Schema;

/**
 * A resource with a relation, a country and a column that must not leave.
 */
final class WidgetRecord extends AcmeRecord {

	/**
	 * What prime() fetched, by relation.
	 *
	 * @var array<string, array<int, object[]>>
	 */
	public static array $primed = [];

	/**
	 * How many times prime() ran.
	 *
	 * @var int
	 */
	public static int $primes = 0;

	public static function name(): string {
		return 'widget';
	}

	public static function base(): string {
		return 'widgets';
	}

	public static function properties(): array {
		return [
			'id'      => Schema::integer( 'The id.' ),
			'name'    => Schema::string( 'The name.' ),
			'country' => self::country_schema(),
			'owner'   => Schema::object( [], 'The owner, when expanded.', true ),
		] + Schema::status( [ 'active', 'retired' ] ) + Schema::stamps();
	}

	public static function expandable(): array {
		return [ 'owner' ];
	}

	public static function prime( array $rows, array $expand = [] ): void {
		++self::$primes;

		if ( in_array( 'owner', $expand, true ) ) {
			self::$primed['owner'] = self::fetch( [ Widgets::class, 'list' ], 'id', self::pluck( $rows, 'owner_id' ) );
		}
	}

	public static function forget(): void {
		self::$primed = [];
	}

	protected static function fields( object $row ): array {
		return [
			'id'            => (int) $row->id,
			'name'          => (string) $row->name,
			'country'       => self::country( (string) $row->country ),
			'status'        => (string) $row->status,
			'status_label'  => ucfirst( (string) $row->status ),
			'date_created'  => self::api()->date( $row->date_created ),
			'date_modified' => self::api()->date( $row->date_modified ),
		];
	}

	protected static function expanded( object $row, array $expand ): array {
		return in_array( 'owner', $expand, true ) ? [ 'owner' => self::related_one( self::$primed, $row, 'owner', self::class ) ] : [];
	}

	protected static function related( object $row ): array {
		return $row->owner_id ? [ 'owner' => 'widgets/' . (int) $row->owner_id ] : [];
	}
}
