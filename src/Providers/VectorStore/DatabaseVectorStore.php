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
	public function search( array $query, int $limit = 5, float $threshold = 0.0, string $embedding_model = '', array $options = [] ): array {
		$text_query        = trim( (string) ( $options['text_query'] ?? '' ) );
		$prefer_source_id  = (int) ( $options['prefer_source_id'] ?? 0 );
		$prefer_bonus      = (float) ( $options['prefer_bonus'] ?? 0.05 );
		$prefer_force      = (int) ( $options['prefer_force'] ?? 2 );

		$rows = $this->candidate_rows( $text_query, $embedding_model, $prefer_source_id );

		/**
		 * Filter retrieval candidate rows before cosine ranking.
		 *
		 * @param list<array<string, mixed>> $rows            Candidate chunk rows.
		 * @param string                     $text_query      Text used for FULLTEXT.
		 * @param string                     $embedding_model Active embedding model.
		 */
		$rows = (array) apply_filters( 'alpha_chat_retrieval_candidates', $rows, $text_query, $embedding_model );

		$scored         = [];
		$preferred      = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$vector = Similarity::unpack( (string) ( $row['embedding'] ?? '' ) );
			if ( empty( $vector ) ) {
				continue;
			}

			$score     = Similarity::cosine( $query, $vector );
			$source_id = (int) ( $row['source_id'] ?? 0 );
			if ( $prefer_source_id > 0 && $source_id === $prefer_source_id ) {
				$score += $prefer_bonus;
			}

			$item = [
				'id'       => self::build_id( (string) ( $row['source_type'] ?? 'post' ), $source_id, (int) ( $row['chunk_index'] ?? 0 ) ),
				'score'    => $score,
				'metadata' => [
					'source_type' => (string) ( $row['source_type'] ?? 'post' ),
					'source_id'   => $source_id,
					'chunk_index' => (int) ( $row['chunk_index'] ?? 0 ),
					'content'     => (string) ( $row['content'] ?? '' ),
					'token_count' => (int) ( $row['token_count'] ?? 0 ),
				],
			];

			if ( $prefer_source_id > 0 && $source_id === $prefer_source_id ) {
				$preferred[] = $item;
			}

			if ( $score < $threshold ) {
				continue;
			}

			$scored[] = $item;
		}

		usort( $preferred, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );
		usort( $scored, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		$forced = array_slice( $preferred, 0, max( 0, $prefer_force ) );
		$merged = [];
		foreach ( array_merge( $forced, $scored ) as $item ) {
			$merged[ $item['id'] ] = $item;
		}

		$ranked = array_values( $merged );
		usort( $ranked, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		return array_slice( $ranked, 0, max( 1, $limit ) );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function candidate_rows( string $text_query, string $embedding_model, int $prefer_source_id ): array {
		$by_id = [];

		if ( '' !== $text_query ) {
			foreach ( $this->fulltext_rows( $text_query, $embedding_model, 50 ) as $row ) {
				$by_id[ (int) $row['id'] ] = $row;
			}
		}

		if ( $prefer_source_id > 0 ) {
			foreach ( $this->source_rows( 'post', $prefer_source_id, $embedding_model ) as $row ) {
				$by_id[ (int) $row['id'] ] = $row;
			}
		}

		if ( empty( $by_id ) ) {
			foreach ( $this->fallback_rows( $embedding_model, 2000 ) as $row ) {
				$by_id[ (int) $row['id'] ] = $row;
			}
		}

		return array_values( $by_id );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function fulltext_rows( string $text_query, string $embedding_model, int $limit ): array {
		global $wpdb;
		if ( ! self::supports_fulltext() ) {
			return [];
		}

		$table = Schema::chunks_table();

		$sql = 'SELECT id, source_type, source_id, chunk_index, content, token_count, embedding FROM ' . esc_sql( $table ) . " WHERE status = 'ready' AND embedding IS NOT NULL";
		$args = [];
		if ( '' !== $embedding_model ) {
			$sql   .= ' AND embedding_model = %s';
			$args[] = $embedding_model;
		}
		$sql   .= ' AND MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE) LIMIT %d';
		$args[] = $text_query;
		$args[] = $limit;

		$show_errors = $wpdb->hide_errors();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is escaped; remaining values are bound.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( $show_errors ) {
			$wpdb->show_errors();
		}
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function source_rows( string $source_type, int $source_id, string $embedding_model ): array {
		global $wpdb;
		$table = Schema::chunks_table();

		$sql = 'SELECT id, source_type, source_id, chunk_index, content, token_count, embedding FROM ' . esc_sql( $table ) . " WHERE status = 'ready' AND embedding IS NOT NULL AND source_type = %s AND source_id = %d";
		$args = [ $source_type, $source_id ];
		if ( '' !== $embedding_model ) {
			$sql   .= ' AND embedding_model = %s';
			$args[] = $embedding_model;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is escaped; remaining values are bound.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function fallback_rows( string $embedding_model, int $limit ): array {
		global $wpdb;
		$table = Schema::chunks_table();

		$sql = 'SELECT id, source_type, source_id, chunk_index, content, token_count, embedding FROM ' . esc_sql( $table ) . " WHERE status = 'ready' AND embedding IS NOT NULL";
		$args = [];
		if ( '' !== $embedding_model ) {
			$sql   .= ' AND embedding_model = %s';
			$args[] = $embedding_model;
		}
		$sql   .= ' ORDER BY id DESC LIMIT %d';
		$args[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is escaped; remaining values are bound.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
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

	private static function supports_fulltext(): bool {
		static $supported = null;
		if ( null !== $supported ) {
			return $supported;
		}

		global $wpdb;
		$driver = strtolower( $wpdb::class );
		$supported = ! str_contains( $driver, 'sqlite' );

		return $supported;
	}
}
