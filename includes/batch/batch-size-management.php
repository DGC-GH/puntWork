<?php

/**
 * Batch size management utilities
 *
 * @package    Puntwork
 * @subpackage Batch
 * @since      1.0.0
 */

// Prevent direct access
if (! defined('ABSPATH') ) {
    exit;
}

/**
 * Batch size management and performance optimization
 * Handles dynamic batch size adjustments based on memory and time metrics
 */

/**
 * Adjust batch size based on memory and consecutive batch time metrics.
 *
 * @param  int   $batch_size          Current batch size.
 * @param  float $memory_limit_bytes  Memory limit.
 * @param  float $last_memory_ratio   Last memory ratio.
 * @param  float $current_batch_time  Current batch completion time.
 * @param  float $previous_batch_time Previous batch completion time.
 * @return array Array with 'batch_size' and 'reason' keys.
 */
function adjust_batch_size( $batch_size, $memory_limit_bytes, $last_memory_ratio, $current_batch_time, $previous_batch_time )
{
    $old_batch_size = $batch_size;

    // Ensure batch size is within reasonable bounds
    $batch_size = max(5, min(500, $batch_size));

    // Memory-based adjustment (most critical)
    if ($last_memory_ratio > 0.85 ) {
        // High memory usage - reduce batch size significantly
        $batch_size = max(1, floor($batch_size * 0.6));
    } elseif ($last_memory_ratio > 0.75 ) {
        // Moderate high memory - reduce slightly
        $batch_size = max(1, floor($batch_size * 0.8));
    } elseif ($last_memory_ratio < 0.4 ) {
        // Low memory usage - gradually increase batch size
        $new_size = floor($batch_size * 1.2);
        if ($new_size == $batch_size ) {
            $new_size = $batch_size + 1; // Ensure at least +1 if multiplier doesn't change
        }
        $batch_size = min(500, $new_size);
    }

    // Dynamic batch size adjustment based on consecutive batch completion times
    if ($previous_batch_time > 0 && $current_batch_time > 0 ) {
        if ($current_batch_time > $previous_batch_time ) {
            // Current batch took longer than previous - decrease batch size moderately
            $batch_size = max(1, floor($batch_size * 0.9));
        } elseif ($current_batch_time < $previous_batch_time ) {
            // Current batch took less time than previous - gradually increase batch size
            $new_size = floor($batch_size * 1.1);
            if ($new_size == $batch_size ) {
                $new_size = $batch_size + 1; // Ensure at least +1 if multiplier doesn't change
            }
            $batch_size = min(500, $new_size);
        }
        // If times are equal, keep batch size the same
    }

    // Minimum batch size recovery mechanism
    // If batch size is stuck at 5 or below, try to gradually recover
    if ($batch_size <= 5 ) {
        // Check if we can safely increase from low batch sizes
        $consecutive_small_batches = get_option('job_import_consecutive_small_batches', 0);

        // If we've had several small batches but memory is OK, try increasing
        if ($consecutive_small_batches >= 3 && $last_memory_ratio < 0.7 ) {
            if ($batch_size === 5 ) {
                $batch_size = 6; // Start with 6 instead of 5
            } elseif ($batch_size === 6 ) {
                $batch_size = 7; // Increase from 6 to 7
            }
            update_option('job_import_consecutive_small_batches', 0, false);
        } else {
            update_option('job_import_consecutive_small_batches', $consecutive_small_batches + 1, false);
        }
    } elseif ($batch_size > 5 ) {
        // Reset consecutive small batches counter when batch size recovers
        update_option('job_import_consecutive_small_batches', 0, false);
    }

    // Ensure batch size never goes below 1 or above 500
    $batch_size = max(1, min(500, $batch_size));

    // Cast to int to ensure type compatibility
    $batch_size = (int) $batch_size;

    // Log batch size changes for debugging
    if ($batch_size != $old_batch_size ) {
        $reason = '';
        if ($last_memory_ratio > 0.85 ) {
            $reason = 'high memory usage';
        } elseif ($last_memory_ratio > 0.75 ) {
            $reason = 'moderate high memory usage';
        } elseif ($last_memory_ratio < 0.4 ) {
            $reason = 'low memory usage';
        } elseif ($previous_batch_time > 0 && $current_batch_time > 0 ) {
            if ($current_batch_time > $previous_batch_time ) {
                $reason = 'current batch slower than previous';
            } elseif ($current_batch_time < $previous_batch_time ) {
                $reason = 'current batch faster than previous';
            }
        }

        if (empty($reason) && $batch_size === 1 ) {
            $reason = 'minimum batch size recovery attempt';
        }

        // Add detailed log message for user-visible logs
        $detailed_reason = '';
        if ($last_memory_ratio > 0.85 ) {
            $detailed_reason = 'high memory usage detected';
        } elseif ($last_memory_ratio > 0.75 ) {
            $detailed_reason = 'moderate memory usage detected';
        } elseif ($last_memory_ratio < 0.4 ) {
            $detailed_reason = 'low memory usage allows larger batches';
        } elseif ($previous_batch_time > 0 && $current_batch_time > 0 ) {
            if ($current_batch_time > $previous_batch_time ) {
                $detailed_reason = 'current batch took longer than previous - decreasing batch size';
            } elseif ($current_batch_time < $previous_batch_time ) {
                $detailed_reason = 'current batch took less time than previous - increasing batch size';
            }
        }

        if (empty($detailed_reason) && $batch_size === 1 ) {
            $detailed_reason = 'attempting recovery from minimum batch size';
        } elseif (empty($detailed_reason) && $batch_size === 2 ) {
            $detailed_reason = 'attempting recovery from small batch size';
        }

        error_log(
            sprintf(
                '[PUNTWORK] Batch size adjusted from %d to %d due to %s (memory: %.2f, current_batch: %.3f, prev_batch: %.3f)',
                $old_batch_size,
                $batch_size,
                $reason,
                $last_memory_ratio,
                $current_batch_time,
                $previous_batch_time
            )
        );

        // Return detailed reason for user logs
        return array(
        'batch_size' => $batch_size,
        'reason'     => $detailed_reason,
        );
    }

    return array(
    'batch_size' => $batch_size,
    'reason'     => '',
    );
}

