<?php
/**
 * The idempotency keys table, as a sugarcommerce/data component describes it.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support\Keys;

use BerlinDB\Database\Kern\Schema as Base;

/**
 * The columns Stores\Table::schema() creates.
 */
final class Schema extends Base {

	/**
	 * The columns.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $columns = [
		[
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'sortable'  => true,
			'cache_key' => true,
			'extra'     => 'auto_increment',
		],
		[
			'name'      => 'key_hash',
			'type'      => 'char',
			'length'    => '64',
			'default'   => '',
			'cache_key' => true,
		],
		[
			'name'     => 'user_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => '0',
		],
		[
			'name'    => 'route',
			'type'    => 'varchar',
			'length'  => '200',
			'default' => '',
		],
		[
			'name'    => 'fingerprint',
			'type'    => 'char',
			'length'  => '64',
			'default' => '',
		],
		[
			'name'     => 'response_code',
			'type'     => 'smallint',
			'length'   => '6',
			'unsigned' => true,
			'default'  => '0',
		],
		[
			'name'    => 'response',
			'type'    => 'longtext',
			'default' => '',
		],
		[
			'name'       => 'date_expires',
			'type'       => 'datetime',
			'allow_null' => true,
			'default'    => null,
		],
		[
			'name'       => 'date_created',
			'type'       => 'datetime',
			'created'    => true,
			'date_query' => true,
			'sortable'   => true,
			'default'    => '',
		],
		[
			'name'       => 'date_modified',
			'type'       => 'datetime',
			'modified'   => true,
			'date_query' => true,
			'sortable'   => true,
			'default'    => '',
		],
	];
}
