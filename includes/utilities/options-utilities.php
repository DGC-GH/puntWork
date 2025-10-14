<?php
/**
 * Options Management Utilities for PuntWork Plugin
 *
 * Centralized functions for WordPress options management with validation and defaults.
 */

namespace Puntwork;

/**
 * Get import status with default structure
 */
function get_import_status() {
    $status = get_option('job_import_status', []);
    
    // Ensure status is an array and sanitize the logs field
    if (!is_array($status)) {
        $status = [];
    }
    
    // Ensure logs is always an array
    if (!isset($status['logs']) || !is_array($status['logs'])) {
        $status['logs'] = [];
    }
    
    return $status;
}

/**
 * Set import status with validation
 */
function set_import_status($status) {
    // Ensure status is an array
    if (!is_array($status)) {
        $status = [];
    }

    // Ensure logs is always an array
    if (!isset($status['logs']) || !is_array($status['logs'])) {
        $status['logs'] = [];
    }

    update_option('job_import_status', $status, false);
}

/**
 * Set import status with atomic locking to prevent race conditions in concurrent processing
 *
 * @param array $status The status array to set
 * @param int $max_retries Maximum number of lock acquisition attempts (default: 5)
 * @param int $lock_timeout_seconds How long to hold the lock (default: 10)
 * @return bool True if status was updated, false if failed to acquire lock
 */
function set_import_status_atomic($status, $max_retries = 5, $lock_timeout_seconds = 10) {
    // Ensure status is an array
    if (!is_array($status)) {
        $status = [];
    }

    // Ensure logs is always an array
    if (!isset($status['logs']) || !is_array($status['logs'])) {
        $status['logs'] = [];
    }

    $lock_key = 'job_import_status_lock';
    $retry_count = 0;
    $lock_acquired = false;

    // Try to acquire lock with retries
    while ($retry_count < $max_retries && !$lock_acquired) {
        $lock_acquired = set_transient($lock_key, time(), $lock_timeout_seconds);

        if (!$lock_acquired) {
            // Lock is held by another process, wait and retry
            $retry_count++;
            if ($retry_count < $max_retries) {
                usleep(rand(10000, 50000)); // Wait 10-50ms before retry
            }
        }
    }

    if (!$lock_acquired) {
        // Failed to acquire lock after all retries
        PuntWorkLogger::warning('Failed to acquire import status lock after retries', PuntWorkLogger::CONTEXT_GENERAL, [
            'max_retries' => $max_retries,
            'lock_timeout' => $lock_timeout_seconds,
            'retry_count' => $retry_count
        ]);
        return false;
    }

    try {
        // Lock acquired, safely update the status
        update_option('job_import_status', $status, false);

        PuntWorkLogger::debug('Import status updated atomically', PuntWorkLogger::CONTEXT_GENERAL, [
            'processed' => $status['processed'] ?? 0,
            'total' => $status['total'] ?? 0,
            'published' => $status['published'] ?? 0,
            'updated' => $status['updated'] ?? 0,
            'skipped' => $status['skipped'] ?? 0
        ]);

        return true;
    } finally {
        // Always release the lock
        delete_transient($lock_key);
    }
}

/**
 * Delete import status
 */
function delete_import_status() {
    delete_option('job_import_status');
}

/**
 * Get batch size with validation and defaults
 */
function get_batch_size() {
    return get_option('job_import_batch_size', DEFAULT_BATCH_SIZE);
}

/**
 * Set batch size with validation
 */
function set_batch_size($size) {
    $validated_size = max(1, min((int)$size, MAX_BATCH_SIZE));
    update_option('job_import_batch_size', $validated_size, false);
    return $validated_size;
}

/**
 * Get import progress
 */
function get_import_progress() {
    return (int) get_option('job_import_progress', 0);
}

/**
 * Set import progress
 */
function set_import_progress($progress) {
    update_option('job_import_progress', (int)$progress, false);
}

/**
 * Get processed GUIDs
 */
function get_processed_guids() {
    return get_option('job_import_processed_guids', []);
}

/**
 * Set processed GUIDs
 */
function set_processed_guids($guids) {
    update_option('job_import_processed_guids', $guids, false);
}

/**
 * Get existing GUIDs
 */
