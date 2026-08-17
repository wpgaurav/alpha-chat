<?php
/**
 * Uninstall cleanup for Alpha Chat.
 *
 * Runs when the plugin is deleted from the WordPress admin. Removes all
 * plugin-owned tables, options, post meta, and scheduled actions. On multisite
 * this runs for every site in the network, since each one has its own tables.
 *
 * @package AlphaChat
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Action Scheduler lives in the Composer autoload, which is not loaded for us
// here. Without it as_unschedule_all_actions() never exists and the plugin's
// queued actions are left behind.
$alpha_chat_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $alpha_chat_autoload ) ) {
	require_once $alpha_chat_autoload;
}

/**
 * Remove every Alpha Chat artefact owned by the current site.
 */
function alpha_chat_uninstall_site(): void {
	global $wpdb;

	$tables = [
		$wpdb->prefix . 'alpha_chat_chunks',
		$wpdb->prefix . 'alpha_chat_threads',
		$wpdb->prefix . 'alpha_chat_messages',
		$wpdb->prefix . 'alpha_chat_contacts',
		$wpdb->prefix . 'alpha_chat_faqs',
	];

	foreach ( $tables as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	$options = [
		'alpha_chat_settings',
		'alpha_chat_db_version',
		'alpha_chat_installed_at',
		'alpha_chat_license',
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	delete_transient( 'alpha_chat_update_info' );

	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM ' . $wpdb->postmeta . ' WHERE meta_key LIKE %s',
			$wpdb->esc_like( '_alpha_chat_' ) . '%'
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', [], 'alpha-chat' );
	}
}

if ( is_multisite() ) {
	$alpha_chat_sites = get_sites(
		[
			'fields'   => 'ids',
			'number'   => 0,
			'no_found_rows' => true,
		]
	);

	foreach ( (array) $alpha_chat_sites as $alpha_chat_site_id ) {
		switch_to_blog( (int) $alpha_chat_site_id );
		alpha_chat_uninstall_site();
		restore_current_blog();
	}

	foreach ( [ 'alpha_chat_settings', 'alpha_chat_db_version', 'alpha_chat_installed_at', 'alpha_chat_license' ] as $alpha_chat_option ) {
		delete_site_option( $alpha_chat_option );
	}
} else {
	alpha_chat_uninstall_site();
}

delete_site_transient( 'update_plugins' );
