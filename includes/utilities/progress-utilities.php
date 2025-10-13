<?php
/**
 * Progress Tracking Utilities for PuntWork Plugin
 *
 * Centralized functions for tracking long-running operations like imports and purges.
 */

namespace Puntwork;

/**
 * Initialize progress tracking for an operation
 *
 * @param string $operation_name Name of the operation (e.g., 'import', 'purge')
 * @param array $initial_data Initial progress data
 * @return void
 */
function initialize_progress_tracking($operation_name, $initial_data = []) {
    $default_data = [
        'total_processed' => 0,
        'total_affected' => 0,
        'current_offset' => 0,
        'complete' => false,
        'start_time' => microtime(true),
        'last_update' => time(),
        'logs' => []
    ];

    $progress_data = array_merge($default_data, $initial_data);
    update_option("job_{$operation_name}_progress", $progress_data, false);
}

/**
 * Update progress for an ongoing operation
 *
 * @param string $operation_name Name of the operation
 * @param array $update_data Data to update
 * @param string $log_message Optional log message to add
 * @return void
 */
function update_progress($operation_name, $update_data = [], $log_message = null) {
    $progress = get_option("job_{$operation_name}_progress", []);

    if (!empty($update_data)) {
        $progress = array_merge($progress, $update_data);
    }

    $progress['last_update'] = time();

    if ($log_message) {
        $progress['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $log_message;
    }

    update_option("job_{$operation_name}_progress", $progress, false);
}

/**
 * Get current progress for an operation
 *
 * @param string $operation_name Name of the operation
 * @param array $default_data Default data if no progress exists
 * @return array Progress data
 */
function get_progress($operation_name, $default_data = []) {
    return get_option("job_{$operation_name}_progress", $default_data);
}

/**
 * Complete progress tracking for an operation
 *
 * @param string $operation_name Name of the operation
 * @param array $final_data Final data to update
 * @param string $completion_message Completion log message
 * @return void
 */
function complete_progress($operation_name, $final_data = [], $completion_message = null) {
    $progress = get_option("job_{$operation_name}_progress", []);

    $progress = array_merge($progress, $final_data, [
        'complete' => true,
        'end_time' => microtime(true),
        'time_elapsed' => microtime(true) - ($progress['start_time'] ?? microtime(true))
    ]);

    if ($completion_message) {
        $progress['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $completion_message;
    }

    update_option("job_{$operation_name}_progress", $progress, false);
}

/**
 * Clean up progress tracking data
 *
 * @param string $operation_name Name of the operation
 * @return void
 */
function cleanup_progress($operation_name) {
    delete_option("job_{$operation_name}_progress");
}

/**
 * Calculate progress percentage
 *
 * @param array $progress Progress data array
 * @return float Progress percentage (0-100)
 */
function calculate_progress_percentage($progress) {
    $total = $progress['total'] ?? 0;
    $processed = $progress['total_processed'] ?? 0;

    if ($total <= 0) {
        return 0;
    }

    return round(($processed / $total) * 100, 1);
}

/**
 * Check if operation is stuck (no progress for specified time)
 *
 * @param array $progress Progress data
 * @param int $timeout_seconds Timeout in seconds (default 300 = 5 minutes)
 * @return bool True if operation appears stuck
 */
function is_operation_stuck($progress, $timeout_seconds = 300) {
    $last_update = $progress['last_update'] ?? 0;
    $start_time = $progress['start_time'] ?? microtime(true);

    // Check if no progress for timeout period
    if (time() - $last_update > $timeout_seconds) {
        return true;
    }

    // Check if operation started but hasn't processed anything for timeout period
    if (($progress['total_processed'] ?? 0) == 0 && (microtime(true) - $start_time) > $timeout_seconds) {
        return true;
    }

    return false;
}

/**
 * Set operation lock to prevent concurrent execution
 *
 * @param string $operation_name Name of the operation
 * @param int $timeout Timeout in seconds (default 30)
 * @return bool True if lock acquired, false if already locked
 */
function acquire_operation_lock($operation_name, $timeout = 30) {
    $lock_key = "job_{$operation_name}_lock";

    if (get_transient($lock_key)) {
        return false; // Already locked
    }

    set_transient($lock_key, true, $timeout);
    return true;
}

/**
 * Release operation lock
 *
 * @param string $operation_name Name of the operation
 * @return void
 */
function release_operation_lock($operation_name) {
    delete_transient("job_{$operation_name}_lock");
}