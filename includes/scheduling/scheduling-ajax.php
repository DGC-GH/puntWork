<?php

/**
 * AJAX handlers for scheduling operations.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/*
 * AJAX handlers for scheduling operations
 * Handles schedule management, history, and execution
 */

// Explicitly load required utility classes for AJAX context
require_once __DIR__ . '/../utilities/SecurityUtils.php';
require_once __DIR__ . '/../utilities/AjaxErrorHandler.php';
require_once __DIR__ . '/../utilities/PuntWorkLogger.php';

add_action(
    'wp_ajax_debug_trigger_async',
    function () {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }

        error_log('[PUNTWORK] === MANUAL DEBUG TRIGGER ===');
        run_scheduled_import_async();
        error_log('[PUNTWORK] === MANUAL DEBUG TRIGGER COMPLETED ===');

        wp_die('Async function triggered - check debug.log');
    }
);

// Debug endpoint to clear import status
add_action(
    'wp_ajax_debug_clear_import_status',
    function () {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }

        delete_option('job_import_status');
        delete_transient('import_cancel');
        error_log('[PUNTWORK] === DEBUG: Cleared import status and cancel transient ===');

        wp_die('Import status cleared - you can now try Run Now again');
    }
);

/**
 * Save import schedule settings via AJAX.
 */
