<?php
declare(strict_types=1);

namespace AlphaChat\Support;

/**
 * Fixed-window rate limiter backed by transients.
 *
 * The window start is stored alongside the counter so a steady stream of
 * requests cannot keep pushing the expiry forward, and the caller is released
 * exactly $window_seconds after the first request in the window.
 */
final class RateLimiter {

	private const PREFIX = 'alpha_chat_rl_';

	/**
	 * Record a hit and report whether the caller is over the limit.
	 *
	 * Returns true when the request should be rejected.
	 */
	public static function hit( string $key, int $limit, int $window_seconds ): bool {
		if ( $limit <= 0 || $window_seconds <= 0 ) {
			return false;
		}

		$transient = self::PREFIX . md5( $key );
		$now       = time();
		$stored    = get_transient( $transient );

		$start = is_array( $stored ) ? (int) ( $stored['start'] ?? 0 ) : 0;
		$count = is_array( $stored ) ? (int) ( $stored['count'] ?? 0 ) : 0;

		if ( $start <= 0 || ( $now - $start ) >= $window_seconds ) {
			$start = $now;
			$count = 0;
		}

		++$count;

		// Keep the record for the remainder of the window only.
		$ttl = max( 1, $window_seconds - ( $now - $start ) );
		set_transient( $transient, [ 'start' => $start, 'count' => $count ], $ttl );

		return $count > $limit;
	}

	/**
	 * Resolve the configured limit pair for a bucket.
	 *
	 * @return array{0: int, 1: int} Tuple of limit and window in seconds.
	 */
	public static function limits( string $bucket, int $default_limit, int $default_window ): array {
		/**
		 * Filter the rate limit applied to an Alpha Chat bucket.
		 *
		 * Buckets: "chat_session", "chat_global", "contact_ip", "contact_global".
		 * Return [ limit, window_seconds ]. A limit of 0 disables the bucket.
		 *
		 * @param array{0: int, 1: int} $limits Tuple of limit and window seconds.
		 * @param string                $bucket Bucket identifier.
		 */
		$limits = apply_filters( 'alpha_chat_rate_limit', [ $default_limit, $default_window ], $bucket );

		if ( ! is_array( $limits ) || ! isset( $limits[0], $limits[1] ) ) {
			return [ $default_limit, $default_window ];
		}

		return [ max( 0, (int) $limits[0] ), max( 0, (int) $limits[1] ) ];
	}
}
