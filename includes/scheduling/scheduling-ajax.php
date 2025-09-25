<?php
/**
 * AJAX handlers for scheduling functionality
 * Handles all AJAX requests related to scheduling operations
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

/**
 * Save import schedule settings via AJAX
 */
function save_import_schedule_ajax() {
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $enabled = isset($_POST['enabled']) ? filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN) : false;

    // Also handle string values '1' and '0'
    if (isset($_POST['enabled']) && is_string($_POST['enabled'])) {
        $enabled = $_POST['enabled'] === '1';
    }
    $frequency = sanitize_text_field($_POST['frequency'] ?? 'daily');
    $interval = intval($_POST['interval'] ?? 24);
    $hour = intval($_POST['hour'] ?? 9);
    $minute = intval($_POST['minute'] ?? 0);

    // Debug logging
    error_log('[PUNTWORK] Save schedule AJAX received: enabled=' . ($enabled ? 'true' : 'false') . ', frequency=' . $frequency);

    // Validate frequency
    $valid_frequencies = ['hourly', '3hours', '6hours', '12hours', 'daily', 'custom'];
    if (!in_array($frequency, $valid_frequencies)) {
        wp_send_json_error(['message' => 'Invalid frequency']);
    }

    // Validate time
    if ($hour < 0 || $hour > 23) {
        wp_send_json_error(['message' => 'Hour must be between 0 and 23']);
    }
    if ($minute < 0 || $minute > 59) {
        wp_send_json_error(['message' => 'Minute must be between 0 and 59']);
    }

    // Validate custom interval
    if ($frequency === 'custom' && ($interval < 1 || $interval > 168)) {
        wp_send_json_error(['message' => 'Custom interval must be between 1 and 168 hours']);
    }

    $schedule_data = [
        'enabled' => $enabled,
        'frequency' => $frequency,
        'interval' => $interval,
        'hour' => $hour,
        'minute' => $minute,
        'updated_at' => current_time('timestamp'),
        'updated_by' => get_current_user_id()
    ];

    update_option('puntwork_import_schedule', $schedule_data);

    // Verify the data was saved
    $saved_data = get_option('puntwork_import_schedule');
    error_log('[PUNTWORK] Data saved to database: enabled=' . ($saved_data['enabled'] ? 'true' : 'false'));

    // Update WordPress cron
    update_cron_schedule($schedule_data);

    $last_run = get_option('puntwork_last_import_run', null);
    $last_run_details = get_option('puntwork_last_import_details', null);

    error_log('[PUNTWORK] Save schedule AJAX response: enabled=' . ($schedule_data['enabled'] ? 'true' : 'false'));

    wp_send_json_success([
        'message' => 'Schedule saved successfully',
        'schedule' => $schedule_data,
        'next_run' => get_next_scheduled_time(),
        'last_run' => $last_run,
        'last_run_details' => $last_run_details
    ]);
}

/**
 * Get current import schedule settings via AJAX
 */
function get_import_schedule_ajax() {
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $schedule = get_option('puntwork_import_schedule', [
        'enabled' => false,
        'frequency' => 'daily',
        'interval' => 24,
        'hour' => 9,
        'minute' => 0,
        'updated_at' => null,
        'updated_by' => null
    ]);

    error_log('[PUNTWORK] Get schedule AJAX loaded: enabled=' . ($schedule['enabled'] ? 'true' : 'false'));

    $last_run = get_option('puntwork_last_import_run', null);
    $last_run_details = get_option('puntwork_last_import_details', null);

    // Add formatted date to last run if it exists
    if ($last_run && isset($last_run['timestamp'])) {
        $last_run['formatted_date'] = wp_date('M j, Y g:i A', $last_run['timestamp']);
    }

    wp_send_json_success([
        'schedule' => $schedule,
        'next_run' => get_next_scheduled_time(),
        'last_run' => $last_run,
        'last_run_details' => $last_run_details
    ]);
}

/**
 * Get import run history via AJAX
 */
function get_import_run_history_ajax() {
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $history = get_option('puntwork_import_run_history', []);

    wp_send_json_success([
        'history' => $history,
        'count' => count($history)
    ]);
}

/**
 * Test import schedule via AJAX
 */
function test_import_schedule_ajax() {
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    // Run a test import
    $result = run_scheduled_import(true); // true = test mode

    wp_send_json_success([
        'message' => 'Test import completed',
        'result' => $result
    ]);
}

/**
 * Run scheduled import immediately via AJAX
 */
function run_scheduled_import_ajax() {
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    // Check if an import is already running
    $import_status = get_option('job_import_status', []);
    if (isset($import_status['complete']) && !$import_status['complete']) {
        wp_send_json_error(['message' => 'An import is already running']);
    }

    // Schedule the import to run in a few seconds so user can see the scheduling process
    $scheduled_time = time() + 3; // Start in 3 seconds for immediate verification
    $scheduled = wp_schedule_single_event($scheduled_time, 'puntwork_manual_import');

    if (!$scheduled) {
        wp_send_json_error(['message' => 'Failed to schedule import']);
    }

    wp_send_json_success([
        'message' => 'Import scheduled to start in 3 seconds',
        'scheduled_time' => $scheduled_time,
        'next_check' => time() + 5 // Suggest when to check for results
    ]);
}

// Register AJAX actions
add_action('wp_ajax_save_import_schedule', __NAMESPACE__ . '\\save_import_schedule_ajax');
add_action('wp_ajax_get_import_schedule', __NAMESPACE__ . '\\get_import_schedule_ajax');
add_action('wp_ajax_get_import_run_history', __NAMESPACE__ . '\\get_import_run_history_ajax');
add_action('wp_ajax_test_import_schedule', __NAMESPACE__ . '\\test_import_schedule_ajax');
add_action('wp_ajax_run_scheduled_import', __NAMESPACE__ . '\\run_scheduled_import_ajax');

// Register cron hook for manual imports
add_action('puntwork_manual_import', __NAMESPACE__ . '\\run_manual_import_cron');

/**
 * Handle manual import cron job
 */
function run_manual_import_cron() {
    error_log('[PUNTWORK] Manual import cron started');

    // Check if an import is already running
    $import_status = get_option('job_import_status', []);
    if (isset($import_status['complete']) && !$import_status['complete']) {
        error_log('[PUNTWORK] Manual import cron skipped - import already running');
        return;
    }

    try {
        $result = run_scheduled_import();

        if ($result['success']) {
            error_log('[PUNTWORK] Manual import cron completed successfully');
        } else {
            error_log('[PUNTWORK] Manual import cron failed: ' . ($result['message'] ?? 'Unknown error'));
        }
    } catch (\Exception $e) {
        error_log('[PUNTWORK] Manual import cron exception: ' . $e->getMessage());
    }
}