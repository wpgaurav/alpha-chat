<?php
declare(strict_types=1);

namespace AlphaChat\Admin;

final class AdminMenu {

	public const PAGE_SLUG = 'alpha-chat';

	public function __construct( private readonly AdminAssets $assets ) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	public function register_menu(): void {
		$hook = add_menu_page(
			__( 'Alpha Chat', 'alpha-chat' ),
			__( 'Alpha Chat', 'alpha-chat' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-format-chat',
			60
		);

		add_action( "admin_print_scripts-{$hook}", [ $this->assets, 'enqueue_admin_app' ] );
	}

	public function render_page(): void {
		echo '<div class="wrap"><div id="alpha-chat-admin-root"></div></div>';
	}
}
