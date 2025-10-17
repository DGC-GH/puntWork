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
/**
 * Get import status with optional overrides
 *
 * @param array $overrides Optional array of values to merge into the returned status.
 * @return array
 */
function get_import_status($overrides = []) {
    $status = get_option('job_import_status', []);
    
    // Ensure status is an array and sanitize the logs field
    if (!is_array($status)) {
        $status = [];
    }
    
    // Ensure logs is always an array
    if (!isset($status['logs']) || !is_array($status['logs'])) {
        $status['logs'] = [];
    }
    
    // Provide defaults for all status fields to prevent undefined values
    $defaults = [
        'total' => 0,
        'processed' => 0,
        'published' => 0,
        'updated' => 0,
        'skipped' => 0,
        'duplicates_drafted' => 0,
        'time_elapsed' => 0.0,
        'complete' => false,
        'success' => null,
        'error_message' => '',
        'batch_size' => 1,
        'inferred_languages' => 0,
        'inferred_benefits' => 0,
        'schema_generated' => 0,
        'start_time' => null,
        'end_time' => null,
        'last_update' => null,
        'resume_progress' => 0,
        'batch_count' => 0,
        'job_importing_time_elapsed' => 0.0,
        'estimated_time_remaining' => 0.0,
        'logs' => []
    ];
    
    // Merge defaults with existing status, ensuring all fields are present
    $merged = array_merge($defaults, is_array($status) ? $status : []);

    // Apply optional overrides passed by callers (used in some places to seed status)
    if (is_array($overrides) && !empty($overrides)) {
        $merged = array_merge($merged, $overrides);
    }

    return $merged;
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

    // Ensure any persistent/shared object cache invalidates the autoloaded options
    // so other PHP processes (heartbeat, ajax, Action Scheduler) read the latest value.
    if (function_exists('wp_cache_delete')) {
        // Remove cached alloptions so get_option will reload from DB
        @wp_cache_delete('alloptions', 'options');
    }
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

    // Try to acquire lock with retries. If an existing lock is stale (older than $lock_timeout_seconds)
    // allow stealing it to avoid permanent deadlocks when a previous process crashed.
    while ($retry_count < $max_retries && !$lock_acquired) {
        $now = time();
        // Attempt to create the lock atomically
        $lock_acquired = add_option($lock_key, $now);

        if ($lock_acquired === false) {
            // Check if existing lock is stale
            $existing = get_option($lock_key, 0);
            if (is_numeric($existing) && ($now - (int)$existing) > $lock_timeout_seconds) {
                // Stale lock detected - remove and retry immediately
                delete_option($lock_key);
                // Try to acquire again immediately
                $lock_acquired = add_option($lock_key, $now);
                if ($lock_acquired !== false) {
                    break;
                }
            }

            // Lock is held by another process, wait and retry
            $retry_count++;
            if ($retry_count < $max_retries) {
                usleep(rand(10000, 50000)); // Wait 10-50ms before retry
            }
        }
    }

    if (!$lock_acquired) {
        // Failed to acquire lock after all retries
    PuntWorkLogger::warn('Failed to acquire import status lock after retries', PuntWorkLogger::CONTEXT_SYSTEM, [
            'max_retries' => $max_retries,
            'lock_timeout' => $lock_timeout_seconds,
            'retry_count' => $retry_count
        ]);
        return false;
    }

    try {
        // Lock acquired, safely update the status
        update_option('job_import_status', $status, false);

        // Invalidate shared object cache for options so other processes see the update
        if (function_exists('wp_cache_delete')) {
            @wp_cache_delete('alloptions', 'options');
        }

    PuntWorkLogger::debug('Import status updated atomically', PuntWorkLogger::CONTEXT_SYSTEM, [
            'processed' => $status['processed'] ?? 0,
            'total' => $status['total'] ?? 0,
            'published' => $status['published'] ?? 0,
            'updated' => $status['updated'] ?? 0,
            'skipped' => $status['skipped'] ?? 0
        ]);

        return true;
    } finally {
        // Always release the lock - remove the option used as lock
        // Use @ to suppress transient deletion warnings if option was auto-removed
        delete_option($lock_key);
    }
}

