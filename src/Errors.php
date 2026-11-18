<?php
/**
 * The API's stable error codes.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest;

use WP_Error;

/**
 * Seven codes a client switches on, whatever went wrong underneath.
 *
 *     {prefix}_unauthorized   401  nobody is signed in
 *     {prefix}_forbidden      403  they are, and may not
 *     {prefix}_not_found      404  there is no such record
 *     {prefix}_invalid_param  400  a parameter is wrong (422: well-formed, cannot be acted on)
 *     {prefix}_conflict       409  not to this record, not now
 *     {prefix}_rate_limited   429  too many, too fast
 *     {prefix}_failed         5xx  the site, or something it called, failed
 *
 * @since 1.0.0
 */
final class Errors {

	/**
	 * The codes, in the order the OpenAPI document lists them.
	 *
	 * @since 1.0.0
	 */
	public const CODES = [ 'unauthorized', 'forbidden', 'not_found', 'invalid_param', 'conflict', 'rate_limited', 'failed' ];

	/**
	 * The codes an error already under is passed on as it is.
	 *
	 * @since 1.0.0
	 */
	private const PASSED_ON = [ 'not_found', 'forbidden', 'invalid_param', 'conflict', 'rate_limited', 'unauthorized' ];

	/**
	 * An error from the function that did the work, under one of the API's codes.
	 *
	 * The message is kept -- it is the one a person can read -- the original
	 * code is kept as `reason`, and the code is chosen by the status.
	 *
	 * @param Api      $api    The API.
	 * @param WP_Error $error  What the function answered.
	 * @param int|null $status The status when the error names none. Left out, it
	 *                         is 409, or 400 when the error names the field at fault.
	 *
	 * @return WP_Error
	 * @since 1.0.0
	 */
	public static function refused( Api $api, WP_Error $error, ?int $status = null ): WP_Error {
		$data = (array) $error->get_error_data();
		$code = (string) $error->get_error_code();

		if ( in_array( $code, array_map( [ $api, 'code' ], self::PASSED_ON ), true ) ) {
			return $error;
		}

		// A function that names the field at fault and no status is saying
		// the input was wrong, not the record.
		$assumed = isset( $data['field'] ) ? 400 : 409;
		$status  = (int) ( $data['status'] ?? $status ?? $assumed );

		$as = match ( true ) {
			404 === $status                         => 'not_found',
			401 === $status                         => 'unauthorized',
			403 === $status                         => 'forbidden',
			429 === $status                         => 'rate_limited',
			in_array( $status, [ 400, 422 ], true ) => 'invalid_param',
			$status >= 500                          => 'failed',
			default                                 => 'conflict',
		};

		$out = [
			'status' => $status,
			'reason' => $code,
		];

		if ( 'invalid_param' === $as ) {
			$out['param'] = (string) ( $data['field'] ?? $data['param'] ?? '' );
		}

		return new WP_Error( $api->code( $as ), $error->get_error_message(), $out );
	}
}
