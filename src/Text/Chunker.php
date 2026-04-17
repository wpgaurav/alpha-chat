<?php
declare(strict_types=1);

namespace AlphaChat\Text;

final class Chunker {

	public function __construct(
		private readonly TokenCounter $counter,
		private readonly int $chunk_size = 400,
		private readonly int $overlap = 50,
	) {}

	/**
	 * Split text into chunks aligned to paragraph/sentence boundaries.
	 *
	 * @return list<string>
	 */
	public function split( string $text ): array {
		$text = $this->normalise( $text );
		if ( '' === $text ) {
			return [];
		}

		$paragraphs = preg_split( '/\n{2,}/u', $text ) ?: [];
		$sentences  = [];
		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}
			$parts = preg_split( '/(?<=[.!?])\s+(?=[A-Z\p{Lu}])/u', $paragraph ) ?: [ $paragraph ];
			foreach ( $parts as $part ) {
				$part = trim( $part );
				if ( '' !== $part ) {
					$sentences[] = $part;
				}
			}
		}

		$chunks       = [];
		$buffer       = [];
		$buffer_count = 0;

		foreach ( $sentences as $sentence ) {
			$tokens = $this->counter->count( $sentence );

			if ( $tokens >= $this->chunk_size ) {
				if ( $buffer_count > 0 ) {
					$chunks[] = implode( ' ', $buffer );
					$buffer   = [];
					$buffer_count = 0;
				}
				foreach ( $this->force_split( $sentence ) as $hard_chunk ) {
					$chunks[] = $hard_chunk;
				}
				continue;
			}

			if ( $buffer_count + $tokens > $this->chunk_size && $buffer_count > 0 ) {
				$chunks[] = implode( ' ', $buffer );
				$buffer   = $this->overlap_tail( $buffer );
				$buffer_count = $this->counter->count( implode( ' ', $buffer ) );
			}

			$buffer[]      = $sentence;
			$buffer_count += $tokens;
		}

		if ( $buffer_count > 0 ) {
			$chunks[] = implode( ' ', $buffer );
		}

		return array_values( array_filter( $chunks, static fn ( string $c ): bool => '' !== trim( $c ) ) );
	}

	/**
	 * @param list<string> $buffer
	 *
	 * @return list<string>
	 */
	private function overlap_tail( array $buffer ): array {
		if ( 0 === $this->overlap ) {
			return [];
		}
		$tail  = [];
		$count = 0;
		for ( $i = count( $buffer ) - 1; $i >= 0; $i-- ) {
			$tokens = $this->counter->count( $buffer[ $i ] );
			if ( $count + $tokens > $this->overlap && 0 !== $count ) {
				break;
			}
			array_unshift( $tail, $buffer[ $i ] );
			$count += $tokens;
		}
		return $tail;
	}

	/**
	 * Hard-split a sentence that is longer than chunk_size.
	 *
	 * @return list<string>
	 */
	private function force_split( string $sentence ): array {
		$words  = preg_split( '/\s+/u', $sentence ) ?: [];
		$chunks = [];
		$buffer = [];
		$count  = 0;

		foreach ( $words as $word ) {
			$tokens = $this->counter->count( $word );
			if ( $count + $tokens > $this->chunk_size && 0 !== $count ) {
				$chunks[] = implode( ' ', $buffer );
				$buffer   = [];
				$count    = 0;
			}
			$buffer[] = $word;
			$count   += $tokens;
		}

		if ( 0 !== $count ) {
			$chunks[] = implode( ' ', $buffer );
		}

		return $chunks;
	}

	private function normalise( string $text ): string {
		$stripped = wp_strip_all_tags( $text, true );
		$stripped = html_entity_decode( $stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$stripped = preg_replace( '/\r\n?/', "\n", $stripped ) ?? $stripped;
		$stripped = preg_replace( "/[ \t]+/u", ' ', $stripped ) ?? $stripped;
		return trim( $stripped );
	}
}
