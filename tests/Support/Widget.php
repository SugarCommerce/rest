<?php
/**
 * A row of the fixture plugin's widgets table.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support;

/**
 * A widget: what a BerlinDB row looks like to a Record.
 */
final class Widget {

	public int $id = 0;

	public string $name = '';

	public string $status = 'active';

	public string $secret = 'never-leaves';

	public int $is_test = 0;

	public int $owner_id = 0;

	public string $country = '';

	public string $date_created = '';

	public string $date_modified = '';

	/**
	 * Its owner, asked of the "database".
	 *
	 * @return Widget|null
	 */
	public function get_owner(): ?Widget {
		++Widgets::$getter_calls;

		return Widgets::get( $this->owner_id );
	}
}
