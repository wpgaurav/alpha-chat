<?php
declare(strict_types=1);

namespace AlphaChat\REST;

use AlphaChat\Chat\ChatService;
use AlphaChat\Settings\SettingsRepository;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ChatController {

	public function __construct(
		private readonly ChatService $chat,
		private readonly SettingsRepository $settings,
	) {}

	public function register( string $namespace ): void {
		$args = [
			'message'    => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => static fn ( $v ): bool => is_string( $v ) && '' !== trim( $v ),
			],
			'thread'     => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'origin_url' => [
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			],
		];

		register_rest_route(
			$namespace,
			'/chat',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send' ],
				'permission_callback' => [ self::class, 'allow_public' ],
				'args'                => $args,
			]
		);

		register_rest_route(
			$namespace,
			'/chat/stream',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'stream' ],
				'permission_callback' => [ self::class, 'allow_public' ],
				'args'                => $args,
			]
		);
	}

	public function send( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$guard = $this->guard( $request );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		try {
			$result = $this->chat->send( ...$this->chat_args( $request ) );
		} catch ( Throwable $e ) {
			return new WP_Error( 'alpha_chat_failed', $e->getMessage(), [ 'status' => 502 ] );
		}

		return new WP_REST_Response( $result );
	}

	public function stream( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$guard = $this->guard( $request );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		if ( headers_sent() ) {
			return $this->send( $request );
		}

		self::send_sse_headers();

		try {
			[ $message, $thread, $session, $user, $origin ] = $this->chat_args( $request );
			$result = $this->chat->send_streaming(
				$message,
				$thread,
				$session,
				$user,
				$origin,
				static function ( string $delta ): void {
					self::sse_event( 'delta', [ 'text' => $delta ] );
				}
			);
			self::sse_event( 'done', $result );
		} catch ( Throwable $e ) {
			self::sse_event( 'error', [ 'message' => $e->getMessage() ] );
		}

		exit;
	}

	public static function allow_public( WP_REST_Request $request ): bool {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}

		return self::verify_frontend_nonce( $nonce );
	}

	public static function verify_frontend_nonce( string $nonce ): bool {
		return (bool) wp_verify_nonce( $nonce, 'alpha_chat_frontend' );
	}

	private function guard( WP_REST_Request $request ): WP_Error|null {
		if ( ! (bool) $this->settings->get( 'chat_enabled', true ) ) {
			return new WP_Error( 'alpha_chat_disabled', __( 'Chat is disabled.', 'alpha-chat' ), [ 'status' => 403 ] );
		}

		$session_hash = self::session_hash( $request );
		if ( self::is_rate_limited( 'chat_' . $session_hash, 30, 60 ) ) {
			return new WP_Error( 'alpha_chat_rate_limited', __( 'Too many requests. Please slow down.', 'alpha-chat' ), [ 'status' => 429 ] );
		}

		return null;
	}

	/**
	 * @return array{0: string, 1: ?string, 2: string, 3: ?int, 4: string}
	 */
	private function chat_args( WP_REST_Request $request ): array {
		$session_hash = self::session_hash( $request );

		$message    = (string) $request->get_param( 'message' );
		$thread     = (string) $request->get_param( 'thread' );
		$thread     = '' === $thread ? null : $thread;
		$user_id    = get_current_user_id();
		$origin_url = mb_substr( (string) $request->get_param( 'origin_url' ), 0, 500 );

		return [
			$message,
			$thread,
			$session_hash,
			$user_id > 0 ? $user_id : null,
			$origin_url,
		];
	}

	private static function send_sse_headers(): void {
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		if ( function_exists( 'apache_setenv' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv -- required to disable proxy buffering.
			apache_setenv( 'no-gzip', '1' );
		}
		if ( '1' === (string) ini_get( 'zlib.output_compression' ) ) {
			ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}

		header( 'Content-Type: text/event-stream; charset=UTF-8' );
		header( 'Cache-Control: no-cache, no-transform' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function sse_event( string $event, array $data ): void {
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			return;
		}

		echo 'event: ' . $event . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE wire format.
		echo 'data: ' . $json . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON payload.
		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}

	private static function session_hash( WP_REST_Request $request ): string {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$ip = (string) ( $request->get_header( 'X-Forwarded-For' ) ?: $remote_addr );
		$ua = (string) $request->get_header( 'User-Agent' );
		return hash( 'sha256', $ip . '|' . $ua . '|' . wp_salt( 'auth' ) );
	}

	/**
	 * Simple fixed-window rate limiter backed by transients.
	 * Returns true if the caller has exceeded $limit within $window_seconds.
	 */
	public static function is_rate_limited( string $key, int $limit, int $window_seconds ): bool {
		$transient = 'alpha_chat_rl_' . md5( $key );
		$count     = (int) get_transient( $transient );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $transient, $count + 1, $window_seconds );
		return false;
	}
}