/**
 * Delete import status
 */
function delete_import_status() {
    delete_option('job_import_status');

    // Ensure cached alloptions is cleared so subsequent reads fetch fresh data
    if (function_exists('wp_cache_delete')) {
        @wp_cache_delete('alloptions', 'options');
    }
}

/**
 * Get batch size with validation and defaults
 *
 * @param bool $skip_system_validation Skip expensive system constraint validation (for status checks)
 */
function get_batch_size($skip_system_validation = false) {
    $batch_size = get_option('job_import_batch_size', 1);

    // VALIDATE RETRIEVED VALUE
    if (!is_numeric($batch_size)) {
    PuntWorkLogger::warn('Invalid batch size retrieved from options, using default', PuntWorkLogger::CONTEXT_BATCH, [
            'retrieved_value' => $batch_size,
            'retrieved_type' => gettype($batch_size),
            'fallback_value' => 1
        ]);
        $batch_size = 1;
    }

    // Ensure batch size is within reasonable bounds
    if ($batch_size < 1) {
    PuntWorkLogger::warn('Batch size too small, correcting to minimum', PuntWorkLogger::CONTEXT_BATCH, [
            'original_value' => $batch_size,
            'corrected_value' => 1,
            'minimum_allowed' => 1
        ]);
        $batch_size = 1;
    }

    if ($batch_size > 200) {
    PuntWorkLogger::warn('Batch size excessively large, capping to maximum', PuntWorkLogger::CONTEXT_BATCH, [
            'original_value' => $batch_size,
            'capped_value' => 100,
            'maximum_allowed' => 100
        ]);
        $batch_size = 100;
    }

    // Skip expensive system constraint validation during AJAX status checks when no import is running
    if (!$skip_system_validation) {
        // Validate against system constraints if available
        $memory_limit = get_memory_limit_bytes();
        $current_memory_ratio = get_last_peak_memory() / max(1, $memory_limit);
        $recommended_max = calculate_recommended_max_batch_size($memory_limit, $current_memory_ratio);

        if ($batch_size > $recommended_max) {
            PuntWorkLogger::warn('Stored batch size exceeds recommended maximum for current system', PuntWorkLogger::CONTEXT_BATCH, [
                'stored_batch_size' => $batch_size,
                'recommended_max' => $recommended_max,
                'memory_limit' => $memory_limit,
                'current_memory_ratio' => $current_memory_ratio,
                'adjustment_reason' => 'system_constraint_validation'
            ]);
            $batch_size = $recommended_max;
        }
    }

    return (int) $batch_size;
}

/**
 * Set batch size with validation
 */
function set_batch_size($size) {
    $validated_size = max(1, min((int)$size, 100));
    update_option('job_import_batch_size', $validated_size, false);
    return $validated_size;
}

/**
 * Get import progress
 */
/**
 * Get import progress
 *
 * @param int $default Default value to return if option not set.
 * @return int
 */
