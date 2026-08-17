<?php
declare(strict_types=1);

namespace AlphaChat\REST;

use AlphaChat\Chat\ChatService;
use AlphaChat\Settings\SettingsRepository;
use AlphaChat\Support\ClientIp;
use AlphaChat\Support\RateLimiter;
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
			'message'      => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => static fn ( $v ): bool => is_string( $v ) && '' !== trim( $v ),
			],
			'thread'       => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'origin_url'   => [
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			],
			'origin_title' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
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
			// 503, not 502: a CDN in front of the site replaces a 502 body with
			// its own error page, so the actual reason ("API key is not
			// configured") never reaches the widget or the site owner.
			return new WP_Error( 'alpha_chat_failed', $e->getMessage(), [ 'status' => 503 ] );
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

		// Proxies and PHP-FPM often close an idle connection well before a slow
		// model emits its first token. A comment line is a no-op to the client
		// but keeps the socket warm.
		self::sse_comment( 'open' );

		try {
			[ $message, $thread, $session, $user, $origin, $origin_title ] = $this->chat_args( $request );
			$result = $this->chat->send_streaming(
				$message,
				$thread,
				$session,
				$user,
				$origin,
				static function ( string $delta ): void {
					self::sse_event( 'delta', [ 'text' => $delta ] );
				},
				$origin_title
			);
			self::sse_event( 'done', $result );
		} catch ( Throwable $e ) {
			self::sse_event( 'error', [ 'message' => $e->getMessage() ] );
		}

		// PHP runs registered shutdown functions on exit, so WordPress's
		// shutdown_action_hook still fires here and the object cache still closes.
		// Do not call do_action( 'shutdown' ) by hand — it would run twice.
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
		// WordPress REST cookie auth already requires a wp_rest nonce in X-WP-Nonce.
		// A custom action here caused "Cookie check failed" before this callback ran.
		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	private function guard( WP_REST_Request $request ): WP_Error|null {
		if ( ! (bool) $this->settings->get( 'chat_enabled', true ) ) {
			return new WP_Error( 'alpha_chat_disabled', __( 'Chat is disabled.', 'alpha-chat' ), [ 'status' => 403 ] );
		}

		$too_many = new WP_Error(
			'alpha_chat_rate_limited',
			__( 'Too many requests. Please slow down.', 'alpha-chat' ),
			[ 'status' => 429 ]
		);

		$session_hash = self::session_hash( $request );

		[ $limit, $window ] = RateLimiter::limits( 'chat_session', 30, 60 );
		if ( RateLimiter::hit( 'chat_' . $session_hash, $limit, $window ) ) {
			return $too_many;
		}

		// A site-wide ceiling that no per-caller identifier can partition. This is
		// the backstop that caps provider spend if the per-session bucket is ever
		// split by a forged header or a fleet of distinct clients.
		[ $global_limit, $global_window ] = RateLimiter::limits( 'chat_global', 240, 60 );
		if ( RateLimiter::hit( 'chat_global', $global_limit, $global_window ) ) {
			return $too_many;
		}

		return null;
	}

	/**
	 * @return array{0: string, 1: ?string, 2: string, 3: ?int, 4: string, 5: string}
	 */
	private function chat_args( WP_REST_Request $request ): array {
		$session_hash = self::session_hash( $request );

		$message      = (string) $request->get_param( 'message' );
		$thread       = (string) $request->get_param( 'thread' );
		$thread       = '' === $thread ? null : $thread;
		$user_id      = get_current_user_id();
		$origin_url   = mb_substr( (string) $request->get_param( 'origin_url' ), 0, 500 );
		$origin_title = mb_substr( (string) $request->get_param( 'origin_title' ), 0, 200 );

		return [
			$message,
			$thread,
			$session_hash,
			$user_id > 0 ? $user_id : null,
			$origin_url,
			$origin_title,
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
	 * Emit an SSE comment line. Clients ignore it; proxies see traffic.
	 */
	public static function sse_comment( string $note ): void {
		echo ': ' . $note . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE wire format.
		if ( function_exists( 'flush' ) ) {
			flush();
		}
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
		$ip = ClientIp::get();
		$ua = (string) $request->get_header( 'User-Agent' );

		// Logged-in visitors get a stable identity that does not shift with a
		// changing IP, so their thread survives a network change.
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return hash( 'sha256', 'user:' . $user_id . '|' . wp_salt( 'auth' ) );
		}

		return hash( 'sha256', $ip . '|' . $ua . '|' . wp_salt( 'auth' ) );
	}

	/**
	 * Fixed-window rate limiter.
	 *
	 * @deprecated 0.3.0 Use AlphaChat\Support\RateLimiter::hit() instead.
	 */
	public static function is_rate_limited( string $key, int $limit, int $window_seconds ): bool {
		return RateLimiter::hit( $key, $limit, $window_seconds );
	}
}
