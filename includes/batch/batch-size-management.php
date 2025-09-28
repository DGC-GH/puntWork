<?php

/**
 * Batch size management utilities.
 *
 * @since      1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Batch size management and performance optimization
 * Handles dynamic batch size adjustments based on memory and time metrics.
 */

/**
 * Adjust batch size based on memory and consecutive batch time metrics.
 * Implements incremental batch size increases with fallback to previous size when processing becomes too slow.
 *
 * @param  int   $batch_size          Current batch size.
 * @param  float $memory_limit_bytes  Memory limit.
 * @param  float $last_memory_ratio   Last memory ratio.
 * @param  float $current_batch_time  Current batch completion time.
 * @param  float $previous_batch_time Previous batch completion time.
 * @return array Array with 'batch_size' and 'reason' keys.
 */
function adjust_batch_size($batch_size, $memory_limit_bytes, $last_memory_ratio, $current_batch_time, $previous_batch_time)
{
    $old_batch_size = $batch_size;

    // Ensure batch size is within reasonable bounds
    $batch_size = max(10, min(500, $batch_size));

    // Memory-based adjustment (most critical - always override other adjustments)
    if ($last_memory_ratio > 0.85) {
        // High memory usage - reduce batch size significantly
        $batch_size = max(1, floor($batch_size * 0.6));
        $reason = 'high memory usage detected';
    } elseif ($last_memory_ratio > 0.75) {
        // Moderate high memory - reduce slightly
        $batch_size = max(1, floor($batch_size * 0.8));
        $reason = 'moderate memory usage detected';
    } elseif ($last_memory_ratio < 0.4) {
        // Low memory usage - allow larger batches
        // Increase batch size when memory is low
        $new_size = min(500, floor($batch_size * 1.5));
        if ($new_size > $batch_size) {
            $batch_size = $new_size;
            $reason = 'low memory usage allows larger batches, increasing batch size';
        } else {
            $reason = 'low memory usage allows larger batches';
        }
    } else {
        $reason = '';
    }

    // Get adaptive batch sizing state
    $adaptive_state = get_option(
        'job_import_adaptive_batch_state',
        [
            'previous_good_batch_size' => 10,
            'current_increment_step' => 1,
            'last_performance_check' => 0,
            'consecutive_slow_batches' => 0,
            'max_consecutive_slow' => 2, // Allow 2 slow batches before reverting
            'slow_threshold_seconds' => 30.0, // Consider batch slow if > 30 seconds
            'loop_prevention_counter' => 0,
            'max_loop_prevention' => 10, // Prevent more than 10 adjustments in a row
        ]
    );

    // Reset loop prevention if we've had successful batches
    if ($current_batch_time > 0 && $current_batch_time <= $adaptive_state['slow_threshold_seconds']) {
        $adaptive_state['loop_prevention_counter'] = 0;
    }

    // Dynamic batch size adjustment based on processing time
    if ($current_batch_time > 0) {
        $is_slow_batch = $current_batch_time > $adaptive_state['slow_threshold_seconds'];

        if ($is_slow_batch) {
            $adaptive_state['consecutive_slow_batches']++;

            // If we've had too many slow batches, revert to previous good size
            if ($adaptive_state['consecutive_slow_batches'] >= $adaptive_state['max_consecutive_slow']) {
                $batch_size = $adaptive_state['previous_good_batch_size'];
                $adaptive_state['consecutive_slow_batches'] = 0;
                $adaptive_state['current_increment_step'] = 1; // Reset increment step
                $reason = 'batch processing too slow (' . number_format($current_batch_time, 2) . 's > ' . $adaptive_state['slow_threshold_seconds'] . 's threshold), reverting to previous good batch size: ' . $batch_size;
                $adaptive_state['loop_prevention_counter']++;
            } else {
                // First slow batch - reduce batch size moderately
                $batch_size = max(5, floor($batch_size * 0.8));
                $reason = 'batch processing slow (' . number_format($current_batch_time, 2) . 's), reducing batch size temporarily';
                $adaptive_state['loop_prevention_counter']++;
            }
        } else {
            // Batch was fast enough - remember this as a good size and try to increase exponentially
            $adaptive_state['previous_good_batch_size'] = $batch_size;
            $adaptive_state['consecutive_slow_batches'] = 0;

            // Exponentially increase batch size (multiply by 1.5 for faster growth)
            $new_size = min(500, floor($batch_size * 1.5));

            // Ensure minimum increase of 5
            if ($new_size <= $batch_size) {
                $new_size = min(500, $batch_size + 5);
            }

            if ($new_size <= 500) {
                $batch_size = $new_size;
                $reason = 'batch processing fast (' . number_format($current_batch_time, 2) . 's), exponentially increasing batch size to ' . $batch_size;

                // Gradually increase increment step for faster adaptation (keep for compatibility)
                if ($adaptive_state['current_increment_step'] < 10) {
                    $adaptive_state['current_increment_step']++;
                }
            } else {
                $reason = 'batch processing fast but at maximum batch size limit';
            }
        }

        $adaptive_state['last_performance_check'] = time();
    } elseif ($last_memory_ratio < 0.6 && empty($reason)) {
        // First batch and memory is OK - allow initial increase
        $new_size = min(500, floor($batch_size * 2.0));
        if ($new_size > $batch_size) {
            $batch_size = $new_size;
            $reason = 'first batch with good memory, increasing batch size for initial adaptation';
            $adaptive_state['previous_good_batch_size'] = $batch_size;
        }
    }

    // Loop prevention: if we've adjusted too many times in a row, stabilize
    if ($adaptive_state['loop_prevention_counter'] >= $adaptive_state['max_loop_prevention']) {
        $batch_size = $adaptive_state['previous_good_batch_size'];
        $reason = 'loop prevention triggered, stabilizing at previous good batch size: ' . $batch_size;
        $adaptive_state['loop_prevention_counter'] = 0;
    }

    // Minimum batch size recovery mechanism
    if ($batch_size <= 5) {
        $consecutive_small_batches = get_option('job_import_consecutive_small_batches', 0);

        // If we've had several small batches but memory is OK, try increasing
        if ($consecutive_small_batches >= 3 && $last_memory_ratio < 0.7) {
            if ($batch_size == 5) {
                $batch_size = 6;
            } elseif ($batch_size == 6) {
                $batch_size = 7;
            }
            update_option('job_import_consecutive_small_batches', 0, false);
            $reason = 'recovery from minimum batch size, memory OK';
        } else {
            update_option('job_import_consecutive_small_batches', $consecutive_small_batches + 1, false);
        }
    } elseif ($batch_size > 5) {
        // Reset consecutive small batches counter when batch size recovers
        update_option('job_import_consecutive_small_batches', 0, false);
    }

    // Ensure batch size never goes below 5 or above 500
    $batch_size = max(5, min(500, $batch_size));

    // Cast to int to ensure type compatibility
    $batch_size = (int)$batch_size;

    // Save adaptive state
    update_option('job_import_adaptive_batch_state', $adaptive_state);

    // Log batch size changes for debugging
    if ($batch_size !== $old_batch_size || !empty($reason)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(
                sprintf(
                    '[PUNTWORK] Batch size adjusted from %d to %d (reason: %s) [memory: %.2f, current_batch: %.3f, prev_batch: %.3f, adaptive_state: %s]',
                    $old_batch_size,
                    $batch_size,
                    $reason,
                    $last_memory_ratio,
                    $current_batch_time,
                    $previous_batch_time,
                    json_encode($adaptive_state)
                )
            );
        }

        // Return detailed reason for user logs
        return [
            'batch_size' => $batch_size,
            'reason' => $reason,
        ];
    }

    return [
        'batch_size' => $batch_size,
        'reason' => '',
    ];
}

