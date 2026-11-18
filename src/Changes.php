<?php
/**
 * What has been deleted lately, for an app keeping a copy.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest;

use SugarCommerce\Dates\Clock;

/**
 * A short memory of deletions.
 *
 * A changed record says so itself -- its `date_modified` moves, and
 * `/changes` finds it by that. A deleted one says nothing: it is not there
 * to be asked. So each deletion of something the API serves is written down
 * here, the resource, the id and when, and `/changes` hands them out beside
 * the records that changed.
 *
 * Which deletions: every resource registered with a `deleted` action (see
 * Api::add_resource()), an add-on's included. The last five hundred, in
 * one option. Deleting is rare in most plugins' records, and an app that
 * has been away for longer than five hundred deletions is told so by
 * `/changes` and fetches its lists afresh.
 *
 * @since 1.0.0
 */
final class Changes {

	/**
	 * The option the deletions are kept in.
	 *
	 * @var string
	 */
	private string $option;

	/**
	 * How many are remembered.
	 *
	 * @var int
	 */
	private int $kept;

	/**
	 * What is listening, by resource: the action and the listener.
	 *
	 * @var array<string, array{0: string, 1: callable}>
	 */
	private array $listening = [];

	/**
	 * The memory of an API.
	 *
	 * @param Api                  $api    The API.
	 * @param array<string, mixed> $config {
	 *     @type string $option The option the deletions are kept in. `{prefix}_api_deleted`.
	 *     @type int    $kept   How many are remembered. 500.
	 * }
	 */
	public function __construct( private readonly Api $api, array $config = [] ) {
		$this->option = (string) ( $config['option'] ?? $api->prefix() . '_api_deleted' );
		$this->kept   = max( 1, (int) ( $config['kept'] ?? 500 ) );
	}

	/**
	 * The option the deletions are kept in.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function option(): string {
		return $this->option;
	}

	/**
	 * How many are remembered.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public function kept(): int {
		return $this->kept;
	}

	/**
	 * Listen for deletions of every resource that says how to.
	 *
	 * Safe to call again: a resource already listened for is not listened
	 * for twice, and one whose action changed is moved over.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function listen(): void {
		foreach ( $this->api->resources() as $resource => $args ) {
			$action = $args['deleted'];

			if ( isset( $this->listening[ $resource ] ) && $this->listening[ $resource ][0] === $action ) {
				continue;
			}

			$this->stop( $resource );

			if ( '' === $action ) {
				continue;
			}

			$listener = function ( $id ) use ( $resource ): void {
				$this->deleted( $resource, (int) $id );
			};

			add_action( $action, $listener );

			$this->listening[ $resource ] = [ $action, $listener ];
		}
	}

	/**
	 * Stop listening altogether.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function unlisten(): void {
		foreach ( array_keys( $this->listening ) as $resource ) {
			$this->stop( $resource );
		}
	}

	/**
	 * Stop listening for one resource.
	 *
	 * @param string $resource The resource.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function stop( string $resource ): void {
		if ( isset( $this->listening[ $resource ] ) ) {
			remove_action( $this->listening[ $resource ][0], $this->listening[ $resource ][1] );

			unset( $this->listening[ $resource ] );
		}
	}

	/**
	 * Write a deletion down.
	 *
	 * @param string $resource Which resource.
	 * @param int    $id       Which record.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function deleted( string $resource, int $id ): void {
		$kept   = $this->all();
		$kept[] = [ $resource, $id, Clock::time() ];

		update_option( $this->option, array_slice( $kept, -$this->kept ), false );
	}

	/**
	 * The deletions since a moment, by resource.
	 *
	 * @param int $since A timestamp.
	 *
	 * @return array{deleted: array<string, int[]>, complete: bool} `complete` is false
	 *               when the memory does not reach back as far as `$since`.
	 * @since 1.0.0
	 */
	public function since( int $since ): array {
		$kept    = $this->all();
		$deleted = [];

		foreach ( $kept as [ $resource, $id, $time ] ) {
			if ( (int) $time >= $since ) {
				$deleted[ (string) $resource ][] = (int) $id;
			}
		}

		return [
			'deleted'  => $deleted,

			// Full, and its oldest entry is newer than what was asked for:
			// there may have been deletions before it that are forgotten.
			'complete' => count( $kept ) < $this->kept || (int) $kept[0][2] <= $since,
		];
	}

	/**
	 * Everything remembered, oldest first.
	 *
	 * @return array<int, array{0: string, 1: int, 2: int}>
	 * @since 1.0.0
	 */
	public function all(): array {
		$kept = get_option( $this->option, [] );

		if ( ! is_array( $kept ) ) {
			return [];
		}

		return array_values( array_filter( $kept, self::is_change( ... ) ) );
	}

	/**
	 * Whether something read from the option is a change as this class writes one.
	 *
	 * @param mixed $row What was read.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private static function is_change( $row ): bool {
		return is_array( $row ) && 3 === count( $row );
	}
}
