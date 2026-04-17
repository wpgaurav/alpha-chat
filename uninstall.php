<?php
/**
 * Uninstall cleanup for Alpha Chat.
 *
 * Runs when the plugin is deleted from the WordPress admin. Removes all
 * plugin-owned tables, options, post meta, and scheduled actions.
 *
 * @package AlphaChat
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = [
	$wpdb->prefix . 'alpha_chat_chunks',
	$wpdb->prefix . 'alpha_chat_threads',
	$wpdb->prefix . 'alpha_chat_messages',
	$wpdb->prefix . 'alpha_chat_contacts',
];

foreach ( $tables as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
}

$options = [
	'alpha_chat_settings',
	'alpha_chat_db_version',
	'alpha_chat_installed_at',
];

foreach ( $options as $option ) {
	delete_option( $option );
	delete_site_option( $option );
}

$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM ' . $wpdb->postmeta . ' WHERE meta_key LIKE %s',
		'_alpha_chat_%'
	)
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', [], 'alpha-chat' );
}
