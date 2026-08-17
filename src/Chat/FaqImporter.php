<?php
declare(strict_types=1);

namespace AlphaChat\Chat;

use AlphaChat\Http\HttpClient;
use AlphaChat\Http\HttpException;

final class FaqImporter {

	public const MAX_URLS  = 10;
	public const MAX_ITEMS = 50;

	public function __construct(
		private readonly HttpClient $http,
		private readonly FaqRepository $faqs,
	) {}

	/**
	 * @param list<string> $urls
	 *
	 * @return list<array{url: string, title: string, source: string, pairs: list<array{question: string, answer: string, duplicate: bool}>, error?: string}>
	 */
	public function preview( array $urls ): array {
		$existing = $this->existing_keys();
		$pages    = [];

		foreach ( $this->sanitize_urls( $urls ) as $url ) {
			try {
				$page  = $this->fetch_page( $url );
				$pairs = FaqExtractor::from_page( $page['html'], $page['raw'] );
				/**
				 * Filter Q&A pairs extracted from an imported page.
				 *
				 * @param list<array{question: string, answer: string}> $pairs
				 * @param array{url: string, title: string, html: string, raw: string, source: string} $page
				 */
				$filtered = apply_filters( 'alpha_chat_faq_extracted', $pairs, $page );
				if ( is_array( $filtered ) ) {
					$pairs = [];
					foreach ( $filtered as $item ) {
						if ( ! is_array( $item ) ) {
							continue;
						}
						$question = trim( (string) ( $item['question'] ?? '' ) );
						$answer   = trim( (string) ( $item['answer'] ?? '' ) );
						if ( '' === $question || '' === $answer ) {
							continue;
						}
						$pairs[] = [
							'question' => $question,
							'answer'   => $answer,
						];
					}
					$pairs = FaqExtractor::unique( $pairs );
				}

				$labeled = [];
				foreach ( $pairs as $pair ) {
					$key       = FaqExtractor::normalize_question( $pair['question'] );
					$labeled[] = [
						'question'  => $pair['question'],
						'answer'    => $pair['answer'],
						'duplicate' => isset( $existing[ $key ] ),
					];
				}

				$pages[] = [
					'url'    => $url,
					'title'  => $page['title'],
					'source' => $page['source'],
					'pairs'  => $labeled,
				];
			} catch ( HttpException $e ) {
				$pages[] = [
					'url'    => $url,
					'title'  => '',
					'source' => '',
					'pairs'  => [],
					'error'  => $e->getMessage(),
				];
			}
		}

		return $pages;
	}

	/**
	 * @param list<array{question?: string, answer?: string}> $items
	 *
	 * @return array{created: int, skipped: int, items: list<array<string, mixed>>}
	 */
	public function import( array $items ): array {
		$existing = $this->existing_keys();
		$created  = [];
		$skipped  = 0;
		$sort     = $this->faqs->next_sort_order();
		$count    = 0;

		foreach ( $items as $item ) {
			if ( $count >= self::MAX_ITEMS ) {
				break;
			}
			if ( ! is_array( $item ) ) {
				++$skipped;
				continue;
			}
			$question = sanitize_text_field( (string) ( $item['question'] ?? '' ) );
			$answer   = wp_kses_post( (string) ( $item['answer'] ?? '' ) );
			$question = trim( $question );
			$answer   = trim( $answer );
			if ( '' === $question || '' === $answer ) {
				++$skipped;
				continue;
			}
			$key = FaqExtractor::normalize_question( $question );
			if ( isset( $existing[ $key ] ) ) {
				++$skipped;
				continue;
			}

			$id  = $this->faqs->create(
				[
					'question'   => $question,
					'answer'     => $answer,
					'sort_order' => $sort,
					'enabled'    => true,
				]
			);
			$row = $this->faqs->find( $id );
			if ( null !== $row ) {
				$created[]        = $row;
				$existing[ $key ] = true;
				++$sort;
				++$count;
			}
		}

		return [
			'created' => count( $created ),
			'skipped' => $skipped,
			'items'   => $created,
		];
	}