function save_import_schedule_ajax()
{
    PuntWorkLogger::logAjaxRequest('save_import_schedule', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('save_import_schedule', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        // Validate and sanitize input fields
        $enabled = SecurityUtils::validateField($_POST, 'enabled', 'boolean', ['default' => false]);
        $frequency = SecurityUtils::validateField(
            $_POST,
            'frequency',
            'string',
            [
                'default' => 'daily',
                'allowed_values' => ['hourly', '3hours', '6hours', '12hours', 'daily', 'custom'],
            ]
        );
        $interval = SecurityUtils::validateField(
            $_POST,
            'interval',
            'integer',
            [
                'min' => 1,
                'max' => 168,
                'default' => 24,
            ]
        );
        $hour = SecurityUtils::validateField(
            $_POST,
            'hour',
            'integer',
            [
                'min' => 0,
                'max' => 23,
                'default' => 9,
            ]
        );
        $minute = SecurityUtils::validateField(
            $_POST,
            'minute',
            'integer',
            [
                'min' => 0,
                'max' => 59,
                'default' => 0,
            ]
        );

        PuntWorkLogger::info(
            'Saving import schedule',
            PuntWorkLogger::CONTEXT_SCHEDULING,
            [
                'enabled' => $enabled,
                'frequency' => $frequency,
                'interval' => $interval,
                'hour' => $hour,
                'minute' => $minute,
            ]
        );

        $schedule_data = [
            'enabled' => $enabled,
            'frequency' => $frequency,
            'interval' => $interval,
            'hour' => $hour,
            'minute' => $minute,
            'updated_at' => current_time('timestamp'),
            'updated_by' => get_current_user_id(),
        ];

        update_option('puntwork_import_schedule', $schedule_data);

        // Update WordPress cron
        update_cron_schedule($schedule_data);

        $last_run = get_option('puntwork_last_import_run', null);
        $last_run_details = get_option('puntwork_last_import_details', null);

        PuntWorkLogger::info('Import schedule saved successfully', PuntWorkLogger::CONTEXT_SCHEDULING);

        PuntWorkLogger::logAjaxResponse(
            'save_import_schedule',
            [
                'message' => 'Schedule saved successfully',
                'schedule' => $schedule_data,
                'next_run' => get_next_scheduled_time(),
                'last_run' => $last_run,
                'last_run_details' => $last_run_details,
            ]
        );
        AjaxErrorHandler::sendSuccess(
            [
                'message' => 'Schedule saved successfully',
                'schedule' => $schedule_data,
                'next_run' => get_next_scheduled_time(),
                'last_run' => $last_run,
                'last_run_details' => $last_run_details,
            ]
        );
    } catch (\Exception $e) {
        PuntWorkLogger::error('Save schedule failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING);
        AjaxErrorHandler::sendError('Save schedule failed: ' . $e->getMessage());
    }
}

/**
 * Get current import schedule settings via AJAX.
 */
function get_import_schedule_ajax()
{
    $debug_mode = defined('WP_DEBUG') && WP_DEBUG;

    if ($debug_mode) {
        error_log('[PUNTWORK] [SCHEDULE-AJAX-START] ===== GET_IMPORT_SCHEDULE_AJAX START =====');
        error_log('[PUNTWORK] [SCHEDULE-AJAX-START] POST data: ' . json_encode($_POST));
        error_log('[PUNTWORK] [SCHEDULE-AJAX-START] Memory usage at start: ' . memory_get_usage(true) . ' bytes');
    }

    PuntWorkLogger::logAjaxRequest('get_import_schedule', $_POST);

    // Simple validation for debugging
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'job_import_nonce')) {
        error_log('[PUNTWORK] [DEBUG-AJAX] Nonce verification failed for get_import_schedule');
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    if (!current_user_can('manage_options')) {
        error_log('[PUNTWORK] [DEBUG-AJAX] Insufficient permissions for get_import_schedule');
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    try {
        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULE-AJAX-DEBUG] Attempting to get schedule from database');
        }
        $schedule = safe_get_option(
            'puntwork_import_schedule',
            [
                'enabled' => false,
                'frequency' => 'daily',
                'interval' => 24,
                'hour' => 9,
                'minute' => 0,
                'updated_at' => null,
                'updated_by' => null,
            ]
        );

        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULE-AJAX-DEBUG] Schedule retrieved: ' . json_encode($schedule));
        }

        PuntWorkLogger::info(
            'Retrieved import schedule',
            PuntWorkLogger::CONTEXT_SCHEDULING,
            [
                'enabled' => $schedule['enabled'],
                'frequency' => $schedule['frequency'],
            ]
        );

        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULE-AJAX-DEBUG] Getting last run data');
        }
        $last_run = safe_get_option('puntwork_last_import_run', null);
        $last_run_details = safe_get_option('puntwork_last_import_details', null);

        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULE-AJAX-DEBUG] Last run: ' . json_encode($last_run));
            error_log('[PUNTWORK] [SCHEDULE-AJAX-DEBUG] Last run details: ' . json_encode($last_run_details));
        }

        // Add formatted date to last run if it exists
        if ($last_run && isset($last_run['timestamp'])) {
            // Timestamps are now stored in UTC using time(), wp_date() handles timezone conversion
            $last_run['formatted_date'] = wp_date('M j, Y H:i', $last_run['timestamp']);
        }

        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULE-AJAX-DEBUG] Getting next scheduled time');
        }
        $next_run = get_next_scheduled_time();

        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULE-AJAX-DEBUG] Next run: ' . json_encode($next_run));
            error_log('[PUNTWORK] [SCHEDULE-AJAX-DEBUG] Preparing success response');
        }

        PuntWorkLogger::logAjaxResponse(
            'get_import_schedule',
            [
                'schedule' => $schedule,
                'next_run' => $next_run,
                'last_run' => $last_run,
                'last_run_details' => $last_run_details,
            ]
        );
        AjaxErrorHandler::sendSuccess(
            [
                'schedule' => $schedule,
                'next_run' => $next_run,
                'last_run' => $last_run,
                'last_run_details' => $last_run_details,
            ]
        );

        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULE-AJAX-END] ===== GET_IMPORT_SCHEDULE_AJAX SUCCESS =====');
        }
    } catch (\Exception $e) {
        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULE-AJAX-ERROR] Exception in get_import_schedule_ajax: ' . $e->getMessage());
            error_log('[PUNTWORK] [SCHEDULE-AJAX-ERROR] Stack trace: ' . $e->getTraceAsString());
        }
        PuntWorkLogger::error('Get schedule failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING);
        AjaxErrorHandler::sendError('Get schedule failed: ' . $e->getMessage());
    } catch (\Throwable $e) {
        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULE-AJAX-FATAL] Fatal error in get_import_schedule_ajax: ' . $e->getMessage());
            error_log('[PUNTWORK] [SCHEDULE-AJAX-FATAL] Stack trace: ' . $e->getTraceAsString());
        }
        PuntWorkLogger::error('Get schedule fatal error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING);
        AjaxErrorHandler::sendError('Get schedule failed with fatal error: ' . $e->getMessage());
    }
}

/**
 * Get import run history via AJAX.
 */