function get_existing_guids() {
    return get_option('job_existing_guids');
}

/**
 * Set existing GUIDs
 */
function set_existing_guids($guids) {
    update_option('job_existing_guids', $guids, false);
}

/**
 * Get import start time
 */
function get_import_start_time() {
    return get_option('job_import_start_time', microtime(true));
}

/**
 * Set import start time
 */
function set_import_start_time($time = null) {
    $time = $time ?? microtime(true);
    update_option('job_import_start_time', $time, false);
}

/**
 * Get consecutive batches counter
 */
function get_consecutive_batches() {
    return (int) get_option('job_import_consecutive_batches', 0);
}

/**
 * Set consecutive batches counter
 */
function set_consecutive_batches($count) {
    update_option('job_import_consecutive_batches', (int)$count, false);
}

/**
 * Get consecutive small batches counter
 */
function get_consecutive_small_batches() {
    return (int) get_option('job_import_consecutive_small_batches', 0);
}

/**
 * Set consecutive small batches counter
 */
function set_consecutive_small_batches($count) {
    update_option('job_import_consecutive_small_batches', (int)$count, false);
}

/**
 * Get time per job metrics
 */
function get_time_per_job() {
    return (float) get_option('job_import_time_per_job', 0);
}

/**
 * Set time per job metrics
 */
function set_time_per_job($time) {
    update_option('job_import_time_per_job', (float)$time, false);
}

/**
 * Get average time per job
 */
function get_avg_time_per_job() {
    return (float) get_option('job_import_avg_time_per_job', 0);
}

/**
 * Set average time per job
 */
function set_avg_time_per_job($time) {
    update_option('job_import_avg_time_per_job', (float)$time, false);
}

/**
 * Get last batch time
 */
function get_last_batch_time() {
    return (float) get_option('job_import_last_batch_time', 0);
}

/**
 * Set last batch time
 */
function set_last_batch_time($time) {
    update_option('job_import_last_batch_time', (float)$time, false);
}

/**
 * Get last batch processed count
 */
function get_last_batch_processed() {
    return (int) get_option('job_import_last_batch_processed', 0);
}

/**
 * Set last batch processed count
 */
function set_last_batch_processed($count) {
    update_option('job_import_last_batch_processed', (int)$count, false);
}

/**
 * Get peak memory usage
 */
function get_last_peak_memory() {
    return (int) get_option('job_import_last_peak_memory', 0);
}

/**
 * Set peak memory usage
 */
function set_last_peak_memory($memory) {
    update_option('job_import_last_peak_memory', (int)$memory, false);
}

/**
 * Get previous batch time
 */
function get_previous_batch_time() {
    return (float) get_option('job_import_previous_batch_time', 0);
}

/**
 * Set previous batch time
 */
function set_previous_batch_time($time) {
    update_option('job_import_previous_batch_time', (float)$time, false);
}

/**
 * Get PuntWork import schedule
 */
function get_import_schedule() {
    return get_option('puntwork_import_schedule', ['enabled' => false]);
}

/**
 * Set PuntWork import schedule
 */
function set_import_schedule($schedule) {
    update_option('puntwork_import_schedule', $schedule, false);
}

/**
 * Get last import run data
 */
function get_last_import_run() {
    return get_option('puntwork_last_import_run', null);
}

/**
 * Set last import run data
 */
function set_last_import_run($data) {
    update_option('puntwork_last_import_run', $data, false);
}

/**
 * Get last import details
 */
function get_last_import_details() {
    return get_option('puntwork_last_import_details', null);
}

/**
 * Set last import details
 */
function set_last_import_details($details) {
    update_option('puntwork_last_import_details', $details, false);
}

/**
 * Get import run history
 */
function get_import_run_history() {
    return get_option('puntwork_import_run_history', []);
}

/**
 * Set import run history
 */
function set_import_run_history($history) {
    update_option('puntwork_import_run_history', $history, false);
}

/**
 * Get cleanup trashed progress
 */
function get_cleanup_trashed_progress() {
    return get_option('job_cleanup_trashed_progress', [
        'total_processed' => 0,
        'total_deleted' => 0,
        'total_jobs' => 0,
        'current_offset' => 0,
        'complete' => false,
        'start_time' => microtime(true),
        'logs' => []
    ]);
}

