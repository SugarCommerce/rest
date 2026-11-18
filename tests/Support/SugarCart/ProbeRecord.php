<?php
/**
 * A record whose expansions a test sets.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support\SugarCart;

/**
 * For rebuilding a list route's parameters from the snapshot.
 */
final class ProbeRecord extends Record {

	/**
	 * What `expand` may name.
	 *
	 * @var string[]
	 */
	public static array $expandable = [];

	public static function name(): string {
		return 'probe';
	}

	public static function expandable(): array {
		return self::$expandable;
	}
}
