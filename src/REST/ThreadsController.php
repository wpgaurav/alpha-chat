<?php
declare(strict_types=1);

namespace AlphaChat\REST;

use AlphaChat\Chat\MessageRepository;
use AlphaChat\Chat\ThreadRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ThreadsController {

	public function __construct(
		private readonly ThreadRepository $threads,
		private readonly MessageRepository $messages,
	) {}

	public function register( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/threads',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list' ],
				'permission_callback' => [ SettingsController::class, 'can_manage' ],
				'args'                => [
					'page'     => [ 'type' => 'integer', 'default' => 1 ],
					'per_page' => [ 'type' => 'integer', 'default' => 20 ],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/threads/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ SettingsController::class, 'can_manage' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => [ SettingsController::class, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/threads/chart',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'chart' ],
				'permission_callback' => [ SettingsController::class, 'can_manage' ],
				'args'                => [
					'days' => [ 'type' => 'integer', 'default' => 14 ],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/threads/export',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'export' ],
				'permission_callback' => [ SettingsController::class, 'can_manage' ],
			]
		);
	}

	public function list( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

		return new WP_REST_Response(
			[
				'items' => $this->threads->list( $per_page, $page ),
				'total' => $this->threads->total(),
			]
		);
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = (int) $request['id'];
		$thread = $this->find_by_id( $id );
		if ( null === $thread ) {
			return new WP_Error( 'alpha_chat_not_found', __( 'Thread not found.', 'alpha-chat' ), [ 'status' => 404 ] );
		}

		return new WP_REST_Response(
			[
				'thread'   => $thread,
				'messages' => $this->messages->for_thread( $id, 500 ),
			]
		);
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request['id'];
		$this->threads->delete( $id );
		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	public function chart( WP_REST_Request $request ): WP_REST_Response {
		$days = max( 1, min( 90, (int) $request->get_param( 'days' ) ) );
		return new WP_REST_Response( $this->messages->daily_chart( $days ) );
	}

	public function export( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$rows       = [ [ 'thread_uuid', 'created_at', 'role', 'content' ] ];
		$page       = 1;
		do {
			$threads = $this->threads->list( 100, $page );
			foreach ( $threads as $thread ) {
				foreach ( $this->messages->for_thread( (int) $thread['id'], 1000 ) as $message ) {
					$rows[] = [
						$thread['uuid'],
						$message['created_at'],
						$message['role'],
						$message['content'],
					];
				}
			}
			$page++;
		} while ( ! empty( $threads ) );

		$handle = fopen( 'php://temp', 'w+' );
		if ( false === $handle ) {
			return new WP_REST_Response( [ 'message' => __( 'Unable to create export stream.', 'alpha-chat' ) ], 500 );
		}
		foreach ( $rows as $row ) {
			fputcsv( $handle, $row );
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );

		$response = new WP_REST_Response();
		$response->set_headers(
			[
				'Content-Type'        => 'text/csv; charset=utf-8',
				'Content-Disposition' => 'attachment; filename="alpha-chat-threads.csv"',
			]
		);
		$response->set_data( $csv );

		return $response;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function find_by_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( \AlphaChat\Database\Schema::threads_table() ) . ' WHERE id = %d',
				$id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return [
			'id'            => (int) $row['id'],
			'uuid'          => (string) $row['uuid'],
			'user_id'       => null === $row['user_id'] ? null : (int) $row['user_id'],
			'title'         => (string) $row['title'],
			'message_count' => (int) $row['message_count'],
			'created_at'    => (string) $row['created_at'],
			'updated_at'    => (string) $row['updated_at'],
		];
	}
}
