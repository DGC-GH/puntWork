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
    PuntWorkLogger::logAjaxRequest('save_import_schedule', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validate_ajax_request('save_import_schedule', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::send_error($validation);
        return;
    }

    try {
        // Validate and sanitize input fields
        $enabled = SecurityUtils::validate_field($_POST, 'enabled', 'boolean', ['default' => false]);
        $frequency = SecurityUtils::validate_field($_POST, 'frequency', 'string', [
            'default' => 'daily',
            'allowed_values' => ['hourly', '3hours', '6hours', '12hours', 'daily', 'custom']
        ]);
        $interval = SecurityUtils::validate_field($_POST, 'interval', 'integer', [
            'min' => 1,
            'max' => 168,
            'default' => 24
        ]);
        $hour = SecurityUtils::validate_field($_POST, 'hour', 'integer', [
            'min' => 0,
            'max' => 23,
            'default' => 9
        ]);
        $minute = SecurityUtils::validate_field($_POST, 'minute', 'integer', [
            'min' => 0,
            'max' => 59,
            'default' => 0
        ]);

        PuntWorkLogger::info('Saving import schedule', PuntWorkLogger::CONTEXT_SCHEDULING, [
            'enabled' => $enabled,
            'frequency' => $frequency,
            'interval' => $interval,
            'hour' => $hour,
            'minute' => $minute
        ]);

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

        // Update WordPress cron
        update_cron_schedule($schedule_data);

        $last_run = get_option('puntwork_last_import_run', null);
        $last_run_details = get_option('puntwork_last_import_details', null);

        PuntWorkLogger::info('Import schedule saved successfully', PuntWorkLogger::CONTEXT_SCHEDULING);

        PuntWorkLogger::logAjaxResponse('save_import_schedule', [
            'message' => 'Schedule saved successfully',
            'schedule' => $schedule_data,
            'next_run' => get_next_scheduled_time(),
            'last_run' => $last_run,
            'last_run_details' => $last_run_details
        ]);
        AjaxErrorHandler::send_success([
            'message' => 'Schedule saved successfully',
            'schedule' => $schedule_data,
            'next_run' => get_next_scheduled_time(),
            'last_run' => $last_run,
            'last_run_details' => $last_run_details
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Save schedule failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING);
        AjaxErrorHandler::send_error('Save schedule failed: ' . $e->getMessage());
    }
}

/**
 * Get current import schedule settings via AJAX
 */
function get_import_schedule_ajax() {
    PuntWorkLogger::logAjaxRequest('get_import_schedule', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validate_ajax_request('get_import_schedule', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::send_error($validation);
        return;
    }

    try {
        $schedule = get_option('puntwork_import_schedule', [
            'enabled' => false,
            'frequency' => 'daily',
            'interval' => 24,
            'hour' => 9,
            'minute' => 0,
            'updated_at' => null,
            'updated_by' => null
        ]);

        PuntWorkLogger::info('Retrieved import schedule', PuntWorkLogger::CONTEXT_SCHEDULING, [
            'enabled' => $schedule['enabled'],
            'frequency' => $schedule['frequency']
        ]);

        $last_run = get_option('puntwork_last_import_run', null);
        $last_run_details = get_option('puntwork_last_import_details', null);

        // Add formatted date to last run if it exists
        if ($last_run && isset($last_run['timestamp'])) {
            // Timestamps are now stored in UTC using time(), wp_date() handles timezone conversion
            $last_run['formatted_date'] = wp_date('M j, Y H:i', $last_run['timestamp']);
        }

        PuntWorkLogger::logAjaxResponse('get_import_schedule', [
            'schedule' => $schedule,
            'next_run' => get_next_scheduled_time(),
            'last_run' => $last_run,
            'last_run_details' => $last_run_details
        ]);
        AjaxErrorHandler::send_success([
            'schedule' => $schedule,
            'next_run' => get_next_scheduled_time(),
            'last_run' => $last_run,
            'last_run_details' => $last_run_details
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Get schedule failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING);
        AjaxErrorHandler::send_error('Get schedule failed: ' . $e->getMessage());
    }
}

/**
 * Get import run history via AJAX
 */
function get_import_run_history_ajax() {
    PuntWorkLogger::logAjaxRequest('get_import_run_history', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validate_ajax_request('get_import_run_history', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::send_error($validation);
        return;
    }

    try {
        $history = get_option('puntwork_import_run_history', []);

        // Format dates for history entries - timestamps are stored in UTC
        foreach ($history as &$entry) {
            if (isset($entry['timestamp'])) {
                $entry['formatted_date'] = wp_date('M j, Y H:i', $entry['timestamp']);
            }
        }

        PuntWorkLogger::info('Retrieved import run history', PuntWorkLogger::CONTEXT_SCHEDULING, [
            'history_count' => count($history)
        ]);

        PuntWorkLogger::logAjaxResponse('get_import_run_history', [
            'history' => $history,
            'count' => count($history)
        ]);
        AjaxErrorHandler::send_success([
            'history' => $history,
            'count' => count($history)
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Get run history failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING);
        AjaxErrorHandler::send_error('Get run history failed: ' . $e->getMessage());
    }
}

/**
 * Test import schedule via AJAX
 */
function test_import_schedule_ajax() {
    PuntWorkLogger::logAjaxRequest('test_import_schedule', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validate_ajax_request('test_import_schedule', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::send_error($validation);
        return;
    }

    try {
        PuntWorkLogger::info('Starting test import schedule', PuntWorkLogger::CONTEXT_SCHEDULING);

        // Run a test import
        $result = run_scheduled_import(true); // true = test mode

        PuntWorkLogger::info('Test import schedule completed', PuntWorkLogger::CONTEXT_SCHEDULING, [
            'success' => $result['success'] ?? false
        ]);

        PuntWorkLogger::logAjaxResponse('test_import_schedule', [
            'message' => 'Test import completed',
            'result' => $result
        ]);
        AjaxErrorHandler::send_success([
            'message' => 'Test import completed',
            'result' => $result
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Test import schedule failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING);
        AjaxErrorHandler::send_error('Test import schedule failed: ' . $e->getMessage());
    }
}

/**
 * Run scheduled import immediately via AJAX
 * Now triggers the import asynchronously like the manual Start Import button
 */
function run_scheduled_import_ajax() {
    PuntWorkLogger::logAjaxRequest('run_scheduled_import', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validate_ajax_request('run_scheduled_import', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::send_error($validation);
        return;
    }

    try {
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
                PuntWorkLogger::warning('Detected stuck import, clearing status for new run', PuntWorkLogger::CONTEXT_SCHEDULING, [
                    'processed' => $import_status['processed'] ?? 'null',
                    'time_elapsed' => $time_elapsed
                ]);
                delete_option('job_import_status');
                delete_transient('import_cancel');
            } else {
                PuntWorkLogger::error('Import already running', PuntWorkLogger::CONTEXT_SCHEDULING);
                AjaxErrorHandler::send_error('An import is already running');
                return;
            }
        }

        PuntWorkLogger::info('Starting scheduled import', PuntWorkLogger::CONTEXT_SCHEDULING);

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

        // Clear any previous cancellation before starting
        delete_transient('import_cancel');

        // Schedule the import to run asynchronously
        if (function_exists('as_schedule_single_action')) {
            // Use Action Scheduler if available
            PuntWorkLogger::info('Scheduling async import using Action Scheduler', PuntWorkLogger::CONTEXT_SCHEDULING);
            as_schedule_single_action(time(), 'puntwork_scheduled_import_async');
        } elseif (function_exists('wp_schedule_single_event')) {
            // Fallback: Use WordPress cron for near-immediate execution
            PuntWorkLogger::info('Action Scheduler not available, using WordPress cron', PuntWorkLogger::CONTEXT_SCHEDULING);
            wp_schedule_single_event(time() + 1, 'puntwork_scheduled_import_async');
        } else {
            // Final fallback: Run synchronously (not ideal for UI but maintains functionality)
            PuntWorkLogger::warning('No async scheduling available, running synchronously', PuntWorkLogger::CONTEXT_SCHEDULING);
            $result = run_scheduled_import();

            if ($result['success']) {
                PuntWorkLogger::info('Synchronous scheduled import completed successfully', PuntWorkLogger::CONTEXT_SCHEDULING);
                PuntWorkLogger::logAjaxResponse('run_scheduled_import', [
                    'message' => 'Import completed successfully',
                    'result' => $result,
                    'async' => false
                ]);
                AjaxErrorHandler::send_success([
                    'message' => 'Import completed successfully',
                    'result' => $result,
                    'async' => false
                ]);
            } else {
                PuntWorkLogger::error('Synchronous scheduled import failed', PuntWorkLogger::CONTEXT_SCHEDULING, [
                    'message' => $result['message'] ?? 'Unknown error'
                ]);
                // Reset import status on failure so future attempts can start
                delete_option('job_import_status');
                AjaxErrorHandler::send_error(['message' => 'Import failed: ' . ($result['message'] ?? 'Unknown error')]);
            }
            return;
        }

        // Return success immediately so UI can start polling
        PuntWorkLogger::info('Scheduled import initiated asynchronously', PuntWorkLogger::CONTEXT_SCHEDULING);
        PuntWorkLogger::logAjaxResponse('run_scheduled_import', [
            'message' => 'Import started successfully',
            'async' => true
        ]);
        AjaxErrorHandler::send_success([
            'message' => 'Import started successfully',
            'async' => true
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Run scheduled import failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING);
        AjaxErrorHandler::send_error('Failed to start import: ' . $e->getMessage());
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

// Register analytics cleanup hook
add_action('puntwork_analytics_cleanup', [__NAMESPACE__ . '\\ImportAnalytics', 'cleanup_old_data']);

/**
 * Run scheduled import asynchronously (non-blocking)
 */

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
        // Get test mode and trigger type from status if set
        $current_status = get_option('job_import_status', []);
        $test_mode_flag = $current_status['test_mode'] ?? false;
        $trigger_type_flag = $current_status['trigger_type'] ?? 'scheduled';

        $result = run_scheduled_import($test_mode_flag, $trigger_type_flag);
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