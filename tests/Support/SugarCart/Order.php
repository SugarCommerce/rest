<?php
/**
 * SugarCart's order, as the snapshot describes it.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support\SugarCart;

/**
 * The order resource.
 */
final class Order extends Record {

	public static function name(): string {
		return 'order';
	}
}
