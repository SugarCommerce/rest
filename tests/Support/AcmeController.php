<?php
/**
 * The fixture plugin's base controller.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support;

use SugarCommerce\Rest\Controller;

/**
 * What a plugin writes once: which API its controllers belong to.
 */
abstract class AcmeController extends Controller {

	protected const API = 'acme';
}
