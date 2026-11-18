<?php
/**
 * A controller whose spec a test sets.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support\SugarCart;

use SugarCommerce\Rest\Controller;

/**
 * For rebuilding a list route's parameters from the snapshot.
 */
final class ProbeController extends Controller {

	protected const API = 'sugarcart';

	/**
	 * The spec.
	 *
	 * @param array<string, mixed> $spec     The spec.
	 * @param bool                 $expands  Whether the resource has expansions.
	 */
	public function __construct( private array $probe, bool $expands ) {
		parent::__construct();

		$this->resource = $expands ? ProbeRecord::class : '';
	}

	protected function spec(): array {
		return $this->probe;
	}

	public function register_routes(): void {}
}
