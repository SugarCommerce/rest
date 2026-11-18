<?php
/**
 * The idempotency keys' query class, as a plugin built on sugarcommerce/data has one.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support\Keys;

use SugarCommerce\Data\Query as Base;

/**
 * A real sugarcommerce/data query over `{prefix}acme_idempotency_keys`, which
 * keeps a note of each claim() and sweep() it is asked for.
 */
final class Query extends Base {

	/**
	 * Every claim() and sweep(), in order.
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	public static array $calls = [];

	/**
	 * Register the table before the query reads its name.
	 *
	 * @param array<string, mixed> $query Query variables.
	 */
	public function __construct( $query = [] ) {
		static $table = null;

		$table ??= new Table();

		parent::__construct( $query );
	}

	protected $table_name = 'idempotency_keys';

	protected $prefix = 'acme';

	protected $table_alias = 'ik';

	protected $table_schema = Schema::class;

	protected $item_name = 'idempotency_key';

	protected $item_name_plural = 'idempotency_keys';

	protected $item_shape = Row::class;

	protected $cache_group = 'acme_idempotency_keys';

	public function claim( array $data, ?callable $insert = null ): ?int {
		self::$calls[] = [ 'claim', $data ];

		return parent::claim( $data, $insert );
	}

	public function sweep( string $column, string $before, array $where = [], array $except = [], int $limit = 1000, bool $dry = false ): int {
		self::$calls[] = [ 'sweep', [ $column, $before, $limit ] ];

		return parent::sweep( $column, $before, $where, $except, $limit, $dry );
	}
}
