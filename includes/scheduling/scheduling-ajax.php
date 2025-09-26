<?php
/**
 * AJAX handlers for scheduling operations
 *
 * @package    Puntwork
 * @subpackage Scheduling
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direc// Debug endpoint to manually trigger async function
add_action('wp_ajax_debug_trigger_async', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied');
    }

    error_log('[PUNTWORK] === MANUAL DEBUG TRIGGER ===');
    run_scheduled_import_async();
    error_log('[PUNTWORK] === MANUAL DEBUG TRIGGER COMPLETED ===');

    wp_die('Async function triggered - check debug.log');
});

// Debug endpoint to clear import status
add_action('wp_ajax_debug_clear_import_status', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied');
    }

    delete_option('job_import_status');
    delete_transient('import_cancel');
    error_log('[PUNTWORK] === DEBUG: Cleared import status and cancel transient ===');

    wp_die('Import status cleared - you can now try Run Now again');
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
        // Timestamps are now stored in UTC using time(), wp_date() handles timezone conversion
        $last_run['formatted_date'] = wp_date('M j, Y H:i', $last_run['timestamp']);
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
    error_log('[PUNTWORK] get_import_run_history_ajax called');

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        error_log('[PUNTWORK] get_import_run_history_ajax: Nonce verification failed');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        error_log('[PUNTWORK] get_import_run_history_ajax: Permission denied');
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $history = get_option('puntwork_import_run_history', []);
    error_log('[PUNTWORK] get_import_run_history_ajax: Retrieved history with ' . count($history) . ' entries');

    // Format dates for history entries - timestamps are stored in UTC
    foreach ($history as &$entry) {
        if (isset($entry['timestamp'])) {
            $entry['formatted_date'] = wp_date('M j, Y H:i', $entry['timestamp']);
        }
    }

    error_log('[PUNTWORK] get_import_run_history_ajax: Returning history data');
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
        // Calculate actual time elapsed
        $time_elapsed = 0;
        if (isset($import_status['start_time']) && $import_status['start_time'] > 0) {
            $time_elapsed = microtime(true) - $import_status['start_time'];
        } elseif (isset($import_status['time_elapsed'])) {
            $time_elapsed = $import_status['time_elapsed'];
        }
        
        // Check if it's a stuck import (processed = 0 and old)
        $is_stuck = (!isset($import_status['processed']) || $import_status['processed'] == 0) &&
                   ($time_elapsed > 300); // 5 minutes
        
        if ($is_stuck) {
            error_log('[PUNTWORK] Detected stuck import (processed: ' . ($import_status['processed'] ?? 'null') . ', time_elapsed: ' . $time_elapsed . '), clearing status for new run');
            delete_option('job_import_status');
            delete_transient('import_cancel');
        } else {
            wp_send_json_error(['message' => 'An import is already running']);
            return;
        }
    }

    try {
        // Initialize import status for immediate UI feedback
        $initial_status = [
            'total' => 0, // Will be updated as import progresses
            'processed' => 0,
            'published' => 0,
            'updated' => 0,
            'skipped' => 0,
            'duplicates_drafted' => 0,
            'time_elapsed' => 0,
            'success' => false,
            'error_message' => '',
            'batch_size' => get_option('job_import_batch_size') ?: 100,
            'inferred_languages' => 0,
            'inferred_benefits' => 0,
            'schema_generated' => 0,
            'start_time' => microtime(true),
            'end_time' => null,
            'last_update' => time(),
            'logs' => ['Scheduled import started - preparing feeds...'],
        ];
        update_option('job_import_status', $initial_status, false);
        error_log('[PUNTWORK] Initialized import status for scheduled run: ' . json_encode($initial_status));

        // Clear any previous cancellation before starting
        delete_transient('import_cancel');
        error_log('[PUNTWORK] Cleared import_cancel transient for scheduled run');

        // Schedule the import to run asynchronously
        if (function_exists('as_schedule_single_action')) {
            // Use Action Scheduler if available
            error_log('[PUNTWORK] Scheduling async import using Action Scheduler');
            as_schedule_single_action(time(), 'puntwork_scheduled_import_async');
        } elseif (function_exists('wp_schedule_single_event')) {
            // Fallback: Use WordPress cron for near-immediate execution
            error_log('[PUNTWORK] Action Scheduler not available, using WordPress cron');
            wp_schedule_single_event(time() + 1, 'puntwork_scheduled_import_async');
        } else {
            // Final fallback: Run synchronously (not ideal for UI but maintains functionality)
            error_log('[PUNTWORK] No async scheduling available, running synchronously');
            $result = run_scheduled_import();
            
            if ($result['success']) {
                error_log('[PUNTWORK] Synchronous scheduled import completed successfully');
                wp_send_json_success([
                    'message' => 'Import completed successfully',
                    'result' => $result,
                    'async' => false
                ]);
            } else {
                error_log('[PUNTWORK] Synchronous scheduled import failed: ' . ($result['message'] ?? 'Unknown error'));
                // Reset import status on failure so future attempts can start
                delete_option('job_import_status');
                error_log('[PUNTWORK] Reset job_import_status due to import failure');
                wp_send_json_error(['message' => 'Import failed: ' . ($result['message'] ?? 'Unknown error')]);
            }
            return;
        }

        // Return success immediately so UI can start polling
        error_log('[PUNTWORK] Scheduled import initiated asynchronously');
        wp_send_json_success([
            'message' => 'Import started successfully',
            'async' => true
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
    error_log('[PUNTWORK] Current import status at async start: ' . json_encode($import_status));

    if (isset($import_status['complete']) && $import_status['complete'] === false && 
        isset($import_status['processed']) && $import_status['processed'] > 0) {
        error_log('[PUNTWORK] Async import skipped - import already running and has processed items');
        return;
    }

    error_log('[PUNTWORK] Starting actual import process...');

    // Clear import_cancel transient again just before starting the import
    delete_transient('import_cancel');
    error_log('[PUNTWORK] Cleared import_cancel transient again before import');

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
    if (isset($import_status['complete']) && $import_status['complete'] === false && 
        isset($import_status['processed']) && $import_status['processed'] > 0) {
        error_log('[PUNTWORK] Manual import cron skipped - import already running and has processed items');
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