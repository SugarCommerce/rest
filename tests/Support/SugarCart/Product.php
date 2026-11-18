<?php
/**
 * SugarCart's product, as the snapshot describes it.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support\SugarCart;

/**
 * The product resource.
 */
final class Product extends Record {

	public static function name(): string {
		return 'product';
	}
}
