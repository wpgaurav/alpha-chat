<?php
declare(strict_types=1);

namespace AlphaChat\Chat;

final class QueryRewriter {

	public static function is_follow_up( string $message ): bool {
		$message = trim( $message );
		if ( '' === $message ) {
			return false;
		}

		if ( mb_strlen( $message ) < 80 ) {
			return true;
		}

		return (bool) preg_match(
			'/^(what about|how about|and that|and this|that one|this one|also|same for|what if)\b/iu',
			$message
		) || (bool) preg_match( '/\b(it|that|this|those|these|they)\b/iu', $message );
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
