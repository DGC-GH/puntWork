<?php

/**
 * CRM Database Setup
 *
 * @package    Puntwork
 * @subpackage Database
 * @since      0.0.4
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create CRM database tables
 */
function create_crm_tables() {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	// CRM sync log table
	$table_name = $wpdb->prefix . 'puntwork_crm_sync_log';

	$sql = "CREATE TABLE $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        platform_id varchar(50) NOT NULL,
        operation varchar(100) NOT NULL,
        success tinyint(1) NOT NULL DEFAULT 0,
        data longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY platform_id (platform_id),
        KEY operation (operation),
        KEY success (success),
        KEY created_at (created_at)
    ) $charset_collate;";

	include_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// CRM contact mapping table (for tracking external IDs)
	$mapping_table = $wpdb->prefix . 'puntwork_crm_contact_mapping';

	$mapping_sql = "CREATE TABLE $mapping_table (
        id int(11) NOT NULL AUTO_INCREMENT,
        application_id varchar(100) NOT NULL,
        platform_id varchar(50) NOT NULL,
        external_contact_id varchar(100) NOT NULL,
        external_deal_id varchar(100) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_mapping (application_id, platform_id),
        KEY platform_id (platform_id),
        KEY external_contact_id (external_contact_id)
    ) $charset_collate;";

	dbDelta( $mapping_sql );

	// Add default options
	add_option( 'puntwork_crm_auto_sync_applications', false );
	add_option( 'puntwork_crm_default_platforms', array() );
	add_option( 'puntwork_crm_sync_contact_fields', true );
	add_option( 'puntwork_crm_create_deals', true );
}

/**
 * Drop CRM database tables
 */
function drop_crm_tables() {
	global $wpdb;

	$tables = array(
		$wpdb->prefix . 'puntwork_crm_sync_log',
		$wpdb->prefix . 'puntwork_crm_contact_mapping',
	);

	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS $table" );
	}

	// Remove options
	delete_option( 'puntwork_crm_platforms' );
	delete_option( 'puntwork_crm_auto_sync_applications' );
	delete_option( 'puntwork_crm_default_platforms' );
	delete_option( 'puntwork_crm_sync_contact_fields' );
	delete_option( 'puntwork_crm_create_deals' );
}

/**
 * Update CRM database schema
 */
function update_crm_schema() {
	// Check if tables exist and create/update as needed
	create_crm_tables();

	// Future schema updates can be added here
	$current_version = get_option( 'puntwork_crm_db_version', '1.0' );

	if ( version_compare( $current_version, '0.0.4', '<' ) ) {
		// Add new columns or tables for version 0.0.4
		update_option( 'puntwork_crm_db_version', '0.0.4' );
	}
}

// Hook into plugin activation
register_activation_hook( __FILE__, 'create_crm_tables' );

// Hook into plugin deactivation (optional cleanup)
register_deactivation_hook(
	__FILE__,
	function () {
		// Only drop tables in development/testing
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			drop_crm_tables();
		}
	}
);
