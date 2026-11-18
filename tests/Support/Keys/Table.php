<?php
/**
 * The idempotency keys table, registered with BerlinDB.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support\Keys;

use BerlinDB\Database\Kern\Table as Base;

/**
 * Made once, it tells $wpdb the table's name, which the query reads.
 */
final class Table extends Base {

	protected $name = 'idempotency_keys';

	protected $prefix = 'acme';

	protected $schema = Schema::class;

	protected $version = 1;

	protected $upgrades = [];
}
