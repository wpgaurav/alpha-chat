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
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
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