/**
 * Set cleanup trashed progress
 */
function set_cleanup_trashed_progress($progress) {
    update_option('job_cleanup_trashed_progress', $progress, false);
}

/**
 * Get cleanup drafted progress
 */
function get_cleanup_drafted_progress() {
    return get_option('job_cleanup_drafted_progress', [
        'total_processed' => 0,
        'total_deleted' => 0,
        'total_jobs' => 0,
        'draft_job_ids' => [],
        'current_index' => 0,
        'complete' => false,
        'start_time' => microtime(true),
        'logs' => []
    ]);
}

/**
 * Set cleanup drafted progress
 */
function set_cleanup_drafted_progress($progress) {
    update_option('job_cleanup_drafted_progress', $progress, false);
}

/**
 * Get cleanup GUIDs
 */
function get_cleanup_guids() {
    return get_option('job_cleanup_guids', []);
}

/**
 * Set cleanup GUIDs
 */
function set_cleanup_guids($guids) {
    update_option('job_cleanup_guids', $guids, false);
}

/**
 * Get cleanup old published progress
 */
function get_cleanup_old_published_progress() {
    return get_option('job_cleanup_old_published_progress', [
        'total_processed' => 0,
        'total_deleted' => 0,
        'total_jobs' => 0,
        'current_offset' => 0,
        'complete' => false,
        'start_time' => microtime(true),
        'logs' => []
    ]);
}

/**
 * Set cleanup old published progress
 */
function set_cleanup_old_published_progress($progress) {
    update_option('job_cleanup_old_published_progress', $progress, false);
}

/**
 * Get last concurrency level used
 */
function get_last_concurrency_level() {
    return (int) get_option('job_import_last_concurrency', 1);
}

/**
 * Set last concurrency level used
 */
function set_last_concurrency_level($level) {
    update_option('job_import_last_concurrency', (int)$level, false);
}

/**
 * Get concurrent processing success rate (0.0 to 1.0)
 */
function get_concurrent_success_rate() {
    return (float) get_option('job_import_concurrent_success_rate', 1.0);
}

/**
 * Set concurrent processing success rate
 */
function set_concurrent_success_rate($rate) {
    update_option('job_import_concurrent_success_rate', (float) max(0, min(1, $rate)), false);
}

/**
 * Update sequential processing success metrics
 */
function update_sequential_success_metrics($total_items, $successful_operations, $skipped, $processed_count) {
    $success_rate = $total_items > 0 ? $successful_operations / $total_items : 1.0;
    $processing_efficiency = $processed_count > 0 ? $successful_operations / $processed_count : 1.0;

    // Combined success rate considers both successful operations and processing efficiency
    $combined_success_rate = ($success_rate + $processing_efficiency) / 2;

    // Use rolling average to stabilize the metric
    $current_rate = get_sequential_success_rate();
    $new_rate = ($current_rate * 0.8) + ($combined_success_rate * 0.2); // More weight to historical data for sequential

    set_sequential_success_rate($new_rate);

    // Track sequential processing statistics
    $stats = get_sequential_processing_stats();
    $stats['total_batches'] = ($stats['total_batches'] ?? 0) + 1;
    $stats['total_items'] += $total_items;
    $stats['total_successful'] += $successful_operations;
    $stats['total_skipped'] += $skipped;
    $stats['last_success_rate'] = $success_rate;
    $stats['last_update'] = microtime(true);

    set_sequential_processing_stats($stats);

    return $new_rate;
}

/**
 * Get sequential processing success rate (0.0 to 1.0)
 */
function get_sequential_success_rate() {
    return (float) get_option('job_import_sequential_success_rate', 1.0);
}

/**
 * Set sequential processing success rate
 */
function set_sequential_success_rate($rate) {
    update_option('job_import_sequential_success_rate', (float) max(0, min(1, $rate)), false);
}

/**
 * Get sequential processing statistics
 */
function get_sequential_processing_stats() {
    return get_option('job_import_sequential_stats', []);
}

/**
 * Set sequential processing statistics
 */
function set_sequential_processing_stats($stats) {
    update_option('job_import_sequential_stats', $stats, false);
}