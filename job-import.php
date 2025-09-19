<?php
/**
 * Plugin Name: Job Import
 * Description: Imports jobs from XML feeds via job-feed CPT.
 * Version: 1.0.0
 * Author: DGC-GH
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'JOB_IMPORT_VERSION', '1.0.0' );
define( 'JOB_IMPORT_PATH', plugin_dir_path( __FILE__ ) );
define( 'JOB_IMPORT_URL', plugin_dir_url( __FILE__ ) );
define( 'JOB_IMPORT_LOGS', JOB_IMPORT_PATH . 'logs/import.log' );

// Activation hook
register_activation_hook( __FILE__, 'job_import_activate' );
function job_import_activate() {
    // Schedule cron
    if ( ! wp_next_scheduled( 'job_import_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'job_import_cron' );
    }
    // Create logs dir if needed
    $logs_dir = dirname( JOB_IMPORT_LOGS );
    if ( ! file_exists( $logs_dir ) ) {
        wp_mkdir_p( $logs_dir );
    }
    // Flush rewrite rules if CPTs involved (though ACF handles)
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook( __FILE__, 'job_import_deactivate' );
function job_import_deactivate() {
    wp_clear_scheduled_hook( 'job_import_cron' );
}

// Init setup
add_action( 'init', 'setup_job_import' );
function setup_job_import() {
    // Global batch limit (from old 1)
    global $job_import_batch_limit;
    $job_import_batch_limit = 50;
    
    // Load includes
    $includes = array(
        'core-structure-logic.php',
        'admin-menu.php',
        'admin-page-html.php',                    
          'ajax-handlers.php',
          'combine-jsonl.php',
          'download-feed.php',
          'enqueue-scripts-js.php',
          'gzip-file.php',
          'handle-duplicates.php',
          'heartbeat-control.php',
          'import-batch.php',
          'item-cleaning.php',
          'item-inference.php',
          'mappings-constants.php',
          'process-batch-items.php',
          'process-xml-batch.php',
          'reset-import.php',
          'scheduling-triggers.php',
          'shortcode.php',
          'utility-helpers.php',
    );
    foreach ( $includes as $include ) {
        $file = JOB_IMPORT_PATH . 'includes/' . $include;
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}

// Uninstall hook (cleanup)
register_uninstall_hook( __FILE__, 'job_import_uninstall' );
function job_import_uninstall() {
    // Delete options, transients; optional: delete job-feed posts
    delete_option( 'job_import_last_run' );
    // Clear cron
    wp_clear_scheduled_hook( 'job_import_cron' );
}
