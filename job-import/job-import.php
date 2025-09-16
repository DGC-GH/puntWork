<?php
/**
 * Plugin Name: Job Import
 * Plugin URI: https://github.com/DGC-GH/puntWork
 * Description: Imports jobs from XML/JSON feeds with batch processing, scheduling, and admin UI.
 * Version: 1.0.0
 * Author: DGC-GH
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('JOB_IMPORT_PATH', plugin_dir_path(__FILE__));
define('JOB_IMPORT_URL', plugin_dir_url(__FILE__));
define('JOB_IMPORT_LOGS', JOB_IMPORT_PATH . 'logs/');

// Include files
require_once JOB_IMPORT_PATH . 'includes/constants.php';
require_once JOB_IMPORT_PATH . 'includes/helpers.php';
require_once JOB_IMPORT_PATH . 'includes/processor.php';
require_once JOB_IMPORT_PATH . 'includes/scheduler.php';
require_once JOB_IMPORT_PATH . 'includes/heartbeat.php';
require_once JOB_IMPORT_PATH . 'includes/admin.php';
require_once JOB_IMPORT_PATH . 'includes/enqueue.php';
require_once JOB_IMPORT_PATH . 'includes/ajax.php';
require_once JOB_IMPORT_PATH . 'includes/shortcode.php';

// Activation hook: Create logs dir, schedule cron
register_activation_hook(__FILE__, 'job_import_activate');
function job_import_activate() {
    if (!file_exists(JOB_IMPORT_LOGS)) {
        wp_mkdir_p(JOB_IMPORT_LOGS);
    }
    if (!wp_next_scheduled('job_import_cron')) {
        wp_schedule_event(time(), 'hourly', 'job_import_cron');
    }
}

// Deactivation hook: Clear cron
register_deactivation_hook(__FILE__, 'job_import_deactivate');
function job_import_deactivate() {
    wp_clear_scheduled_hook('job_import_cron');
}

// Core logic from snippet 1: Initialize on plugins_loaded
add_action('plugins_loaded', 'job_import_init');
function job_import_init() {
    // Register custom post type 'job' if not exists (assuming from core snippet)
    if (!post_type_exists('job')) {
        register_post_type('job', [
            'labels' => ['name' => 'Jobs'],
            'public' => true,
            'supports' => ['title', 'editor', 'custom-fields'],
        ]);
    }
    // Trigger initial download if needed
    job_download_feed_if_needed();
}

// Cron hook for scheduling
add_action('job_import_cron', 'job_import_run_import');
function job_import_run_import() {
    job_process_xml_batch();
}

// Enqueue scripts/styles (from snippet 3)
add_action('admin_enqueue_scripts', 'job_import_enqueue_assets');

// AJAX handlers (from snippet 4)
add_action('wp_ajax_job_import_start', 'job_import_ajax_start');
add_action('wp_ajax_job_import_progress', 'job_import_ajax_progress');

// Admin menu (from snippet 6)
add_action('admin_menu', 'job_import_add_admin_menu');

// Shortcode (from snippet 5)
add_shortcode('job_import_status', 'job_import_shortcode_status');

// Heartbeat integration (from snippets 1.4/1.5)
add_action('wp_ajax_nopriv_heartbeat', 'job_import_heartbeat_tick'); // If frontend needed
add_action('wp_ajax_heartbeat', 'job_import_heartbeat_tick');

// Utility: Check if download needed (e.g., based on last run)
function job_download_feed_if_needed() {
    $last_run = get_option('job_import_last_run', 0);
    if (time() - $last_run > JOB_IMPORT_CHECK_INTERVAL) {
        job_download_feed();
        update_option('job_import_last_run', time());
    }
}
?>
