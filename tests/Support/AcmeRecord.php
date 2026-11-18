<?php
/**
 * The fixture plugin's base record.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support;

use SugarCommerce\Rest\Record;

/**
 * What a plugin writes once: which API its records belong to.
 */
abstract class AcmeRecord extends Record {

	protected const API = 'acme';
}
