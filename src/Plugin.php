<?php
declare(strict_types=1);

namespace AlphaChat;

use AlphaChat\Admin\AdminAssets;
use AlphaChat\Admin\AdminMenu;
use AlphaChat\Admin\PostRowActions;
use AlphaChat\Block\BlockRegistrar;
use AlphaChat\Chat\ChatService;
use AlphaChat\Chat\ContactRepository;
use AlphaChat\Chat\FaqImporter;
use AlphaChat\Chat\FaqRepository;
use AlphaChat\Chat\MessageRepository;
use AlphaChat\Chat\ThreadRepository;
use AlphaChat\Chat\ThreadTitler;
use AlphaChat\Frontend\WidgetLoader;
use AlphaChat\Http\HttpClient;
use AlphaChat\KnowledgeBase\Indexer;
use AlphaChat\KnowledgeBase\PostHooks;
use AlphaChat\Licensing\LicenseManager;
use AlphaChat\Providers\ProviderFactory;
use AlphaChat\REST\ChatController;
use AlphaChat\REST\ContactController;
use AlphaChat\REST\FaqController;
use AlphaChat\REST\KnowledgeBaseController;
use AlphaChat\REST\LogsController;
use AlphaChat\REST\RouteRegistrar;
use AlphaChat\REST\SettingsController;
use AlphaChat\REST\ThreadsController;
use AlphaChat\Scheduler\ReindexScheduler;
use AlphaChat\Settings\SettingsRepository;
use AlphaChat\Support\Container;
use AlphaChat\Support\Logger;
use AlphaChat\Support\LogRepository;
use AlphaChat\Text\TokenCounter;

final class Plugin {

	private static ?self $instance = null;

	private bool $booted = false;

	public readonly Container $container;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->container = new Container();
		$this->register_services( $this->container );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		load_plugin_textdomain( 'alpha-chat', false, dirname( plugin_basename( ALPHA_CHAT_FILE ) ) . '/languages' );

		\AlphaChat\Database\Schema::maybe_upgrade();

		$this->container->get( RouteRegistrar::class )->register();
		$this->container->get( ReindexScheduler::class )->register();
		$this->container->get( PostHooks::class )->register();
		$this->container->get( BlockRegistrar::class )->register( $this->container->get( WidgetLoader::class ) );
		$this->container->get( WidgetLoader::class )->register();
		$this->container->get( LicenseManager::class )->register();
		$this->container->get( ThreadTitler::class )->register();

		// Keep the log table bounded. Hooked to the daily WordPress event rather
		// than pruning on every write, which would add a DELETE to each failure.
		add_action( 'alpha_chat_prune_logs', [ $this->container->get( LogRepository::class ), 'prune' ] );
		if ( ! wp_next_scheduled( 'alpha_chat_prune_logs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'alpha_chat_prune_logs' );
		}

		if ( is_admin() ) {
			$this->container->get( AdminMenu::class )->register();
			$this->container->get( PostRowActions::class )->register();
		}

		/**
		 * Fires after Alpha Chat finishes booting.
		 *
		 * @param Container $container DI container.
		 */
		do_action( 'alpha_chat_booted', $this->container );
	}

