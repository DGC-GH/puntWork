<?php
/**
 * Scheduler for Job Import Plugin
 * Sets up WP cron for periodic imports.
 * Refactored from old WPCode snippet 1.3 - Scheduling and Triggers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/processor.php'; // Ensure processor loaded

/**
 * Schedule the import cron on plugin activation.
 */
function schedule_job_import_cron() {
    if ( ! wp_next_scheduled( 'job_import_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'job_import_cron' ); // Or 'hourly' per requirements
    }
}
add_action( 'wp', 'schedule_job_import_cron' );

/**
 * The cron hook: Run the dynamic import process.
 */
function run_job_import_cron() {
    if ( ! current_user_can( 'manage_options' ) && ! defined( 'DOING_CRON' ) ) {
        return;
    }
    process_all_imports(); // Calls dynamic feeds
}
add_action( 'job_import_cron', 'run_job_import_cron' );

/**
 * Manual trigger for admin/AJAX (force = true to skip last-run).
 * Use in admin.php or ajax.php.
 */
function manual_import_jobs( $force = false ) {
    return process_all_imports( $force );
}
