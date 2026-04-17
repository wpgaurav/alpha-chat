<?php
declare(strict_types=1);

namespace AlphaChat\Database;

final class Schema {

	public const VERSION = '1.4.0';

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$chunks   = self::chunks_table();
		$threads  = self::threads_table();
		$messages = self::messages_table();
		$contacts = self::contacts_table();
		$faqs     = self::faqs_table();

		$sql = [
			"CREATE TABLE $chunks (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				source_type VARCHAR(32) NOT NULL DEFAULT 'post',
				source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				chunk_index INT UNSIGNED NOT NULL DEFAULT 0,
				content LONGTEXT NOT NULL,
				token_count INT UNSIGNED NOT NULL DEFAULT 0,
				embedding LONGBLOB NULL,
				embedding_model VARCHAR(64) NULL,
				content_hash CHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(16) NOT NULL DEFAULT 'pending',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY source (source_type, source_id),
				KEY status (status),
				KEY content_hash (content_hash)
			) $charset_collate;",

			"CREATE TABLE $threads (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				uuid CHAR(36) NOT NULL,
				user_id BIGINT UNSIGNED NULL,
				session_hash CHAR(64) NOT NULL DEFAULT '',
				title VARCHAR(255) NOT NULL DEFAULT '',
				origin_url VARCHAR(500) NOT NULL DEFAULT '',
				message_count INT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY user_id (user_id),
				KEY session_hash (session_hash),
				KEY updated_at (updated_at)
			) $charset_collate;",

			"CREATE TABLE $messages (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				thread_id BIGINT UNSIGNED NOT NULL,
				role VARCHAR(16) NOT NULL,
				content LONGTEXT NOT NULL,
				token_count INT UNSIGNED NOT NULL DEFAULT 0,
				metadata LONGTEXT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY thread_id (thread_id),
				KEY created_at (created_at)
			) $charset_collate;",

			"CREATE TABLE $faqs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				question VARCHAR(500) NOT NULL,
				answer LONGTEXT NOT NULL,
				sort_order INT UNSIGNED NOT NULL DEFAULT 0,
				enabled TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY sort_order (sort_order)
			) $charset_collate;",

			"CREATE TABLE $contacts (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				thread_uuid CHAR(36) NOT NULL DEFAULT '',
				name VARCHAR(191) NOT NULL DEFAULT '',
				email VARCHAR(191) NOT NULL,
				message LONGTEXT NOT NULL,
				user_id BIGINT UNSIGNED NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'new',
				user_agent VARCHAR(255) NOT NULL DEFAULT '',
				ip_hash CHAR(64) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY email (email),
				KEY status (status),
				KEY created_at (created_at)
			) $charset_collate;",
		];

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'alpha_chat_db_version', self::VERSION );
	}

	public static function chunks_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'alpha_chat_chunks';
	}

	public static function threads_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'alpha_chat_threads';
	}

	public static function messages_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'alpha_chat_messages';
	}

	public static function contacts_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'alpha_chat_contacts';
	}

	public static function faqs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'alpha_chat_faqs';
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'alpha_chat_db_version' ) !== self::VERSION ) {
			self::install();
		}
	}
}
