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
function run_scheduled_import($test_mode = false) {
    $start_time = microtime(true);

    try {
        // Log the scheduled run
        $log_message = $test_mode ? 'Test import started' : 'Scheduled import started';
        error_log('[PUNTWORK] ' . $log_message);

        // Run the import
        $result = import_jobs_from_json();

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
                'created' => $result['created'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
                'error_message' => $result['message'] ?? '',
                'timestamp' => time()
            ];

            update_option('puntwork_last_import_details', $details);

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
            'created' => 0,
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
    $run_entry = [
        'timestamp' => $details['timestamp'],
        'duration' => $details['duration'],
        'success' => $details['success'],
        'processed' => $details['processed'],
        'total' => $details['total'],
        'created' => $details['created'],
        'updated' => $details['updated'],
        'skipped' => $details['skipped'],
        'error_message' => $details['error_message'] ?? '',
        'test_mode' => $test_mode
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
        '[PUNTWORK] Scheduled import %s%s - Duration: %.2fs, Processed: %d/%d, Created: %d, Updated: %d, Skipped: %d',
        $status,
        $mode,
        $details['duration'],
        $details['processed'],
        $details['total'],
        $details['created'],
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