<?php
declare(strict_types=1);

namespace AlphaChat\Chat;

use AlphaChat\Database\Schema;

final class MessageRepository {

	/**
	 * @param array<string, mixed> $metadata
	 *
	 * @return array<string, mixed>
	 */
	public function append( int $thread_id, string $role, string $content, int $token_count = 0, array $metadata = [] ): array {
		global $wpdb;
		$wpdb->insert(
			Schema::messages_table(),
			[
				'thread_id'   => $thread_id,
				'role'        => $role,
				'content'     => $content,
				'token_count' => $token_count,
				'metadata'    => empty( $metadata ) ? null : (string) wp_json_encode( $metadata ),
			],
			[ '%d', '%s', '%s', '%d', '%s' ]
		);

		$id = (int) $wpdb->insert_id;

		return [
			'id'          => $id,
			'thread_id'   => $thread_id,
			'role'        => $role,
			'content'     => $content,
			'token_count' => $token_count,
			'metadata'    => $metadata,
			'created_at'  => current_time( 'mysql', true ),
		];
	}

	/** @return list<array<string, mixed>> */
	public function for_thread( int $thread_id, int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( Schema::messages_table() ) . ' WHERE thread_id = %d ORDER BY id ASC LIMIT %d',
				$thread_id,
				$limit
			),
			ARRAY_A
		);

		$output = [];
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$metadata = null;
			if ( ! empty( $row['metadata'] ) ) {
				$decoded  = json_decode( (string) $row['metadata'], true );
				$metadata = is_array( $decoded ) ? $decoded : null;
			}

			$output[] = [
				'id'          => (int) $row['id'],
				'thread_id'   => (int) $row['thread_id'],
				'role'        => (string) $row['role'],
				'content'     => (string) $row['content'],
				'token_count' => (int) $row['token_count'],
				'metadata'    => $metadata,
				'created_at'  => (string) $row['created_at'],
			];
		}

		return $output;
	}

	public function count_since( int $days ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . esc_sql( Schema::messages_table() ) . ' WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)',
				$days
			)
		);
	}

	/**
	 * @return array{labels: list<string>, messages: list<int>, sessions: list<int>}
	 */
	public function daily_chart( int $days = 14 ): array {
		global $wpdb;

		$labels   = [];
		$messages = [];
		$sessions = [];

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$labels[] = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE(created_at) AS d, COUNT(*) AS msgs, COUNT(DISTINCT thread_id) AS sess
				 FROM ' . esc_sql( Schema::messages_table() ) . "
				 WHERE created_at >= DATE_SUB(UTC_DATE(), INTERVAL %d DAY)
				 GROUP BY DATE(created_at)",
				$days
			),
			ARRAY_A
		);

		$by_date = [];
		foreach ( (array) $rows as $row ) {
			$by_date[ (string) $row['d'] ] = [
				'messages' => (int) $row['msgs'],
				'sessions' => (int) $row['sess'],
			];
		}

		foreach ( $labels as $date ) {
			$messages[] = $by_date[ $date ]['messages'] ?? 0;
			$sessions[] = $by_date[ $date ]['sessions'] ?? 0;
		}

		return compact( 'labels', 'messages', 'sessions' );
	}
}
