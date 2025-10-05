<?php

/**
 * Feeds Path Utilities
 *
 * Centralized utilities for determining feeds directory and file paths.
 *
 * @since      1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the feeds directory path with fallback logic.
 *
 * Priority order:
 * 1. Plugin feeds directory (preferred - moved from WP root for better organization)
 * 2. WordPress root feeds directory (legacy support)
 * 3. Configured option path
 * 4. Server root feeds directory
 * 5. Domain root feeds directory
 *
 * @return string The feeds directory path
 */
function puntwork_get_feeds_directory() {
	static $feeds_dir = null;

	if ( $feeds_dir !== null ) {
		return $feeds_dir;
	}

	// Priority 1: Plugin feeds directory (preferred location - always use this for new operations)
	$plugin_dir = dirname( plugin_dir_path( __FILE__ ), 2 );
	$plugin_feeds_dir = $plugin_dir . '/feeds/';
	$feeds_dir = $plugin_feeds_dir;
	return $feeds_dir;
}

/**
 * Get the combined jobs JSONL file path.
 *
 * @return string The combined jobs JSONL file path
 */
function puntwork_get_combined_jsonl_path() {
	return puntwork_get_feeds_directory() . 'combined-jobs.jsonl';
}

/**
 * Get the feeds directory URL for web access.
 *
 * @return string The feeds directory URL
 */
function puntwork_get_feeds_directory_url() {
	$feeds_dir = puntwork_get_feeds_directory();

	// Convert filesystem path to URL
	if ( str_starts_with( $feeds_dir, ABSPATH ) ) {
		return site_url( str_replace( ABSPATH, '', $feeds_dir ) );
	}

	// For paths outside ABSPATH, try to construct URL
	if ( str_starts_with( $feeds_dir, '/home/u164580062/domains/belgiumjobs.work/' ) ) {
		return 'https://belgiumjobs.work/' . str_replace( '/home/u164580062/domains/belgiumjobs.work/public_html/', '', $feeds_dir );
	}

	// Fallback
	return site_url( 'feeds/' );
}

/**
 * Ensure the feeds directory exists and is writable.
 *
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function puntwork_ensure_feeds_directory() {
	$feeds_dir = puntwork_get_feeds_directory();

	if ( ! is_dir( $feeds_dir ) ) {
		if ( ! wp_mkdir_p( $feeds_dir ) ) {
			return new WP_Error( 'feeds_dir_create_failed', 'Failed to create feeds directory: ' . $feeds_dir );
		}
	}

	if ( ! is_writable( $feeds_dir ) ) {
		return new WP_Error( 'feeds_dir_not_writable', 'Feeds directory is not writable: ' . $feeds_dir );
	}

	return true;
}

/**
 * Migrate feeds directory from WordPress root to plugin directory.
 *
 * This function moves existing feeds files from the WordPress root feeds/
 * directory to the plugin's feeds/ directory for better organization.
 *
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function puntwork_migrate_feeds_directory() {
	$old_feeds_dir = ABSPATH . 'feeds/';
	$plugin_dir = dirname( plugin_dir_path( __FILE__ ), 2 );
	$new_feeds_dir = $plugin_dir . '/feeds/';

	// Check if old directory exists
	if ( ! is_dir( $old_feeds_dir ) ) {
		return new WP_Error( 'old_feeds_dir_not_found', 'Old feeds directory not found: ' . $old_feeds_dir );
	}

	// Check if new directory already exists and has content
	if ( is_dir( $new_feeds_dir ) && count( scandir( $new_feeds_dir ) ) > 2 ) {
		return new WP_Error( 'new_feeds_dir_not_empty', 'New feeds directory already contains files: ' . $new_feeds_dir );
	}

	// Ensure new directory exists
	if ( ! is_dir( $new_feeds_dir ) ) {
		if ( ! wp_mkdir_p( $new_feeds_dir ) ) {
			return new WP_Error( 'new_feeds_dir_create_failed', 'Failed to create new feeds directory: ' . $new_feeds_dir );
		}
	}

	// Get list of files to migrate
	$files = scandir( $old_feeds_dir );
	$migrated_files = 0;
	$errors = array();

	foreach ( $files as $file ) {
		if ( $file === '.' || $file === '..' ) {
			continue;
		}

		$old_path = $old_feeds_dir . $file;
		$new_path = $new_feeds_dir . $file;

		// Skip if it's a directory (we only want files)
		if ( is_dir( $old_path ) ) {
			continue;
		}

		// Attempt to copy the file
		if ( copy( $old_path, $new_path ) ) {
			$migrated_files++;
		} else {
			$errors[] = 'Failed to copy: ' . $file;
		}
	}

	// Log migration results
	if ( ! empty( $errors ) ) {
		error_log( '[PUNTWORK] [MIGRATION] Feeds migration completed with errors. Migrated: ' . $migrated_files . ', Errors: ' . count( $errors ) );
		foreach ( $errors as $error ) {
			error_log( '[PUNTWORK] [MIGRATION-ERROR] ' . $error );
		}
		return new WP_Error( 'migration_errors', 'Migration completed with errors. Check logs for details.' );
	}

	error_log( '[PUNTWORK] [MIGRATION] Successfully migrated ' . $migrated_files . ' files from ' . $old_feeds_dir . ' to ' . $new_feeds_dir );

	return true;
}