	/**
	 * @param list<string> $urls
	 *
	 * @return list<string>
	 */
	public function sanitize_urls( array $urls ): array {
		$out = [];
		foreach ( $urls as $url ) {
			if ( count( $out ) >= self::MAX_URLS ) {
				break;
			}
			$normalized = PageContext::normalize_url( (string) $url );
			if ( '' === $normalized ) {
				continue;
			}
			$out[] = $normalized;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @return array{url: string, title: string, html: string, raw: string, source: string}
	 */
	private function fetch_page( string $url ): array {
		$this->assert_safe_url( $url );

		if ( PageContext::is_same_site( $url ) ) {
			$local = $this->fetch_local( $url );
			if ( null !== $local ) {
				return $local;
			}
		}

		$rest = $this->fetch_rest( $url );
		if ( null !== $rest ) {
			return $rest;
		}

		return $this->fetch_html( $url );
	}

	/**
	 * @return array{url: string, title: string, html: string, raw: string, source: string}|null
	 */
	private function fetch_local( string $url ): ?array {
		$post_id = PageContext::resolve_post_id( $url );
		if ( $post_id <= 0 ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}

		$raw = (string) $post->post_content;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core content filter.
		$html = (string) apply_filters( 'the_content', $raw );

		return [
			'url'    => (string) get_permalink( $post_id ),
			'title'  => (string) get_the_title( $post_id ),
			'html'   => $html,
			'raw'    => $raw,
			'source' => 'local',
		];
	}

	/**
	 * @return array{url: string, title: string, html: string, raw: string, source: string}|null
	 */
	private function fetch_rest( string $url ): ?array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		$path = trim( (string) ( $parts['path'] ?? '' ), '/' );
		if ( '' === $path ) {
			return null;
		}
		$segments = explode( '/', $path );
		$slug     = (string) end( $segments );
		if ( '' === $slug ) {
			return null;
		}

		foreach ( [ 'pages', 'posts' ] as $type ) {
			$endpoint = $origin . '/wp-json/wp/v2/' . $type . '?slug=' . rawurlencode( $slug ) . '&per_page=1';
			try {
				$data = $this->http->get_untrusted(
					$endpoint,
					[
						'Accept' => 'application/json',
					]
				);
			} catch ( HttpException ) {
				continue;
			}

			$item = $data[0] ?? null;
			if ( ! is_array( $item ) ) {
				continue;
			}

			$title = '';
			if ( is_array( $item['title'] ?? null ) ) {
				$title = (string) ( $item['title']['rendered'] ?? '' );
			}
			$html = '';
			$raw  = '';
			if ( is_array( $item['content'] ?? null ) ) {
				$html = (string) ( $item['content']['rendered'] ?? '' );
				$raw  = (string) ( $item['content']['raw'] ?? '' );
			}
			if ( '' === $html && '' === $raw ) {
				continue;
			}

			return [
				'url'    => (string) ( $item['link'] ?? $url ),
				'title'  => wp_strip_all_tags( $title ),
				'html'   => $html,
				'raw'    => $raw,
				'source' => 'rest',
			];
		}

		return null;
	}

	/**
	 * @return array{url: string, title: string, html: string, raw: string, source: string}
	 */
	private function fetch_html( string $url ): array {
		$data = $this->http->get_untrusted(
			$url,
			[
				'Accept' => 'text/html,application/xhtml+xml',
			]
		);
		$html = (string) ( $data['raw'] ?? '' );
		if ( '' === $html && isset( $data['html'] ) ) {
			$html = (string) $data['html'];
		}
		$title = '';
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $match ) ) {
			$title = wp_strip_all_tags( $match[1] );
		}

		return [
			'url'    => $url,
			'title'  => trim( $title ),
			'html'   => $html,
			'raw'    => '',
			'source' => 'html',
		];
	}

	/**
	 * Reject an import target before we fetch it.
	 *
	 * This is a fast fail with a readable message, not the real boundary — it
	 * resolves DNS ahead of the request, so a rebinding host could answer
	 * differently a moment later. The actual protection is that every outbound
	 * fetch goes through wp_safe_remote_request, which re-validates the host on
	 * each hop and after each redirect.
	 */
	private function assert_safe_url( string $url ): void {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered as HTML.
			throw new HttpException( __( 'Invalid import URL.', 'alpha-chat' ), 400 );
		}

		if ( PageContext::is_same_site( $url ) ) {
			return;
		}

		// A bare IP literal never needs resolving and must be checked directly.
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( self::is_private_ip( $host ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered as HTML.
				throw new HttpException( __( 'Import URLs cannot target private or local addresses.', 'alpha-chat' ), 400 );
			}
			return;
		}

		$records = self::resolve_all( $host );
		if ( [] === $records ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered as HTML.
			throw new HttpException( __( 'Could not resolve the import URL host.', 'alpha-chat' ), 400 );
		}

		foreach ( $records as $ip ) {
			if ( self::is_private_ip( $ip ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered as HTML.
				throw new HttpException( __( 'Import URLs cannot target private or local addresses.', 'alpha-chat' ), 400 );
			}
		}
	}

	/**
	 * Resolve both A and AAAA records.
	 *
	 * Only IPv4 is returned by gethostbynamel(), so an AAAA-only host pointing at
	 * ::1 used to skip the private-address check entirely.
	 *
	 * @return list<string>
	 */
	private static function resolve_all( string $host ): array {
		$ips = [];

		$v4 = gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			foreach ( $v4 as $ip ) {
				$ips[] = (string) $ip;
			}
		}

		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- lookup failures are handled by the empty check.
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( isset( $record['ipv6'] ) ) {
						$ips[] = (string) $record['ipv6'];
					}
				}
			}
		}

		return array_values( array_unique( $ips ) );
	}

	private static function is_private_ip( string $ip ): bool {
		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * @return array<string, true>
	 */
	private function existing_keys(): array {
		$keys = [];
		foreach ( $this->faqs->all() as $faq ) {
			$keys[ FaqExtractor::normalize_question( $faq['question'] ) ] = true;
		}

		return $keys;
	}
}
