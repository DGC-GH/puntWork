<?php
/**
 * Scheduling functionality for job import plugin
 * Main entry point that loads all scheduling modules
 *
 * @package    Puntwork
 * @subpackage Scheduling
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load scheduling modules
require_once __DIR__ . '/scheduling/scheduling-core.php';
require_once __DIR__ . '/scheduling/scheduling-ajax.php';
require_once __DIR__ . '/scheduling/scheduling-history.php';

/**
 * Initialize scheduling functionality
 */
function init_scheduling() {
    add_action('wp_ajax_save_import_schedule', __NAMESPACE__ . '\\save_import_schedule_ajax');
    add_action('wp_ajax_get_import_schedule', __NAMESPACE__ . '\\get_import_schedule_ajax');
    add_action('wp_ajax_test_import_schedule', __NAMESPACE__ . '\\test_import_schedule_ajax');
    add_action('wp_ajax_run_scheduled_import', __NAMESPACE__ . '\\run_scheduled_import_ajax');
    add_action('wp_ajax_get_import_run_history', __NAMESPACE__ . '\\get_import_run_history_ajax');

    // Register cron hook
    add_action('puntwork_scheduled_import', __NAMESPACE__ . '\\run_scheduled_import');

    // Register custom cron schedules
    add_filter('cron_schedules', __NAMESPACE__ . '\\register_custom_cron_schedules');

    // Schedule cleanup on plugin deactivation
    register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\cleanup_scheduled_imports');
}