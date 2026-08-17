<?php
declare(strict_types=1);

namespace AlphaChat\Chat;

final class QueryRewriter {

	/**
	 * Whether this message only makes sense against the previous turn.
	 *
	 * Length alone is a bad signal: treating everything under 80 characters as a
	 * follow-up meant almost every message dragged the previous topic into the
	 * retrieval query, so a clean subject change kept returning the old subject's
	 * chunks. A follow-up now has to actually look like one — a continuation
	 * opener, a dangling pronoun, or a fragment with no subject of its own.
	 */
	public static function is_follow_up( string $message ): bool {
		$message = trim( $message );
		if ( '' === $message ) {
			return false;
		}

		// "What about that?", "and this one", "same for teams"…
		if ( preg_match( '/^(what about|how about|and that|and this|and those|that one|this one|also|same for|what if|why not|how so|why)\b/iu', $message ) ) {
			return true;
		}

		// A bare pronoun with no noun to bind to in this message.
		if ( preg_match( '/\b(it|its|that|this|those|these|they|them|their|the same|the other)\b/iu', $message ) ) {
			return true;
		}

		// A message that opens with an interrogative or auxiliary carries its own
		// subject ("How do refunds work?"), so it stands alone however short it is.
		if ( preg_match( '/^(what|how|when|where|who|which|do|does|did|is|are|can|could|should|will|would|may|tell|show|list)\b/iu', $message ) ) {
			return false;
		}

		// What is left is a fragment with no subject of its own — "cheaper?",
		// "in euros", "for teams" — which only resolves against the prior turn.
		return mb_strlen( $message ) < 25;
	}

	public static function rewrite( string $message, string $previous_user, string $previous_assistant ): string {
		$parts = array_filter(
			[
				trim( $previous_user ),
				self::first_sentence( $previous_assistant ),
				trim( $message ),
			],
			static function ( string $part ): bool {
				return '' !== $part;
			}
		);

		return implode( ' ', $parts );
	}

	public static function first_sentence( string $text ): string {
		$text = trim( wp_strip_all_tags( $text ) );
		if ( '' === $text ) {
			return '';
		}

		if ( preg_match( '/^(.+?[.!?])(\s|$)/u', $text, $matches ) ) {
			return trim( $matches[1] );
		}

		return mb_substr( $text, 0, 180 );
	}
}