function get_import_progress($default = 0) {
    return (int) get_option('job_import_progress', $default);
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
/**
 * Get import start time
 *
 * @param float|null $default Optional default to use if start time option not set.
 * @return float
 */
function get_import_start_time($default = null) {
    $fallback = $default ?? microtime(true);
    return get_option('job_import_start_time', $fallback);
}

/**
 * Delete helper functions for cleanup
 */
function delete_import_progress() {
    delete_option('job_import_progress');
}

function delete_processed_guids() {
    delete_option('job_import_processed_guids');
}

function delete_existing_guids() {
    delete_option('job_existing_guids');
}

function delete_time_per_job() {
    delete_option('job_import_time_per_job');
}

function delete_avg_time_per_job() {
    delete_option('job_import_avg_time_per_job');
}

function delete_last_peak_memory() {
    delete_option('job_import_last_peak_memory');
}

function delete_batch_size() {
    delete_option('job_import_batch_size');
}

function delete_consecutive_small_batches() {
    delete_option('job_import_consecutive_small_batches');
}

function delete_consecutive_batches() {
    delete_option('job_import_consecutive_batches');
}

function delete_last_batch_time() {
    delete_option('job_import_last_batch_time');
}

function delete_last_batch_processed() {
    delete_option('job_import_last_batch_processed');
}

/**
 * Append a diagnostics entry to job_import_diagnostics option to help debugging continuation and cron issues.
 * Keeps last 50 entries.
 *
 * @param string $note Short note describing context
 * @param array $extra Additional data to include
 */
function add_import_diagnostics($note = '', $extra = []) {
    global $wpdb;
    $diagnostics = get_option('job_import_diagnostics', []);

    $entry = [
        'timestamp' => microtime(true),
        'note' => $note,
        'disable_wp_cron' => defined('DISABLE_WP_CRON') ? (DISABLE_WP_CRON ? true : false) : null,
        'wp_next_puntwork' => wp_next_scheduled('puntwork_continue_import'),
        'wp_next_retry' => wp_next_scheduled('puntwork_continue_import_retry'),
        'wp_next_manual' => wp_next_scheduled('puntwork_continue_import_manual'),
        'cron_array_size' => function_exists('_get_cron_array') ? count(_get_cron_array()) : null,
        'action_scheduler_available' => function_exists('\ActionScheduler') || class_exists('\ActionScheduler'),
        'extra' => $extra
    ];

    // If Action Scheduler tables exist, include counts (wrap in try/catch)
    try {
        $tables = $wpdb->get_results("SHOW TABLES LIKE 'action_scheduler_actions'", ARRAY_N);
        if (!empty($tables)) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}action_scheduler_actions");
            $entry['action_scheduler_action_count'] = (int) $count;
        }
    } catch (\Exception $e) {
        // Ignore DB errors in diagnostics
        $entry['action_scheduler_action_count'] = null;
    }

    $diagnostics[] = $entry;
    // Keep a rolling window of last 50 entries
    if (count($diagnostics) > 50) {
        $diagnostics = array_slice($diagnostics, -50);
    }

    update_option('job_import_diagnostics', $diagnostics, false);
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
 * Update concurrent processing success metrics
 */
function update_concurrent_success_metrics($concurrency_used, $chunks_completed, $total_chunks, $items_processed, $total_items) {
    $success_rate = $total_chunks > 0 ? $chunks_completed / $total_chunks : 1.0;
    $processing_efficiency = $total_items > 0 ? $items_processed / $total_items : 1.0;

    // Combined success rate considers both chunk completion and item processing efficiency
    $combined_success_rate = ($success_rate + $processing_efficiency) / 2;

    // Use rolling average to stabilize the metric (less weight to historical data for concurrent to adapt faster)
    $current_rate = get_concurrent_success_rate();
    $new_rate = ($current_rate * 0.7) + ($combined_success_rate * 0.3);

    set_concurrent_success_rate($new_rate);

    // Track concurrent processing statistics
    $stats = get_concurrent_processing_stats();
    $stats['total_batches'] = ($stats['total_batches'] ?? 0) + 1;
    $stats['total_chunks'] = ($stats['total_chunks'] ?? 0) + $total_chunks;
    $stats['total_completed'] = ($stats['total_completed'] ?? 0) + $chunks_completed;
    $stats['total_items'] = ($stats['total_items'] ?? 0) + $total_items;
    $stats['total_processed'] = ($stats['total_processed'] ?? 0) + $items_processed;
    $stats['last_success_rate'] = $success_rate;
    $stats['last_concurrency_used'] = $concurrency_used;
    $stats['last_update'] = microtime(true);

    set_concurrent_processing_stats($stats);

    return $new_rate;
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
    $stats['total_items'] = ($stats['total_items'] ?? 0) + $total_items;
    $stats['total_successful'] = ($stats['total_successful'] ?? 0) + $successful_operations;
    $stats['total_skipped'] = ($stats['total_skipped'] ?? 0) + $skipped;
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

/**
 * Get concurrent processing statistics
 */
function get_concurrent_processing_stats() {
    return get_option('job_import_concurrent_stats', []);
}

/**
 * Set concurrent processing statistics
 */
function set_concurrent_processing_stats($stats) {
    update_option('job_import_concurrent_stats', $stats, false);
}