/**
 * Update batch performance metrics.
 *
 * @param  float $time_elapsed    Time elapsed for batch.
 * @param  int   $processed_count Number of items processed.
 * @param  int   $batch_size      Current batch size.
 * @return void
 */
function update_batch_metrics( $time_elapsed, $processed_count, $batch_size )
{
    // Store previous batch time before updating
    $previous_batch_time = get_option('job_import_last_batch_time', 0);
    update_option('job_import_previous_batch_time', $previous_batch_time, false);

    // Update stored metrics for next batch
    $time_per_item      = $processed_count > 0 ? $time_elapsed / $processed_count : 0;
    $prev_time_per_item = get_option('job_import_time_per_job', 0);
    update_option('job_import_time_per_job', $time_per_item, false);

    $peak_memory = memory_get_peak_usage(true);
    update_option('job_import_last_peak_memory', $peak_memory, false);

    // Use rolling average for time_per_item to stabilize adjustments
    $avg_time_per_item = get_option('job_import_avg_time_per_job', $time_per_item);
    $avg_time_per_item = ( $avg_time_per_item * 0.7 ) + ( $time_per_item * 0.3 );
    update_option('job_import_avg_time_per_job', $avg_time_per_item, false);

    update_option('job_import_batch_size', $batch_size, false);

    // Track consecutive small batches for recovery mechanism
    if ($batch_size <= 3 ) {
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
function validate_and_adjust_batch_size( array $setup ): array
{
    $memory_limit_bytes = get_memory_limit_bytes();
    $threshold          = 0.6 * $memory_limit_bytes;
    $batch_size         = get_option('job_import_batch_size') ?: 5; // Force smaller starting batch size for real-time progress

    // Ensure batch_size is at least 5 for incremental updates
    $batch_size = max(5, (int) $batch_size);

    $old_batch_size     = $batch_size;
    $prev_time_per_item = get_option('job_import_time_per_job', 0);
    $avg_time_per_item  = get_option('job_import_avg_time_per_job', $prev_time_per_item);
    $last_peak_memory   = get_option('job_import_last_peak_memory', $memory_limit_bytes);
    $last_memory_ratio  = $last_peak_memory / $memory_limit_bytes;

    $current_batch_time  = get_option('job_import_last_batch_time', 0);
    $previous_batch_time = get_option('job_import_previous_batch_time', 0);

    $adjustment_result = adjust_batch_size($batch_size, $memory_limit_bytes, $last_memory_ratio, $current_batch_time, $previous_batch_time);
    $batch_size        = $adjustment_result['batch_size'];
    $batch_size        = max(5, (int) $batch_size); // Ensure batch_size is at least 5 for real-time progress

    $logs = array();
    if ($batch_size != $old_batch_size ) {
        update_option('job_import_batch_size', $batch_size, false);
        $reason = '';
        if ($last_memory_ratio > 0.85 ) {
            $reason = 'high previous memory';
        } elseif ($last_memory_ratio < 0.5 ) {
            $reason = 'low previous memory and low avg time';
        } elseif ($current_batch_time > $previous_batch_time ) {
            $reason = 'current batch slower than previous';
        } elseif ($current_batch_time < $previous_batch_time ) {
            $reason = 'current batch faster than previous';
        }
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Batch size adjusted to ' . $batch_size . ' due to ' . $reason;
        if (! empty($adjustment_result['reason']) ) {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Reason: ' . $adjustment_result['reason'];
        }
    }

    return array(
    'batch_size' => $batch_size,
    'threshold'  => $threshold,
    'logs'       => $logs,
    );
}

/**
 * Prepare batch processing variables.
 *
 * @param  array $setup      Original setup.
 * @param  int   $batch_size Adjusted batch size.
 * @return array Prepared variables.
 */
function prepare_batch_processing( array $setup, int $batch_size ): array
{
    $end_index = min($setup['start_index'] + $batch_size, $setup['total']);

    return array(
    'end_index'          => $end_index,
    'published'          => 0,
    'updated'            => 0,
    'skipped'            => 0,
    'duplicates_drafted' => 0,
    'inferred_languages' => 0,
    'inferred_benefits'  => 0,
    'schema_generated'   => 0,
    );
}
