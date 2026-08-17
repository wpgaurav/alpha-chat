<?php
declare(strict_types=1);

namespace AlphaChat\Support;

/**
 * Resolve the originating client IP.
 *
 * Forwarding headers are attacker-controlled unless the request actually arrived
 * through a proxy we trust. Honouring them unconditionally lets a caller mint a
 * fresh rate-limit bucket per request, so they are only consulted when
 * REMOTE_ADDR belongs to a configured trusted proxy.
 */
final class ClientIp {

	/**
	 * Headers consulted, in priority order, when the peer is a trusted proxy.
	 *
	 * @var list<string>
	 */
	private const FORWARD_HEADERS = [
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_REAL_IP',
		'HTTP_X_FORWARDED_FOR',
	];

	/**
	 * Cloudflare's published edge ranges, from cloudflare.com/ips-v4 and ips-v6.
	 *
	 * A very large share of WordPress sites sit behind Cloudflare, where
	 * REMOTE_ADDR is always an edge address. Without this, every anonymous
	 * visitor on such a site collapses into a handful of rate-limit buckets.
	 *
	 * Trusting these is safe because REMOTE_ADDR is the actual TCP peer and
	 * cannot be forged — a caller can send any CF-Connecting-IP they like, but it
	 * is only believed when the connection genuinely arrives from Cloudflare.
	 *
	 * The list is static by design: fetching it at runtime would be an outbound
	 * call this plugin does not otherwise make. If Cloudflare adds a range, that
	 * range simply falls back to REMOTE_ADDR, which is safe but coarse. Override
	 * with the alpha_chat_trust_cloudflare filter to disable, or
	 * alpha_chat_trusted_proxies to supply your own list.
	 *
	 * Last checked: 2026-08-17.
	 *
	 * @var list<string>
	 */
	private const CLOUDFLARE_RANGES = [
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	];

	public static function get(): string {
		$remote = self::server( 'REMOTE_ADDR' );

		if ( ! self::is_trusted_proxy( $remote ) ) {
			return $remote;
		}

		foreach ( self::FORWARD_HEADERS as $header ) {
			$value = self::server( $header );
			if ( '' === $value ) {
				continue;
			}

			$candidate = self::rightmost_untrusted( $value );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return $remote;
	}

	/**
	 * X-Forwarded-For is appended to by each hop, so the rightmost entry that is
	 * not itself a trusted proxy is the closest address we can still believe.
	 * Walking from the right prevents a caller from prepending a forged hop.
	 */
	private static function rightmost_untrusted( string $header_value ): string {
		$parts = array_reverse( array_map( 'trim', explode( ',', $header_value ) ) );

		foreach ( $parts as $part ) {
			$ip = self::normalize( $part );
			if ( '' === $ip ) {
				continue;
			}
			if ( self::is_trusted_proxy( $ip ) ) {
				continue;
			}
			return $ip;
		}

		return '';
	}

	private static function is_trusted_proxy( string $ip ): bool {
		if ( '' === $ip ) {
			return false;
		}

		foreach ( self::trusted_proxies() as $entry ) {
			if ( self::matches( $ip, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Trusted proxy addresses or CIDR ranges.
	 *
	 * Cloudflare's edge ranges are included by default. Anything else — a load
	 * balancer, another CDN, a caching layer — must be declared explicitly,
	 * because trusting a forwarding header from an untrusted peer lets any caller
	 * mint a fresh rate-limit bucket per request.
	 *
	 * @return list<string>
	 */
	public static function trusted_proxies(): array {
		$configured = [];

		if ( defined( 'ALPHA_CHAT_TRUSTED_PROXIES' ) ) {
			$constant = constant( 'ALPHA_CHAT_TRUSTED_PROXIES' );
			if ( is_string( $constant ) ) {
				$configured = explode( ',', $constant );
			} elseif ( is_array( $constant ) ) {
				$configured = $constant;
			}
		}

		/**
		 * Filter whether Cloudflare's published edge ranges are trusted.
		 *
		 * Enabled by default so sites behind Cloudflare get real visitor
		 * addresses without configuration. Return false to require an explicit
		 * ALPHA_CHAT_TRUSTED_PROXIES list instead.
		 *
		 * @param bool $trust Whether to trust Cloudflare edge addresses.
		 */
		if ( (bool) apply_filters( 'alpha_chat_trust_cloudflare', true ) ) {
			$configured = array_merge( $configured, self::CLOUDFLARE_RANGES );
		}

		/**
		 * Filter the reverse proxies whose forwarding headers are trusted.
		 *
		 * Accepts plain addresses ("192.0.2.10") and CIDR ranges
		 * ("192.0.2.0/24", "2001:db8::/32"). Leave empty when the site is not
		 * behind a proxy.
		 *
		 * @param list<string> $proxies Trusted proxy addresses or ranges.
		 */
		$filtered = apply_filters( 'alpha_chat_trusted_proxies', array_values( (array) $configured ) );

		$out = [];
		foreach ( (array) $filtered as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' !== $entry ) {
				$out[] = $entry;
			}
		}

		return $out;
	}

	private static function matches( string $ip, string $entry ): bool {
		if ( ! str_contains( $entry, '/' ) ) {
			return self::normalize( $entry ) === $ip;
		}

		[ $subnet, $bits ] = explode( '/', $entry, 2 );
		$subnet            = self::normalize( $subnet );
		if ( '' === $subnet ) {
			return false;
		}

		$ip_packed     = inet_pton( $ip );
		$subnet_packed = inet_pton( $subnet );
		if ( false === $ip_packed || false === $subnet_packed ) {
			return false;
		}
		if ( strlen( $ip_packed ) !== strlen( $subnet_packed ) ) {
			return false;
		}

		$bits = (int) $bits;
		$max  = strlen( $ip_packed ) * 8;
		if ( $bits < 0 || $bits > $max ) {
			return false;
		}

		$whole_bytes = intdiv( $bits, 8 );
		$remainder   = $bits % 8;

		if ( $whole_bytes > 0 && substr( $ip_packed, 0, $whole_bytes ) !== substr( $subnet_packed, 0, $whole_bytes ) ) {
			return false;
		}

		if ( 0 === $remainder ) {
			return true;
		}

		$mask = ~( ( 1 << ( 8 - $remainder ) ) - 1 ) & 0xFF;

		return ( ord( $ip_packed[ $whole_bytes ] ) & $mask ) === ( ord( $subnet_packed[ $whole_bytes ] ) & $mask );
	}

	private static function normalize( string $ip ): string {
		$ip = trim( $ip );
		if ( '' === $ip ) {
			return '';
		}

		// Strip a port from "203.0.113.4:8080" and "[2001:db8::1]:8080".
		if ( str_starts_with( $ip, '[' ) ) {
			$close = strpos( $ip, ']' );
			if ( false !== $close ) {
				$ip = substr( $ip, 1, $close - 1 );
			}
		} elseif ( substr_count( $ip, ':' ) === 1 ) {
			$ip = strstr( $ip, ':', true ) ?: $ip;
		}

		return false === filter_var( $ip, FILTER_VALIDATE_IP ) ? '' : $ip;
	}

	private static function server( string $key ): string {
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return '';
		}

		$raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		return is_string( $raw ) ? $raw : '';
	}
}
