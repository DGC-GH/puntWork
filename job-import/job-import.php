<?php
/**
 * Plugin Name: Job Import
 * Description: Imports jobs from XML feeds into custom post type.
 * Version: 1.0.0
 * Author: DGC-GH
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define constants.
require_once plugin_dir_path( __FILE__ ) . 'includes/constants.php';

// Load includes with error handling for future debugging.
$includes = [
    'constants.php', // Already loaded, but explicit
    'mappings.php', // New: Added for mappings
    'core.php',
    'helpers.php',
    'scheduler.php',
    'heartbeat.php',
    'processor.php',
    'admin.php',
    'ajax.php',
    'enqueue.php',
    'shortcode.php',
];
foreach ( $includes as $include ) {
    $file = plugin_dir_path( __FILE__ ) . 'includes/' . $include;
    if ( file_exists( $file ) ) {
        require_once $file;
    } else {
        // Log missing file for debug.
        error_log( 'Job Import: Missing include ' . $file );
    }
}

// Activation hook: Create CPT, schedule cron.
register_activation_hook( __FILE__, 'job_import_activate' );
function job_import_activate() {
    // Flush rewrite rules after CPT registration (in core.php).
    flush_rewrite_rules();
    // Schedule cron if not set.
    if ( ! wp_next_scheduled( 'job_import_cron' ) ) {
        wp_schedule_event( time(), 'hourly', 'job_import_cron' );
    }
    // Ensure logs dir exists.
    $logs_dir = plugin_dir_path( __FILE__ ) . 'logs/';
    if ( ! file_exists( $logs_dir ) ) {
        wp_mkdir_p( $logs_dir );
    }
}

// Deactivation: Clear cron.
register_deactivation_hook( __FILE__, 'job_import_deactivate' );
function job_import_deactivate() {
    wp_clear_scheduled_hook( 'job_import_cron' );
}
?>
