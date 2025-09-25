<?php
/**
 * AJAX handlers for scheduli    // Check if our hook is registered
    if (has_action('puntwork_scheduled_import_async')) {
        error_log('[PUNTWORK] puntwork_scheduled_import_async hook is registered');
    } else {
        error_log('[PUNTWORK] puntwork_scheduled_import_async hook is NOT registered');
    }ctionality
 * Handles all AJAX requests related to scheduling operations
 *
 * @package    Puntwork
 * @subpackage Scheduling
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direc// Debug: Check if Action Scheduler is available and working
add_action('admin_init', function() {
    if (function_exists('as_enqueue_async_action')) {
        error_log('[PUNTWORK] Action Scheduler is available and loaded');

        // Check if there are any pending actions for our hook
        global $wpdb;
        $table_name = $wpdb->prefix . 'actionscheduler_actions';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            $pending_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE hook = %s AND status = %s",
                'puntwork_scheduled_import_async',
                'pending'
            ));
            error_log('[PUNTWORK] Pending actions for puntwork_scheduled_import_async: ' . $pending_count);

            $running_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE hook = %s AND status = %s",
                'puntwork_scheduled_import_async',
                'running'
            ));
            error_log('[PUNTWORK] Running actions for puntwork_scheduled_import_async: ' . $running_count);
        }
    } else {
        error_log('[PUNTWORK] Action Scheduler is NOT available');
    }

    // Check if our hook is registered
    if (has_action('puntwork_scheduled_import_async')) {
        error_log('[PUNTWORK] puntwork_run_scheduled_import_async hook is registered');
    } else {
        error_log('[PUNTWORK] puntwork_run_scheduled_import_async hook is NOT registered');
    }
});

// Debug endpoint to manually trigger async function
add_action('wp_ajax_debug_trigger_async', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied');
    }

    error_log('[PUNTWORK] === MANUAL DEBUG TRIGGER ===');
    run_scheduled_import_async();
    error_log('[PUNTWORK] === MANUAL DEBUG TRIGGER COMPLETED ===');

    wp_die('Async function triggered - check debug.log');
});

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
 * Now triggers the import asynchronously like the manual Start Import button
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

    try {
        // Use Action Scheduler if available (best for background processing)
        if (function_exists('as_enqueue_async_action')) {
            error_log('[PUNTWORK] Action Scheduler available, queuing async action');
            $action_id = as_enqueue_async_action('puntwork_scheduled_import_async', [], 'puntwork');
            error_log('[PUNTWORK] Scheduled import queued via Action Scheduler, action ID: ' . $action_id);

            // Check if action was actually queued
            if ($action_id) {
                error_log('[PUNTWORK] Action successfully queued with ID: ' . $action_id);
            } else {
                error_log('[PUNTWORK] Action queuing failed - no action ID returned');
            }
        } else {
            error_log('[PUNTWORK] Action Scheduler NOT available, falling back to WP cron');
            // Fallback: Schedule resumable cron job
            wp_schedule_single_event(time() + 1, 'puntwork_scheduled_import_async');
            error_log('[PUNTWORK] Scheduled import queued via resumable cron');
        }

        wp_send_json_success([
            'message' => 'Import started asynchronously',
            'next_check' => time() + 3
        ]);

    } catch (\Exception $e) {
        error_log('[PUNTWORK] Run scheduled import AJAX error: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Failed to start import: ' . $e->getMessage()]);
    }
}

// Register AJAX actions
add_action('wp_ajax_save_import_schedule', __NAMESPACE__ . '\\save_import_schedule_ajax');
add_action('wp_ajax_get_import_schedule', __NAMESPACE__ . '\\get_import_schedule_ajax');
add_action('wp_ajax_get_import_run_history', __NAMESPACE__ . '\\get_import_run_history_ajax');
add_action('wp_ajax_test_import_schedule', __NAMESPACE__ . '\\test_import_schedule_ajax');
add_action('wp_ajax_run_scheduled_import', __NAMESPACE__ . '\\run_scheduled_import_ajax');

// Register cron hook for manual imports
add_action('puntwork_manual_import', __NAMESPACE__ . '\\run_manual_import_cron');

// Register async action hooks
add_action('puntwork_scheduled_import_async', __NAMESPACE__ . '\\run_scheduled_import_async');

// Debug: Check if Action Scheduler is available and working
add_action('admin_init', function() {
    if (function_exists('as_enqueue_async_action')) {
        error_log('[PUNTWORK] Action Scheduler is available and loaded');
    } else {
        error_log('[PUNTWORK] Action Scheduler is NOT available');
    }

    // Check if our hook is registered
    if (has_action('puntwork_scheduled_import_async')) {
        error_log('[PUNTWORK] puntwork_scheduled_import_async hook is registered');
    } else {
        error_log('[PUNTWORK] puntwork_scheduled_import_async hook is NOT registered');
    }
});

/**
 * Run scheduled import asynchronously (non-blocking)
 */
function run_scheduled_import_async() {
    error_log('[PUNTWORK] === ASYNC FUNCTION STARTED ===');
    error_log('[PUNTWORK] Async scheduled import started - Action Scheduler hook fired');
    error_log('[PUNTWORK] Current time: ' . date('Y-m-d H:i:s'));
    error_log('[PUNTWORK] Function called with arguments: ' . print_r(func_get_args(), true));

    // Clear any previous cancellation before starting
    delete_transient('import_cancel');
    error_log('[PUNTWORK] Cleared import_cancel transient');

    // Check if an import is already running
    $import_status = get_option('job_import_status', []);
    error_log('[PUNTWORK] Current import status: ' . print_r($import_status, true));

    if (isset($import_status['complete']) && !$import_status['complete']) {
        error_log('[PUNTWORK] Async import skipped - import already running');
        return;
    }

    error_log('[PUNTWORK] Starting actual import process...');

    try {
        $result = run_scheduled_import();
        error_log('[PUNTWORK] Import result: ' . print_r($result, true));

        // Import runs to completion without pausing
        if ($result['success']) {
            error_log('[PUNTWORK] Async scheduled import completed successfully');
        } else {
            error_log('[PUNTWORK] Async scheduled import failed: ' . ($result['message'] ?? 'Unknown error'));
            // Reset import status on failure so future attempts can start
            delete_option('job_import_status');
            error_log('[PUNTWORK] Reset job_import_status due to import failure');
        }
    } catch (\Exception $e) {
        error_log('[PUNTWORK] Async scheduled import exception: ' . $e->getMessage());
        error_log('[PUNTWORK] Exception trace: ' . $e->getTraceAsString());
        // Reset import status on exception so future attempts can start
        delete_option('job_import_status');
        error_log('[PUNTWORK] Reset job_import_status due to import exception');
    }

    error_log('[PUNTWORK] === ASYNC FUNCTION COMPLETED ===');
}

/**
 * Handle manual import cron job
 * Modified to handle resumable imports
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

        // Import runs to completion without pausing
        if ($result['success']) {
            error_log('[PUNTWORK] Manual import cron completed successfully');
        } else {
            error_log('[PUNTWORK] Manual import cron failed: ' . ($result['message'] ?? 'Unknown error'));
        }
    } catch (\Exception $e) {
        error_log('[PUNTWORK] Manual import cron exception: ' . $e->getMessage());
    }
}