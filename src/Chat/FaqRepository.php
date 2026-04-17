<?php
declare(strict_types=1);

namespace AlphaChat\Chat;

use AlphaChat\Database\Schema;

final class FaqRepository {

	/** @return list<array{id:int, question:string, answer:string, sort_order:int, enabled:bool, created_at:string, updated_at:string}> */
	public function all( bool $enabled_only = false ): array {
		global $wpdb;

		$table = Schema::faqs_table();
		$sql   = 'SELECT id, question, answer, sort_order, enabled, created_at, updated_at FROM ' . esc_sql( $table );
		if ( $enabled_only ) {
			$sql .= ' WHERE enabled = 1';
		}
		$sql .= ' ORDER BY sort_order ASC, id ASC';

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ self::class, 'hydrate' ], $rows );
	}

	/** @return array{id:int, question:string, answer:string, sort_order:int, enabled:bool, created_at:string, updated_at:string}|null */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, question, answer, sort_order, enabled, created_at, updated_at FROM ' . esc_sql( Schema::faqs_table() ) . ' WHERE id = %d',
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * @param array{question:string, answer:string, sort_order?:int, enabled?:bool} $data
	 */
	public function create( array $data ): int {
		global $wpdb;
		$wpdb->insert(
			Schema::faqs_table(),
			[
				'question'   => (string) $data['question'],
				'answer'     => (string) $data['answer'],
				'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
				'enabled'    => ! empty( $data['enabled'] ?? true ) ? 1 : 0,
			],
			[ '%s', '%s', '%d', '%d' ]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array{question?:string, answer?:string, sort_order?:int, enabled?:bool} $data
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$update = [];
		$format = [];
		if ( isset( $data['question'] ) ) {
			$update['question'] = (string) $data['question'];
			$format[]           = '%s';
		}
		if ( isset( $data['answer'] ) ) {
			$update['answer'] = (string) $data['answer'];
			$format[]         = '%s';
		}
		if ( isset( $data['sort_order'] ) ) {
			$update['sort_order'] = (int) $data['sort_order'];
			$format[]             = '%d';
		}
		if ( array_key_exists( 'enabled', $data ) ) {
			$update['enabled'] = ! empty( $data['enabled'] ) ? 1 : 0;
			$format[]          = '%d';
		}
		if ( empty( $update ) ) {
			return false;
		}
		return (bool) $wpdb->update( Schema::faqs_table(), $update, [ 'id' => $id ], $format, [ '%d' ] );
	}

	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( Schema::faqs_table(), [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array{id:int, question:string, answer:string, sort_order:int, enabled:bool, created_at:string, updated_at:string}
	 */
	private static function hydrate( array $row ): array {
		return [
			'id'         => (int) $row['id'],
			'question'   => (string) $row['question'],
			'answer'     => (string) $row['answer'],
			'sort_order' => (int) $row['sort_order'],
			'enabled'    => (bool) $row['enabled'],
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		];
	}
}
