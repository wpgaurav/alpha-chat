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

		// CJK characters cost roughly one token each, so the 4-chars-per-token
		// rule undercounts them by about 4x. Score them separately or a CJK site
		// silently produces chunks far over the intended budget.
		$cjk = (int) preg_match_all( '/[\x{3000}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}]/u', $text );

		$latin_len = max( 0, mb_strlen( $text ) - $cjk );
		$by_chars  = (int) ceil( $latin_len / 4 ) + $cjk;

		// str_word_count() is byte-oriented and misses non-ASCII words entirely,
		// so split on Unicode whitespace instead.
		$words    = preg_split( '/\s+/u', trim( $text ) ) ?: [];
		$by_words = (int) ceil( count( array_filter( $words, static fn ( string $w ): bool => '' !== $w ) ) * 1.3 );

		return max( 1, max( $by_chars, $by_words ) );
	}
}
