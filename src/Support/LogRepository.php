<?php
declare(strict_types=1);

namespace AlphaChat\Support;

use AlphaChat\Database\Schema;

/**
 * Persistent store for plugin errors, so failures are visible in the admin
 * instead of only in a PHP error log the site owner may never see.
 */
final class LogRepository {

	/** Levels that are worth keeping on disk. */
	private const PERSISTED = [ 'error', 'warning' ];

	/**
	 * Record a log line.
	 *
	 * @param array<string, mixed> $context
	 */
	public function write( string $level, string $message, array $context = [], string $source = '' ): void {
		if ( ! in_array( $level, self::PERSISTED, true ) ) {
			return;
		}

		global $wpdb;

		$encoded = empty( $context ) ? null : wp_json_encode( self::redact( $context ) );

		$wpdb->insert(
			Schema::logs_table(),
			[
				'level'      => $level,
				'message'    => self::redact_string( $message ),
				'context'    => is_string( $encoded ) ? $encoded : null,
				'source'     => mb_substr( $source, 0, 191 ),
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function list( int $page = 1, int $per_page = 25, string $level = '' ): array {
		global $wpdb;

		$offset = max( 0, ( max( 1, $page ) - 1 ) * $per_page );

		$table = esc_sql( Schema::logs_table() );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( in_array( $level, self::PERSISTED, true ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . $table . ' WHERE level = %s ORDER BY id DESC LIMIT %d OFFSET %d',
					$level,
					$per_page,
					$offset
				),
				ARRAY_A
			);
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE level = %s', $level ) );
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . $table . ' ORDER BY id DESC LIMIT %d OFFSET %d',
					$per_page,
					$offset
				),
				ARRAY_A
			);
			$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
		}

		// phpcs:enable

		$items = [];
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$context = null;
			if ( ! empty( $row['context'] ) ) {
				$decoded = json_decode( (string) $row['context'], true );
				$context = is_array( $decoded ) ? $decoded : null;
			}
			$items[] = [
				'id'         => (int) $row['id'],
				'level'      => (string) $row['level'],
				'message'    => (string) $row['message'],
				'context'    => $context,
				'source'     => (string) $row['source'],
				'created_at' => (string) $row['created_at'],
			];
		}

		return [ 'items' => $items, 'total' => $total ];
	}

	/** @return array{error: int, warning: int, total: int} */
	public function counts(): array {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT level, COUNT(*) AS c FROM ' . esc_sql( Schema::logs_table() ) . ' GROUP BY level', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$out = [ 'error' => 0, 'warning' => 0, 'total' => 0 ];
		foreach ( (array) $rows as $row ) {
			$level = (string) ( $row['level'] ?? '' );
			$count = (int) ( $row['c'] ?? 0 );
			if ( isset( $out[ $level ] ) ) {
				$out[ $level ] = $count;
			}
			$out['total'] += $count;
		}

		return $out;
	}

	public function clear(): int {
		global $wpdb;

		$table = esc_sql( Schema::logs_table() );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
		$wpdb->query( 'DELETE FROM ' . $table );
		// phpcs:enable

		return $count;
	}

	/**
	 * Drop entries past the retention window and the row ceiling, so the table
	 * cannot grow without bound on a site that is failing continuously.
	 */
	public function prune(): void {
		global $wpdb;

		$table = esc_sql( Schema::logs_table() );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		/**
		 * Filter how many days of Alpha Chat log entries are kept.
		 *
		 * @param int $days Retention window in days.
		 */
		$days = max( 1, (int) apply_filters( 'alpha_chat_log_retention_days', 30 ) );

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $table . ' WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)',
				$days
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		/**
		 * Filter the maximum number of Alpha Chat log rows retained.
		 *
		 * @param int $max Maximum rows.
		 */
		$max = max( 100, (int) apply_filters( 'alpha_chat_log_max_rows', 2000 ) );

		$cutoff = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . $table . ' ORDER BY id DESC LIMIT 1 OFFSET %d', $max )
		);

		if ( null !== $cutoff ) {
			$wpdb->query(
				$wpdb->prepare( 'DELETE FROM ' . $table . ' WHERE id <= %d', (int) $cutoff )
			);
		}

		// phpcs:enable
	}

	/**
	 * Strip anything that looks like a credential.
	 *
	 * Provider errors frequently quote the failing request back, and settings
	 * arrays can carry keys directly, so nothing reaches the table unfiltered.
	 *
	 * @param array<string, mixed> $context
	 *
	 * @return array<string, mixed>
	 */
	private static function redact( array $context ): array {
		$out = [];

		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/(key|secret|token|password|authorization|hash)/i', $key ) ) {
				$out[ $key ] = '[redacted]';
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = self::redact( $value );
				continue;
			}
			$out[ $key ] = is_string( $value ) ? self::redact_string( $value ) : $value;
		}

		return $out;
	}

	public static function redact_string( string $value ): string {
		// Known provider key shapes, plus any Bearer credential.
		$patterns = [
			'/\b(sk|pk|rk)-[A-Za-z0-9_\-]{12,}/',
			'/\bxai-[A-Za-z0-9_\-]{12,}/',
			'/\bpa-[A-Za-z0-9_\-]{12,}/',
			'/\bBearer\s+[A-Za-z0-9._\-]{12,}/i',
		];

		foreach ( $patterns as $pattern ) {
			$value = (string) preg_replace( $pattern, '[redacted]', $value );
		}

		return mb_substr( $value, 0, 2000 );
	}
}
