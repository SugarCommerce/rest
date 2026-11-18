<?php
/**
 * The fixture API's widgets.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests\Support;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A controller the way a plugin writes one: a spec for the list and the
 * single record, and actions that answer through the base's helpers.
 */
final class WidgetsController extends AcmeController {

	/**
	 * How many times the refund action has actually run.
	 *
	 * @var int
	 */
	public static int $refunds = 0;

	/**
	 * What the next action answers with, when a test wants it to fail.
	 *
	 * @var WP_Error|null
	 */
	public static ?WP_Error $fail = null;

	protected string $area = 'widgets';

	protected string $resource = WidgetRecord::class;

	protected function spec(): array {
		return [
			'get'      => [ Widgets::class, 'get' ],
			'list'     => [ Widgets::class, 'list' ],
			'count'    => [ Widgets::class, 'count' ],
			'sortable' => [ 'name' ],
			'orderby'  => 'id',
			'order'    => 'asc',
			'search'   => true,
			'mode'     => true,
			'filters'  => [
				'status' => [
					'description' => 'Only widgets with these statuses.',
					'type'        => 'string',
					'enum'        => [ 'active', 'retired' ],
					'list'        => true,
				],
				'owner'  => [
					'column'      => 'owner_id',
					'description' => 'Only this owner’s widgets.',
					'type'        => 'integer',
				],
				'flag'   => [
					'description' => 'A yes or no.',
					'type'        => 'boolean',
				],
				'code'   => [
					'description' => 'Cleaned by its own function.',
					'type'        => 'string',
					'sanitize'    => 'strtoupper',
				],
			],
		];
	}

	public function register_routes(): void {
		$this->route( '/widgets', [ $this->handle( 'GET', 'get_items', 'read', $this->get_collection_params() ) ] );

		$this->route(
			'/widgets/' . self::id(),
			[ $this->handle( 'GET', 'get_item', 'read', self::id_arg() + [ 'expand' => self::expand_arg( WidgetRecord::class ) ] ) ]
		);

		$this->route(
			'/widgets/' . self::id() . '/refund',
			[
				$this->handle(
					'POST',
					'refund',
					'refund',
					self::id_arg() + [
						'amount' => [
							'description' => 'How much.',
							'type'        => 'integer',
							'required'    => true,
						],
					],
					true
				),
			],
			self::answers( 'refund', [ 'refunded' => [ 'type' => 'integer' ] ] )
		);

		$this->route( '/widgets/' . self::id() . '/rename', [ $this->handle( 'POST', 'rename', 'write', self::id_arg() ) ] );
	}

	/**
	 * Refund a widget: counts, so a test can see it ran once.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function refund( WP_REST_Request $request ) {
		$row = $this->record( $request );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( null !== self::$fail ) {
			return self::refused( self::$fail );
		}

		++self::$refunds;

		return self::done( [ 'refunded' => self::$refunds ], 201 );
	}

	/**
	 * An action that fails the way a plugin's function does.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rename( WP_REST_Request $request ) {
		$row = $this->record( $request );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		return null !== self::$fail ? self::refused( self::$fail ) : $this->shown( $request, $row );
	}
}
