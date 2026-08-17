<?php
declare(strict_types=1);

namespace AlphaChat\Chat;

final class FaqExtractor {

	/**
	 * @return list<array{question: string, answer: string}>
	 */
	public static function from_page( string $html, string $raw = '' ): array {
		$pairs = [];
		$pairs = array_merge( $pairs, self::from_json_ld( $html ) );
		$pairs = array_merge( $pairs, self::from_block_comments( '' !== $raw ? $raw : $html ) );
		$pairs = array_merge( $pairs, self::from_markup( $html ) );
		if ( '' !== $raw && $raw !== $html ) {
			$pairs = array_merge( $pairs, self::from_markup( $raw ) );
		}
		$pairs = array_merge( $pairs, self::from_headings( $html ) );

		return self::unique( $pairs );
	}

	public static function normalize_question( string $question ): string {
		$question = mb_strtolower( trim( wp_strip_all_tags( $question ) ) );
		$question = preg_replace( '/\s+/u', ' ', $question ) ?? $question;

		return $question;
	}

	/**
	 * @return list<array{question: string, answer: string}>
	 */
	public static function from_json_ld( string $html ): array {
		$pairs = [];
		if ( ! preg_match_all( '#<script[^>]*type=(["\'])application/ld\+json\1[^>]*>(.*?)</script>#is', $html, $matches ) ) {
			return [];
		}

		foreach ( $matches[2] as $json ) {
			$decoded = json_decode( html_entity_decode( trim( (string) $json ), ENT_QUOTES | ENT_HTML5 ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$pairs = array_merge( $pairs, self::walk_json_ld( $decoded ) );
		}

		return $pairs;
	}

	/**
	 * @return list<array{question: string, answer: string}>
	 */
	public static function from_block_comments( string $content ): array {
		$pairs = [];
		if ( ! preg_match_all( '/<!--\s+wp:(?:yoast\/faq-block|rank-math\/faq-block|yoast-seo\/faq-block)\s+(\{.*?\})\s+(?:\/)?-->/s', $content, $matches ) ) {
			return [];
		}

		foreach ( $matches[1] as $json ) {
			$data = json_decode( (string) $json, true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$questions = $data['questions'] ?? [];
			if ( ! is_array( $questions ) ) {
				continue;
			}
			foreach ( $questions as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$question = self::plain( (string) ( $item['title'] ?? $item['question'] ?? '' ) );
				$answer   = self::plain( (string) ( $item['content'] ?? $item['answer'] ?? '' ) );
				if ( '' === $question && isset( $item['question'] ) && is_array( $item['question'] ) ) {
					$question = self::plain( self::yoast_rich( $item['question'] ) );
				}
				if ( '' === $answer && isset( $item['answer'] ) && is_array( $item['answer'] ) ) {
					$answer = self::plain( self::yoast_rich( $item['answer'] ) );
				}
				$pair = self::pair( $question, $answer );
				if ( null !== $pair ) {
					$pairs[] = $pair;
				}
			}
		}

		return $pairs;
	}

	/**
	 * @return list<array{question: string, answer: string}>
	 */
	public static function from_markup( string $html ): array {
		$pairs = [];

		if ( preg_match_all( '#<details\b[^>]*>\s*<summary\b[^>]*>(.*?)</summary>(.*?)</details>#is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$pair = self::pair( $match[1], $match[2] );
				if ( null !== $pair ) {
					$pairs[] = $pair;
				}
			}
		}

		if ( preg_match_all( '#<dt\b[^>]*>(.*?)</dt>\s*<dd\b[^>]*>(.*?)</dd>#is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$pair = self::pair( $match[1], $match[2] );
				if ( null !== $pair ) {
					$pairs[] = $pair;
				}
			}
		}

		$patterns = [
			'#<(?:strong|h[2-4]|div|p)[^>]*class="[^"]*schema-faq-question[^"]*"[^>]*>(.*?)</(?:strong|h[2-4]|div|p)>\s*<(?:p|div)[^>]*class="[^"]*schema-faq-answer[^"]*"[^>]*>(.*?)</(?:p|div)>#is',
			'#<(?:h[2-4]|div|button|p)[^>]*class="[^"]*rank-math-question[^"]*"[^>]*>(.*?)</(?:h[2-4]|div|button|p)>\s*<(?:div|p)[^>]*class="[^"]*rank-math-answer[^"]*"[^>]*>(.*?)</(?:div|p)>#is',
			'#<(?:h[2-4]|button|div|p)[^>]*class="[^"]*(?:faq-question|accordion__title|accordion-title|accordion-header)[^"]*"[^>]*>(.*?)</(?:h[2-4]|button|div|p)>\s*<(?:div|p)[^>]*class="[^"]*(?:faq-answer|accordion__content|accordion-content|accordion-body)[^"]*"[^>]*>(.*?)</(?:div|p)>#is',
		];
		foreach ( $patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $matches as $match ) {
				$pair = self::pair( $match[1], $match[2] );
				if ( null !== $pair ) {
					$pairs[] = $pair;
				}
			}
		}

		return $pairs;
	}

	/**
	 * @return list<array{question: string, answer: string}>
	 */
	public static function from_headings( string $html ): array {
		$pairs = [];
		if ( ! preg_match_all( '#<h([2-4])\b[^>]*>(.*?)</h\1>(.*?)(?=<h[2-4]\b|$)#is', $html, $matches, PREG_SET_ORDER ) ) {
			return [];
		}

		foreach ( $matches as $match ) {
			$question = self::plain( $match[2] );
			if ( ! str_contains( $question, '?' ) ) {
				continue;
			}
			$pair = self::pair( $question, $match[3] );
			if ( null !== $pair ) {
				$pairs[] = $pair;
			}
		}

		return $pairs;
	}

	/**
	 * @param list<array{question: string, answer: string}> $pairs
	 *
	 * @return list<array{question: string, answer: string}>
	 */
	public static function unique( array $pairs ): array {
		$seen = [];
		$out  = [];
		foreach ( $pairs as $pair ) {
			$key = self::normalize_question( $pair['question'] );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $pair;
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>|list<mixed> $node
	 *
	 * @return list<array{question: string, answer: string}>
	 */
	private static function walk_json_ld( array $node ): array {
		$pairs = [];
		$types = self::json_types( $node['@type'] ?? null );
		if ( in_array( 'FAQPage', $types, true ) ) {
			$entities = $node['mainEntity'] ?? [];
			if ( isset( $entities['@type'] ) ) {
				$entities = [ $entities ];
			}
			if ( is_array( $entities ) ) {
				foreach ( $entities as $entity ) {
					if ( ! is_array( $entity ) ) {
						continue;
					}
					$pair = self::question_from_json( $entity );
					if ( null !== $pair ) {
						$pairs[] = $pair;
					}
				}
			}
		}

		if ( in_array( 'Question', $types, true ) ) {
			$pair = self::question_from_json( $node );
			if ( null !== $pair ) {
				$pairs[] = $pair;
			}
		}

		foreach ( [ '@graph', 'mainEntity', 'hasPart' ] as $key ) {
			$child = $node[ $key ] ?? null;
			if ( ! is_array( $child ) ) {
				continue;
			}
			if ( self::is_list( $child ) ) {
				foreach ( $child as $item ) {
					if ( is_array( $item ) ) {
						$pairs = array_merge( $pairs, self::walk_json_ld( $item ) );
					}
				}
			} else {
				$pairs = array_merge( $pairs, self::walk_json_ld( $child ) );
			}
		}

		return $pairs;
	}

	/**
	 * @param array<int|string, mixed> $entity
	 *
	 * @return array{question: string, answer: string}|null
	 */
	private static function question_from_json( array $entity ): ?array {
		$question = (string) ( $entity['name'] ?? $entity['text'] ?? '' );
		$answer   = $entity['acceptedAnswer'] ?? $entity['suggestedAnswer'] ?? '';
		if ( is_array( $answer ) ) {
			$answer = (string) ( $answer['text'] ?? $answer['name'] ?? '' );
		}

		return self::pair( $question, (string) $answer );
	}

	/**
	 * @return list<string>
	 */
	private static function json_types( mixed $type ): array {
		if ( is_string( $type ) ) {
			return [ $type ];
		}
		if ( ! is_array( $type ) ) {
			return [];
		}
		$out = [];
		foreach ( $type as $item ) {
			if ( is_string( $item ) ) {
				$out[] = $item;
			}
		}

		return $out;
	}

	/**
	 * @param array<int|string, mixed> $nodes
	 */
	private static function yoast_rich( array $nodes ): string {
		$parts = [];
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$props = $node['props'] ?? [];
			if ( ! is_array( $props ) ) {
				continue;
			}
			$children = $props['children'] ?? [];
			if ( is_string( $children ) ) {
				$parts[] = $children;
				continue;
			}
			if ( is_array( $children ) ) {
				foreach ( $children as $child ) {
					if ( is_string( $child ) ) {
						$parts[] = $child;
					} elseif ( is_array( $child ) ) {
						$parts[] = self::yoast_rich( [ $child ] );
					}
				}
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * @return array{question: string, answer: string}|null
	 */
	private static function pair( string $question, string $answer ): ?array {
		$question = self::plain( $question );
		$answer   = self::plain( $answer );
		if ( '' === $question || '' === $answer ) {
			return null;
		}
		if ( mb_strlen( $question ) > 500 ) {
			$question = mb_substr( $question, 0, 500 );
		}
		if ( mb_strlen( $answer ) > 20000 ) {
			$answer = mb_substr( $answer, 0, 20000 ) . '…';
		}

		return [
			'question' => $question,
			'answer'   => $answer,
		];
	}

	private static function plain( string $value ): string {
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5 );
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( $value );
	}

	/**
	 * @param array<mixed> $value
	 */
	private static function is_list( array $value ): bool {
		if ( [] === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
