<?php
/**
 * A SugarCart resource, as the snapshot describes it.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support\SugarCart;

/**
 * Its properties are the ones /search's schema nested in the snapshot.
 */
abstract class Record extends \SugarCommerce\Rest\Record {

	protected const API = 'sugarcart';

	public static function base(): string {
		return static::name() . 's';
	}

	public static function properties(): array {
		$nested = Snapshot::route( '/search' )['schema']['properties'][ static::base() ]['items']['properties'];

		unset( $nested['_links'] );

		return $nested;
	}

	protected static function fields( object $row ): array {
		return [];
	}
}