/**
 * Update batch performance metrics.
 *
 * @param  float $time_elapsed    Time elapsed for batch.
 * @param  int   $processed_count Number of items processed.
 * @param  int   $batch_size      Current batch size.
 * @return void
 */
function update_batch_metrics($time_elapsed, $processed_count, $batch_size)
{
    // Store previous batch time before updating
    $previous_batch_time = get_option('job_import_last_batch_time', 0);
    update_option('job_import_previous_batch_time', $previous_batch_time, false);

    // Update stored metrics for next batch
    $time_per_item = $processed_count > 0 ? $time_elapsed / $processed_count : 0;
    $prev_time_per_item = get_option('job_import_time_per_job', 0);
    update_option('job_import_time_per_job', $time_per_item, false);

    $peak_memory = memory_get_peak_usage(true);
    update_option('job_import_last_peak_memory', $peak_memory, false);

    // Use rolling average for time_per_item to stabilize adjustments
    $avg_time_per_item = get_option('job_import_avg_time_per_job', $time_per_item);
    $avg_time_per_item = ($avg_time_per_item * 0.7) + ($time_per_item * 0.3);
    update_option('job_import_avg_time_per_job', $avg_time_per_item, false);

    update_option('job_import_batch_size', $batch_size, false);

    // Track consecutive small batches for recovery mechanism
    if ($batch_size <= 3) {
        $consecutive = get_option('job_import_consecutive_small_batches', 0) + 1;
        update_option('job_import_consecutive_small_batches', $consecutive, false);
    } else {
        update_option('job_import_consecutive_small_batches', 0, false);
    }
}

