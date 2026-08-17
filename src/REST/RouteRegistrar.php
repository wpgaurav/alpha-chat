<?php
declare(strict_types=1);

namespace AlphaChat\REST;

use AlphaChat\Support\Container;

final class RouteRegistrar {

	public const NAMESPACE = 'alpha-chat/v1';

	public function __construct( private readonly Container $container ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/ping',
			[
				'methods'             => 'GET',
				'callback'            => static fn () => [ 'ok' => true, 'version' => ALPHA_CHAT_VERSION ],
				'permission_callback' => '__return_true',
			]
		);

		// The widget's nonce is printed into the page, so a full-page cache serves
		// a stale one once it expires and every chat request 403s with no way for
		// the visitor to recover. This endpoint is never cached, so the widget can
		// mint a fresh nonce and retry.
		register_rest_route(
			self::NAMESPACE,
			'/nonce',
			[
				'methods'             => 'GET',
				'callback'            => static function (): \WP_REST_Response {
					$response = new \WP_REST_Response( [ 'nonce' => wp_create_nonce( 'wp_rest' ) ] );
					$response->set_headers( [ 'Cache-Control' => 'no-store, max-age=0' ] );

					return $response;
				},
				'permission_callback' => '__return_true',
			]
		);

		/** @var SettingsController $settings */
		$settings = $this->container->get( SettingsController::class );
		$settings->register( self::NAMESPACE );

		/** @var ChatController $chat */
		$chat = $this->container->get( ChatController::class );
		$chat->register( self::NAMESPACE );

		/** @var KnowledgeBaseController $kb */
		$kb = $this->container->get( KnowledgeBaseController::class );
		$kb->register( self::NAMESPACE );

		/** @var ThreadsController $threads */
		$threads = $this->container->get( ThreadsController::class );
		$threads->register( self::NAMESPACE );

		/** @var ContactController $contacts */
		$contacts = $this->container->get( ContactController::class );
		$contacts->register( self::NAMESPACE );

		/** @var FaqController $faqs */
		$faqs = $this->container->get( FaqController::class );
		$faqs->register( self::NAMESPACE );

		/**
		 * Fires after built-in Alpha Chat REST routes are registered.
		 *
		 * @param string    $namespace Route namespace.
		 * @param Container $container DI container.
		 */
		do_action( 'alpha_chat_register_rest_routes', self::NAMESPACE, $this->container );
	}
}