function get_import_run_history_ajax()
{
    $debug_mode = defined('WP_DEBUG') && WP_DEBUG;

    if ($debug_mode) {
        error_log('[PUNTWORK] [HISTORY-AJAX-START] ===== GET_IMPORT_RUN_HISTORY_AJAX START =====');
        error_log('[PUNTWORK] [HISTORY-AJAX-START] POST data: ' . json_encode($_POST));
        error_log('[PUNTWORK] [HISTORY-AJAX-START] Memory usage at start: ' . memory_get_usage(true) . ' bytes');
    }

    PuntWorkLogger::logAjaxRequest('get_import_run_history', $_POST);

    // Simple validation for debugging
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'job_import_nonce')) {
        error_log('[PUNTWORK] [DEBUG-AJAX] Nonce verification failed for get_import_run_history');
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    if (!current_user_can('manage_options')) {
        error_log('[PUNTWORK] [DEBUG-AJAX] Insufficient permissions for get_import_run_history');
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    try {
        if ($debug_mode) {
            error_log('[PUNTWORK] [HISTORY-AJAX-DEBUG] Attempting to get history from database');
        }
        $history = safe_get_option('puntwork_import_run_history', []);

        if ($debug_mode) {
            error_log('[PUNTWORK] [HISTORY-AJAX-DEBUG] History retrieved, count: ' . count($history));
        }

        // Format dates for history entries - timestamps are stored in UTC
        foreach ($history as &$entry) {
            if (isset($entry['timestamp'])) {
                $entry['formatted_date'] = wp_date('M j, Y H:i', $entry['timestamp']);
            }
        }

        if ($debug_mode) {
            error_log('[PUNTWORK] [HISTORY-AJAX-DEBUG] History formatted, preparing response');
        }

        PuntWorkLogger::info(
            'Retrieved import run history',
            PuntWorkLogger::CONTEXT_SCHEDULING,
            [
                'history_count' => count($history),
            ]
        );

        PuntWorkLogger::logAjaxResponse(
            'get_import_run_history',
            [
                'history' => $history,
                'count' => count($history),
            ]
        );
        AjaxErrorHandler::sendSuccess(
            [
                'history' => $history,
                'count' => count($history),
            ]
        );

        if ($debug_mode) {
            error_log('[PUNTWORK] [HISTORY-AJAX-END] ===== GET_IMPORT_RUN_HISTORY_AJAX SUCCESS =====');
        }
    } catch (\Exception $e) {
        if ($debug_mode) {
            error_log('[PUNTWORK] [HISTORY-AJAX-ERROR] Exception in get_import_run_history_ajax: ' . $e->getMessage());
            error_log('[PUNTWORK] [HISTORY-AJAX-ERROR] Stack trace: ' . $e->getTraceAsString());
        }
        PuntWorkLogger::error('Get run history failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING);
        AjaxErrorHandler::sendError('Get run history failed: ' . $e->getMessage());
    } catch (\Throwable $e) {
        if ($debug_mode) {
            error_log('[PUNTWORK] [HISTORY-AJAX-FATAL] Fatal error in get_import_run_history_ajax: ' . $e->getMessage());
            error_log('[PUNTWORK] [HISTORY-AJAX-FATAL] Stack trace: ' . $e->getTraceAsString());
        }
        PuntWorkLogger::error('Get run history fatal error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING);
        AjaxErrorHandler::sendError('Get run history failed with fatal error: ' . $e->getMessage());
    }
}

/**
 * Test import schedule via AJAX.
 */
function test_import_schedule_ajax()
{
    PuntWorkLogger::logAjaxRequest('test_import_schedule', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('test_import_schedule', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        PuntWorkLogger::info('Starting test import schedule', PuntWorkLogger::CONTEXT_SCHEDULING);

        // Run a test import
        $result = run_scheduled_import(true); // true = test mode

        PuntWorkLogger::info(
            'Test import schedule completed',
            PuntWorkLogger::CONTEXT_SCHEDULING,
            [
                'success' => $result['success'] ?? false,
            ]
        );

        PuntWorkLogger::logAjaxResponse(
            'test_import_schedule',
            [
                'message' => 'Test import completed',
                'result' => $result,
            ]
        );
        AjaxErrorHandler::sendSuccess(
            [
                'message' => 'Test import completed',
                'result' => $result,
            ]
        );
    } catch (\Exception $e) {
        PuntWorkLogger::error('Test import schedule failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING);
        AjaxErrorHandler::sendError('Test import schedule failed: ' . $e->getMessage());
    }
}

/**
 * Run scheduled import immediately via AJAX
 * Now triggers the import asynchronously like the manual Start Import button.
 */
function run_scheduled_import_ajax()
{
    PuntWorkLogger::logAjaxRequest('run_scheduled_import', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('run_scheduled_import', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

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
            $is_stuck = (!isset($import_status['processed']) || $import_status['processed'] === 0) &&
                        ($time_elapsed > 300); // 5 minutes

            if ($is_stuck) {
                PuntWorkLogger::warn(
                    'Detected stuck import, clearing status for new run',
                    PuntWorkLogger::CONTEXT_SCHEDULING,
                    [
                        'processed' => $import_status['processed'] ?? 'null',
                        'time_elapsed' => $time_elapsed,
                    ]
                );
                delete_option('job_import_status');
                delete_transient('import_cancel');
            } else {
                PuntWorkLogger::error('Import already running', PuntWorkLogger::CONTEXT_SCHEDULING);
                AjaxErrorHandler::sendError('An import is already running');

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
            'batch_size' => get_option('job_import_batch_size') ?: 1,
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
            PuntWorkLogger::warn('No async scheduling available, running synchronously', PuntWorkLogger::CONTEXT_SCHEDULING);
            $result = run_scheduled_import();

            if ($result['success']) {
                PuntWorkLogger::info('Synchronous scheduled import completed successfully', PuntWorkLogger::CONTEXT_SCHEDULING);
                PuntWorkLogger::logAjaxResponse(
                    'run_scheduled_import',
                    [
                        'message' => 'Import completed successfully',
                        'result' => $result,
                        'async' => false,
                    ]
                );
                AjaxErrorHandler::sendSuccess(
                    [
                        'message' => 'Import completed successfully',
                        'result' => $result,
                        'async' => false,
                    ]
                );
            } else {
                PuntWorkLogger::error(
                    'Synchronous scheduled import failed',
                    PuntWorkLogger::CONTEXT_SCHEDULING,
                    [
                        'message' => $result['message'] ?? 'Unknown error',
                    ]
                );
                // Reset import status on failure so future attempts can start
                delete_option('job_import_status');
                AjaxErrorHandler::sendError(['message' => 'Import failed: ' . ($result['message'] ?? 'Unknown error')]);
            }

            return;
        }

        // Return success immediately so UI can start polling
        PuntWorkLogger::info('Scheduled import initiated asynchronously', PuntWorkLogger::CONTEXT_SCHEDULING);
        PuntWorkLogger::logAjaxResponse(
            'run_scheduled_import',
            [
                'message' => 'Import started successfully',
                'async' => true,
            ]
        );
        AjaxErrorHandler::sendSuccess(
            [
                'message' => 'Import started successfully',
                'async' => true,
            ]
        );
    } catch (\Exception $e) {
        PuntWorkLogger::error('Run scheduled import failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING);
        AjaxErrorHandler::sendError('Failed to start import: ' . $e->getMessage());
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
 * Run scheduled import asynchronously (non-blocking).
 */
function run_scheduled_import_async()
{
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

    if (
        isset($import_status['complete']) && $import_status['complete'] == false
        && isset($import_status['processed']) && $import_status['processed'] > 0
    ) {
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
 * Run the complete scheduled import process: feed processing -> combination -> import.
 */
function run_scheduled_import(bool $test_mode = false, string $trigger_type = 'scheduled'): array
{
    $debug_mode = defined('WP_DEBUG') && WP_DEBUG;
    $start_time = microtime(true);

    // Initialize memory management for large batch operations
    \Puntwork\Utilities\MemoryManager::reset();
    \Puntwork\Utilities\MemoryManager::optimizeForLargeBatch();

    if ($debug_mode) {
        error_log('[PUNTWORK] [SCHEDULED-IMPORT] ===== STARTING SCHEDULED IMPORT =====');
        error_log('[PUNTWORK] [SCHEDULED-IMPORT] Test mode: ' . ($test_mode ? 'true' : 'false'));
        error_log('[PUNTWORK] [SCHEDULED-IMPORT] Trigger type: ' . $trigger_type);
        error_log('[PUNTWORK] [SCHEDULED-IMPORT] Start time: ' . date('Y-m-d H:i:s'));
    }

    try {
        // Step 1: Get all configured feeds
        $feeds = get_feeds();
        if (empty($feeds)) {
            $error_msg = 'No feeds configured for import';
            if ($debug_mode) {
                error_log('[PUNTWORK] [SCHEDULED-IMPORT] ERROR: ' . $error_msg);
            }

            return [
                'success' => false,
                'message' => $error_msg,
                'logs' => [$error_msg],
            ];
        }

        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULED-IMPORT] Found ' . count($feeds) . ' feeds to process: ' . json_encode(array_keys($feeds)));
        }

        // Step 2: Process all feeds (download and convert to JSONL)
        $output_dir = ABSPATH . 'feeds/';
        $fallback_domain = 'belgiumjobs.work';
        $total_items = 0;
        $all_logs = [];

        // Ensure output directory exists
        if (!wp_mkdir_p($output_dir) || !is_writable($output_dir)) {
            $error_msg = 'Feeds directory not writable: ' . $output_dir;
            if ($debug_mode) {
                error_log('[PUNTWORK] [SCHEDULED-IMPORT] ERROR: ' . $error_msg);
            }

            return [
                'success' => false,
                'message' => $error_msg,
                'logs' => [$error_msg],
            ];
        }

        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULED-IMPORT] Output directory ready: ' . $output_dir);
        }

        // Load required functions
        if (!function_exists('process_one_feed')) {
            require_once __DIR__ . '/../core/core-structure-logic.php';
        }

        foreach ($feeds as $feed_key => $feed_url) {
            if ($debug_mode) {
                error_log('[PUNTWORK] [SCHEDULED-IMPORT] Processing feed: ' . $feed_key . ' -> ' . $feed_url);
            }

            $logs = [];
            $item_count = process_one_feed($feed_key, $feed_url, $output_dir, $fallback_domain, $logs);
            $total_items += $item_count;

            // Check if feed processing failed and log specific error
            if ($item_count === 0 && !empty($logs)) {
                $last_log = end($logs);
                if (strpos($last_log, 'Download error:') !== false || strpos($last_log, 'ERROR') !== false) {
                    PuntWorkLogger::error(
                        'Feed processing failed',
                        PuntWorkLogger::CONTEXT_FEED_PROCESSING,
                        [
                            'feed_key' => $feed_key,
                            'feed_url' => $feed_url,
                            'error_details' => $last_log,
                            'all_logs' => $logs,
                        ]
                    );
                }
            }

            if ($debug_mode) {
                error_log('[PUNTWORK] [SCHEDULED-IMPORT] Feed ' . $feed_key . ' processed, items: ' . $item_count);
                error_log('[PUNTWORK] [SCHEDULED-IMPORT] Feed logs: ' . json_encode($logs));
            }

            $all_logs = array_merge($all_logs, $logs);

            // Check memory usage after each feed processing
            $memory_check = \Puntwork\Utilities\MemoryManager::checkMemoryUsage($total_items);
            if (!empty($memory_check['actions_taken'])) {
                $all_logs[] = 'Memory management triggered: ' . implode(', ', $memory_check['actions_taken']) . ' (Usage: ' . round($memory_check['memory_ratio'] * 100, 1) . '%)';
                if ($debug_mode) {
                    error_log('[PUNTWORK] [SCHEDULED-IMPORT] Memory check after feed ' . $feed_key . ': ' . json_encode($memory_check));
                }
            }
        }

        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULED-IMPORT] All feeds processed, total items: ' . $total_items);
        }

        // Step 3: Combine JSONL files
        if (!function_exists('combine_jsonl_files')) {
            require_once __DIR__ . '/../import/combine-jsonl.php';
        }

        // Check memory before combining
        $memory_check = \Puntwork\Utilities\MemoryManager::checkMemoryUsage($total_items);
        if ($memory_check['memory_ratio'] > 0.7) {
            $all_logs[] = 'High memory usage before combining: ' . round($memory_check['memory_ratio'] * 100, 1) . '% - forcing cleanup';
            gc_collect_cycles();
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            if ($debug_mode) {
                error_log('[PUNTWORK] [SCHEDULED-IMPORT] Memory cleanup before combining: ' . json_encode($memory_check));
            }
        }

        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULED-IMPORT] Combining JSONL files...');
        }

        $combine_logs = [];
        combine_jsonl_files($feeds, $output_dir, $total_items, $combine_logs);
        $all_logs = array_merge($all_logs, $combine_logs);

        // Check if combined file was created
        $combined_file = $output_dir . 'combined-jobs.jsonl';
        if (!file_exists($combined_file)) {
            $error_msg = 'Combined JSONL file was not created';
            if ($debug_mode) {
                error_log('[PUNTWORK] [SCHEDULED-IMPORT] ERROR: ' . $error_msg);
            }

            return [
                'success' => false,
                'message' => $error_msg,
                'logs' => $all_logs,
            ];
        }

        $file_size = filesize($combined_file);
        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULED-IMPORT] Combined file created: ' . $combined_file . ' (' . $file_size . ' bytes)');
        }

        if ($file_size == 0) {
            $error_msg = 'Combined JSONL file is empty';
            if ($debug_mode) {
                error_log('[PUNTWORK] [SCHEDULED-IMPORT] ERROR: ' . $error_msg);
            }

            return [
                'success' => false,
                'message' => $error_msg,
                'logs' => $all_logs,
            ];
        }

        // Step 4: Run the import
        // Check memory before import
        $memory_check = \Puntwork\Utilities\MemoryManager::checkMemoryUsage($total_items);
        if ($memory_check['memory_ratio'] > 0.8) {
            $all_logs[] = 'High memory usage before import: ' . round($memory_check['memory_ratio'] * 100, 1) . '% - forcing cleanup';
            gc_collect_cycles();
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            if ($debug_mode) {
                error_log('[PUNTWORK] [SCHEDULED-IMPORT] Memory cleanup before import: ' . json_encode($memory_check));
            }
        }

        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULED-IMPORT] Starting import process...');
        }

        $import_result = import_all_jobs_from_json();

        $total_duration = microtime(true) - $start_time;

        if ($import_result['success']) {
            if ($debug_mode) {
                error_log('[PUNTWORK] [SCHEDULED-IMPORT] Scheduled import completed successfully in ' . $total_duration . ' seconds');
            }

            // Log the successful run
            include_once __DIR__ . '/../scheduling/scheduling-history.php';
            if (function_exists('log_manual_import_run')) {
                log_manual_import_run(
                    [
                        'timestamp' => time(),
                        'duration' => $total_duration,
                        'success' => true,
                        'processed' => $import_result['processed'] ?? 0,
                        'total' => $import_result['total'] ?? 0,
                        'published' => $import_result['published'] ?? 0,
                        'updated' => $import_result['updated'] ?? 0,
                        'skipped' => $import_result['skipped'] ?? 0,
                        'error_message' => '',
                    ]
                );
            }

            return array_merge(
                $import_result,
                [
                    'logs' => array_merge($all_logs, $import_result['logs'] ?? []),
                    'feed_processing_time' => $total_duration - ($import_result['time_elapsed'] ?? 0),
                ]
            );
        } else {
            $error_msg = $import_result['message'] ?? 'Import failed';
            if ($debug_mode) {
                error_log('[PUNTWORK] [SCHEDULED-IMPORT] Scheduled import failed: ' . $error_msg);
            }

            // Log the failed run
            include_once __DIR__ . '/../scheduling/scheduling-history.php';
            if (function_exists('log_manual_import_run')) {
                log_manual_import_run(
                    [
                        'timestamp' => time(),
                        'duration' => $total_duration,
                        'success' => false,
                        'processed' => $import_result['processed'] ?? 0,
                        'total' => $import_result['total'] ?? 0,
                        'published' => $import_result['published'] ?? 0,
                        'updated' => $import_result['updated'] ?? 0,
                        'skipped' => $import_result['skipped'] ?? 0,
                        'error_message' => $error_msg,
                    ]
                );
            }

            return [
                'success' => false,
                'message' => $error_msg,
                'logs' => array_merge($all_logs, $import_result['logs'] ?? []),
            ];
        }
    } catch (\Exception $e) {
        $error_msg = 'Scheduled import failed: ' . $e->getMessage();
        if ($debug_mode) {
            error_log('[PUNTWORK] [SCHEDULED-IMPORT] Exception: ' . $error_msg);
            error_log('[PUNTWORK] [SCHEDULED-IMPORT] Stack trace: ' . $e->getTraceAsString());
        }

        return [
            'success' => false,
            'message' => $error_msg,
            'logs' => $all_logs ?? [],
        ];
    }
}
