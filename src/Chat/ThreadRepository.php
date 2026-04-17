<?php
declare(strict_types=1);

namespace AlphaChat\Chat;

use AlphaChat\Database\Schema;

final class ThreadRepository {

	/** @return array<string, mixed>|null */
	public function find_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( Schema::threads_table() ) . ' WHERE uuid = %s',
				$uuid
			),
			ARRAY_A
		);
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/** @return array<string, mixed> */
	public function create( string $session_hash, ?int $user_id = null ): array {
		global $wpdb;
		$uuid = wp_generate_uuid4();
		$wpdb->insert(
			Schema::threads_table(),
			[
				'uuid'         => $uuid,
				'user_id'      => $user_id,
				'session_hash' => $session_hash,
				'title'        => '',
			],
			[ '%s', '%d', '%s', '%s' ]
		);
		return $this->find_by_uuid( $uuid ) ?? [];
	}

	public function touch( int $thread_id, int $added_messages = 0, ?string $title = null ): void {
		global $wpdb;
		$updates = [ 'updated_at' => current_time( 'mysql', true ) ];
		$format  = [ '%s' ];

		if ( $added_messages > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . esc_sql( Schema::threads_table() ) . ' SET message_count = message_count + %d WHERE id = %d',
					$added_messages,
					$thread_id
				)
			);
		}

		if ( null !== $title ) {
			$updates['title'] = $title;
			$format[]         = '%s';
		}

		$wpdb->update( Schema::threads_table(), $updates, [ 'id' => $thread_id ], $format, [ '%d' ] );
	}

	public function delete( int $thread_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::messages_table(), [ 'thread_id' => $thread_id ], [ '%d' ] );
		$wpdb->delete( Schema::threads_table(), [ 'id' => $thread_id ], [ '%d' ] );
	}

	/** @return list<array<string, mixed>> */
	public function list( int $per_page = 20, int $page = 1 ): array {
		global $wpdb;
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( Schema::threads_table() ) . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d',
				$per_page,
				$offset
			),
			ARRAY_A
		);
		$output = [];
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$output[] = self::hydrate( $row );
			}
		}
		return $output;
	}

	public function total(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . esc_sql( Schema::threads_table() ) );
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return [
			'id'            => (int) $row['id'],
			'uuid'          => (string) $row['uuid'],
			'user_id'       => null === $row['user_id'] ? null : (int) $row['user_id'],
			'session_hash'  => (string) $row['session_hash'],
			'title'         => (string) $row['title'],
			'message_count' => (int) $row['message_count'],
			'created_at'    => (string) $row['created_at'],
			'updated_at'    => (string) $row['updated_at'],
		];
	}
}
