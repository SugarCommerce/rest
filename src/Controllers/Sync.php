<?php
/**
 * What has changed, for an app keeping a copy.
 *
 * @package     SugarCommerce\Rest\Controllers
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Controllers;

use SugarCommerce\Rest\Api;
use SugarCommerce\Dates\Timestamp;
use SugarCommerce\Rest\Controller;
use SugarCommerce\Rest\Schema;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET /changes?since=…`: the ids that changed, and nothing else.
 *
 * An app that keeps a copy of the site's records does not want every list
 * again each time it is opened. It keeps the `cursor` from its last look,
 * sends it back as `since`, and is told which records of each kind have
 * changed or gone; it fetches those -- `?include=1,2,3` on the list -- and
 * keeps the new cursor.
 *
 * Which kinds: every resource the API was given a `table` for, an add-on's
 * included (Api::add_resource()). Changed is `date_modified`, which every
 * write stamps and which must be indexed on every table this reads. The
 * cursor handed back is the moment the question was asked, taken before
 * the tables are read, so a record written while this runs is in the next
 * answer and never in neither.
 *
 * @since 1.0.0
 */
final class Sync extends Controller {

	/**
	 * The most ids of one kind in one answer.
	 *
	 * @since 1.0.0
	 */
	public const int MOST = 500;

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
	#[\Override]
	public function register_routes(): void {
		$kinds = array_keys( $this->kit()->resources( 'changes' ) );
		$type  = $this->kit()->object_type( 'changes' );
		$ids   = [
			'description'          => __( 'Ids, a list for each kind of record.', 'sugarcommerce' ),
			'type'                 => 'object',
			'readonly'             => true,
			'additionalProperties' => [
				'type'  => 'array',
				'items' => [ 'type' => 'integer' ],
			],
		];

		$properties = [
			'since'     => Schema::date( __( 'The moment asked about.', 'sugarcommerce' ) ),
			'cursor'    => Schema::date( __( 'What to send as `since` next time.', 'sugarcommerce' ) ),
			'changed'   => $ids + [ 'description' => __( 'Records made or changed since then, oldest change first.', 'sugarcommerce' ) ],
			'deleted'   => $ids + [ 'description' => __( 'Records deleted since then.', 'sugarcommerce' ) ],
			'truncated' => Schema::list( Schema::string(), __( 'Kinds with more changes than one answer holds. Fetch those lists with `updated_after` instead.', 'sugarcommerce' ) ),
			'complete'  => Schema::boolean( __( 'False when deletions from that long ago may have been forgotten: fetch the lists afresh.', 'sugarcommerce' ) ),
		];

		$this->route(
			'/changes',
			[
				$this->handle(
					'GET',
					'get_changes',
					'read',
					[
						'since' => [
							'description' => __( 'The `cursor` from the last answer, or any moment (ISO 8601).', 'sugarcommerce' ),
							'type'        => 'string',
							'format'      => 'date-time',
							'required'    => true,
						],
						'types' => [
							'description' => $this->kit()->text( 'changes_types' ),
							'type'        => 'array',
							'items'       => [
								'type' => 'string',
								'enum' => $kinds,
							],
							'default'     => $kinds,
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
	 * The ids of one table's rows changed after a moment, oldest change first.
	 *
	 * Asked of the table directly, deliberately. This is the route an app
	 * calls every time it wakes, its answer is ids and nothing else, and
	 * through query classes -- which build every row to hand back its
	 * number -- eight kinds of record cost half a second on a small site.
	 * One indexed column, one comparison, ids out.
	 *
	 * @param string $table The table, without `$wpdb->prefix`.
	 * @param string $since A moment, as stored.
	 *
	 * @return int[]
	 * @since 1.0.0
	 */
	private static function ids( string $table, string $since ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above; the table is one the API was configured with and nothing a request can set.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id
				FROM %i
				WHERE date_modified > %s
				ORDER BY date_modified ASC, id ASC
				LIMIT %d',
				$wpdb->prefix . $table,
				$since,
				self::MOST + 1
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * What changed since a moment.
	 *
	 * Both modes: an app that switches the site to live and back should
	 * not find its copy of the test records stale.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	public function get_changes( WP_REST_Request $request ): WP_REST_Response {
		$cursor    = Timestamp::now();
		$since     = self::moment( $request['since'] );
		$types     = (array) $request['types'];
		$changed   = [];
		$truncated = [];

		$readable  = $this->kit()->readable( 'changes', $request );
		$types     = array_values( array_intersect( $types, array_keys( $readable ) ) );

		foreach ( $readable as $resource => $args ) {
			if ( ! in_array( $resource, $types, true ) ) {
				continue;
			}

			$rows = self::ids( $args['table'], $since );

			if ( count( $rows ) > self::MOST ) {
				$truncated[] = $resource;
				$rows        = array_slice( $rows, 0, self::MOST );
			}

			$changed[ $resource ] = array_map( 'intval', array_values( $rows ) );
		}

		$gone = $this->kit()->changes()->since( (int) Timestamp::to_unix( $since ) );

		return self::done(
			[
				'since'     => $this->kit()->date( $since ),
				'cursor'    => $this->kit()->date( $cursor ),
				'changed'   => (object) $changed,
				'deleted'   => (object) array_intersect_key( $gone['deleted'], array_flip( $types ) ),
				'truncated' => $truncated,
				'complete'  => $gone['complete'],
			]
		);
	}
}
