<?php
/**
 * SugarCart's customer, as the snapshot describes it.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support\SugarCart;

/**
 * The customer resource.
 */
final class Customer extends Record {

	public static function name(): string {
		return 'customer';
	}
}
