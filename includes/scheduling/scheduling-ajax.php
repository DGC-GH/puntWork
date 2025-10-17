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

    PuntWorkLogger::info('Manual debug trigger initiated', PuntWorkLogger::CONTEXT_SCHEDULING, [
        'action' => 'debug_trigger_async',
        'user_id' => get_current_user_id(),
        'timestamp' => time()
    ]);

    run_scheduled_import_async();

    PuntWorkLogger::info('Manual debug trigger completed', PuntWorkLogger::CONTEXT_SCHEDULING, [
        'action' => 'debug_trigger_async',
        'status' => 'completed'
    ]);

    wp_die('Async function triggered - check debug.log');
});

// Debug endpoint to clear import status
add_action('wp_ajax_debug_clear_import_status', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied');
    }

    delete_import_status();
    delete_transient('import_cancel');

    PuntWorkLogger::info('Debug import status clear executed', PuntWorkLogger::CONTEXT_SCHEDULING, [
        'action' => 'debug_clear_import_status',
        'user_id' => get_current_user_id(),
        'cleared_items' => ['import_status', 'import_cancel_transient'],
        'timestamp' => time()
    ]);

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
    PuntWorkLogger::info('Save schedule AJAX request received', PuntWorkLogger::CONTEXT_SCHEDULING, [
        'enabled' => $enabled,
        'frequency' => $frequency,
        'interval' => $interval,
        'hour' => $hour,
        'minute' => $minute,
        'user_id' => get_current_user_id()
    ]);

    // Validate frequency
    $valid_frequencies = ['hourly', '3hours', '6hours', '12hours', 'daily', 'custom'];
    if (!in_array($frequency, $valid_frequencies)) {
        PuntWorkLogger::error('Invalid frequency in schedule save request', PuntWorkLogger::CONTEXT_SCHEDULING, [
            'provided_frequency' => $frequency,
            'valid_frequencies' => $valid_frequencies
        ]);
        send_ajax_error('save_import_schedule', 'Invalid frequency');
    }

    // Validate time
    if ($hour < 0 || $hour > 23) {
        PuntWorkLogger::error('Invalid hour in schedule save request', PuntWorkLogger::CONTEXT_SCHEDULING, [
            'provided_hour' => $hour,
            'valid_range' => '0-23'
        ]);
        send_ajax_error('save_import_schedule', 'Hour must be between 0 and 23');
    }
    if ($minute < 0 || $minute > 59) {
        PuntWorkLogger::error('Invalid minute in schedule save request', PuntWorkLogger::CONTEXT_SCHEDULING, [
            'provided_minute' => $minute,
            'valid_range' => '0-59'
        ]);
        send_ajax_error('save_import_schedule', 'Minute must be between 0 and 59');
    }

    // Validate custom interval
    if ($frequency === 'custom' && ($interval < 1 || $interval > 168)) {
        PuntWorkLogger::error('Invalid custom interval in schedule save request', PuntWorkLogger::CONTEXT_SCHEDULING, [
            'provided_interval' => $interval,
            'valid_range' => '1-168 hours'
        ]);
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
    PuntWorkLogger::info('Schedule data saved to database', PuntWorkLogger::CONTEXT_SCHEDULING, [
        'saved_enabled' => $saved_data['enabled'] ?? null,
        'saved_frequency' => $saved_data['frequency'] ?? null,
        'data_integrity_check' => ($saved_data['enabled'] === $enabled)
    ]);

    // Update WordPress cron
    update_cron_schedule($schedule_data);

    $last_run = get_last_import_run(null);
    $last_run_details = get_last_import_details(null);

    PuntWorkLogger::info('Save schedule AJAX response sent', PuntWorkLogger::CONTEXT_SCHEDULING, [
        'response_enabled' => $schedule_data['enabled'],
        'next_run_scheduled' => get_next_scheduled_time(),
        'last_run_timestamp' => $last_run['timestamp'] ?? null
    ]);

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

    PuntWorkLogger::info('Get schedule AJAX request processed', PuntWorkLogger::CONTEXT_SCHEDULING, [
        'schedule_enabled' => $schedule['enabled'],
        'schedule_frequency' => $schedule['frequency'],
        'schedule_updated_at' => $schedule['updated_at'],
        'user_id' => get_current_user_id()
    ]);

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

    // Always clear any existing import status before starting a new one
    delete_import_status();
    delete_transient('import_cancel');

    PuntWorkLogger::info('Import initiation started', PuntWorkLogger::CONTEXT_SCHEDULING, [
        'import_type' => $is_manual ? 'manual' : 'scheduled',
        'cleared_existing_status' => true,
        'cleared_cancel_transient' => true,
        'user_id' => get_current_user_id(),
        'timestamp' => time()
    ]);

    try {
        // Initialize import status for immediate UI feedback
        $status_message = $is_manual ? 'Manual import started - preparing feeds...' : 'Scheduled import started - preparing feeds...';
        $initial_status = initialize_import_status(0, $status_message);
        set_import_status($initial_status);

        PuntWorkLogger::info('Import status initialized', PuntWorkLogger::CONTEXT_SCHEDULING, [
            'import_type' => $is_manual ? 'manual' : 'scheduled',
            'initial_total' => 0,
            'initial_message' => $status_message,
            'start_time' => $initial_status['start_time'] ?? null
        ]);

        // Clear any previous cancellation before starting
        delete_transient('import_cancel');

        PuntWorkLogger::info('Import cancel transient cleared', PuntWorkLogger::CONTEXT_SCHEDULING, [
            'import_type' => $is_manual ? 'manual' : 'scheduled',
            'action' => 'preparation_complete'
        ]);

        // Schedule the import to run asynchronously via WordPress cron
        wp_schedule_single_event(time(), $is_manual ? 'puntwork_manual_import' : 'puntwork_scheduled_import_async');

        PuntWorkLogger::info('Import scheduled for asynchronous execution', PuntWorkLogger::CONTEXT_SCHEDULING, [
            'import_type' => $is_manual ? 'manual' : 'scheduled',
            'execution_method' => 'wordpress_cron',
            'scheduled_hook' => $is_manual ? 'puntwork_manual_import' : 'puntwork_scheduled_import_async',
            'expected_timing' => 'immediate_via_cron'
        ]);

        // Return success immediately so UI can start polling
        PuntWorkLogger::info('Import initiation response sent', PuntWorkLogger::CONTEXT_SCHEDULING, [
            'import_type' => $is_manual ? 'manual' : 'scheduled',
            'response_type' => 'async_success',
            'ui_polling_enabled' => true
        ]);

        send_ajax_success('run_scheduled_import', [
            'message' => 'Import started successfully',
            'async' => true
        ], [
            'message' => 'Import started successfully',
            'async' => true,
            'import_type' => $is_manual ? 'manual' : 'scheduled'
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Import initiation failed', PuntWorkLogger::CONTEXT_SCHEDULING, [
            'import_type' => $is_manual ? 'manual' : 'scheduled',
            'error_message' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine()
        ]);

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

    // Check for cancellation before starting async import
    if (get_transient('import_cancel') === true || get_transient('import_force_cancel') === true || get_transient('import_emergency_stop') === true) {
        $cancel_type = get_transient('import_emergency_stop') === true ? 'emergency stopped' :
                      (get_transient('import_force_cancel') === true ? 'force cancelled' : 'cancelled');
        error_log('[PUNTWORK] Async scheduled import ' . $cancel_type . ' - not starting');
        PuntWorkLogger::info('Async scheduled import ' . $cancel_type . ' before execution', PuntWorkLogger::CONTEXT_SCHEDULER, [
            'reason' => 'import_cancel_transient_set',
            'action' => 'skipped_async_scheduled_import',
            'cancel_type' => $cancel_type
        ]);
        return;
    }

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
        if ($time_since_last_update > 300) { // 5 minutes since last update
            $is_stuck = true;
            $stuck_reason = 'no status update for 5+ minutes';
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

    // Check for cancellation before starting manual async import
    if (get_transient('import_cancel') === true || get_transient('import_force_cancel') === true || get_transient('import_emergency_stop') === true) {
        $cancel_type = get_transient('import_emergency_stop') === true ? 'emergency stopped' :
                      (get_transient('import_force_cancel') === true ? 'force cancelled' : 'cancelled');
        error_log('[PUNTWORK] Manual async import ' . $cancel_type . ' - not starting');
        PuntWorkLogger::info('Manual async import ' . $cancel_type . ' before execution', PuntWorkLogger::CONTEXT_SCHEDULER, [
            'reason' => 'import_cancel_transient_set',
            'action' => 'skipped_manual_async_import',
            'cancel_type' => $cancel_type
        ]);
        return;
    }

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
        if ($time_since_last_update > 300) { // 5 minutes since last update
            $is_stuck = true;
            $stuck_reason = 'no status update for 5+ minutes';
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
