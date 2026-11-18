<?php
/**
 * One search box.
 *
 * @package     SugarCommerce\Rest\Controllers
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Controllers;

use SugarCommerce\Rest\Api;
use SugarCommerce\Rest\Controller;
use SugarCommerce\Rest\Schema;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET /search`: every searchable resource, for one query.
 *
 * What somebody types into an app's search box is a number, an email
 * address, a name, a title or a key, and they do not say which. So each
 * kind is asked in the way that finds it -- the exact thing first (the
 * resource's `find`), then its list's own search -- and the answer is
 * grouped by kind.
 *
 * Which kinds: every resource the API was given `search` for, an add-on's
 * included (Api::add_resource()), in the order they were given.
 *
 * @since 1.0.0
 */
final class Search extends Controller {

	/**
	 * The route, for an API.
	 *
	 * @param Api $api The API.
	 */
	public function __construct( Api $api ) {
		parent::__construct( $api );

		$this->area = $api->area();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$kinds      = $this->kit()->resources( 'search' );
		$type       = $this->kit()->object_type( 'search' );
		$properties = [ 'query' => Schema::string( __( 'What was looked for.', 'sugarcommerce' ) ) ];

		foreach ( $kinds as $kind => $args ) {
			$record      = $args['record'];
			$description = (string) ( $args['search']['description'] ?? '' );

			$properties[ $kind ] = Schema::list(
				Schema::object( $record::whole() ),
				'' !== $description ? $description : sprintf(
					/* translators: %s: the kind of record, plural. */
					__( 'Matching %s.', 'sugarcommerce' ),
					str_replace( [ '_', '-' ], ' ', $kind )
				)
			);
		}

		$this->route(
			'/search',
			[
				$this->handle(
					'GET',
					'search',
					'read',
					[
						'q'        => [
							'description' => $this->kit()->text( 'search_query' ),
							'type'        => 'string',
							'required'    => true,
							'minLength'   => 2,
						],
						'types'    => [
							'description' => $this->kit()->text( 'search_types' ),
							'type'        => 'array',
							'items'       => [
								'type' => 'string',
								'enum' => array_keys( $kinds ),
							],
							'default'     => array_keys( $kinds ),
						],
						'per_type' => [
							'description' => __( 'The most results of each kind.', 'sugarcommerce' ),
							'type'        => 'integer',
							'default'     => 5,
							'minimum'     => 1,
							'maximum'     => 20,
						],
					]
				),
			],
			static fn(): array => [
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => $type,
				'type'       => 'object',
				'properties' => $properties,
			]
		);
	}

	/**
	 * Look everywhere for one query.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		$query = trim( (string) $request['q'] );
		$most  = (int) $request['per_type'];
		$types = (array) $request['types'];
		$out   = [ 'query' => $query ];

		foreach ( $this->kit()->resources( 'search' ) as $kind => $args ) {
			if ( ! in_array( $kind, $types, true ) ) {
				continue;
			}

			$record = $args['record'];
			$find   = $args['search']['find'] ?? null;
			$list   = $args['search']['list'] ?? null;
			$adjust = $args['search']['args'] ?? null;
			$rows   = [];
			$exact  = is_callable( $find ) ? $find( $query ) : null;

			if ( is_object( $exact ) && ! is_wp_error( $exact ) ) {
				$rows[ (int) $exact->id ] = $exact;
			}

			// A resource with nothing to search but what its finder has
			// already tried -- a key -- has no list.
			if ( is_callable( $list ) ) {
				$query_args = [
					'search'  => $query,
					'number'  => $most,
					'orderby' => 'id',
					'order'   => 'DESC',
				];

				// The resource's own say: an email address, say, is a column
				// to match and not words to search for.
				if ( is_callable( $adjust ) ) {
					$query_args = (array) $adjust( $query_args, $query );
				}

				foreach ( (array) $list( $query_args ) as $row ) {
					if ( is_object( $row ) ) {
						$rows[ (int) $row->id ] ??= $row;
					}
				}
			}

			$out[ $kind ] = $record::shapes( array_slice( array_values( $rows ), 0, $most ) );
		}

		return self::done( $out );
	}
}
