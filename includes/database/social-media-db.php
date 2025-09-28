<?php

/**
 * Social Media Database Setup.
 *
 * @package    Puntwork
 * @subpackage Database
 * @since      2.2.0
 */

// phpcs:disable WordPress.Files.FileName,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

namespace Puntwork;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Social Media Database Class
 */
class SocialMediaDb {

	/**
	 * Database version
	 */
	public const DB_VERSION = '1.0';

	/**
	 * Option name for database version
	 */
	public const DB_VERSION_OPTION = 'puntwork_social_db_version';

	/**
	 * Initialize database
	 */
	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'checkDbVersion' ) );
	}

	/**
	 * Check database version and upgrade if needed
	 */
	public static function checkDbVersion(): void {
		$current_version = get_option( self::DB_VERSION_OPTION, '0' );

		if ( version_compare( $current_version, self::DB_VERSION, '<' ) ) {
			self::create_tables();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Create database tables
	 */
	public static function createTables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Social media posts table.
		$table_name = $wpdb->prefix . 'puntwork_social_posts';

		$sql = "CREATE TABLE $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            post_data longtext NOT NULL,
            scheduled_time datetime DEFAULT NULL,
            status enum('scheduled','posted','failed') NOT NULL DEFAULT 'scheduled',
            results longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            posted_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY scheduled_time (scheduled_time)
        ) $charset_collate;";

		include_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Log successful table creation.
		PuntWorkLogger::info(
			'Social media database tables created',
			PuntWorkLogger::CONTEXT_SYSTEM,
			array(
				'table'   => $table_name,
				'version' => self::DB_VERSION,
			)
		);
	}

	/**
	 * Drop database tables (for uninstall)
	 */
	public static function dropTables(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'puntwork_social_posts';

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %s', $table_name ) );

		delete_option( self::DB_VERSION_OPTION );

		PuntWorkLogger::info(
			'Social media database tables dropped',
			PuntWorkLogger::CONTEXT_SYSTEM,
			array(
				'table' => $table_name,
			)
		);
	}

	/**
	 * Get table name with prefix
	 *
	 * @param string $table Table identifier.
	 */
	public static function getTableName( string $table ): string {
		global $wpdb;

		$tables = array(
			'posts' => $wpdb->prefix . 'puntwork_social_posts',
		);

		return $tables[ $table ] ?? '';
	}
}

// Initialize database.
SocialMediaDb::init();
