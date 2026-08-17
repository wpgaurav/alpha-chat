<?php
declare(strict_types=1);

namespace AlphaChat\Chat;

final class SourcePicker {

	/**
	 * @param list<array{id: string, score: float, metadata: array<string, mixed>}> $chunks
	 *
	 * @return list<array{id: string, score: float, metadata: array<string, mixed>}>
	 */
	public static function used( array $chunks, string $reply, string $question, int $current_post_id = 0 ): array {
		$reply    = trim( $reply );
		$question = trim( $question );
		if ( '' === $reply || empty( $chunks ) ) {
			return [];
		}

		$page_referential = self::is_page_referential( $question );
		$picked           = [];

		foreach ( $chunks as $chunk ) {
			$meta  = (array) ( $chunk['metadata'] ?? [] );
			$title = trim( (string) ( $meta['title'] ?? '' ) );
			$body  = trim( (string) ( $meta['content'] ?? '' ) );
			$sid   = (int) ( $meta['source_id'] ?? 0 );

			$hit = self::mentions_title( $reply, $title )
				|| self::overlaps( $reply, $body )
				|| ( $page_referential && $current_post_id > 0 && $sid === $current_post_id );

			if ( $hit ) {
				$picked[] = $chunk;
			}
		}

		usort( $picked, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		if ( $current_post_id > 0 ) {
			usort(
				$picked,
				static function ( array $a, array $b ) use ( $current_post_id ): int {
					$a_cur = (int) ( $a['metadata']['source_id'] ?? 0 ) === $current_post_id ? 1 : 0;
					$b_cur = (int) ( $b['metadata']['source_id'] ?? 0 ) === $current_post_id ? 1 : 0;
					if ( $a_cur !== $b_cur ) {
						return $b_cur <=> $a_cur;
					}
					return $b['score'] <=> $a['score'];
				}
			);
		}

		return array_slice( $picked, 0, 3 );
	}

	public static function is_page_referential( string $question ): bool {
		return (bool) preg_match( '/\b(this page|this article|this post|this|here)\b/iu', $question );
	}

	public static function mentions_title( string $reply, string $title ): bool {
		$title = trim( $title );
		return '' !== $title && mb_strlen( $title ) >= 4 && false !== mb_stripos( $reply, $title );
	}

	public static function overlaps( string $reply, string $chunk ): bool {
		$reply_grams = self::shingles( $reply );
		$chunk_grams = self::shingles( $chunk );
		if ( empty( $reply_grams ) || empty( $chunk_grams ) ) {
			return false;
		}

		$hits = 0;
		foreach ( $reply_grams as $gram ) {
			if ( isset( $chunk_grams[ $gram ] ) ) {
				++$hits;
			}
		}

		return $hits >= 2;
	}

	/**
	 * @return array<string, true>
	 */
	private static function shingles( string $text ): array {
		$text = mb_strtolower( wp_strip_all_tags( $text ) );
		$text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text ) ?? $text;
		$words = preg_split( '/\s+/u', trim( $text ) ) ?: [];
		$words = array_values(
			array_filter(
				$words,
				static fn ( string $word ): bool => mb_strlen( $word ) >= 4
			)
		);

		$grams = [];
		$count = count( $words );
		for ( $i = 0; $i < $count - 1; $i++ ) {
			$grams[ $words[ $i ] . ' ' . $words[ $i + 1 ] ] = true;
		}

		return $grams;
	}
}
