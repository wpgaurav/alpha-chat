<?php
declare(strict_types=1);

namespace AlphaChat\REST;

use AlphaChat\Database\Schema;
use AlphaChat\KnowledgeBase\Indexer;
use AlphaChat\Scheduler\ReindexScheduler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class KnowledgeBaseController {

	public function __construct(
		private readonly Indexer $indexer,
		private readonly ReindexScheduler $scheduler,
	) {}

	public function register( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/knowledge-base',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list' ],
				'permission_callback' => [ SettingsController::class, 'can_manage' ],
				'args'                => [
					'search'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'post_type' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => 'any' ],
					'indexed'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => 'any' ],
					'page'      => [ 'type' => 'integer', 'default' => 1 ],
					'per_page'  => [ 'type' => 'integer', 'default' => 20 ],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/bulk',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'bulk' ],
				'permission_callback' => [ SettingsController::class, 'can_manage' ],
				'args'                => [
					'action'   => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'add',
					],
					'post_ids' => [
						'type'     => 'array',
						'required' => true,
						'items'    => [ 'type' => 'integer' ],
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/index-remaining',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'index_remaining' ],
				'permission_callback' => [ SettingsController::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/queue',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'queue_stats' ],
					'permission_callback' => [ SettingsController::class, 'can_manage' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'process_queue' ],
					'permission_callback' => [ SettingsController::class, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/post-types',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_post_types' ],
				'permission_callback' => [ SettingsController::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/(?P<post_id>\d+)',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'add' ],
					'permission_callback' => [ SettingsController::class, 'can_manage' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'remove' ],
					'permission_callback' => [ SettingsController::class, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/knowledge-base/reindex-all',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reindex_all' ],
				'permission_callback' => [ SettingsController::class, 'can_manage' ],
			]
		);
	}

	public function list( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$post_type = (string) $request->get_param( 'post_type' );
		$search    = (string) $request->get_param( 'search' );
		$page      = max( 1, (int) $request->get_param( 'page' ) );
		$per_page  = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

		$types = ( '' === $post_type || 'any' === $post_type )
			? self::public_post_types()
			: [ $post_type ];

		$indexed = (string) $request->get_param( 'indexed' );
		$chunks_table = esc_sql( Schema::chunks_table() );

		if ( 'yes' === $indexed || 'no' === $indexed ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT source_id FROM ' . $chunks_table . ' WHERE source_type = %s',
					'post'
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = array_map( 'intval', (array) $ids );
			if ( empty( $ids ) ) {
				$ids = [ 0 ];
			}
		}

		$query_args = [
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			's'              => $search,
			'no_found_rows'  => false,
		];

		if ( 'yes' === $indexed ) {
			$query_args['post__in'] = $ids;
			$query_args['orderby']  = 'post__in';
		} elseif ( 'no' === $indexed ) {
			$query_args['post__not_in'] = $ids;
		}

		$query = new \WP_Query( $query_args );

		$items = [];
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$chunks = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $chunks_table . ' WHERE source_type = %s AND source_id = %d',
					'post',
					$post->ID
				)
			);
			$items[] = [
				'id'           => $post->ID,
				'title'        => get_the_title( $post ),
				'type'         => $post->post_type,
				'status'       => $post->post_status,
				'url'          => (string) get_permalink( $post ),
				'modified'     => $post->post_modified_gmt,
				'indexed'      => $chunks > 0,
				'chunk_count'  => $chunks,
				'last_indexed' => (int) get_post_meta( $post->ID, '_alpha_chat_indexed_at', true ),
				'index_error'  => (string) get_post_meta( $post->ID, '_alpha_chat_index_error', true ),
			];
		}

		return new WP_REST_Response(
			[
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			]
		);
	}

	public function add( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'alpha_chat_not_found', __( 'Post not found.', 'alpha-chat' ), [ 'status' => 404 ] );
		}

		$this->scheduler->queue_index( $post_id );

		return new WP_REST_Response( [ 'queued' => true, 'post_id' => $post_id ] );
	}

	public function remove( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['post_id'];
		$this->indexer->forget_post( $post_id );

		return new WP_REST_Response( [ 'removed' => true, 'post_id' => $post_id ] );
	}

	public function reindex_all( WP_REST_Request $request ): WP_REST_Response {
		$requested = (string) $request->get_param( 'post_type' );
		$types     = ( '' === $requested || 'any' === $requested )
			? self::public_post_types()
			: [ $requested ];

		$count = 0;
		foreach ( $types as $type ) {
			$count += $this->scheduler->queue_all( $type );
		}

		return new WP_REST_Response( [ 'queued' => $count ] );
	}

	public function bulk( WP_REST_Request $request ): WP_REST_Response {
		$action = (string) $request->get_param( 'action' );
		$ids    = array_values( array_filter( array_map( 'intval', (array) $request->get_param( 'post_ids' ) ) ) );

		if ( empty( $ids ) ) {
			return new WP_REST_Response( [ 'queued' => 0, 'removed' => 0 ] );
		}

		if ( 'remove' === $action ) {
			foreach ( $ids as $id ) {
				$this->indexer->forget_post( $id );
			}
			return new WP_REST_Response( [ 'queued' => 0, 'removed' => count( $ids ) ] );
		}

		foreach ( $ids as $id ) {
			$this->scheduler->queue_index( $id );
		}

		return new WP_REST_Response( [ 'queued' => count( $ids ), 'removed' => 0 ] );
	}

	public function index_remaining( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		global $wpdb;

		$indexed_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT source_id FROM ' . esc_sql( Schema::chunks_table() ) . ' WHERE source_type = %s',
				'post'
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$indexed_ids = array_map( 'intval', (array) $indexed_ids );

		$query = new \WP_Query(
			[
				'post_type'      => self::public_post_types(),
				'post_status'    => 'publish',
				'post__not_in'   => empty( $indexed_ids ) ? [ 0 ] : $indexed_ids,
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			]
		);

		$ids = array_map( 'intval', (array) $query->posts );
		foreach ( $ids as $id ) {
			$this->scheduler->queue_index( $id );
		}

		return new WP_REST_Response( [ 'queued' => count( $ids ) ] );
	}

	public function queue_stats(): WP_REST_Response {
		return new WP_REST_Response( self::stats_for_group() );
	}

	public function process_queue(): WP_REST_Response|WP_Error {
		if ( ! class_exists( \ActionScheduler::class ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return new WP_Error( 'alpha_chat_no_scheduler', __( 'Action Scheduler is not available.', 'alpha-chat' ), [ 'status' => 500 ] );
		}

		$before = self::stats_for_group();

		try {
			$runner = \ActionScheduler::runner();
			if ( method_exists( $runner, 'run' ) ) {
				$runner->run( 'Alpha Chat manual' );
			}
		} catch ( \Throwable $e ) {
			return new WP_Error( 'alpha_chat_runner_failed', $e->getMessage(), [ 'status' => 500 ] );
		}

		$after = self::stats_for_group();

		return new WP_REST_Response(
			[
				'before'    => $before,
				'after'     => $after,
				'processed' => max( 0, $after['complete'] - $before['complete'] ),
			]
		);
	}

	/**
	 * @return array{pending:int, in_progress:int, complete:int, failed:int}
	 */
	private static function stats_for_group(): array {
		global $wpdb;

		$groups_table  = esc_sql( $wpdb->prefix . 'actionscheduler_groups' );
		$actions_table = esc_sql( $wpdb->prefix . 'actionscheduler_actions' );

		$group_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT group_id FROM {$groups_table} WHERE slug = %s",
				'alpha-chat'
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $group_id ) {
			return [ 'pending' => 0, 'in_progress' => 0, 'complete' => 0, 'failed' => 0 ];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS c FROM {$actions_table} WHERE group_id = %d GROUP BY status",
				(int) $group_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = [ 'pending' => 0, 'in_progress' => 0, 'complete' => 0, 'failed' => 0 ];
		foreach ( (array) $rows as $row ) {
			$status = str_replace( '-', '_', (string) $row['status'] );
			if ( isset( $out[ $status ] ) ) {
				$out[ $status ] = (int) $row['c'];
			}
		}
		return $out;
	}

	public function list_post_types(): WP_REST_Response {
		$items = [];
		foreach ( self::public_post_types() as $slug ) {
			$obj = get_post_type_object( $slug );
			if ( ! $obj ) {
				continue;
			}
			$items[] = [
				'slug'  => $slug,
				'label' => (string) $obj->labels->name,
			];
		}
		return new WP_REST_Response( [ 'items' => $items ] );
	}

	/** @return list<string> */
	private static function public_post_types(): array {
		$types = get_post_types( [ 'public' => true ], 'names' );
		unset( $types['attachment'] );

		/**
		 * Filter the list of post types indexed by Alpha Chat.
		 *
		 * @param list<string> $types Slugs of post types to include.
		 */
		return array_values( (array) apply_filters( 'alpha_chat_indexable_post_types', array_values( $types ) ) );
	}
}
