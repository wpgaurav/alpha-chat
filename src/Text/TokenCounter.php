<?php
declare(strict_types=1);

namespace AlphaChat\Text;

final class TokenCounter {

	/**
	 * Approximate token count.
	 *
	 * Heuristic sized for GPT-4o family tokenisers: ~4 characters per token for English,
	 * with a floor on non-whitespace word count. Not exact — fine for chunk budgeting and
	 * cost estimation, not fine for hard API limits (leave headroom).
	 */
	public function count( string $text ): int {
		if ( '' === $text ) {
			return 0;
		}
		$by_chars = (int) ceil( mb_strlen( $text ) / 4 );
		$by_words = (int) ceil( str_word_count( $text ) * 1.3 );
		return max( 1, max( $by_chars, $by_words ) );
	}
}
