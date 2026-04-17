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

		/**
		 * Fires after built-in Alpha Chat REST routes are registered.
		 *
		 * @param string    $namespace Route namespace.
		 * @param Container $container DI container.
		 */
		do_action( 'alpha_chat_register_rest_routes', self::NAMESPACE, $this->container );
	}
}
