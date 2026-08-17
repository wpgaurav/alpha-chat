<?php
declare(strict_types=1);

namespace AlphaChat\Support;

use Throwable;

final class Logger {

	public function __construct(
		private readonly ?LogRepository $store = null,
	) {}

	/** @param array<string, mixed> $context */
	public function debug( string $message, array $context = [] ): void {
		$this->log( 'debug', $message, $context );
	}

	/** @param array<string, mixed> $context */
	public function info( string $message, array $context = [] ): void {
		$this->log( 'info', $message, $context );
	}

	/** @param array<string, mixed> $context */
	public function warning( string $message, array $context = [] ): void {
		$this->log( 'warning', $message, $context );
	}

	/** @param array<string, mixed> $context */
	public function error( string $message, array $context = [] ): void {
		$this->log( 'error', $message, $context );
	}

	/** @param array<string, mixed> $context */
	private function log( string $level, string $message, array $context ): void {
		// Errors and warnings always go to the log. Gating everything behind
		// WP_DEBUG meant a production site failing every provider call left no
		// trace at all, which makes support impossible.
		$always = in_array( $level, [ 'error', 'warning' ], true );

		/**
		 * Filter whether an Alpha Chat log line is written.
		 *
		 * @param bool                 $enabled Whether to write the line.
		 * @param string               $level   Log level.
		 * @param string               $message Log message.
		 * @param array<string, mixed> $context Structured context.
		 */
		$enabled = (bool) apply_filters(
			'alpha_chat_should_log',
			$always || ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			$level,
			$message,
			$context
		);

		if ( ! $enabled ) {
			return;
		}

		$line = sprintf(
			'[alpha-chat][%s] %s',
			$level,
			$message
		);

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Persist so the failure is visible in the admin. Logging must never be
		// able to break the request it is describing — a missing table during an
		// upgrade would otherwise turn a warning into a fatal.
		if ( null === $this->store ) {
			return;
		}

		try {
			$this->store->write( $level, $message, $context, self::caller() );
		} catch ( Throwable $e ) {
			// Already emitted to error_log above. Swallowing here is deliberate:
			// a logging failure must not escalate into a request failure.
			unset( $e );
		}
	}

	/**
	 * Identify the plugin class that logged this, for the admin view.
	 */
	private static function caller(): string {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace

		foreach ( $frames as $frame ) {
			$class = (string) ( $frame['class'] ?? '' );
			if ( '' !== $class && ! str_ends_with( $class, 'Logger' ) && str_starts_with( $class, 'AlphaChat\\' ) ) {
				return $class . '::' . $frame['function'];
			}
		}

		return '';
	}
}
