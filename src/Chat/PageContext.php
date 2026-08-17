<?php
declare(strict_types=1);

namespace AlphaChat\Chat;

final class PageContext {

	/**
	 * Resolve a visitor page URL (and optional document title) into prompt context.
	 *
	 * @return array{url: string, title: string, content: string, post_id: int}|null
	 */
	public static function resolve( string $origin_url, string $origin_title = '' ): ?array {
		$url = self::normalize_url( $origin_url );
		if ( '' === $url ) {
			return null;
		}

		$post_id = self::resolve_post_id( $url );
		$title   = trim( wp_strip_all_tags( $origin_title ) );
		$content = '';

		if ( $post_id > 0 ) {
			$post   = get_post( $post_id );
			$status = is_object( $post ) ? (string) $post->post_status : '';
			if ( 'publish' === $status ) {
				$permalink = get_permalink( $post_id );
				if ( is_string( $permalink ) && '' !== $permalink ) {
					$url = $permalink;
				}
				$post_title = (string) get_the_title( $post_id );
				if ( '' !== $post_title ) {
					$title = $post_title;
				}
				$content = self::plain_excerpt( (string) $post->post_content, 4000 );
			} else {
				$post_id = 0;
			}
		}

		$resolved = [
			'url'     => $url,
			'title'   => $title,
			'content' => $content,
			'post_id' => $post_id,
		];

		/**
		 * Filter the current-page context injected into the chat prompt.
		 *
		 * @param array{url: string, title: string, content: string, post_id: int} $resolved
		 * @param string                                                           $origin_url
		 */
		$filtered = apply_filters( 'alpha_chat_page_context', $resolved, $origin_url );
		if ( ! is_array( $filtered ) ) {
			return $resolved;
		}

		return [
			'url'     => (string) ( $filtered['url'] ?? $resolved['url'] ),
			'title'   => (string) ( $filtered['title'] ?? $resolved['title'] ),
			'content' => (string) ( $filtered['content'] ?? $resolved['content'] ),
			'post_id' => (int) ( $filtered['post_id'] ?? $resolved['post_id'] ),
		];
	}

	public static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( '' === $path ) {
			$path = '/';
		}

		$normalized = $scheme . '://' . $host;
		if ( isset( $parts['port'] ) ) {
			$normalized .= ':' . (int) $parts['port'];
		}
		$normalized .= $path;
		if ( ! empty( $parts['query'] ) ) {
			$normalized .= '?' . (string) $parts['query'];
		}

		return $normalized;
	}

	public static function is_same_site( string $url ): bool {
		$page_host = self::bare_host( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home_host = self::bare_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return '' !== $page_host && $page_host === $home_host;
	}

	public static function resolve_post_id( string $url ): int {
		$url = self::normalize_url( $url );
		if ( '' === $url || ! self::is_same_site( $url ) ) {
			return 0;
		}

		$candidates = [ $url ];
		$without_query = preg_replace( '/\?.*$/', '', $url );
		if ( is_string( $without_query ) && $without_query !== $url ) {
			$candidates[] = $without_query;
		}
		foreach ( [ $url, $without_query ] as $variant ) {
			if ( ! is_string( $variant ) || '' === $variant ) {
				continue;
			}
			$candidates[] = trailingslashit( $variant );
			$candidates[] = untrailingslashit( $variant );
		}

		foreach ( array_unique( $candidates ) as $candidate ) {
			$post_id = (int) url_to_postid( $candidate );
			if ( $post_id > 0 ) {
				return $post_id;
			}
		}

		$path      = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '' );
		$home_path = (string) ( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?? '/' );
		$relative  = trim( $path, '/' );
		$home_trim = trim( $home_path, '/' );
		if ( '' !== $home_trim && str_starts_with( $relative, $home_trim . '/' ) ) {
			$relative = substr( $relative, strlen( $home_trim ) + 1 );
		} elseif ( $relative === $home_trim ) {
			$relative = '';
		}

		if ( '' === $relative ) {
			$front = (int) get_option( 'page_on_front' );
			return $front > 0 ? $front : 0;
		}

		foreach ( [ 'page', 'post' ] as $type ) {
			$found = get_page_by_path( $relative, 'OBJECT', $type );
			$id    = is_object( $found ) ? (int) $found->ID : 0;
			if ( $id > 0 ) {
				return $id;
			}
		}

		return 0;
	}

	public static function plain_excerpt( string $html, int $max_chars = 4000 ): string {
		$text = strip_shortcodes( $html );
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		$text = trim( $text );
		if ( mb_strlen( $text ) > $max_chars ) {
			return mb_substr( $text, 0, $max_chars ) . '…';
		}

		return $text;
	}

	private static function bare_host( string $host ): string {
		$host = strtolower( trim( $host ) );
		return (string) preg_replace( '/^www\./', '', $host );
	}
}
