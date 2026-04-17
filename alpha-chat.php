<?php
/**
 * Plugin Name:       Alpha Chat
 * Plugin URI:        https://github.com/wpgaurav/alpha-chat
 * Description:       AI-powered chatbot for WordPress. Turn your site's content into a conversation.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Gaurav Tiwari
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       alpha-chat
 * Domain Path:       /languages
 *
 * @package AlphaChat
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Alpha Chat requires PHP 8.2 or higher.', 'alpha-chat' )
			);
		}
	);
	return;
}

define( 'ALPHA_CHAT_VERSION', '0.1.0' );
define( 'ALPHA_CHAT_FILE', __FILE__ );
define( 'ALPHA_CHAT_PATH', plugin_dir_path( __FILE__ ) );
define( 'ALPHA_CHAT_URL', plugin_dir_url( __FILE__ ) );
define( 'ALPHA_CHAT_MIN_WP', '6.5' );
define( 'ALPHA_CHAT_MIN_PHP', '8.2' );

$alpha_chat_autoload = ALPHA_CHAT_PATH . 'vendor/autoload.php';

if ( ! is_readable( $alpha_chat_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Alpha Chat is missing its Composer autoload. Run `composer install` in the plugin directory.', 'alpha-chat' )
			);
		}
	);
	return;
}

require_once $alpha_chat_autoload;

register_activation_hook( __FILE__, [ AlphaChat\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ AlphaChat\Deactivator::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		AlphaChat\Plugin::instance()->boot();
	}
);
