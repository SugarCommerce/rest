<?php
/**
 * What a list takes, and how that becomes a query.
 *
 * @package     SugarCommerce\Rest\Concerns
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Concerns;

use SugarCommerce\Dates\Timestamp;
use SugarCommerce\Rest\Record;
use WP_REST_Request;

/**
 * The list parameters, for Controller.
 *
 * Every list in the API takes the same things -- `page`, `per_page`,
 * `orderby`, `order`, `include`, `exclude`, the three date filters, and
 * where the table has them `search`, `mode` and `expand` -- and each adds
 * its own filters by naming columns in spec(). This is where those are
 * declared, so `OPTIONS` and the OpenAPI document describe them, and where a
 * request that passed that declaration is turned into the arguments a
 * BerlinDB-style query function takes.
 *
 * Apart from Controller only so that neither file is the length of both.
 *
 * @since 1.0.0
 */
trait Lists {

	/**
	 * What every list can be sorted by, whatever else its spec() allows.
	 *
	 * @since 1.0.0
	 */
	private const ALWAYS_SORTABLE = [ 'id', 'date_created', 'date_modified' ];

	/**
	 * The parameters a list takes.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	public function get_collection_params() {
		$spec     = $this->spec();
		$resource = $this->resource;
		$own      = (array) ( $spec['sortable'] ?? [] );
		$sortable = array_values( array_unique( array_merge( self::ALWAYS_SORTABLE, $own ) ) );

		$params = [
			'page'          => [
				'description' => __( 'Which page of the list.', 'sugarcommerce' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			],
			'per_page'      => [
				'description' => __( 'How many records a page.', 'sugarcommerce' ),
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 100,
			],
			'orderby'       => [
				'description' => __( 'The column to sort by.', 'sugarcommerce' ),
				'type'        => 'string',
				'default'     => (string) ( $spec['orderby'] ?? 'date_created' ),
				'enum'        => $sortable,
			],
			'order'         => [
				'description' => __( 'Ascending or descending.', 'sugarcommerce' ),
				'type'        => 'string',
				'default'     => (string) ( $spec['order'] ?? 'desc' ),
				'enum'        => [ 'asc', 'desc' ],
			],
			'include'       => [
				'description' => __( 'Only the records with these ids.', 'sugarcommerce' ),
				'type'        => 'array',
				'items'       => [ 'type' => 'integer' ],
				'default'     => [],
			],
			'exclude'       => [
				'description' => __( 'Every record but the ones with these ids.', 'sugarcommerce' ),
				'type'        => 'array',
				'items'       => [ 'type' => 'integer' ],
				'default'     => [],
			],
			'after'         => [
				'description' => __( 'Only records made after this moment (ISO 8601).', 'sugarcommerce' ),
				'type'        => 'string',
				'format'      => 'date-time',
			],
			'before'        => [
				'description' => __( 'Only records made before this moment (ISO 8601).', 'sugarcommerce' ),
				'type'        => 'string',
				'format'      => 'date-time',
			],
			'updated_after' => [
				'description' => __( 'Only records changed after this moment (ISO 8601). For an app keeping a copy up to date.', 'sugarcommerce' ),
				'type'        => 'string',
				'format'      => 'date-time',
			],
		];

		if ( ! empty( $spec['search'] ) ) {
			$params['search'] = [
				'description' => __( 'Only records matching these words.', 'sugarcommerce' ),
				'type'        => 'string',
				'default'     => '',
			];
		}

		if ( ! empty( $spec['mode'] ) ) {
			$params['mode'] = [
				'description' => $this->kit()->text( 'mode' ),
				'type'        => 'string',
				'enum'        => [ 'test', 'live', 'all' ],
			];
		}

		if ( '' !== $resource && [] !== $resource::expandable() ) {
			$params['expand'] = self::expand_arg( $resource );
		}

		foreach ( (array) ( $spec['filters'] ?? [] ) as $name => $filter ) {
			$param = [
				'description' => (string) ( $filter['description'] ?? '' ),
				'type'        => (string) ( $filter['type'] ?? 'string' ),
			];

			if ( ! empty( $filter['list'] ) ) {
				$param = [
					'description' => $param['description'],
					'type'        => 'array',
					'items'       => array_filter(
						[
							'type' => $param['type'],
							'enum' => $filter['enum'] ?? null,
						]
					),
				];
			} elseif ( isset( $filter['enum'] ) ) {
				$param['enum'] = $filter['enum'];
			}

			$params[ $name ] = $param;
		}

		return $params;
	}

	/**
	 * The `expand` argument, for a resource.
	 *
	 * @param class-string<Record> $resource The serializer.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	protected static function expand_arg( string $resource ): array {
		return [
			'description' => sprintf(
				/* translators: %s: a list of names. */
				__( 'Related records to include in full, comma-separated. Any of: %s.', 'sugarcommerce' ),
				implode( ', ', $resource::expandable() )
			),
			'type'        => 'array',
			'items'       => [
				'type' => 'string',
				'enum' => $resource::expandable(),
			],
			'default'     => [],
		];
	}

	/**
	 * A list request as the arguments a query function takes.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	protected function query( WP_REST_Request $request ): array {
		$spec = $this->spec();
		$per  = (int) $request['per_page'];
		$args = [
			'number'  => $per,
			'offset'  => ( max( 1, (int) $request['page'] ) - 1 ) * $per,
			'orderby' => (string) $request['orderby'],
			'order'   => strtoupper( (string) $request['order'] ),
		];

		if ( ! empty( $spec['search'] ) && '' !== trim( (string) $request['search'] ) ) {
			$args['search'] = trim( (string) $request['search'] );
		}

		if ( [] !== (array) $request['include'] ) {
			$args['id__in'] = array_map( 'intval', (array) $request['include'] );
		}

		if ( [] !== (array) $request['exclude'] ) {
			$args['id__not_in'] = array_map( 'intval', (array) $request['exclude'] );
		}

		$created = array_filter(
			[
				'after'  => self::moment( $request['after'] ),
				'before' => self::moment( $request['before'] ),
			]
		);

		if ( [] !== $created ) {
			$args['date_created_query'] = $created;
		}

		if ( '' !== self::moment( $request['updated_after'] ) ) {
			$args['date_modified_query'] = [ 'after' => self::moment( $request['updated_after'] ) ];
		}

		if ( ! empty( $spec['mode'] ) ) {
			$mode   = (string) ( $request['mode'] ?? '' );
			$column = is_string( $spec['mode'] ) ? $spec['mode'] : 'is_test';

			// Asking for records by id is asking for those records,
			// whichever set of books they are in.
			if ( 'all' !== $mode && ! isset( $args['id__in'] ) ) {
				$test = '' === $mode ? $this->kit()->is_test() : 'test' === $mode;

				$args[ $column ] = $test ? 1 : 0;
			}
		}

		foreach ( (array) ( $spec['filters'] ?? [] ) as $name => $filter ) {
			$value = $request[ $name ];

			if ( null === $value || '' === $value || [] === $value ) {
				continue;
			}

			$column = (string) ( $filter['column'] ?? $name );
			$clean  = static function ( $one ) use ( $filter ) {
				if ( isset( $filter['sanitize'] ) && is_callable( $filter['sanitize'] ) ) {
					return $filter['sanitize']( $one );
				}

				return match ( $filter['type'] ?? 'string' ) {
					'integer' => (int) $one,
					'boolean' => rest_sanitize_boolean( $one ) ? 1 : 0,
					default   => sanitize_text_field( (string) $one ),
				};
			};

			if ( ! empty( $filter['list'] ) ) {
				$args[ $column . '__in' ] = array_values( array_map( $clean, (array) $value ) );
			} else {
				$args[ $column ] = $clean( $value );
			}
		}

		return $args;
	}

	/**
	 * A moment from a request as the database stores one.
	 *
	 * @param mixed $value ISO 8601, with or without an offset.
	 *
	 * @return string `Y-m-d H:i:s` in UTC, or empty.
	 * @since 1.0.0
	 */
	protected static function moment( $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		$time = rest_parse_date( $value );

		return false === $time ? '' : Timestamp::at( (int) $time );
	}
}
