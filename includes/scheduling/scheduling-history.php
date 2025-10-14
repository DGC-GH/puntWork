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

require_once __DIR__ . '/../utilities/options-utilities.php';

/**
 * Run the scheduled import
 */
function run_scheduled_import($test_mode = false) {
    // Check for cancellation before starting scheduled import
    if (get_transient('import_cancel') === true) {
        error_log('[PUNTWORK] Scheduled import cancelled - not starting new import');
        PuntWorkLogger::info('Scheduled import cancelled before execution', PuntWorkLogger::CONTEXT_SCHEDULER, [
            'reason' => 'import_cancel_transient_set',
            'test_mode' => $test_mode,
            'action' => 'skipped_scheduled_import'
        ]);
        return [
            'success' => false,
            'message' => 'Scheduled import cancelled by user'
        ];
    }

    // Check if scheduling is still enabled (skip this check for test mode)
    if (!$test_mode) {
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
    $end_time = 0; // Initialize to prevent undefined variable warnings

    try {
        // Log the scheduled run
        $log_message = $test_mode ? 'Test import started' : 'Scheduled import started';
        error_log('[PUNTWORK] ' . $log_message);

        // For scheduled imports, refresh the feed data first
        if (!$test_mode) {
            error_log('[PUNTWORK] Refreshing feed data for scheduled import');
            try {
                fetch_and_generate_combined_json();
                
                // Update status after feed refresh
                $feed_status = get_import_status([]);
                if (!empty($feed_status)) {
                    if (!is_array($feed_status['logs'] ?? null)) {
                        $feed_status['logs'] = [];
                    }
                    $feed_status['logs'][] = 'Feed data refreshed successfully';
                    set_import_status($feed_status);
                }
            } catch (\Exception $e) {
                error_log('[PUNTWORK] Feed refresh failed: ' . $e->getMessage());
                return [
                    'success' => false,
                    'message' => 'Feed refresh failed: ' . $e->getMessage()
                ];
            }
        }

    // Run the import - reset status since feed processing is complete
    $result = import_all_jobs_from_json(false); // false = reset status for fresh import
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

            set_last_import_details($details);

            // Log this run to history
            log_scheduled_run($details, $test_mode);
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
        ], $test_mode);

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
function log_scheduled_run($details, $test_mode = false) {
    log_import_run($details, $test_mode ? 'test' : 'scheduled');
}

/**
 * Log an import run to history
 */
function log_import_run($details, $import_type = 'manual') {
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
        'test_mode' => $import_type === 'test',
        'import_type' => $import_type // 'scheduled', 'manual', or 'test'
    ];

    // Get existing history
    $history = get_import_run_history([]);

    // Add new entry to the beginning
    array_unshift($history, $run_entry);

    // Keep only the last 50 runs to prevent the option from growing too large
    if (count($history) > 50) {
        $history = array_slice($history, 0, 50);
    }

    set_import_run_history($history);

    // Log to debug log
    $status = $details['success'] ? 'SUCCESS' : 'FAILED';
    $type_label = $import_type === 'test' ? ' (TEST)' : ($import_type === 'scheduled' ? ' (SCHEDULED)' : ' (MANUAL)');
    error_log(sprintf(
        '[PUNTWORK] %s import %s%s - Duration: %.2fs, Processed: %d/%d, Published: %d, Updated: %d, Skipped: %d',
        ucfirst($import_type),
        $status,
        $type_label,
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

/**
 * Run the manual import (unified with scheduled import)
 */
function run_manual_import() {
    $start_time = microtime(true);
    $end_time = 0; // Initialize to prevent undefined variable warnings

    try {
        // Log the manual run
        error_log('[PUNTWORK] Manual import started');

        // For manual imports, we need to do the full feed processing workflow
        // This includes feed fetching, JSONL combination, and then the import
        error_log('[PUNTWORK] Processing feeds for manual import');

        // Process feeds using the unified function (same as scheduled import)
        fetch_and_generate_combined_json();

        // Now run the actual import
        error_log('[PUNTWORK] Starting import processing for manual import');
        $result = import_all_jobs_from_json(false); // false = reset status for fresh import

        $end_time = microtime(true);
        $duration = $end_time - $start_time;

        // Store last run information as manual import
        $last_run_data = [
            'timestamp' => time(),
            'duration' => $duration,
            'test_mode' => false,
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

            set_last_import_details($details);

            // Log this run to history as manual import
            log_import_run($details, 'manual');
        }

        return $result;

    } catch (\Exception $e) {
        $end_time = microtime(true);
        $duration = $end_time - $start_time;

        $error_data = [
            'timestamp' => time(),
            'duration' => $duration,
            'test_mode' => false,
            'error' => $e->getMessage()
        ];

        update_option('puntwork_last_import_run', $error_data);

        // Log failed run to history as manual import
        log_import_run([
            'success' => false,
            'duration' => $duration,
            'processed' => 0,
            'total' => 0,
            'published' => 0,
            'updated' => 0,
            'skipped' => 0,
            'error_message' => $e->getMessage(),
            'timestamp' => time()
        ], 'manual');

        error_log('[PUNTWORK] Manual import failed: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Manual import failed: ' . $e->getMessage()
        ];
    }
}