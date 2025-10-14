<?php
/**
 * AJAX handlers for scheduling operations
 *
 * @package    Puntwork
 * @subpackage Scheduling
 * @since      1.0.0
 */

namespace Puntwork;

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

// Debug endpoint to clear import status
add_action('wp_ajax_debug_clear_import_status', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied');
    }

    delete_import_status();
    delete_transient('import_cancel');
    error_log('[PUNTWORK] === DEBUG: Cleared import status and cancel transient ===');

    wp_die('Import status cleared - you can now try Run Now again');
});

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/../utilities/ajax-utilities.php';
require_once __DIR__ . '/../utilities/options-utilities.php';
require_once __DIR__ . '/scheduling-core.php';
require_once __DIR__ . '/scheduling-history.php';
require_once __DIR__ . '/../batch/batch-size-management.php';

/**
 * Save import schedule settings via AJAX
 */
function save_import_schedule_ajax() {
    if (!validate_ajax_request('save_import_schedule')) {
        return;
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
        send_ajax_error('save_import_schedule', 'Invalid frequency');
    }

    // Validate time
    if ($hour < 0 || $hour > 23) {
        send_ajax_error('save_import_schedule', 'Hour must be between 0 and 23');
    }
    if ($minute < 0 || $minute > 59) {
        send_ajax_error('save_import_schedule', 'Minute must be between 0 and 59');
    }

    // Validate custom interval
    if ($frequency === 'custom' && ($interval < 1 || $interval > 168)) {
        send_ajax_error('save_import_schedule', 'Custom interval must be between 1 and 168 hours');
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

    $last_run = get_last_import_run(null);
    $last_run_details = get_last_import_details(null);

    error_log('[PUNTWORK] Save schedule AJAX response: enabled=' . ($schedule_data['enabled'] ? 'true' : 'false'));

    send_ajax_success('save_import_schedule', [
        'message' => 'Schedule saved successfully',
        'schedule' => $schedule_data,
        'next_run' => get_next_scheduled_time(),
        'last_run' => $last_run,
        'last_run_details' => $last_run_details
    ], [
        'message' => 'Schedule saved successfully',
        'schedule_enabled' => $schedule_data['enabled'] ?? false,
        'schedule_frequency' => $schedule_data['frequency'] ?? 'daily',
        'next_run' => get_next_scheduled_time(),
        'last_run_timestamp' => $last_run['timestamp'] ?? null,
        'last_run_success' => $last_run['success'] ?? null
    ]);
}

/**
 * Get current import schedule settings via AJAX
 */
function get_import_schedule_ajax() {
    if (!validate_ajax_request('get_import_schedule')) {
        return;
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

    $last_run = get_last_import_run(null);
    $last_run_details = get_last_import_details(null);

    // Add formatted date to last run if it exists
    if ($last_run && isset($last_run['timestamp'])) {
        // Timestamps are now stored in UTC using time(), wp_date() handles timezone conversion
        $last_run['formatted_date'] = wp_date('M j, Y H:i', $last_run['timestamp']);
    }

    send_ajax_success('get_import_schedule', [
        'schedule' => $schedule,
        'next_run' => get_next_scheduled_time(),
        'last_run' => $last_run,
        'last_run_details' => $last_run_details
    ], [
        'schedule_enabled' => $schedule['enabled'] ?? false,
        'schedule_frequency' => $schedule['frequency'] ?? 'daily',
        'next_run' => get_next_scheduled_time(),
        'last_run_timestamp' => $last_run['timestamp'] ?? null,
        'last_run_success' => $last_run['success'] ?? null
    ]);
}

/**
 * Get import run history via AJAX
 */
function get_import_run_history_ajax() {
    if (!validate_ajax_request('get_import_run_history')) {
        return;
    }

    $history = get_import_run_history([]);

    // Format dates for history entries - timestamps are stored in UTC
    foreach ($history as &$entry) {
        if (isset($entry['timestamp'])) {
            $entry['formatted_date'] = wp_date('M j, Y H:i', $entry['timestamp']);
        }
    }

    send_ajax_success('get_import_run_history', [
        'history' => $history,
        'count' => count($history)
    ], [
        'history_count' => count($history),
        'latest_entry' => count($history) > 0 ? [
            'timestamp' => $history[0]['timestamp'] ?? null,
            'success' => $history[0]['success'] ?? null,
            'processed' => $history[0]['processed'] ?? null,
            'total' => $history[0]['total'] ?? null,
            'duration' => $history[0]['duration'] ?? null
        ] : null
    ]);
}

/**
 * Test import schedule via AJAX
 */
function test_import_schedule_ajax() {
    if (!validate_ajax_request('test_import_schedule')) {
        return;
    }

    // Run a test import
    $result = run_scheduled_import(true); // true = test mode

    send_ajax_success('test_import_schedule', [
        'message' => 'Test import completed',
        'result' => $result
    ], [
        'message' => 'Test import completed',
        'success' => $result['success'] ?? null,
        'processed' => $result['processed'] ?? null,
        'total' => $result['total'] ?? null,
        'time_elapsed' => $result['time_elapsed'] ?? null
    ]);
}

/**
 * Run scheduled import immediately via AJAX
 * Now triggers the import asynchronously like the manual Start Import button
 */
function run_scheduled_import_ajax() {
    if (!validate_ajax_request('run_scheduled_import')) {
        return;
    }

    // Check if this is a manual import trigger (from Start Import button)
    $import_type = isset($_POST['import_type']) ? sanitize_text_field($_POST['import_type']) : 'scheduled';
    $is_manual = $import_type === 'manual';

    // Check if an import is already running
    $import_status = get_import_status([]);
    if (isset($import_status['complete']) && !$import_status['complete']) {
        // Calculate actual time elapsed
        $time_elapsed = 0;
        if (isset($import_status['start_time']) && $import_status['start_time'] > 0) {
            $time_elapsed = microtime(true) - $import_status['start_time'];
        } elseif (isset($import_status['time_elapsed'])) {
            $time_elapsed = $import_status['time_elapsed'];
        }

        // Check for stuck imports and clear them automatically
        $current_time = time();
        $last_update = isset($import_status['last_update']) ? $import_status['last_update'] : 0;
        $time_since_last_update = $current_time - $last_update;

        error_log('[PUNTWORK] Checking for stuck import: processed=' . $import_status['processed'] . ', time_elapsed=' . $time_elapsed . ', time_since_last_update=' . $time_since_last_update);

        // Detect stuck imports with multiple criteria:
        // 1. No progress for 5+ minutes (300 seconds)
        // 2. Import running for more than 2 hours without completion (7200 seconds)
        // 3. No status update for 10+ minutes (600 seconds)
        $is_stuck = false;
        $stuck_reason = '';

        if ($import_status['processed'] == 0 && $time_elapsed > 300) {
            $is_stuck = true;
            $stuck_reason = 'no progress for 5+ minutes';
        } elseif ($import_status['processed'] > 0 && $import_status['processed'] < 50 && $time_elapsed > 600) { // Low progress
            $is_stuck = true;
            $stuck_reason = 'low progress (<50 items) for more than 10 minutes';
        } elseif ($time_elapsed > 7200) { // 2 hours
            $is_stuck = true;
            $stuck_reason = 'running for more than 2 hours';
        } elseif ($time_since_last_update > 600) { // 10 minutes since last update
            $is_stuck = true;
            $stuck_reason = 'no status update for 10+ minutes';
        }

        if ($is_stuck) {
            error_log('[PUNTWORK] Detected stuck import in scheduled start, clearing status: ' . json_encode([
                'processed' => $import_status['processed'],
                'total' => $import_status['total'],
                'time_elapsed' => $time_elapsed,
                'time_since_last_update' => $time_since_last_update,
                'reason' => $stuck_reason
            ]));
            delete_import_status();
            delete_transient('import_cancel');
        } else {
            send_ajax_error('run_scheduled_import', 'An import is already running');
            return;
        }
    }

    try {
        // Initialize import status for immediate UI feedback
        $status_message = $is_manual ? 'Manual import started - preparing feeds...' : 'Scheduled import started - preparing feeds...';
        $initial_status = initialize_import_status(0, $status_message);
        set_import_status($initial_status);
        error_log('[PUNTWORK] Initialized import status for ' . ($is_manual ? 'manual' : 'scheduled') . ' run: total=0, complete=false');

        // Clear any previous cancellation before starting
        delete_transient('import_cancel');
        error_log('[PUNTWORK] Cleared import_cancel transient for ' . ($is_manual ? 'manual' : 'scheduled') . ' run');

        // Schedule the import to run on shutdown for immediate execution
        add_action('shutdown', function() use ($is_manual) {
            error_log('[PUNTWORK] Shutdown hook triggered for ' . ($is_manual ? 'manual' : 'scheduled') . ' import');
            try {
                $result = $is_manual ? run_manual_import() : run_scheduled_import();
                error_log('[PUNTWORK] Shutdown import result: success=' . ($result['success'] ? 'true' : 'false') . ', processed=' . ($result['processed'] ?? 0) . ', total=' . ($result['total'] ?? 0));
            } catch (\Exception $e) {
                error_log('[PUNTWORK] Shutdown import error: ' . $e->getMessage());
            }
        });
        error_log('[PUNTWORK] ' . ($is_manual ? 'Manual' : 'Scheduled') . ' import scheduled to run on shutdown (immediate execution)');

        // Return success immediately so UI can start polling
        error_log('[PUNTWORK] ' . ($is_manual ? 'Manual' : 'Scheduled') . ' import initiated asynchronously');
        send_ajax_success('run_scheduled_import', [
            'message' => 'Import started successfully',
            'async' => true
        ], [
            'message' => 'Import started successfully',
            'async' => true,
            'import_type' => $is_manual ? 'manual' : 'scheduled'
        ]);

    } catch (\Exception $e) {
        error_log('[PUNTWORK] Run ' . ($is_manual ? 'manual' : 'scheduled') . ' import AJAX error: ' . $e->getMessage());
        send_ajax_error('run_scheduled_import', 'Failed to start import: ' . $e->getMessage());
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
add_action('puntwork_manual_import_async', __NAMESPACE__ . '\\run_manual_import_async');

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
    $import_status = get_import_status([]);
    error_log('[PUNTWORK] Current import status at async start: processed=' . ($import_status['processed'] ?? 0) . ', total=' . ($import_status['total'] ?? 0) . ', complete=' . ($import_status['complete'] ? 'true' : 'false'));

    // Check for stuck imports (similar to AJAX handler logic)
    if (isset($import_status['complete']) && !$import_status['complete']) {
        // Calculate actual time elapsed
        $time_elapsed = 0;
        if (isset($import_status['start_time']) && $import_status['start_time'] > 0) {
            $time_elapsed = microtime(true) - $import_status['start_time'];
        } elseif (isset($import_status['time_elapsed'])) {
            $time_elapsed = $import_status['time_elapsed'];
        }

        // Check for stuck imports with multiple criteria:
        // 1. No progress for 5+ minutes (300 seconds)
        // 2. Import running for more than 2 hours without completion (7200 seconds)
        // 3. No status update for 10+ minutes (600 seconds)
        $is_stuck = false;
        $stuck_reason = '';

        if ($import_status['processed'] == 0 && $time_elapsed > 300) {
            $is_stuck = true;
            $stuck_reason = 'no progress for 5+ minutes';
        } elseif ($time_elapsed > 7200) { // 2 hours
            $is_stuck = true;
            $stuck_reason = 'running for more than 2 hours';
        }

        $current_time = time();
        $last_update = isset($import_status['last_update']) ? $import_status['last_update'] : 0;
        $time_since_last_update = $current_time - $last_update;
        if ($time_since_last_update > 600) { // 10 minutes since last update
            $is_stuck = true;
            $stuck_reason = 'no status update for 10+ minutes';
        }

        if ($is_stuck) {
            error_log('[PUNTWORK] Detected stuck import in async function (processed: ' . ($import_status['processed'] ?? 'null') . ', time_elapsed: ' . $time_elapsed . ', time_since_last_update: ' . $time_since_last_update . ', reason: ' . $stuck_reason . '), clearing status for new run');
            delete_import_status();
            delete_transient('import_cancel');
            $import_status = []; // Reset for fresh start
        } elseif (isset($import_status['processed']) && $import_status['processed'] > 0) {
            error_log('[PUNTWORK] Async import skipped - import already running and has processed items');
            return;
        }
    }

    error_log('[PUNTWORK] Starting actual import process...');

    // Clear import_cancel transient again just before starting the import
    delete_transient('import_cancel');
    error_log('[PUNTWORK] Cleared import_cancel transient again before import');

    try {
        $result = run_scheduled_import();
        error_log('[PUNTWORK] Import result: success=' . ($result['success'] ? 'true' : 'false') . ', processed=' . ($result['processed'] ?? 0) . ', total=' . ($result['total'] ?? 0) . ', time_elapsed=' . round($result['time_elapsed'] ?? 0, 2));

        // Import runs to completion without pausing
        if ($result['success']) {
            error_log('[PUNTWORK] Async scheduled import completed successfully');
        } else {
            error_log('[PUNTWORK] Async scheduled import failed: ' . ($result['message'] ?? 'Unknown error'));
            // Reset import status on failure so future attempts can start
            delete_import_status();
            error_log('[PUNTWORK] Reset job_import_status due to import failure');
        }
    } catch (\Exception $e) {
        error_log('[PUNTWORK] Async scheduled import exception: ' . $e->getMessage());
        error_log('[PUNTWORK] Exception trace: ' . $e->getTraceAsString());
        // Reset import status on exception so future attempts can start
        delete_import_status();
        error_log('[PUNTWORK] Reset job_import_status due to import exception');
    }

    error_log('[PUNTWORK] === ASYNC FUNCTION COMPLETED ===');
}

/**
 * Run manual import asynchronously (non-blocking)
 */
function run_manual_import_async() {
    error_log('[PUNTWORK] === MANUAL ASYNC FUNCTION STARTED ===');
    error_log('[PUNTWORK] Manual async import started - Action Scheduler hook fired');
    error_log('[PUNTWORK] Current time: ' . date('Y-m-d H:i:s'));
    error_log('[PUNTWORK] Function called with arguments: ' . print_r(func_get_args(), true));

    // Clear any previous cancellation before starting
    delete_transient('import_cancel');
    error_log('[PUNTWORK] Cleared import_cancel transient');

    // Check if an import is already running
    $import_status = get_import_status([]);
    error_log('[PUNTWORK] Current import status at manual async start: processed=' . ($import_status['processed'] ?? 0) . ', total=' . ($import_status['total'] ?? 0) . ', complete=' . ($import_status['complete'] ? 'true' : 'false'));

    // Check for stuck imports (similar to AJAX handler logic)
    if (isset($import_status['complete']) && !$import_status['complete']) {
        // Calculate actual time elapsed
        $time_elapsed = 0;
        if (isset($import_status['start_time']) && $import_status['start_time'] > 0) {
            $time_elapsed = microtime(true) - $import_status['start_time'];
        } elseif (isset($import_status['time_elapsed'])) {
            $time_elapsed = $import_status['time_elapsed'];
        }

        // Check for stuck imports with multiple criteria:
        // 1. No progress for 5+ minutes (300 seconds)
        // 2. Import running for more than 2 hours without completion (7200 seconds)
        // 3. No status update for 10+ minutes (600 seconds)
        $is_stuck = false;
        $stuck_reason = '';

        if ($import_status['processed'] == 0 && $time_elapsed > 300) {
            $is_stuck = true;
            $stuck_reason = 'no progress for 5+ minutes';
        } elseif ($time_elapsed > 7200) { // 2 hours
            $is_stuck = true;
            $stuck_reason = 'running for more than 2 hours';
        }

        $current_time = time();
        $last_update = isset($import_status['last_update']) ? $import_status['last_update'] : 0;
        $time_since_last_update = $current_time - $last_update;
        if ($time_since_last_update > 600) { // 10 minutes since last update
            $is_stuck = true;
            $stuck_reason = 'no status update for 10+ minutes';
        }

        if ($is_stuck) {
            error_log('[PUNTWORK] Detected stuck import in manual async function (processed: ' . ($import_status['processed'] ?? 'null') . ', time_elapsed: ' . $time_elapsed . ', time_since_last_update: ' . $time_since_last_update . ', reason: ' . $stuck_reason . '), clearing status for new run');
            delete_import_status();
            delete_transient('import_cancel');
            $import_status = []; // Reset for fresh start
        } elseif (isset($import_status['processed']) && $import_status['processed'] > 0) {
            error_log('[PUNTWORK] Manual async import skipped - import already running and has processed items');
            return;
        }
    }

    error_log('[PUNTWORK] Starting actual manual import process...');

    // Clear import_cancel transient again just before starting the import
    delete_transient('import_cancel');
    error_log('[PUNTWORK] Cleared import_cancel transient again before manual import');

    try {
        $result = run_manual_import();
        error_log('[PUNTWORK] Manual import result: success=' . ($result['success'] ? 'true' : 'false') . ', processed=' . ($result['processed'] ?? 0) . ', total=' . ($result['total'] ?? 0) . ', time_elapsed=' . round($result['time_elapsed'] ?? 0, 2));

        // Import runs to completion without pausing
        if ($result['success']) {
            error_log('[PUNTWORK] Manual async import completed successfully');
        } else {
            error_log('[PUNTWORK] Manual async import failed: ' . ($result['message'] ?? 'Unknown error'));
            // Reset import status on failure so future attempts can start
            delete_import_status();
            error_log('[PUNTWORK] Reset job_import_status due to manual import failure');
        }
    } catch (\Exception $e) {
        error_log('[PUNTWORK] Manual async import exception: ' . $e->getMessage());
        error_log('[PUNTWORK] Exception trace: ' . $e->getTraceAsString());
        // Reset import status on exception so future attempts can start
        delete_import_status();
        error_log('[PUNTWORK] Reset job_import_status due to manual import exception');
    }

    error_log('[PUNTWORK] === MANUAL ASYNC FUNCTION COMPLETED ===');
}

/**
 * Handle manual import cron job
 * Modified to handle resumable imports
 */
function run_manual_import_cron() {
    error_log('[PUNTWORK] Manual import cron started');

    // Check if an import is already running
    $import_status = get_import_status([]);
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