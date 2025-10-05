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
 * Get the feeds directory path.
 *
 * Returns the feeds directory path within the Puntwork plugin directory.
 *
 * @return string The feeds directory path
 */
function puntwork_get_feeds_directory() {
	// Return the feeds directory path within the Puntwork plugin directory
	$plugin_dir = dirname( plugin_dir_path( __FILE__ ), 2 );
	return $plugin_dir . '/feeds/';
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