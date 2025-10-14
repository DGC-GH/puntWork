<?php
/**
 * Plugin Name: puntWork
 * Description: Imports jobs from XML feeds via job-feed CPT.
 * Version: 1.1.1
 * Author: DGC-GH
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PUNTWORK_VERSION', '1.1.1' );
define( 'PUNTWORK_PATH', plugin_dir_path( __FILE__ ) );
define( 'PUNTWORK_URL', plugin_dir_url( __FILE__ ) );
define( 'PUNTWORK_LOGS', PUNTWORK_PATH . 'logs/import.log' );

// Debug configuration - set to true to enable detailed job update logging
define( 'PUNTWORK_DEBUG_JOB_UPDATES', false );

// Activation hook
register_activation_hook( __FILE__, __NAMESPACE__ . '\\job_import_activate' );
function job_import_activate() {
    // Schedule cron (DISABLED - removed automatic cron loop)
    // if ( ! wp_next_scheduled( 'job_import_cron' ) ) {
    //     wp_schedule_event( current_time('timestamp'), 'daily', 'job_import_cron' );
    // }
    // Create logs dir if needed
    $logs_dir = dirname( PUNTWORK_LOGS );
    if ( ! file_exists( $logs_dir ) ) {
        wp_mkdir_p( $logs_dir );
    }
    // Flush rewrite rules if CPTs involved (though ACF handles)
    flush_rewrite_rules();

    // Clear any cached admin menu data to ensure icon updates
    if ( function_exists( 'wp_cache_flush' ) ) {
        wp_cache_flush();
    }
}

// Deactivation hook
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\job_import_deactivate' );
function job_import_deactivate() {
    wp_clear_scheduled_hook( 'job_import_cron' );
}

// Register custom cron schedules
add_filter('cron_schedules', __NAMESPACE__ . '\\register_custom_cron_schedules');
function register_custom_cron_schedules($schedules) {
    $schedules['puntwork_hourly'] = [
        'interval' => HOUR_IN_SECONDS,
        'display' => __('Hourly', 'puntwork')
    ];

    $schedules['puntwork_3hours'] = [
        'interval' => 3 * HOUR_IN_SECONDS,
        'display' => __('Every 3 hours', 'puntwork')
    ];

    $schedules['puntwork_6hours'] = [
        'interval' => 6 * HOUR_IN_SECONDS,
        'display' => __('Every 6 hours', 'puntwork')
    ];

    $schedules['puntwork_12hours'] = [
        'interval' => 12 * HOUR_IN_SECONDS,
        'display' => __('Every 12 hours', 'puntwork')
    ];

    // Add common custom intervals
    for ($hours = 2; $hours <= 24; $hours++) {
        if ($hours != 3 && $hours != 6 && $hours != 12) { // Skip already defined ones
            $schedules['puntwork_' . $hours . 'hours'] = [
                'interval' => $hours * HOUR_IN_SECONDS,
                'display' => sprintf(__('Every %d hours', 'puntwork'), $hours)
            ];
        }
    }

    return $schedules;
}

// Init setup
add_action( 'init', __NAMESPACE__ . '\\setup_job_import' );
function setup_job_import() {
    // Load includes
    $includes = array(
        // Core functionality
        'core/core-structure-logic.php',
        'core/enqueue-scripts-js.php',
        
        // Admin interface
        'admin/admin-menu.php',
        'admin/admin-page-html.php',
        'admin/admin-ui-debug.php',
        'admin/admin-ui-main.php',
        'admin/admin-ui-scheduling.php',
        
        // API handlers
        'api/ajax-feed-processing.php',
        'api/ajax-handlers.php',
        'api/ajax-import-control.php',
        'api/ajax-purge.php',
        
        // Batch processing
        'batch/batch-core.php',
        'batch/batch-data.php',
        'batch/batch-processing.php',
        'batch/batch-size-management.php',
        'batch/batch-utils.php',
        
        // Import functionality
        'import/combine-jsonl.php',
        'import/download-feed.php',
        'import/import-batch.php',
        'import/import-finalization.php',
        'import/import-setup.php',
        'import/process-batch-items.php',
        'import/process-batch-items-concurrent.php',
        'import/process-xml-batch.php',
        
        // Utilities
        'utilities/ajax-utilities.php',
        'utilities/database-utilities.php',
        'utilities/file-utilities.php',
        'utilities/options-utilities.php',
        'utilities/progress-utilities.php',
        'utilities/puntwork-logger.php',
        'utilities/retry-utility.php',
        'utilities/gzip-file.php',
        'utilities/handle-duplicates.php',
        'utilities/heartbeat-control.php',
        'utilities/item-cleaning.php',
        'utilities/item-inference.php',
        'utilities/shortcode.php',
        'utilities/utility-helpers.php',
        
        // Mappings
        'mappings/mappings-constants.php',
        'mappings/mappings-fields.php',
        'mappings/mappings-geographic.php',
        'mappings/mappings-icons.php',
        'mappings/mappings-salary.php',
        'mappings/mappings-schema.php',
        
        // Scheduling
        'scheduling/scheduling-ajax.php',
        'scheduling/scheduling-core.php',
        'scheduling/scheduling-history.php',
        'scheduling/scheduling-triggers.php',
        'scheduling/test-scheduling.php',
    );
    foreach ( $includes as $include ) {
        $file = PUNTWORK_PATH . 'includes/' . $include;
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }

    // Initialize scheduling
    if (function_exists(__NAMESPACE__ . '\\init_scheduling')) {
        call_user_func(__NAMESPACE__ . '\\init_scheduling');
    }
}

// Add custom favicon
add_action( 'wp_head', __NAMESPACE__ . '\\add_custom_favicon' );
function add_custom_favicon() {
    $favicon_url = PUNTWORK_URL . 'assets/images/icon.svg?v=' . PUNTWORK_VERSION;
    echo '<link rel="icon" type="image/svg+xml" href="' . esc_url( $favicon_url ) . '">' . "\n";
}

// Uninstall hook (cleanup)
register_uninstall_hook( __FILE__, __NAMESPACE__ . '\\job_import_uninstall' );
function job_import_uninstall() {
    // Delete options, transients; optional: delete job-feed posts
    delete_option( 'job_import_last_run' );
    // Clear cron
    wp_clear_scheduled_hook( 'job_import_cron' );
}
