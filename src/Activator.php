<?php
declare(strict_types=1);

namespace AlphaChat;

use AlphaChat\Database\Schema;

final class Activator {

	public static function activate( bool $network_wide = false ): void {
		if ( version_compare( PHP_VERSION, ALPHA_CHAT_MIN_PHP, '<' ) ) {
			deactivate_plugins( plugin_basename( ALPHA_CHAT_FILE ) );
			wp_die(
				esc_html__( 'Alpha Chat requires PHP 8.2 or higher.', 'alpha-chat' ),
				esc_html__( 'Plugin activation error', 'alpha-chat' ),
				[ 'back_link' => true ]
			);
		}

		// Each site in a network has its own tables, so a network activation has
		// to install on all of them or the subsites boot without a schema.
		if ( $network_wide && is_multisite() ) {
			$sites = get_sites(
				[
					'fields'        => 'ids',
					'number'        => 0,
					'no_found_rows' => true,
				]
			);

			foreach ( (array) $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				restore_current_blog();
			}

			return;
		}

		self::install_site();
	}

	private static function install_site(): void {
		Schema::install();

		add_option( 'alpha_chat_db_version', Schema::VERSION );
		add_option( 'alpha_chat_installed_at', time() );

		if ( false === get_option( 'alpha_chat_settings' ) ) {
			add_option( 'alpha_chat_settings', [] );
		}

		do_action( 'alpha_chat_activated' );
	}
}
