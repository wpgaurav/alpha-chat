<?php
declare(strict_types=1);

namespace AlphaChat\Frontend;

use AlphaChat\REST\RouteRegistrar;
use AlphaChat\Settings\SettingsRepository;
use AlphaChat\Support\AssetManifest;

final class WidgetLoader {

	public const SCRIPT_HANDLE = 'alpha-chat-widget';

	private bool $localized = false;

	public function __construct(
		private readonly SettingsRepository $settings,
	) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
		add_shortcode( 'alpha_chat', [ $this, 'shortcode' ] );
	}

	public function shortcode( mixed $atts = [] ): string {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return '';
		}
		$this->enqueue_assets();
		return '<div class="alpha-chat-block"><div class="alpha-chat-block__mount" data-alpha-chat-embed></div></div>';
	}

	public function maybe_enqueue(): void {
		if ( ! $this->should_display() ) {
			return;
		}

		$this->enqueue_assets();

		add_action( 'wp_footer', [ $this, 'render_mount' ] );
	}

	/**
	 * Enqueue the widget script + styles and localize client data.
	 * Safe to call multiple times — wp_enqueue_script dedupes by handle.
	 */
	public function enqueue_assets(): void {
		$handle = self::SCRIPT_HANDLE;
		$asset  = AssetManifest::load( 'build/widget/index.asset.php' );

		wp_enqueue_script(
			$handle,
			ALPHA_CHAT_URL . 'build/widget/index.js',
			$asset['dependencies'],
			$asset['version'],
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		wp_set_script_translations( $handle, 'alpha-chat' );

		if ( wp_script_is( $handle, 'done' ) || $this->localized ) {
			return;
		}

		$settings = $this->settings->all();
		$position = in_array( $settings['launcher_position'] ?? 'right', [ 'left', 'center', 'right' ], true )
			? $settings['launcher_position']
			: 'right';

		wp_localize_script(
			$handle,
			'alphaChatClient',
			[
				'restUrl'               => esc_url_raw( rest_url( RouteRegistrar::NAMESPACE ) ),
				'nonce'                 => wp_create_nonce( 'alpha_chat_frontend' ),
				'welcomeMessage'        => (string) $settings['welcome_message'],
				'fallbackMessage'       => (string) $settings['fallback_message'],
				'predefinedQuestions'   => (array) $settings['predefined_questions'],
				'colors'                => (array) $settings['colors'],
				'launcherNudge'         => (string) ( $settings['launcher_nudge'] ?? '' ),
				'launcherPosition'      => $position,
				'brandName'             => (string) ( $settings['brand_name'] ?? get_bloginfo( 'name' ) ),
				'contactFormEnabled'    => (bool) ( $settings['contact_form_enabled'] ?? true ),
				'contactCtaLabel'       => (string) ( $settings['contact_cta_label'] ?? '' ),
				'contactSuccessMessage' => (string) ( $settings['contact_success_message'] ?? '' ),
				'strings'               => [
					'send'         => __( 'Send', 'alpha-chat' ),
					'typing'       => __( 'Typing…', 'alpha-chat' ),
					'reset'        => __( 'New conversation', 'alpha-chat' ),
					'input'        => __( 'Type your message…', 'alpha-chat' ),
					'nameLabel'    => __( 'Your name', 'alpha-chat' ),
					'emailLabel'   => __( 'Your email', 'alpha-chat' ),
					'messageLabel' => __( 'How can we help?', 'alpha-chat' ),
					'cancel'       => __( 'Cancel', 'alpha-chat' ),
					'submit'       => __( 'Send', 'alpha-chat' ),
				],
			]
		);

		$this->localized = true;
	}

	public function render_mount(): void {
		echo '<div id="alpha-chat-widget-root" data-alpha-chat-root></div>';
	}

	private function should_display(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( ! (bool) $this->settings->get( 'chat_enabled', true ) ) {
			return false;
		}

		$default = (bool) $this->settings->get( 'show_launcher', false );

		/**
		 * Filter whether to render the floating Alpha Chat widget on the current request.
		 *
		 * @param bool $default True if the site-wide launcher is enabled.
		 */
		return (bool) apply_filters( 'alpha_chat_display_widget', $default );
	}
}
