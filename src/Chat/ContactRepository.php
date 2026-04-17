<?php
declare(strict_types=1);

namespace AlphaChat\Chat;

use AlphaChat\Database\Schema;

final class ContactRepository {

	/**
	 * @param array{thread_uuid?: string, name?: string, email: string, message: string, user_id?: ?int, user_agent?: string, ip_hash?: string} $data
	 */
	public function create( array $data ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::contacts_table(),
			[
				'thread_uuid' => (string) ( $data['thread_uuid'] ?? '' ),
				'name'        => (string) ( $data['name'] ?? '' ),
				'email'       => (string) $data['email'],
				'message'     => (string) $data['message'],
				'user_id'     => $data['user_id'] ?? null,
				'status'      => 'new',
				'user_agent'  => substr( (string) ( $data['user_agent'] ?? '' ), 0, 255 ),
				'ip_hash'     => (string) ( $data['ip_hash'] ?? '' ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function list( int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$table  = Schema::contacts_table();
		$offset = max( 0, ( $page - 1 ) * $per_page );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, thread_uuid, name, email, message, user_id, status, created_at FROM " . esc_sql( $table ) . " ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . esc_sql( $table ) );

		return [
			'items' => is_array( $rows ) ? array_map( [ self::class, 'hydrate' ], $rows ) : [],
			'total' => $total,
		];
	}

	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( Schema::contacts_table(), [ 'id' => $id ], [ '%d' ] );
	}

	public function update_status( int $id, string $status ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			Schema::contacts_table(),
			[ 'status' => $status ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return [
			'id'          => (int) $row['id'],
			'thread_uuid' => (string) $row['thread_uuid'],
			'name'        => (string) ( $row['name'] ?? '' ),
			'email'       => (string) $row['email'],
			'message'     => (string) $row['message'],
			'user_id'     => null === $row['user_id'] ? null : (int) $row['user_id'],
			'status'      => (string) $row['status'],
			'created_at'  => (string) $row['created_at'],
		];
	}
}
