<?php
/**
 * Batch size management utilities
 *
 * @package    Puntwork
 * @subpackage Batch
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Batch size management and performance optimization
 * Handles dynamic batch size adjustments based on memory and time metrics
 */

/**
 * Adjust batch size based on memory and time metrics.
 *
 * @param int $batch_size Current batch size.
 * @param float $memory_limit_bytes Memory limit.
 * @param float $last_memory_ratio Last memory ratio.
 * @param float $prev_time_per_item Previous time per item.
 * @param float $avg_time_per_item Average time per item.
 * @return int Adjusted batch size.
 */
function adjust_batch_size($batch_size, $memory_limit_bytes, $last_memory_ratio, $prev_time_per_item, $avg_time_per_item) {
    $old_batch_size = $batch_size;

    // Ensure batch size is within reasonable bounds
    $batch_size = max(1, min(50, $batch_size));

    // Memory-based adjustment (most critical)
    if ($last_memory_ratio > 0.85) {
        // High memory usage - reduce batch size significantly
        $batch_size = max(1, floor($batch_size * 0.6));
    } elseif ($last_memory_ratio > 0.75) {
        // Moderate high memory - reduce slightly
        $batch_size = max(1, floor($batch_size * 0.8));
    } elseif ($last_memory_ratio < 0.4) {
        // Low memory usage - can increase batch size
        $batch_size = min(50, floor($batch_size * 1.3));
    }

    // Time-based adjustment (secondary)
    if ($prev_time_per_item > 0 && $avg_time_per_item > 0) {
        $time_ratio = $avg_time_per_item / $prev_time_per_item;

        // Stabilize time ratio for very small values
        if ($time_ratio < 0.1) $time_ratio = 0.1;
        if ($time_ratio > 10) $time_ratio = 10;

        if ($time_ratio > 1.5) {
            // Processing is getting significantly slower - reduce batch size
            $batch_size = max(1, floor($batch_size * 0.7));
        } elseif ($time_ratio < 0.7) {
            // Processing is getting faster - can increase batch size
            $batch_size = min(50, floor($batch_size * 1.2));
        }
    }

    // Minimum batch size recovery mechanism
    // If batch size is stuck at 1, try to gradually recover
    if ($batch_size === 1) {
        // Check if we can safely increase from 1
        $consecutive_small_batches = get_option('job_import_consecutive_small_batches', 0);

        // If we've had several small batches but memory is OK, try increasing
        if ($consecutive_small_batches >= 3 && $last_memory_ratio < 0.7) {
            $batch_size = 2; // Start with 2 instead of 1
            update_option('job_import_consecutive_small_batches', 0, false);
        } else {
            update_option('job_import_consecutive_small_batches', $consecutive_small_batches + 1, false);
        }
    } elseif ($batch_size > 1) {
        // Reset consecutive small batches counter when batch size recovers
        update_option('job_import_consecutive_small_batches', 0, false);
    }

    // Ensure batch size never goes below 1 or above 50
    $batch_size = max(1, min(50, $batch_size));

    // Log batch size changes for debugging
    if ($batch_size != $old_batch_size) {
        $reason = '';
        if ($last_memory_ratio > 0.85) {
            $reason = 'high memory usage';
        } elseif ($last_memory_ratio > 0.75) {
            $reason = 'moderate high memory usage';
        } elseif ($last_memory_ratio < 0.4) {
            $reason = 'low memory usage';
        } elseif ($prev_time_per_item > 0 && $avg_time_per_item > 0) {
            $time_ratio = $avg_time_per_item / $prev_time_per_item;
            if ($time_ratio > 1.5) {
                $reason = 'slowing processing time';
            } elseif ($time_ratio < 0.7) {
                $reason = 'improving processing time';
            }
        }

        if (empty($reason) && $batch_size === 1) {
            $reason = 'minimum batch size recovery attempt';
        }

        error_log(sprintf(
            '[PUNTWORK] Batch size adjusted from %d to %d due to %s (memory: %.2f, avg_time: %.3f)',
            $old_batch_size,
            $batch_size,
            $reason,
            $last_memory_ratio,
            $avg_time_per_item
        ));
    }

    return $batch_size;
}

/**
 * Update batch performance metrics.
 *
 * @param float $time_elapsed Time elapsed for batch.
 * @param int $processed_count Number of items processed.
 * @param int $batch_size Current batch size.
 * @return void
 */
function update_batch_metrics($time_elapsed, $processed_count, $batch_size) {
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
    if ($batch_size <= 2) {
        $consecutive = get_option('job_import_consecutive_small_batches', 0) + 1;
        update_option('job_import_consecutive_small_batches', $consecutive, false);
    } else {
        update_option('job_import_consecutive_small_batches', 0, false);
    }
}