<?php
/**
 * One idempotency key.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support\Keys;

use BerlinDB\Database\Kern\Row as Base;

/**
 * A row.
 */
final class Row extends Base {

	public $id;

	public $key_hash;

	public $user_id;

	public $route;

	public $fingerprint;

	public $response_code;

	public $response;

	public $date_expires;

	public $date_created;

	public $date_modified;
}