/**
 * Validate and adjust batch size based on performance metrics.
 *
 * @param  array $setup Setup data.
 * @return array Adjusted setup with batch_size and logs.
 */
function validate_and_adjust_batch_size(array $setup): array
{
    $memory_limit_bytes = get_memory_limit_bytes();
    $threshold = 0.6 * $memory_limit_bytes;
    $batch_size = get_option('job_import_batch_size') ?: 25; // Starting batch size for real-time progress

    // Ensure batch_size is at least 10 for incremental updates
    $batch_size = max(10, (int)$batch_size);

    $old_batch_size = $batch_size;
    $prev_time_per_item = get_option('job_import_time_per_job', 0);
    $avg_time_per_item = get_option('job_import_avg_time_per_job', $prev_time_per_item);
    $last_peak_memory = get_option('job_import_last_peak_memory', $memory_limit_bytes);
    $last_memory_ratio = $last_peak_memory / $memory_limit_bytes;

    $current_batch_time = get_option('job_import_last_batch_time', 0);
    $previous_batch_time = get_option('job_import_previous_batch_time', 0);

    $adjustment_result = adjust_batch_size($batch_size, $memory_limit_bytes, $last_memory_ratio, $current_batch_time, $previous_batch_time);
    $batch_size = $adjustment_result['batch_size'];
    $batch_size = max(10, (int)$batch_size); // Ensure batch_size is at least 10 for real-time progress

    $logs = [];
    if ($batch_size !== $old_batch_size) {
        update_option('job_import_batch_size', $batch_size, false);
        $reason = '';
        if ($last_memory_ratio > 0.85) {
            $reason = 'high previous memory';
        } elseif ($last_memory_ratio < 0.5) {
            $reason = 'low previous memory and low avg time';
        } elseif ($current_batch_time > $previous_batch_time) {
            $reason = 'current batch slower than previous';
        } elseif ($current_batch_time < $previous_batch_time) {
            $reason = 'current batch faster than previous';
        }
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Batch size adjusted to ' . $batch_size . ' due to ' . $reason;
        if (!empty($adjustment_result['reason'])) {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Reason: ' . $adjustment_result['reason'];
        }
    }

    return [
        'batch_size' => $batch_size,
        'threshold' => $threshold,
        'logs' => $logs,
    ];
}

/**
 * Prepare batch processing variables.
 *
 * @param  array $setup      Original setup.
 * @param  int   $batch_size Adjusted batch size.
 * @return array Prepared variables.
 */
function prepare_batch_processing(array $setup, int $batch_size): array
{
    $end_index = min($setup['start_index'] + $batch_size, $setup['total']);

    return [
        'end_index' => $end_index,
        'published' => 0,
        'updated' => 0,
        'skipped' => 0,
        'duplicates_drafted' => 0,
        'inferred_languages' => 0,
        'inferred_benefits' => 0,
        'schema_generated' => 0,
    ];
}
