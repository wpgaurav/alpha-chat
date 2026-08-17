<?php
declare(strict_types=1);

namespace AlphaChat\Support;

final class Logger {

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
	}
}
