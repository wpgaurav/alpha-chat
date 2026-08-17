<?php
declare(strict_types=1);

namespace AlphaChat\Scheduler;

use AlphaChat\KnowledgeBase\Indexer;

final class ReindexScheduler {

	public const SINGLE_HOOK = 'alpha_chat_index_post';
	public const BULK_HOOK   = 'alpha_chat_bulk_index';
	public const GROUP       = 'alpha-chat';

	public function __construct( private readonly Indexer $indexer ) {}

	public function register(): void {
		add_action( self::SINGLE_HOOK, [ $this, 'run_single' ], 10, 1 );
		add_action( self::BULK_HOOK, [ $this, 'run_bulk' ], 10, 2 );
	}

	public function queue_index( int $post_id, int $delay = 0 ): void {
		if ( function_exists( 'as_enqueue_async_action' ) && 0 === $delay ) {
			as_enqueue_async_action( self::SINGLE_HOOK, [ $post_id ], self::GROUP );
			return;
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::SINGLE_HOOK, [ $post_id ], self::GROUP );
			return;
		}
		wp_schedule_single_event( time() + $delay, self::SINGLE_HOOK, [ $post_id ] );
	}

	public function queue_all( string $post_type = 'post' ): int {
		$ids = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);

		$count = 0;
		foreach ( array_chunk( (array) $ids, 20 ) as $batch ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::BULK_HOOK, [ $batch, $post_type ], self::GROUP );
			} else {
				wp_schedule_single_event( time() + $count, self::BULK_HOOK, [ $batch, $post_type ] );
			}
			$count += count( $batch );
		}

		return $count;
	}

	/**
	 * @return array{pending: int, in_progress: int, complete: int, failed: int}
	 */
	public function queue_counts(): array {
		global $wpdb;

		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$empty         = [
			'pending'     => 0,
			'in_progress' => 0,
			'complete'    => 0,
			'failed'      => 0,
		];

		$wpdb->suppress_errors( true );
		$show_errors = $wpdb->hide_errors();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_table = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $groups_table )
			)
		);
		if ( ! $has_table ) {
			if ( $show_errors ) {
				$wpdb->show_errors();
			}
			$wpdb->suppress_errors( false );
			return $empty;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$group_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT group_id FROM ' . esc_sql( $groups_table ) . ' WHERE slug = %s',
				self::GROUP
			)
		);
		if ( ! $group_id || '' !== (string) $wpdb->last_error ) {
			if ( $show_errors ) {
				$wpdb->show_errors();
			}
			$wpdb->suppress_errors( false );
			return $empty;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS c FROM ' . esc_sql( $actions_table ) . ' WHERE group_id = %d GROUP BY status',
				(int) $group_id
			),
			ARRAY_A
		);
		if ( $show_errors ) {
			$wpdb->show_errors();
		}
		$wpdb->suppress_errors( false );
		if ( ! is_array( $rows ) ) {
			return $empty;
		}

		$out = $empty;
		foreach ( (array) $rows as $row ) {
			$status = str_replace( '-', '_', (string) ( $row['status'] ?? '' ) );
			if ( isset( $out[ $status ] ) ) {
				$out[ $status ] = (int) $row['c'];
			}
		}

		return $out;
	}

	public function run_single( int $post_id ): void {
		$this->indexer->index_post( $post_id );
	}

	/**
	 * @param list<int> $post_ids
	 */
	public function run_bulk( array $post_ids, string $post_type = 'post' ): void {
		unset( $post_type );
		foreach ( $post_ids as $id ) {
			$this->indexer->index_post( (int) $id );
		}
	}
}
