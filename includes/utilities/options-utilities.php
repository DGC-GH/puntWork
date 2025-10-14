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
    return get_option('job_import_status', []);
}

/**
 * Set import status with validation
 */
function set_import_status($status) {
    update_option('job_import_status', $status, false);
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
 * Update concurrent processing success metrics
 */
function update_concurrent_success_metrics($concurrency_used, $chunks_completed, $total_chunks, $items_processed, $total_items) {
    $success_rate = $total_chunks > 0 ? $chunks_completed / $total_chunks : 1.0;
    $processing_success_rate = $total_items > 0 ? $items_processed / $total_items : 1.0;

    // Combined success rate considers both chunk completion and item processing
    $combined_success_rate = ($success_rate + $processing_success_rate) / 2;

    // Use rolling average to stabilize the metric
    $current_rate = get_concurrent_success_rate();
    $new_rate = ($current_rate * 0.7) + ($combined_success_rate * 0.3);

    set_concurrent_success_rate($new_rate);
    set_last_concurrency_level($concurrency_used);

    return $new_rate;
}