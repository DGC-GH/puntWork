<?php
/**
 * History and logging functionality for scheduling
 * Handles import run history, logging, and cleanup operations
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
 * Run the scheduled import
 */
function run_scheduled_import($test_mode = false, $trigger_type = 'scheduled') {
    // Check if scheduling is still enabled (skip this check for test mode or API triggers)
    if (!$test_mode && $trigger_type !== 'api') {
        $schedule = get_option('puntwork_import_schedule', ['enabled' => false]);
        if (!$schedule['enabled']) {
            error_log('[PUNTWORK] Scheduled import skipped - scheduling is disabled');
            return [
                'success' => false,
                'message' => 'Scheduled import skipped - scheduling is disabled'
            ];
        }
    }

    $start_time = microtime(true);

    try {
        // Log the scheduled run
        $log_message = $test_mode ? 'Test import started' : 'Scheduled import started';
        error_log('[PUNTWORK] ' . $log_message);

        // For scheduled imports, refresh the feed data first
        // For API imports, also refresh to get latest data
        if (!$test_mode) {
            error_log('[PUNTWORK] Refreshing feed data for import');
            try {
                fetch_and_generate_combined_json();
                
                // Update status after feed refresh
                $feed_status = get_option('job_import_status', []);
                if (!empty($feed_status)) {
                    $feed_status['logs'][] = 'Feed data refreshed successfully';
                    update_option('job_import_status', $feed_status, false);
                }
            } catch (\Exception $e) {
                error_log('[PUNTWORK] Feed refresh failed: ' . $e->getMessage());
                return [
                    'success' => false,
                    'message' => 'Feed refresh failed: ' . $e->getMessage()
                ];
            }
        }

        // Run the import - don't reset status if it's already initialized for UI polling
        // For API triggers, don't preserve status to allow fresh imports
        $preserve_status = ($trigger_type !== 'api');
        $result = import_all_jobs_from_json($preserve_status);

        $end_time = microtime(true);
        $duration = $end_time - $start_time;

        // Store last run information
        $last_run_data = [
            'timestamp' => time(),
            'duration' => $duration,
            'test_mode' => $test_mode,
            'result' => $result
        ];

        update_option('puntwork_last_import_run', $last_run_data);

        // Store detailed run information
        if (isset($result['success'])) {
            $details = [
                'success' => $result['success'],
                'duration' => $duration,
                'processed' => $result['processed'] ?? 0,
                'total' => $result['total'] ?? 0,
                'published' => $result['published'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
                'error_message' => $result['message'] ?? '',
                'timestamp' => time()
            ];

            update_option('puntwork_last_import_details', $details);

            // Store trigger info in status for later logging
            $current_status = get_option('job_import_status', []);
            $current_status['trigger_type'] = $trigger_type;
            $current_status['test_mode'] = $test_mode;
            update_option('job_import_status', $current_status, false);

            // Only log to history if the import is actually complete (not paused)
            // Paused imports will be logged when they resume and complete
            if (!isset($result['paused']) || !$result['paused']) {
                log_scheduled_run($details, $test_mode, $trigger_type);
            }
        }

        return $result;

    } catch (\Exception $e) {
        $end_time = microtime(true);
        $duration = $end_time - $start_time;

        $error_data = [
            'timestamp' => time(),
            'duration' => $duration,
            'test_mode' => $test_mode,
            'error' => $e->getMessage()
        ];

        update_option('puntwork_last_import_run', $error_data);

        // Log failed run to history
        log_scheduled_run([
            'success' => false,
            'duration' => $duration,
            'processed' => 0,
            'total' => 0,
            'published' => 0,
            'updated' => 0,
            'skipped' => 0,
            'error_message' => $e->getMessage(),
            'timestamp' => time()
        ], $test_mode, $trigger_type);

        error_log('[PUNTWORK] Scheduled import failed: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Scheduled import failed: ' . $e->getMessage()
        ];
    }
}

/**
 * Log a scheduled run to history
 */
function log_scheduled_run($details, $test_mode = false, $trigger_type = 'scheduled') {
    $run_entry = [
        'timestamp' => $details['timestamp'],
        'formatted_date' => wp_date('M j, Y H:i', $details['timestamp']),
        'duration' => $details['duration'],
        'success' => $details['success'],
        'processed' => $details['processed'],
        'total' => $details['total'],
        'published' => $details['published'],
        'updated' => $details['updated'],
        'skipped' => $details['skipped'],
        'error_message' => $details['error_message'] ?? '',
        'test_mode' => $test_mode,
        'trigger_type' => $trigger_type
    ];

    // Get existing history
    $history = get_option('puntwork_import_run_history', []);

    // Add new entry to the beginning
    array_unshift($history, $run_entry);

    // Keep only the last 20 runs to prevent the option from growing too large
    if (count($history) > 20) {
        $history = array_slice($history, 0, 20);
    }

    update_option('puntwork_import_run_history', $history);

    // Log to debug log
    $status = $details['success'] ? 'SUCCESS' : 'FAILED';
    $mode = $test_mode ? ' (TEST)' : '';
    error_log(sprintf(
        '[PUNTWORK] Scheduled import %s%s - Duration: %.2fs, Processed: %d/%d, Published: %d, Updated: %d, Skipped: %d',
        $status,
        $mode,
        $details['duration'],
        $details['processed'],
        $details['total'],
        $details['published'],
        $details['updated'],
        $details['skipped']
    ));
}

/**
 * Log a manual import run to history
 */
function log_manual_import_run($details) {
    $run_entry = [
        'timestamp' => $details['timestamp'],
        'formatted_date' => wp_date('M j, Y H:i', $details['timestamp']),
        'duration' => $details['duration'],
        'success' => $details['success'],
        'processed' => $details['processed'],
        'total' => $details['total'],
        'published' => $details['published'],
        'updated' => $details['updated'],
        'skipped' => $details['skipped'],
        'error_message' => $details['error_message'] ?? '',
        'test_mode' => false,
        'trigger_type' => 'manual'
    ];

    // Get existing history
    $history = get_option('puntwork_import_run_history', []);

    // Add new entry to the beginning
    array_unshift($history, $run_entry);

    // Keep only the last 20 runs to prevent the option from growing too large
    if (count($history) > 20) {
        $history = array_slice($history, 0, 20);
    }

    update_option('puntwork_import_run_history', $history);

    // Log to debug log
    $status = $details['success'] ? 'SUCCESS' : 'FAILED';
    error_log(sprintf(
        '[PUNTWORK] Manual import %s - Duration: %.2fs, Processed: %d/%d, Published: %d, Updated: %d, Skipped: %d',
        $status,
        $details['duration'],
        $details['processed'],
        $details['total'],
        $details['published'],
        $details['updated'],
        $details['skipped']
    ));
}

/**
 * Cleanup scheduled imports on plugin deactivation
 */
function cleanup_scheduled_imports() {
    wp_clear_scheduled_hook('puntwork_scheduled_import');
    delete_option('puntwork_import_schedule');
    delete_option('puntwork_last_import_run');
    delete_option('puntwork_last_import_details');
    delete_option('puntwork_import_run_history');
}