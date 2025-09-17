<?php
/**
 * Scheduler file for job import plugin.
 * Sets up WP Cron for periodic job feed imports.
 *
 * @package JobImport
 * @version 1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Schedule the cron job on plugin activation or init.
 */
function job_import_schedule_cron() {
    if ( ! wp_next_scheduled( 'job_import_cron' ) ) {
        wp_schedule_event( time(), 'hourly', 'job_import_cron' ); // Hourly; adjust as needed
    }
}
add_action( 'wp', 'job_import_schedule_cron' ); // Use 'wp' for reliability

/**
 * Hook to run the feed processing on cron trigger.
 */
function job_import_handle_cron() {
    if ( ! wp_verify_nonce( $_GET['token'] ?? '', 'job_import_cron' ) && ! defined( 'DOING_CRON' ) ) {
        die( 'Unauthorized.' );
    }
    job_import_process_feeds(); // Call processor
}
add_action( 'job_import_cron', 'job_import_handle_cron' );

/**
 * Clear schedule on plugin deactivation.
 */
function job_import_unschedule_cron() {
    $timestamp = wp_next_scheduled( 'job_import_cron' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'job_import_cron' );
    }
}
// Hook to 'deactivation' in main plugin file
