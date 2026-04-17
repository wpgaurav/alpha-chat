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
		register_rest_route(
			$namespace,
			'/chat',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send' ],
				'permission_callback' => [ self::class, 'allow_public' ],
				'args'                => [
					'message' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => static fn ( $v ): bool => is_string( $v ) && '' !== trim( $v ),
					],
					'thread'  => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	public function send( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! (bool) $this->settings->get( 'chat_enabled', true ) ) {
			return new WP_Error( 'alpha_chat_disabled', __( 'Chat is disabled.', 'alpha-chat' ), [ 'status' => 403 ] );
		}

		$session_hash = self::session_hash( $request );
		if ( self::is_rate_limited( 'chat_' . $session_hash, 30, 60 ) ) {
			return new WP_Error( 'alpha_chat_rate_limited', __( 'Too many requests. Please slow down.', 'alpha-chat' ), [ 'status' => 429 ] );
		}

		$message = (string) $request->get_param( 'message' );
		$thread  = (string) $request->get_param( 'thread' );
		$thread  = '' === $thread ? null : $thread;
		$user_id = get_current_user_id();

		try {
			$result = $this->chat->send( $message, $thread, $session_hash, $user_id > 0 ? $user_id : null );
		} catch ( Throwable $e ) {
			return new WP_Error( 'alpha_chat_failed', $e->getMessage(), [ 'status' => 502 ] );
		}

		return new WP_REST_Response( $result );
	}

	public static function allow_public(): bool {
		return true;
	}

	private static function session_hash( WP_REST_Request $request ): string {
		$ip = (string) ( $request->get_header( 'X-Forwarded-For' ) ?: $_SERVER['REMOTE_ADDR'] ?? '' );
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
