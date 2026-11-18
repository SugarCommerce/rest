<?php
/**
 * The pieces every resource's schema is made of.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest;

/**
 * JSON Schema for the shapes that repeat.
 *
 * Money, a date, an id, a status: said once here so that every resource
 * says them the same way, and so the OpenAPI document can name them once
 * instead of spelling each out four hundred times.
 *
 * Static, so a Record's properties() can say `Schema::money()` without
 * being handed anything. A plugin that words something differently extends
 * this and overrides the word: money_formatted(), for one.
 *
 * @since 1.0.0
 */
class Schema {

	/**
	 * An amount of money.
	 *
	 * @param string $description What the amount is.
	 * @param bool   $nullable    Whether there may be none.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function money( string $description = '', bool $nullable = false ): array {
		return [
			'description' => $description,
			'type'        => $nullable ? [ 'object', 'null' ] : 'object',
			'readonly'    => true,
			'properties'  => [
				'amount'    => [
					'description' => __( 'The amount in the smallest unit of the currency: 4900 is 49.00.', 'sugarcommerce' ),
					'type'        => 'integer',
				],
				'currency'  => [
					'description' => __( 'ISO 4217 currency code, in capitals.', 'sugarcommerce' ),
					'type'        => 'string',
				],
				'formatted' => [
					'description' => static::money_formatted(),
					'type'        => 'string',
				],
			],
		];
	}

	/**
	 * What the `formatted` part of an amount of money is said to be.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected static function money_formatted(): string {
		return __( 'The amount written out as the site writes money.', 'sugarcommerce' );
	}

	/**
	 * A moment, in UTC.
	 *
	 * @param string $description What happened then.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function date( string $description = '' ): array {
		return [
			'description' => $description,
			'type'        => [ 'string', 'null' ],
			'format'      => 'date-time',
			'readonly'    => true,
		];
	}

	/**
	 * A whole number.
	 *
	 * @param string $description What it counts or points at.
	 * @param bool   $nullable    Whether there may be none.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function integer( string $description = '', bool $nullable = false ): array {
		return [
			'description' => $description,
			'type'        => $nullable ? [ 'integer', 'null' ] : 'integer',
			'readonly'    => true,
		];
	}

	/**
	 * A number that may have a fraction: a rate, a share.
	 *
	 * @param string $description What it measures.
	 * @param bool   $nullable    Whether there may be none.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function number( string $description = '', bool $nullable = false ): array {
		return [
			'description' => $description,
			'type'        => $nullable ? [ 'number', 'null' ] : 'number',
			'readonly'    => true,
		];
	}

	/**
	 * Some text.
	 *
	 * @param string   $description What it says.
	 * @param string[] $options     The values it can take, when they are known.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function string( string $description = '', array $options = [] ): array {
		$schema = [
			'description' => $description,
			'type'        => 'string',
			'readonly'    => true,
		];

		if ( [] !== $options ) {
			$schema['enum'] = array_values( $options );
		}

		return $schema;
	}

	/**
	 * Text that may be absent.
	 *
	 * @param string $description What it says.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function nullable_string( string $description = '' ): array {
		return [
			'description' => $description,
			'type'        => [ 'string', 'null' ],
			'readonly'    => true,
		];
	}

	/**
	 * Yes or no.
	 *
	 * @param string $description What is true when it is.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function boolean( string $description = '' ): array {
		return [
			'description' => $description,
			'type'        => 'boolean',
			'readonly'    => true,
		];
	}

	/**
	 * A list of one thing.
	 *
	 * @param array<string, mixed> $items       The schema of each.
	 * @param string               $description What the list is.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function list( array $items, string $description = '' ): array {
		return [
			'description' => $description,
			'type'        => 'array',
			'readonly'    => true,
			'items'       => $items,
		];
	}

	/**
	 * A thing with named parts.
	 *
	 * @param array<string, array<string, mixed>> $properties  Its parts.
	 * @param string                              $description What it is.
	 * @param bool                                $nullable    Whether there may be none.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function object( array $properties, string $description = '', bool $nullable = false ): array {
		return [
			'description' => $description,
			'type'        => $nullable ? [ 'object', 'null' ] : 'object',
			'readonly'    => true,
			'properties'  => $properties,
		];
	}

	/**
	 * A thing whose parts are not known ahead of time.
	 *
	 * For what the plugin does not own the shape of: a report component's
	 * figures, which an add-on can register in any shape it likes.
	 *
	 * @param string $description What it is.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function open( string $description = '' ): array {
		return [
			'description'          => $description,
			'type'                 => [ 'object', 'array', 'null' ],
			'readonly'             => true,
			'additionalProperties' => true,
		];
	}

	/**
	 * A record's links, as WordPress writes them.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function links(): array {
		return [
			'description'          => __( 'Where this record and the ones it points at can be fetched. Each is a list of objects with an href.', 'sugarcommerce' ),
			'type'                 => 'object',
			'readonly'             => true,
			'additionalProperties' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'href' => [
							'type'   => 'string',
							'format' => 'uri',
						],
					],
				],
			],
		];
	}

	/**
	 * A status and the words for it.
	 *
	 * Two properties, to be spread into a resource's own.
	 *
	 * @param string[] $statuses The statuses this kind of record can have. Not an
	 *                           enum: an add-on can add one.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	public static function status( array $statuses = [] ): array {
		return [
			'status'       => self::string(
				[] === $statuses
					? __( 'The status, as a key.', 'sugarcommerce' )
					: sprintf(
						/* translators: %s: a list of statuses. */
						__( 'The status, as a key. One of %s, or one an add-on has added.', 'sugarcommerce' ),
						implode( ', ', $statuses )
					)
			),
			'status_label' => self::string( __( 'The status in words, in the site’s language.', 'sugarcommerce' ) ),
		];
	}

	/**
	 * The two dates every record has.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	public static function stamps(): array {
		return [
			'date_created'  => self::date( __( 'When the record was made.', 'sugarcommerce' ) ),
			'date_modified' => self::date( __( 'When the record last changed. What `updated_after` and `/changes` compare.', 'sugarcommerce' ) ),
		];
	}
}
