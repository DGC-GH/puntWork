<?php
/**
 * Batch size management utilities
 *
 * @package    Puntwork
 * @subpackage Batch
 * @since      1.0.0
 */

namespace Puntwork;

// Include options utilities
require_once plugin_dir_path(__FILE__) . '../utilities/options-utilities.php';

/**
 * Batch size management and performance optimization
 * Handles dynamic batch size adjustments based on memory and time metrics
 */

// Batch size configuration constants
const DEFAULT_BATCH_SIZE = 5;
const MAX_BATCH_SIZE = 100;
const MIN_BATCH_SIZE = 1;

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

        // Conservative bounds: start small and grow gradually, max 100 for stability
        $batch_size = max(MIN_BATCH_SIZE, min(MAX_BATCH_SIZE, $batch_size)); // Maximum of 100 for stability

        // Conservative oscillation prevention for stable scaling
        $max_change_factor = 2.0; // Allow 2x increase per adjustment for controlled scaling
        if ($old_batch_size > 0) {
            $change_ratio = $batch_size / $old_batch_size;
            if ($change_ratio > $max_change_factor) {
                $batch_size = (int)($old_batch_size * $max_change_factor);
            } elseif ($change_ratio < (1 / $max_change_factor)) {
                $batch_size = max(1, (int)($old_batch_size / $max_change_factor));
            }
        }

        // Final bounds check after oscillation prevention
        $batch_size = max(MIN_BATCH_SIZE, min(MAX_BATCH_SIZE, $batch_size)); // Maximum of 100 for stability

        // Skip time-based and efficiency adjustments for first batch (no real metrics available)
        $has_previous_metrics = ($current_batch_time > 0 || $previous_batch_time > 0);
        $is_first_batch = (get_consecutive_batches() == 0);

        // Enhanced memory-based adjustment with tiered thresholds and absolute limits
        $memory_adjusted = false;
        $last_memory_bytes = $last_memory_ratio * $memory_limit_bytes;

        // Conservative absolute memory thresholds for stability
        $absolute_very_low_memory = 50 * 1024 * 1024; // 50MB
        $absolute_low_memory = 100 * 1024 * 1024;      // 100MB

        // Only reduce for extremely high memory usage - prioritize stability
        if ($last_memory_ratio > 0.95) { // Very high memory usage - reduce batch size
            $new_batch_size = max(DEFAULT_BATCH_SIZE, floor($batch_size * 0.8)); // Conservative reduction
            if ($new_batch_size < $batch_size) {
                $batch_size = $new_batch_size;
                $memory_adjusted = true;
                PuntWorkLogger::debug('Memory-based reduction: very high usage threshold', PuntWorkLogger::CONTEXT_BATCH, [
                    'memory_ratio' => $last_memory_ratio,
                    'memory_bytes' => $last_memory_bytes,
                    'threshold' => 0.95,
                    'old_batch_size' => $old_batch_size,
                    'new_batch_size' => $batch_size,
                    'reduction_factor' => 0.8
                ]);
            }
        } elseif ($last_memory_bytes < $absolute_very_low_memory || $last_memory_ratio < 0.15) {
            // Very low memory usage - conservative batch size increase
            $consecutive_batches = get_consecutive_batches();
            if ($consecutive_batches < 3) {
                // First 3 batches: 1.5x the batch size for gradual ramp-up
                $new_size = min(MAX_BATCH_SIZE, $batch_size * 1.5);
            } elseif ($consecutive_batches < 6) {
                // Next 3 batches: 1.3x increase
                $new_size = min(MAX_BATCH_SIZE, floor($batch_size * 1.3));
            } else {
                // After initial ramp-up: 1.2x increase
                $new_size = min(MAX_BATCH_SIZE, floor($batch_size * 1.2));
            }
            if ($new_size == $batch_size) {
                $new_size = $batch_size + 5; // Ensure modest increase
            }
            $batch_size = $new_size;
            $memory_adjusted = true;
            PuntWorkLogger::debug('Memory-based increase: very low usage threshold (conservative ramp-up)', PuntWorkLogger::CONTEXT_BATCH, [
                'memory_ratio' => $last_memory_ratio,
                'memory_bytes' => $last_memory_bytes,
                'absolute_threshold' => $absolute_very_low_memory,
                'percentage_threshold' => 0.15,
                'old_batch_size' => $old_batch_size,
                'new_batch_size' => $batch_size,
                'growth_factor' => $consecutive_batches < 3 ? 1.5 : ($consecutive_batches < 6 ? 1.3 : 1.2),
                'consecutive_batches' => $consecutive_batches,
                'trigger_type' => $last_memory_bytes < $absolute_very_low_memory ? 'absolute' : 'percentage'
            ]);
        } elseif ($last_memory_bytes < $absolute_low_memory || $last_memory_ratio < 0.25) {
            // Low memory usage - moderate batch size increase
            $new_size = min(MAX_BATCH_SIZE, floor($batch_size * 1.2));
            if ($new_size == $batch_size) {
                $new_size = $batch_size + 3; // Ensure modest increase
            }
            $batch_size = $new_size;
            $memory_adjusted = true;
            PuntWorkLogger::debug('Memory-based increase: low usage threshold (moderate)', PuntWorkLogger::CONTEXT_BATCH, [
                'memory_ratio' => $last_memory_ratio,
                'memory_bytes' => $last_memory_bytes,
                'absolute_threshold' => $absolute_low_memory,
                'percentage_threshold' => 0.25,
                'old_batch_size' => $old_batch_size,
                'new_batch_size' => $batch_size,
                'growth_factor' => 1.2,
                'trigger_type' => $last_memory_bytes < $absolute_low_memory ? 'absolute' : 'percentage'
            ]);
        } elseif ($last_memory_ratio < 0.40) {
            // Moderate low memory usage - slight batch size increase
            $new_size = min(MAX_BATCH_SIZE, floor($batch_size * 1.1));
            if ($new_size == $batch_size) {
                $new_size = $batch_size + 2; // Ensure small increase
            }
            $batch_size = $new_size;
            $memory_adjusted = true;
            PuntWorkLogger::debug('Memory-based increase: moderate low usage threshold (slight)', PuntWorkLogger::CONTEXT_BATCH, [
                'memory_ratio' => $last_memory_ratio,
                'memory_bytes' => $last_memory_bytes,
                'percentage_threshold' => 0.40,
                'old_batch_size' => $old_batch_size,
                'new_batch_size' => $batch_size,
                'growth_factor' => 1.1
            ]);
        }

        // Optimized time-based adjustments - focus on stability
        $time_adjusted = false;
        if ($current_batch_time > 1800) { // 30-minute threshold
            $batch_size = max(DEFAULT_BATCH_SIZE, (int)($batch_size * 0.9)); // Conservative reduction
            $time_adjusted = true;
            PuntWorkLogger::info('Time-based batch size reduction applied', PuntWorkLogger::CONTEXT_BATCH, [
                'current_batch_time' => $current_batch_time,
                'threshold' => 1800,
                'old_batch_size' => $old_batch_size,
                'new_batch_size' => $batch_size
            ]);
        }

        // Efficiency optimization - reward fast processing, penalize slow processing
        // Only apply if we have real metrics from previous batches
        if (!$time_adjusted && $has_previous_metrics && !$is_first_batch && $batch_size > 0) {
            $time_per_item = $current_batch_time / $batch_size;

            // Enhanced performance thresholds for more aggressive optimization
            if ($time_per_item <= 0.5) { // Excellent performance - aggressive increase
                $new_size = min(MAX_BATCH_SIZE, floor($batch_size * 1.5));
                if ($new_size > $batch_size) {
                    $batch_size = $new_size;
                    PuntWorkLogger::debug('Efficiency-based increase: excellent performance (aggressive)', PuntWorkLogger::CONTEXT_BATCH, [
                        'time_per_item' => $time_per_item,
                        'threshold' => 0.5,
                        'old_batch_size' => $old_batch_size,
                        'new_batch_size' => $batch_size,
                        'growth_factor' => 1.5,
                        'performance_rating' => 'excellent'
                    ]);
                }
            } elseif ($time_per_item <= 1.0) { // Very good performance - moderate increase
                $new_size = min(MAX_BATCH_SIZE, floor($batch_size * 1.3));
                if ($new_size > $batch_size) {
                    $batch_size = $new_size;
                    PuntWorkLogger::debug('Efficiency-based increase: very good performance', PuntWorkLogger::CONTEXT_BATCH, [
                        'time_per_item' => $time_per_item,
                        'threshold' => 1.0,
                        'old_batch_size' => $old_batch_size,
                        'new_batch_size' => $batch_size,
                        'growth_factor' => 1.3,
                        'performance_rating' => 'very_good'
                    ]);
                }
            } elseif ($time_per_item <= 1.5) { // Good performance - slight increase
                $new_size = min(MAX_BATCH_SIZE, floor($batch_size * 1.2));
                if ($new_size > $batch_size) {
                    $batch_size = $new_size;
                    PuntWorkLogger::debug('Efficiency-based increase: good performance', PuntWorkLogger::CONTEXT_BATCH, [
                        'time_per_item' => $time_per_item,
                        'threshold' => 1.5,
                        'old_batch_size' => $old_batch_size,
                        'new_batch_size' => $batch_size,
                        'growth_factor' => 1.2,
                        'performance_rating' => 'good'
                    ]);
                }
            } elseif ($time_per_item <= 2.0) { // Moderate performance - minimal increase
                $new_size = min(MAX_BATCH_SIZE, floor($batch_size * 1.1));
                if ($new_size > $batch_size) {
                    $batch_size = $new_size;
                    PuntWorkLogger::debug('Efficiency-based increase: moderate performance', PuntWorkLogger::CONTEXT_BATCH, [
                        'time_per_item' => $time_per_item,
                        'threshold' => 2.0,
                        'old_batch_size' => $old_batch_size,
                        'new_batch_size' => $batch_size,
                        'growth_factor' => 1.1,
                        'performance_rating' => 'moderate'
                    ]);
                }
            } elseif ($time_per_item > 3.0 && $time_per_item <= 4.0) { // Poor performance - slight reduction
                $batch_size = max(DEFAULT_BATCH_SIZE, (int)($batch_size * 0.95));
                PuntWorkLogger::info('Efficiency-based batch size reduction: poor performance', PuntWorkLogger::CONTEXT_BATCH, [
                    'time_per_item' => $time_per_item,
                    'threshold' => 3.0,
                    'old_batch_size' => $old_batch_size,
                    'new_batch_size' => $batch_size,
                    'reduction_factor' => 0.95,
                    'performance_rating' => 'poor'
                ]);
            } elseif ($time_per_item > 4.0) { // Very poor performance - moderate reduction
                $batch_size = max(DEFAULT_BATCH_SIZE, (int)($batch_size * 0.85));
                PuntWorkLogger::info('Efficiency-based batch size reduction: very poor performance', PuntWorkLogger::CONTEXT_BATCH, [
                    'time_per_item' => $time_per_item,
                    'threshold' => 4.0,
                    'old_batch_size' => $old_batch_size,
                    'new_batch_size' => $batch_size,
                    'reduction_factor' => 0.85,
                    'performance_rating' => 'very_poor'
                ]);
            }
        }

        // Dynamic batch size adjustment based on consecutive batch completion times
        // Only apply time-based adjustments if memory, time, and efficiency didn't trigger a change
        if (!$memory_adjusted && !$time_adjusted && $previous_batch_time > 0 && $current_batch_time > 0) {
            $time_adjusted = false;
            $time_ratio = $current_batch_time / $previous_batch_time;

            if ($current_batch_time > $previous_batch_time * 1.5) {
                // Current batch significantly slower - reduce moderately
                $new_batch_size = max(DEFAULT_BATCH_SIZE, floor($batch_size * 0.95));
                if ($new_batch_size < $batch_size) {
                    $batch_size = $new_batch_size;
                    $time_adjusted = true;
                    PuntWorkLogger::debug('Time-based batch size adjustment: slower than previous', PuntWorkLogger::CONTEXT_BATCH, [
                        'previous_batch_time' => $previous_batch_time,
                        'current_batch_time' => $current_batch_time,
                        'time_ratio' => $time_ratio,
                        'threshold_ratio' => 1.5,
                        'old_batch_size' => $old_batch_size,
                        'new_batch_size' => $batch_size
                    ]);
                }
            } elseif ($current_batch_time < $previous_batch_time * 0.7) {
                // Current batch significantly faster - increase modestly
                $new_size = min(MAX_BATCH_SIZE, floor($batch_size * 1.2));
                if ($new_size == $batch_size) {
                    $new_size = $batch_size + 2;
                }
                $batch_size = $new_size;
                $time_adjusted = true;
                PuntWorkLogger::debug('Time-based batch size adjustment: faster than previous', PuntWorkLogger::CONTEXT_BATCH, [
                    'previous_batch_time' => $previous_batch_time,
                    'current_batch_time' => $current_batch_time,
                    'time_ratio' => $time_ratio,
                    'threshold_ratio' => 0.7,
                    'old_batch_size' => $old_batch_size,
                    'new_batch_size' => $batch_size
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

        // Adaptive learning: track consecutive successful batches for gradual ramp-up
        $consecutive_batches = get_consecutive_batches();
        if (!$time_adjusted && !$memory_adjusted && $batch_size < MAX_BATCH_SIZE) {
            // No adjustments needed - this batch was successful
            $consecutive_batches++;
            set_consecutive_batches($consecutive_batches);

            // Conservative ramp-up for stability
            if ($consecutive_batches <= 5) {
                // First 5 successful batches: 1.2x growth for gradual scaling
                $new_size = min(MAX_BATCH_SIZE, floor($batch_size * 1.2));
                if ($new_size > $batch_size) {
                    $batch_size = $new_size;
                    PuntWorkLogger::debug('Adaptive learning: gradual ramp-up (1.2x)', PuntWorkLogger::CONTEXT_BATCH, [
                        'consecutive_batches' => $consecutive_batches,
                        'old_batch_size' => $old_batch_size,
                        'new_batch_size' => $batch_size,
                        'growth_factor' => 1.2
                    ]);
                }
            } elseif ($consecutive_batches <= 10) {
                // Next 5 successful batches: 1.15x growth to continue scaling
                $new_size = min(MAX_BATCH_SIZE, floor($batch_size * 1.15));
                if ($new_size > $batch_size) {
                    $batch_size = $new_size;
                    PuntWorkLogger::debug('Adaptive learning: moderate ramp-up (1.15x)', PuntWorkLogger::CONTEXT_BATCH, [
                        'consecutive_batches' => $consecutive_batches,
                        'old_batch_size' => $old_batch_size,
                        'new_batch_size' => $batch_size,
                        'growth_factor' => 1.15
                    ]);
                }
            } elseif ($consecutive_batches % 10 == 0) {
                // Every 10th successful batch beyond 10: modest 1.1x growth to maintain optimization
                $new_size = min(MAX_BATCH_SIZE, floor($batch_size * 1.1));
                if ($new_size > $batch_size) {
                    $batch_size = $new_size;
                    PuntWorkLogger::debug('Adaptive learning: maintenance ramp-up (1.1x)', PuntWorkLogger::CONTEXT_BATCH, [
                        'consecutive_batches' => $consecutive_batches,
                        'old_batch_size' => $old_batch_size,
                        'new_batch_size' => $batch_size,
                        'growth_factor' => 1.1
                    ]);
                }
            }
        } else {
            // Batch had issues - reset consecutive counter
            set_consecutive_batches(0);
        }

        // Minimum batch size recovery mechanism - simplified to prevent oscillations
        if ($batch_size <= 2) {
            try {
                $consecutive_small_batches = get_consecutive_small_batches();

                // Only attempt recovery if we have consistent small batches AND good memory conditions
                if ($consecutive_small_batches >= 5 && $last_memory_ratio < 0.25) {
                    if ($batch_size === 1) {
                        $batch_size = 2;
                    } elseif ($batch_size === 2) {
                        $batch_size = 5; // Jump to a more stable size
                    }

                    // Reset counter on successful recovery
                    retry_option_operation(function() {
                        return set_consecutive_small_batches(0);
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
                        return set_consecutive_small_batches($consecutive_small_batches + 1);
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
                    return set_consecutive_small_batches(0);
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

        // Ensure batch size never goes below 1 or above 100
        $batch_size = max(1, min(100, $batch_size));

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
                $time_per_item = $batch_size > 0 ? $current_batch_time / $batch_size : 0,
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
            } elseif ($has_previous_metrics && !$is_first_batch && $batch_size > 0 && ($current_batch_time / $batch_size) > 3.0) {
                $reason = 'low processing efficiency';
                $detailed_reason = 'processing efficiency below 3.0 seconds per item - reducing batch size';
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
        $previous_batch_time = get_last_batch_time();
        retry_option_operation(function() use ($previous_batch_time) {
            return set_previous_batch_time($previous_batch_time);
        }, [$previous_batch_time], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_previous_batch_time'
        ]);

        // Update stored metrics for next batch
        $time_per_item = $processed_count > 0 ? $time_elapsed / $processed_count : 0;
        $prev_time_per_item = get_time_per_job();
        retry_option_operation(function() use ($time_per_item) {
            return set_time_per_job($time_per_item);
        }, [$time_per_item], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_time_per_job'
        ]);

        $peak_memory = memory_get_peak_usage(true);
        retry_option_operation(function() use ($peak_memory) {
            return set_last_peak_memory($peak_memory);
        }, [$peak_memory], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_peak_memory'
        ]);

        // Use rolling average for time_per_item to stabilize adjustments
        $avg_time_per_item = get_avg_time_per_job($time_per_item);
        $avg_time_per_item = ($avg_time_per_item * 0.7) + ($time_per_item * 0.3);
        retry_option_operation(function() use ($avg_time_per_item) {
            return set_avg_time_per_job($avg_time_per_item);
        }, [$avg_time_per_item], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_avg_time_per_job'
        ]);

        retry_option_operation(function() use ($batch_size) {
            return set_batch_size($batch_size);
        }, [$batch_size], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_batch_size_metric'
        ]);

        // Track consecutive small batches for recovery mechanism
        if ($batch_size <= 3) {
            $consecutive = get_consecutive_small_batches() + 1;
            retry_option_operation(function() use ($consecutive) {
                return set_consecutive_small_batches($consecutive);
            }, [$consecutive], [
                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                'operation' => 'update_consecutive_small_batches'
            ]);
        } else {
            retry_option_operation(function() {
                return set_consecutive_small_batches(0);
            }, [], [
                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                'operation' => 'reset_consecutive_small_batches_metric'
            ]);
        }

        // Increment consecutive batches counter for aggressive ramp-up logic
        $consecutive_batches = get_consecutive_batches() + 1;
        retry_option_operation(function() use ($consecutive_batches) {
            return set_consecutive_batches($consecutive_batches);
        }, [$consecutive_batches], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'increment_consecutive_batches'
        ]);

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
            'efficiency_status' => $time_per_item <= 1.5 ? 'very_efficient' : ($time_per_item <= 2.0 ? 'efficient' : ($time_per_item <= 3.0 ? 'moderate' : 'inefficient')),
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

/**
 * Initialize import status with standardized structure.
 *
 * @param int $total Total number of items to process (0 for unknown/in-progress).
 * @param string $initial_message Initial log message for the import.
 * @param float|null $start_time Optional start time (defaults to current microtime).
 * @return array Standardized import status array.
 */
function initialize_import_status($total = 0, $initial_message = 'Import started', $start_time = null) {
    if ($start_time === null) {
        $start_time = microtime(true);
    }

    return [
        'total' => $total,
        'processed' => 0,
        'published' => 0,
        'updated' => 0,
        'skipped' => 0,
        'duplicates_drafted' => 0,
        'time_elapsed' => 0,
        'complete' => false,
        'success' => false,
        'error_message' => '',
        'batch_size' => get_batch_size(),
        'inferred_languages' => 0,
        'inferred_benefits' => 0,
        'schema_generated' => 0,
        'start_time' => $start_time,
        'end_time' => null,
        'last_update' => time(),
        'logs' => [$initial_message],
    ];
}