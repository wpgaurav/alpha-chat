<?php
declare(strict_types=1);

namespace AlphaChat\Providers\VectorStore;

use AlphaChat\Database\Schema;
use AlphaChat\Providers\Contracts\VectorStore;
use AlphaChat\Text\Similarity;

final class DatabaseVectorStore implements VectorStore {

	/**
	 * @param list<float>          $vector
	 * @param array<string, mixed> $metadata
	 */
	public function upsert( string $id, array $vector, array $metadata = [] ): void {
		global $wpdb;

		[ $source_type, $source_id, $chunk_index ] = self::parse_id( $id );

		$table = Schema::chunks_table();
		$data  = [
			'source_type'     => $source_type,
			'source_id'       => $source_id,
			'chunk_index'     => $chunk_index,
			'content'         => (string) ( $metadata['content'] ?? '' ),
			'token_count'     => (int) ( $metadata['token_count'] ?? 0 ),
			'content_hash'    => (string) ( $metadata['content_hash'] ?? '' ),
			'embedding'       => Similarity::pack( $vector ),
			'embedding_model' => (string) ( $metadata['embedding_model'] ?? '' ),
			'status'          => 'ready',
		];

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . esc_sql( $table ) . ' WHERE source_type = %s AND source_id = %d AND chunk_index = %d',
				$source_type,
				$source_id,
				$chunk_index
			)
		);

		if ( $existing ) {
			$wpdb->update(
				$table,
				$data,
				[ 'id' => (int) $existing ],
				[ '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
			return;
		}

		$wpdb->insert(
			$table,
			$data,
			[ '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);
	}

	public function delete( string $id ): void {
		global $wpdb;

		[ $source_type, $source_id, $chunk_index ] = self::parse_id( $id );
		$table = Schema::chunks_table();

		if ( -1 === $chunk_index ) {
			$wpdb->delete(
				$table,
				[
					'source_type' => $source_type,
					'source_id'   => $source_id,
				],
				[ '%s', '%d' ]
			);
			return;
		}

		$wpdb->delete(
			$table,
			[
				'source_type' => $source_type,
				'source_id'   => $source_id,
				'chunk_index' => $chunk_index,
			],
			[ '%s', '%d', '%d' ]
		);
	}

	/**
	 * @param list<float> $query
	 *
	 * @return list<array{id: string, score: float, metadata: array<string, mixed>}>
	 */
	public function search( array $query, int $limit = 5, float $threshold = 0.0 ): array {
		global $wpdb;

		$table = Schema::chunks_table();
		$rows  = $wpdb->get_results(
			"SELECT id, source_type, source_id, chunk_index, content, token_count, embedding FROM " . esc_sql( $table ) . " WHERE status = 'ready' AND embedding IS NOT NULL",
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return [];
		}

		$scored = [];
		foreach ( $rows as $row ) {
			$vector = Similarity::unpack( (string) $row['embedding'] );
			if ( empty( $vector ) ) {
				continue;
			}

			$score = Similarity::cosine( $query, $vector );
			if ( $score < $threshold ) {
				continue;
			}

			$scored[] = [
				'id'       => self::build_id( (string) $row['source_type'], (int) $row['source_id'], (int) $row['chunk_index'] ),
				'score'    => $score,
				'metadata' => [
					'source_type' => (string) $row['source_type'],
					'source_id'   => (int) $row['source_id'],
					'chunk_index' => (int) $row['chunk_index'],
					'content'     => (string) $row['content'],
					'token_count' => (int) $row['token_count'],
				],
			];
		}

		usort( $scored, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		return array_slice( $scored, 0, max( 1, $limit ) );
	}

	/**
	 * @return array{0: string, 1: int, 2: int} tuple of source_type, source_id, chunk_index. chunk_index = -1 wildcards all chunks.
	 */
	public static function parse_id( string $id ): array {
		$parts = explode( ':', $id );
		$type  = $parts[0] ?? 'post';
		$sid   = isset( $parts[1] ) ? (int) $parts[1] : 0;
		$cidx  = isset( $parts[2] ) ? (int) $parts[2] : -1;
		return [ $type, $sid, $cidx ];
	}

	public static function build_id( string $source_type, int $source_id, int $chunk_index ): string {
		return sprintf( '%s:%d:%d', $source_type, $source_id, $chunk_index );
	}
}