	private function register_services( Container $c ): void {
		$c->set( LogRepository::class, static fn () => new LogRepository() );
		$c->set(
			Logger::class,
			static fn ( Container $c ) => new Logger( $c->get( LogRepository::class ) ),
		);
		$c->set( TokenCounter::class, static fn () => new TokenCounter() );
		$c->set(
			HttpClient::class,
			static function (): HttpClient {
				$timeout = (int) apply_filters( 'alpha_chat_http_timeout', 60 );

				return new HttpClient( max( 1, $timeout ) );
			},
		);
		$c->set( SettingsRepository::class, static fn () => new SettingsRepository() );
		$c->set( LicenseManager::class, static fn () => new LicenseManager() );

		$c->set(
			ProviderFactory::class,
			static fn ( Container $c ) => new ProviderFactory(
				$c->get( SettingsRepository::class ),
				$c->get( HttpClient::class ),
			),
		);

		$c->set( ThreadRepository::class, static fn () => new ThreadRepository() );
		$c->set( MessageRepository::class, static fn () => new MessageRepository() );
		$c->set( ContactRepository::class, static fn () => new ContactRepository() );
		$c->set( FaqRepository::class, static fn () => new FaqRepository() );
		$c->set(
			FaqImporter::class,
			static fn ( Container $c ) => new FaqImporter(
				$c->get( HttpClient::class ),
				$c->get( FaqRepository::class ),
			),
		);

		$c->set(
			Indexer::class,
			static fn ( Container $c ) => new Indexer(
				$c->get( ProviderFactory::class ),
				$c->get( SettingsRepository::class ),
				$c->get( TokenCounter::class ),
				$c->get( Logger::class ),
			),
		);

		$c->set(
			ThreadTitler::class,
			static fn ( Container $c ) => new ThreadTitler(
				$c->get( ProviderFactory::class ),
				$c->get( SettingsRepository::class ),
				$c->get( ThreadRepository::class ),
				$c->get( MessageRepository::class ),
				$c->get( Logger::class ),
			),
		);

		$c->set(
			ChatService::class,
			static fn ( Container $c ) => new ChatService(
				$c->get( ProviderFactory::class ),
				$c->get( SettingsRepository::class ),
				$c->get( ThreadRepository::class ),
				$c->get( MessageRepository::class ),
				$c->get( TokenCounter::class ),
				$c->get( Logger::class ),
				$c->get( FaqRepository::class ),
				$c->get( ThreadTitler::class ),
			),
		);

		$c->set(
			ReindexScheduler::class,
			static fn ( Container $c ) => new ReindexScheduler( $c->get( Indexer::class ) ),
		);

		$c->set(
			PostHooks::class,
			static fn ( Container $c ) => new PostHooks(
				$c->get( Indexer::class ),
				$c->get( ReindexScheduler::class ),
			),
		);

		$c->set( AdminAssets::class, static fn () => new AdminAssets() );

		$c->set(
			AdminMenu::class,
			static fn ( Container $c ) => new AdminMenu( $c->get( AdminAssets::class ) ),
		);

		$c->set(
			PostRowActions::class,
			static fn ( Container $c ) => new PostRowActions( $c->get( Indexer::class ) ),
		);

		$c->set( BlockRegistrar::class, static fn () => new BlockRegistrar() );

		$c->set(
			WidgetLoader::class,
			static fn ( Container $c ) => new WidgetLoader(
				$c->get( SettingsRepository::class ),
			),
		);

		$c->set(
			SettingsController::class,
			static fn ( Container $c ) => new SettingsController(
				$c->get( SettingsRepository::class ),
				$c->get( Indexer::class ),
				$c->get( MessageRepository::class ),
				$c->get( ReindexScheduler::class ),
			),
		);

		$c->set(
			ChatController::class,
			static fn ( Container $c ) => new ChatController(
				$c->get( ChatService::class ),
				$c->get( SettingsRepository::class ),
			),
		);

		$c->set(
			KnowledgeBaseController::class,
			static fn ( Container $c ) => new KnowledgeBaseController(
				$c->get( Indexer::class ),
				$c->get( ReindexScheduler::class ),
			),
		);

		$c->set(
			ThreadsController::class,
			static fn ( Container $c ) => new ThreadsController(
				$c->get( ThreadRepository::class ),
				$c->get( MessageRepository::class ),
			),
		);

		$c->set(
			ContactController::class,
			static fn ( Container $c ) => new ContactController(
				$c->get( ContactRepository::class ),
				$c->get( SettingsRepository::class ),
			),
		);

		$c->set(
			FaqController::class,
			static fn ( Container $c ) => new FaqController(
				$c->get( FaqRepository::class ),
				$c->get( FaqImporter::class ),
			),
		);

		$c->set(
			LogsController::class,
			static fn ( Container $c ) => new LogsController(
				$c->get( LogRepository::class ),
			),
		);

		$c->set(
			RouteRegistrar::class,
			static fn ( Container $c ) => new RouteRegistrar( $c ),
		);
	}
}
