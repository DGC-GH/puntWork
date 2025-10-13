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

// Include retry utility
require_once plugin_dir_path(__FILE__) . '../utilities/retry-utility.php';

/**
 * Adjust batch size based on memory and consecutive batch time metrics.
 *
 * @param int $batch_size Current batch size.
 * @param float $memory_limit_bytes Memory limit.
 * @param float $last_memory_ratio Last memory ratio.
 * @param float $current_batch_time Current batch completion time.
 * @param float $previous_batch_time Previous batch completion time.
 * @return array Array with 'batch_size' and 'reason' keys.
 */
function adjust_batch_size($batch_size, $memory_limit_bytes, $last_memory_ratio, $current_batch_time, $previous_batch_time) {
    try {
        $old_batch_size = $batch_size;

        // Apply bounds checking and ensure stability
        $batch_size = max(1, min(750, $batch_size)); // Increased hard limit from 500 to 750

        // Prevent rapid oscillations by limiting change magnitude
        $max_change_factor = 2.0; // Maximum 2x increase or 0.5x decrease per adjustment
        if ($old_batch_size > 0) {
            $change_ratio = $batch_size / $old_batch_size;
            if ($change_ratio > $max_change_factor) {
                $batch_size = (int)($old_batch_size * $max_change_factor);
            } elseif ($change_ratio < (1 / $max_change_factor)) {
                $batch_size = max(1, (int)($old_batch_size / $max_change_factor));
            }
        }

        // Final bounds check after oscillation prevention
        $batch_size = max(1, min(750, $batch_size)); // Increased hard limit from 500 to 750

        // Enhanced memory-based adjustment with tiered thresholds and absolute limits
        $memory_adjusted = false;
        $last_memory_bytes = $last_memory_ratio * $memory_limit_bytes;

        // Absolute memory thresholds for precision (complement percentage thresholds)
        $absolute_very_low_memory = 25 * 1024 * 1024; // 25MB absolute threshold
        $absolute_low_memory = 50 * 1024 * 1024;      // 50MB absolute threshold

        if ($last_memory_ratio > 0.80) { // High memory usage - reduce batch size significantly
            // High memory usage - reduce batch size significantly
            $new_batch_size = max(1, floor($batch_size * 0.6));
            if ($new_batch_size < $batch_size) {
                $batch_size = $new_batch_size;
                $memory_adjusted = true;
                PuntWorkLogger::debug('Memory-based reduction: high usage threshold', PuntWorkLogger::CONTEXT_BATCH, [
                    'memory_ratio' => $last_memory_ratio,
                    'memory_bytes' => $last_memory_bytes,
                    'threshold' => 0.80,
                    'old_batch_size' => $old_batch_size,
                    'new_batch_size' => $batch_size,
                    'reduction_factor' => 0.6
                ]);
            }
        } elseif ($last_memory_ratio > 0.85) { // Moderate high memory - reduce slightly
            // Moderate high memory - reduce slightly
            $new_batch_size = max(1, floor($batch_size * 0.8));
            if ($new_batch_size < $batch_size) {
                $batch_size = $new_batch_size;
                $memory_adjusted = true;
                PuntWorkLogger::debug('Memory-based reduction: moderate high usage threshold', PuntWorkLogger::CONTEXT_BATCH, [
                    'memory_ratio' => $last_memory_ratio,
                    'memory_bytes' => $last_memory_bytes,
                    'threshold' => 0.85,
                    'old_batch_size' => $old_batch_size,
                    'new_batch_size' => $batch_size,
                    'reduction_factor' => 0.8
                ]);
            }
        } elseif ($last_memory_bytes < $absolute_very_low_memory || $last_memory_ratio < 0.05) {
            // Very low memory usage - aggressive batch size increase (60%)
            $new_size = floor($batch_size * 1.6);
            if ($new_size == $batch_size) {
                $new_size = $batch_size + 5; // Ensure at least +5 if multiplier doesn't change
            }
            $batch_size = min(750, $new_size);
            $memory_adjusted = true;
            PuntWorkLogger::debug('Memory-based increase: very low usage threshold (aggressive)', PuntWorkLogger::CONTEXT_BATCH, [
                'memory_ratio' => $last_memory_ratio,
                'memory_bytes' => $last_memory_bytes,
                'absolute_threshold' => $absolute_very_low_memory,
                'percentage_threshold' => 0.05,
                'old_batch_size' => $old_batch_size,
                'new_batch_size' => $batch_size,
                'growth_factor' => 1.6,
                'trigger_type' => $last_memory_bytes < $absolute_very_low_memory ? 'absolute' : 'percentage'
            ]);
        } elseif ($last_memory_bytes < $absolute_low_memory || $last_memory_ratio < 0.10) {
            // Low memory usage - moderate batch size increase (40%)
            $new_size = floor($batch_size * 1.4);
            if ($new_size == $batch_size) {
                $new_size = $batch_size + 3; // Ensure at least +3 if multiplier doesn't change
            }
            $batch_size = min(750, $new_size);
            $memory_adjusted = true;
            PuntWorkLogger::debug('Memory-based increase: low usage threshold (moderate)', PuntWorkLogger::CONTEXT_BATCH, [
                'memory_ratio' => $last_memory_ratio,
                'memory_bytes' => $last_memory_bytes,
                'absolute_threshold' => $absolute_low_memory,
                'percentage_threshold' => 0.10,
                'old_batch_size' => $old_batch_size,
                'new_batch_size' => $batch_size,
                'growth_factor' => 1.4,
                'trigger_type' => $last_memory_bytes < $absolute_low_memory ? 'absolute' : 'percentage'
            ]);
        } elseif ($last_memory_ratio < 0.20) {
            // Moderate low memory usage - conservative batch size increase (20%)
            $new_size = floor($batch_size * 1.2);
            if ($new_size == $batch_size) {
                $new_size = $batch_size + 1; // Ensure at least +1 if multiplier doesn't change
            }
            $batch_size = min(750, $new_size);
            $memory_adjusted = true;
            PuntWorkLogger::debug('Memory-based increase: moderate low usage threshold (conservative)', PuntWorkLogger::CONTEXT_BATCH, [
                'memory_ratio' => $last_memory_ratio,
                'memory_bytes' => $last_memory_bytes,
                'percentage_threshold' => 0.20,
                'old_batch_size' => $old_batch_size,
                'new_batch_size' => $batch_size,
                'growth_factor' => 1.2
            ]);
        }

        // Time-based batch size reduction for slow processing
        $time_adjusted = false;
        if ($current_batch_time > 180) { // 3-minute threshold for slow processing
            $batch_size = max(50, (int)($batch_size * 0.8)); // Reduce by 20%, minimum 50
            $time_adjusted = true;
            PuntWorkLogger::info('Time-based batch size reduction applied', PuntWorkLogger::CONTEXT_BATCH, [
                'current_batch_time' => $current_batch_time,
                'threshold' => 180,
                'old_batch_size' => $old_batch_size,
                'new_batch_size' => $batch_size
            ]);
        }

        // Efficiency tracking - reduce batch size if processing becomes inefficient
        if (!$time_adjusted && $batch_size > 0) {
            $time_per_item = $current_batch_time / $batch_size;
            if ($time_per_item > 3.0) { // Increased from 2.5 to 3.0 seconds per item
                $batch_size = max(50, (int)($batch_size * 0.9)); // Reduce by 10%, minimum 50
                PuntWorkLogger::info('Efficiency-based batch size reduction applied', PuntWorkLogger::CONTEXT_BATCH, [
                    'time_per_item' => $time_per_item,
                    'threshold' => 3.0,
                    'old_batch_size' => $old_batch_size,
                    'new_batch_size' => $batch_size,
                    'memory_ratio' => $last_memory_ratio,
                    'memory_bytes' => $last_memory_bytes,
                    'current_batch_time' => $current_batch_time,
                    'batch_size' => $batch_size
                ]);
            } elseif ($time_per_item <= 1.0) { // Log when processing is very efficient
                PuntWorkLogger::debug('Efficient processing detected', PuntWorkLogger::CONTEXT_BATCH, [
                    'time_per_item' => $time_per_item,
                    'efficiency_threshold' => 1.0,
                    'memory_ratio' => $last_memory_ratio,
                    'memory_bytes' => $last_memory_bytes,
                    'current_batch_size' => $batch_size,
                    'current_batch_time' => $current_batch_time
                ]);
            }
        }

        // Dynamic batch size adjustment based on consecutive batch completion times
        // Only apply time-based adjustments if memory, time, and efficiency didn't trigger a change
        if (!$memory_adjusted && !$time_adjusted && $previous_batch_time > 0 && $current_batch_time > 0) {
            $time_adjusted = false;
            $time_ratio = $current_batch_time / $previous_batch_time;

            if ($current_batch_time > $previous_batch_time * 1.2) {
                // Current batch took significantly longer (>20%) - decrease batch size moderately
                $new_batch_size = max(1, floor($batch_size * 0.9));
                if ($new_batch_size < $batch_size) {
                    $batch_size = $new_batch_size;
                    $time_adjusted = true;
                    PuntWorkLogger::debug('Time-based batch size adjustment: slower than previous', PuntWorkLogger::CONTEXT_BATCH, [
                        'previous_batch_time' => $previous_batch_time,
                        'current_batch_time' => $current_batch_time,
                        'time_ratio' => $time_ratio,
                        'threshold_ratio' => 1.2,
                        'old_batch_size' => $old_batch_size,
                        'new_batch_size' => $batch_size,
                        'memory_ratio' => $last_memory_ratio,
                        'memory_bytes' => $last_memory_bytes
                    ]);
                }
            } elseif ($current_batch_time < $previous_batch_time * 0.8) {
                // Current batch took significantly less time (<80%) - gradually increase batch size
                $new_size = floor($batch_size * 1.1);
                if ($new_size == $batch_size) {
                    $new_size = $batch_size + 1; // Ensure at least +1 if multiplier doesn't change
                }
                $batch_size = min(750, $new_size); // Updated hard limit
                $time_adjusted = true;
                PuntWorkLogger::debug('Time-based batch size adjustment: faster than previous', PuntWorkLogger::CONTEXT_BATCH, [
                    'previous_batch_time' => $previous_batch_time,
                    'current_batch_time' => $current_batch_time,
                    'time_ratio' => $time_ratio,
                    'threshold_ratio' => 0.8,
                    'old_batch_size' => $old_batch_size,
                    'new_batch_size' => $batch_size,
                    'memory_ratio' => $last_memory_ratio,
                    'memory_bytes' => $last_memory_bytes
                ]);
            }

            // Log time-based adjustments
            if ($time_adjusted) {
                PuntWorkLogger::debug('Time-based batch size adjustment applied', PuntWorkLogger::CONTEXT_BATCH, [
                    'previous_batch_time' => $previous_batch_time,
                    'current_batch_time' => $current_batch_time,
                    'ratio' => $time_ratio,
                    'new_batch_size' => $batch_size
                ]);
            }
        }

        // Adaptive learning - track optimal batch sizes based on historical performance
        if (!$memory_adjusted && !$time_adjusted && $batch_size > 0) {
            $time_per_item = $current_batch_time / $batch_size;
            $optimal_batch_size = get_option('job_import_optimal_batch_size', 200);

            // If current batch is efficient (good time per item and reasonable memory usage)
            if ($time_per_item < 1.5 && $last_memory_ratio < 0.25) {
                // Gradually increase optimal batch size
                $new_optimal = min(750, $optimal_batch_size + 5); // Updated hard limit
                if ($new_optimal != $optimal_batch_size) {
                    retry_option_operation(function() use ($new_optimal) {
                        return update_option('job_import_optimal_batch_size', $new_optimal, false);
                    }, [$new_optimal], [
                        'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                        'operation' => 'update_optimal_batch_size'
                    ]);
                    PuntWorkLogger::debug('Optimal batch size increased', PuntWorkLogger::CONTEXT_BATCH, [
                        'old_optimal' => $optimal_batch_size,
                        'new_optimal' => $new_optimal,
                        'time_per_item' => $time_per_item,
                        'memory_ratio' => $last_memory_ratio,
                        'memory_bytes' => $last_memory_bytes,
                        'current_batch_size' => $batch_size,
                        'current_batch_time' => $current_batch_time
                    ]);
                }
            } elseif ($time_per_item > 3.0 || $last_memory_ratio > 0.90) {
                // Current batch is inefficient - slightly decrease optimal batch size
                $new_optimal = max(50, $optimal_batch_size - 5);
                if ($new_optimal != $optimal_batch_size) {
                    retry_option_operation(function() use ($new_optimal) {
                        return update_option('job_import_optimal_batch_size', $new_optimal, false);
                    }, [$new_optimal], [
                        'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                        'operation' => 'decrease_optimal_batch_size'
                    ]);
                    PuntWorkLogger::debug('Optimal batch size decreased', PuntWorkLogger::CONTEXT_BATCH, [
                        'old_optimal' => $optimal_batch_size,
                        'new_optimal' => $new_optimal,
                        'time_per_item' => $time_per_item,
                        'memory_ratio' => $last_memory_ratio,
                        'memory_bytes' => $last_memory_bytes,
                        'current_batch_size' => $batch_size,
                        'current_batch_time' => $current_batch_time
                    ]);
                }
            }

            // If current batch size differs significantly from optimal, suggest adjustment
            $size_difference_ratio = abs($batch_size - $optimal_batch_size) / max($batch_size, $optimal_batch_size);
            if ($size_difference_ratio > 0.3 && !$memory_adjusted && !$time_adjusted) {
                // Suggest moving toward optimal batch size
                if ($batch_size < $optimal_batch_size) {
                    $suggested_size = min(750, (int)($batch_size * 1.15)); // Updated hard limit
                    if ($suggested_size > $batch_size) {
                        $batch_size = $suggested_size;
                        PuntWorkLogger::info('Batch size adjusted toward optimal', PuntWorkLogger::CONTEXT_BATCH, [
                            'current_size' => $old_batch_size,
                            'optimal_size' => $optimal_batch_size,
                            'new_size' => $batch_size,
                            'reason' => 'moving toward learned optimal batch size',
                            'size_difference_ratio' => $size_difference_ratio,
                            'memory_ratio' => $last_memory_ratio,
                            'memory_bytes' => $last_memory_bytes,
                            'time_per_item' => $time_per_item
                        ]);
                    }
                }
            }
        }

        // Minimum batch size recovery mechanism - simplified to prevent oscillations
        if ($batch_size <= 2) {
            try {
                $consecutive_small_batches = get_option('job_import_consecutive_small_batches', 0);

                // Only attempt recovery if we have consistent small batches AND good memory conditions
                if ($consecutive_small_batches >= 5 && $last_memory_ratio < 0.25) {
                    if ($batch_size === 1) {
                        $batch_size = 2;
                    } elseif ($batch_size === 2) {
                        $batch_size = 5; // Jump to a more stable size
                    }

                    // Reset counter on successful recovery
                    retry_option_operation(function() {
                        return update_option('job_import_consecutive_small_batches', 0, false);
                    }, [], [
                        'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                        'operation' => 'reset_consecutive_small_batches'
                    ]);

                    PuntWorkLogger::info('Batch size recovery applied', PuntWorkLogger::CONTEXT_BATCH, [
                        'consecutive_small_batches' => $consecutive_small_batches,
                        'memory_ratio' => $last_memory_ratio,
                        'recovered_to' => $batch_size
                    ]);
                } else {
                    // Increment counter for tracking
                    retry_option_operation(function() use ($consecutive_small_batches) {
                        return update_option('job_import_consecutive_small_batches', $consecutive_small_batches + 1, false);
                    }, [$consecutive_small_batches], [
                        'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                        'operation' => 'increment_consecutive_small_batches'
                    ]);
                }
            } catch (\Exception $e) {
                PuntWorkLogger::error('Failed to update consecutive small batches option', PuntWorkLogger::CONTEXT_BATCH, [
                    'error' => $e->getMessage(),
                    'batch_size' => $batch_size
                ]);
                // Continue without updating the option
            }
        } elseif ($batch_size > 5) {
            // Reset counter when batch size is healthy
            try {
                retry_option_operation(function() {
                    return update_option('job_import_consecutive_small_batches', 0, false);
                }, [], [
                    'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                    'operation' => 'reset_consecutive_small_batches_recovery'
                ]);
            } catch (\Exception $e) {
                PuntWorkLogger::error('Failed to reset consecutive small batches option', PuntWorkLogger::CONTEXT_BATCH, [
                    'error' => $e->getMessage(),
                    'batch_size' => $batch_size
                ]);
                // Continue without updating the option
            }
        }

        // Ensure batch size never goes below 1 or above 750
        $batch_size = max(1, min(750, $batch_size));

        // Log batch size changes for debugging with comprehensive metrics
        if ($batch_size != $old_batch_size) {
            $reason = '';
            $detailed_reason = '';
            $trigger_type = '';
            $metrics = [
                'old_batch_size' => $old_batch_size,
                'new_batch_size' => $batch_size,
                'memory_ratio' => $last_memory_ratio,
                'memory_bytes' => $last_memory_bytes,
                'memory_limit_bytes' => $memory_limit_bytes,
                'current_batch_time' => $current_batch_time,
                'previous_batch_time' => $previous_batch_time,
                'time_per_item' => $batch_size > 0 ? $current_batch_time / $batch_size : 0,
                'absolute_very_low_threshold' => $absolute_very_low_memory,
                'absolute_low_threshold' => $absolute_low_memory
            ];

            if ($last_memory_ratio > 0.80) {
                $reason = 'high memory usage';
                $detailed_reason = 'high memory usage detected (>80%)';
                $trigger_type = 'memory_high';
            } elseif ($last_memory_ratio > 0.85) {
                $reason = 'moderate high memory usage';
                $detailed_reason = 'moderate high memory usage detected (>85%)';
                $trigger_type = 'memory_moderate_high';
            } elseif ($last_memory_bytes < $absolute_very_low_memory || $last_memory_ratio < 0.05) {
                $reason = 'very low memory usage';
                $detailed_reason = 'very low memory usage allows aggressive batch size increase';
                $trigger_type = $last_memory_bytes < $absolute_very_low_memory ? 'memory_absolute_very_low' : 'memory_percentage_very_low';
            } elseif ($last_memory_bytes < $absolute_low_memory || $last_memory_ratio < 0.10) {
                $reason = 'low memory usage';
                $detailed_reason = 'low memory usage allows moderate batch size increase';
                $trigger_type = $last_memory_bytes < $absolute_low_memory ? 'memory_absolute_low' : 'memory_percentage_low';
            } elseif ($last_memory_ratio < 0.20) {
                $reason = 'moderate low memory usage';
                $detailed_reason = 'moderate low memory usage allows conservative batch size increase';
                $trigger_type = 'memory_moderate_low';
            } elseif ($current_batch_time > 120) {
                $reason = 'slow processing time';
                $detailed_reason = 'processing time exceeded 2-minute threshold - reducing batch size';
                $trigger_type = 'time_slow_batch';
            } elseif ($batch_size > 0 && ($current_batch_time / $batch_size) > 2.5) {
                $reason = 'low processing efficiency';
                $detailed_reason = 'processing efficiency below 2.5 seconds per item - reducing batch size';
                $trigger_type = 'efficiency_low';
            } elseif ($previous_batch_time > 0 && $current_batch_time > 0) {
                if ($current_batch_time > $previous_batch_time) {
                    $reason = 'current batch slower than previous';
                    $detailed_reason = 'current batch took longer than previous - decreasing batch size';
                    $trigger_type = 'time_relative_slower';
                } elseif ($current_batch_time < $previous_batch_time) {
                    $reason = 'current batch faster than previous';
                    $detailed_reason = 'current batch took less time than previous - increasing batch size';
                    $trigger_type = 'time_relative_faster';
                }
            }

            if (empty($reason) && $batch_size === 1) {
                $reason = 'minimum batch size recovery attempt';
                $detailed_reason = 'attempting recovery from minimum batch size';
                $trigger_type = 'recovery_minimum';
            } elseif (empty($reason) && $batch_size === 2) {
                $reason = 'small batch size recovery attempt';
                $detailed_reason = 'attempting recovery from small batch size';
                $trigger_type = 'recovery_small';
            } elseif (empty($reason)) {
                $reason = 'moving toward learned optimal batch size';
                $detailed_reason = 'moving toward learned optimal batch size';
                $trigger_type = 'adaptive_learning';
            }

            // Add trigger type and comprehensive metrics to the log
            $metrics['reason'] = $reason;
            $metrics['trigger_type'] = $trigger_type;
            $metrics['change_factor'] = $old_batch_size > 0 ? $batch_size / $old_batch_size : 0;

            PuntWorkLogger::info('Batch size adjusted', PuntWorkLogger::CONTEXT_BATCH, $metrics);

            // Return detailed reason for user logs
            return ['batch_size' => $batch_size, 'reason' => $detailed_reason];
        }

        return ['batch_size' => $batch_size, 'reason' => ''];

    } catch (\Exception $e) {
        PuntWorkLogger::error('Critical error in batch size adjustment', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'original_batch_size' => $batch_size
        ]);
        // Return original batch size as fallback
        return ['batch_size' => $batch_size, 'reason' => 'error fallback'];
    }
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
    try {
        // Store previous batch time before updating
        $previous_batch_time = get_option('job_import_last_batch_time', 0);
        retry_option_operation(function() use ($previous_batch_time) {
            return update_option('job_import_previous_batch_time', $previous_batch_time, false);
        }, [$previous_batch_time], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_previous_batch_time'
        ]);

        // Update stored metrics for next batch
        $time_per_item = $processed_count > 0 ? $time_elapsed / $processed_count : 0;
        $prev_time_per_item = get_option('job_import_time_per_job', 0);
        retry_option_operation(function() use ($time_per_item) {
            return update_option('job_import_time_per_job', $time_per_item, false);
        }, [$time_per_item], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_time_per_job'
        ]);

        $peak_memory = memory_get_peak_usage(true);
        retry_option_operation(function() use ($peak_memory) {
            return update_option('job_import_last_peak_memory', $peak_memory, false);
        }, [$peak_memory], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_peak_memory'
        ]);

        // Use rolling average for time_per_item to stabilize adjustments
        $avg_time_per_item = get_option('job_import_avg_time_per_job', $time_per_item);
        $avg_time_per_item = ($avg_time_per_item * 0.7) + ($time_per_item * 0.3);
        retry_option_operation(function() use ($avg_time_per_item) {
            return update_option('job_import_avg_time_per_job', $avg_time_per_item, false);
        }, [$avg_time_per_item], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_avg_time_per_job'
        ]);

        retry_option_operation(function() use ($batch_size) {
            return update_option('job_import_batch_size', $batch_size, false);
        }, [$batch_size], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_batch_size_metric'
        ]);

        // Track consecutive small batches for recovery mechanism
        if ($batch_size <= 3) {
            $consecutive = get_option('job_import_consecutive_small_batches', 0) + 1;
            retry_option_operation(function() use ($consecutive) {
                return update_option('job_import_consecutive_small_batches', $consecutive, false);
            }, [$consecutive], [
                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                'operation' => 'update_consecutive_small_batches'
            ]);
        } else {
            retry_option_operation(function() {
                return update_option('job_import_consecutive_small_batches', 0, false);
            }, [], [
                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                'operation' => 'reset_consecutive_small_batches_metric'
            ]);
        }

        PuntWorkLogger::debug('Batch metrics updated', PuntWorkLogger::CONTEXT_BATCH, [
            'time_elapsed' => $time_elapsed,
            'processed_count' => $processed_count,
            'batch_size' => $batch_size,
            'time_per_item' => $time_per_item,
            'peak_memory' => $peak_memory,
            'memory_limit' => get_memory_limit_bytes(),
            'memory_ratio' => get_memory_limit_bytes() > 0 ? $peak_memory / get_memory_limit_bytes() : 0,
            'avg_time_per_item' => $avg_time_per_item,
            'prev_time_per_item' => $prev_time_per_item,
            'consecutive_small_batches' => $batch_size <= 3 ? ($consecutive ?? 0) : 0,
            'efficiency_status' => $time_per_item <= 1.0 ? 'very_efficient' : ($time_per_item <= 1.5 ? 'efficient' : ($time_per_item <= 2.5 ? 'moderate' : 'inefficient')),
            'memory_status' => ($peak_memory / get_memory_limit_bytes()) <= 0.05 ? 'very_low' : (($peak_memory / get_memory_limit_bytes()) <= 0.10 ? 'low' : (($peak_memory / get_memory_limit_bytes()) <= 0.20 ? 'moderate_low' : (($peak_memory / get_memory_limit_bytes()) <= 0.30 ? 'moderate' : (($peak_memory / get_memory_limit_bytes()) <= 0.50 ? 'moderate_high' : 'high'))))
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Failed to update batch metrics', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage(),
            'time_elapsed' => $time_elapsed,
            'processed_count' => $processed_count,
            'batch_size' => $batch_size
        ]);
        // Continue execution even if metrics update fails
    }
}