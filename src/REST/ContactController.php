<?php
declare(strict_types=1);

namespace AlphaChat\REST;

use AlphaChat\Chat\ContactRepository;
use AlphaChat\Settings\SettingsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ContactController {

	public function __construct(
		private readonly ContactRepository $contacts,
		private readonly SettingsRepository $settings,
	) {}

	public function register( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/contact',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'name'    => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn ( $v ): bool => is_string( $v ) && '' !== trim( $v ),
					],
					'email'   => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => static fn ( $v ): bool => is_string( $v ) && is_email( $v ),
					],
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

		register_rest_route(
			$namespace,
			'/contacts',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list' ],
				'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
				'args'                => [
					'page'     => [ 'type' => 'integer', 'default' => 1 ],
					'per_page' => [ 'type' => 'integer', 'default' => 20 ],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/contacts/(?P<id>\d+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete' ],
				'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
				'args'                => [
					'id' => [ 'type' => 'integer', 'required' => true ],
				],
			]
		);
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ip_hash = self::ip_hash( $request );
		if ( ChatController::is_rate_limited( 'contact_' . $ip_hash, 5, 3600 ) ) {
			return new WP_Error( 'alpha_chat_rate_limited', __( 'Too many submissions. Try again later.', 'alpha-chat' ), [ 'status' => 429 ] );
		}

		$user_id = get_current_user_id();
		$name    = (string) $request->get_param( 'name' );
		$email   = (string) $request->get_param( 'email' );
		$message = (string) $request->get_param( 'message' );
		$thread  = (string) $request->get_param( 'thread' );

		$id = $this->contacts->create(
			[
				'thread_uuid' => $thread,
				'name'        => $name,
				'email'       => $email,
				'message'     => $message,
				'user_id'     => $user_id > 0 ? $user_id : null,
				'user_agent'  => (string) $request->get_header( 'User-Agent' ),
				'ip_hash'     => $ip_hash,
			]
		);

		$this->notify_admin( $id, $name, $email, $message, $thread );

		do_action( 'alpha_chat_contact_submitted', $id, $request );

		return new WP_REST_Response( [ 'ok' => true, 'id' => $id ] );
	}

	private function notify_admin( int $id, string $name, string $email, string $message, string $thread ): void {
		$to = (string) $this->settings->get( 'contact_notify_email', '' );
		if ( '' === $to || ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		if ( '' === $to ) {
			return;
		}

		$site    = (string) get_bloginfo( 'name' );
		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] New chat inquiry', 'alpha-chat' ),
			$site
		);

		$lines = [
			sprintf( __( 'Name: %s', 'alpha-chat' ), $name ),
			sprintf( __( 'Email: %s', 'alpha-chat' ), $email ),
			'',
			__( 'Message:', 'alpha-chat' ),
			$message,
			'',
			'—',
			sprintf( __( 'Thread: %s', 'alpha-chat' ), '' === $thread ? __( '(none)', 'alpha-chat' ) : $thread ),
			sprintf( __( 'Admin: %s', 'alpha-chat' ), admin_url( 'admin.php?page=alpha-chat#contacts' ) ),
		];

		$headers = [
			'Content-Type: text/plain; charset=UTF-8',
			'Reply-To: ' . sprintf( '%s <%s>', $name, $email ),
		];

		wp_mail( $to, $subject, implode( "\n", $lines ), $headers );
	}

	public function list( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

		return new WP_REST_Response( $this->contacts->list( $page, $per_page ) );
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );
		return new WP_REST_Response( [ 'deleted' => $this->contacts->delete( $id ) ] );
	}

	private static function ip_hash( WP_REST_Request $request ): string {
		$ip = (string) ( $request->get_header( 'X-Forwarded-For' ) ?: $_SERVER['REMOTE_ADDR'] ?? '' );
		return '' === $ip ? '' : hash( 'sha256', $ip . '|' . wp_salt( 'auth' ) );
	}
}
