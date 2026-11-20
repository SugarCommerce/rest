<?php
/**
 * Refusals: not found, a wrong parameter, a conflict, and a function's own error under one of the API's codes.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Traits\Controller;

use SugarCommerce\Rest\Errors;
use WP_Error;

/**
 * Refusals: not found, a wrong parameter, a conflict, and a function's own error under one of the API's codes.
 *
 * @since 1.0.0
 */
trait Refusals {

	/**
	 * There is no such record.
	 *
	 * @param string $message Something more exact than the default.
	 *
	 * @return WP_Error
	 * @since 1.0.0
	 */
	protected static function not_found( string $message = '' ): WP_Error {
		return new WP_Error(
			static::api()->code( 'not_found' ),
			'' !== $message ? $message : __( 'There is no record with that id.', 'sugarcommerce' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * A parameter is wrong.
	 *
	 * @param string $param   Which one.
	 * @param string $message What is wrong with it.
	 * @param int    $status  400, or 422 when it is well-formed and cannot be acted on.
	 *
	 * @return WP_Error
	 * @since 1.0.0
	 */
	protected static function invalid( string $param, string $message, int $status = 400 ): WP_Error {
		return new WP_Error(
			static::api()->code( 'invalid_param' ),
			$message,
			[
				'status' => $status,
				'param'  => $param,
			]
		);
	}

	/**
	 * It cannot be done to this record as it stands.
	 *
	 * @param string $message Why not.
	 *
	 * @return WP_Error
	 * @since 1.0.0
	 */
	protected static function conflict( string $message ): WP_Error {
		return new WP_Error( static::api()->code( 'conflict' ), $message, [ 'status' => 409 ] );
	}

	/**
	 * An error from the function that did the work, under one of the API's codes.
	 *
	 * The plugin's functions answer with codes of their own, a new one for
	 * each thing that can go wrong, and a client cannot be expected to know
	 * them all. The message is kept -- it is the one a person can read --
	 * the original code is kept as `reason`, and the code a client switches
	 * on is one of seven, chosen by the status.
	 *
	 * @param WP_Error $error  What the function answered.
	 * @param int|null $status The status when the error names none. Left out, it
	 *                         is 409 -- what a plugin's functions refuse is nearly
	 *                         always "not to this record, not now" -- or 400 when
	 *                         the error names the field at fault.
	 *
	 * @return WP_Error
	 * @since 1.0.0
	 */
	protected static function refused( WP_Error $error, ?int $status = null ): WP_Error {
		return Errors::refused( static::api(), $error, $status );
	}
}
