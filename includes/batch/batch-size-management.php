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
        $batch_size = max(1, min(500, $batch_size)); // Enforce absolute bounds

        // Prevent rapid oscillations by limiting change magnitude
        $max_change_factor = 2.0; // Maximum 2x increase or 0.5x decrease per adjustment
        if ($original_batch_size > 0) {
            $change_ratio = $batch_size / $original_batch_size;
            if ($change_ratio > $max_change_factor) {
                $batch_size = (int)($original_batch_size * $max_change_factor);
            } elseif ($change_ratio < (1 / $max_change_factor)) {
                $batch_size = max(1, (int)($original_batch_size / $max_change_factor));
            }
        }

        // Final bounds check after oscillation prevention
        $batch_size = max(1, min(500, $batch_size));

        // Memory-based adjustment (most critical) - with damping to prevent oscillations
        $memory_adjusted = false;
        if ($last_memory_ratio > 0.85) {
            // High memory usage - reduce batch size significantly
            $new_batch_size = max(1, floor($batch_size * 0.6));
            if ($new_batch_size < $batch_size) {
                $batch_size = $new_batch_size;
                $memory_adjusted = true;
            }
        } elseif ($last_memory_ratio > 0.75) {
            // Moderate high memory - reduce slightly
            $new_batch_size = max(1, floor($batch_size * 0.8));
            if ($new_batch_size < $batch_size) {
                $batch_size = $new_batch_size;
                $memory_adjusted = true;
            }
        } elseif ($last_memory_ratio < 0.4) {
            // Low memory usage - gradually increase batch size
            $new_size = floor($batch_size * 1.2);
            if ($new_size == $batch_size) {
                $new_size = $batch_size + 1; // Ensure at least +1 if multiplier doesn't change
            }
            $batch_size = min(500, $new_size);
            $memory_adjusted = true;
        }

        // Dynamic batch size adjustment based on consecutive batch completion times
        // Only apply time-based adjustments if memory didn't trigger a change
        if (!$memory_adjusted && $previous_batch_time > 0 && $current_batch_time > 0) {
            $time_adjusted = false;
            if ($current_batch_time > $previous_batch_time * 1.2) {
                // Current batch took significantly longer (>20%) - decrease batch size moderately
                $new_batch_size = max(1, floor($batch_size * 0.9));
                if ($new_batch_size < $batch_size) {
                    $batch_size = $new_batch_size;
                    $time_adjusted = true;
                }
            } elseif ($current_batch_time < $previous_batch_time * 0.8) {
                // Current batch took significantly less time (<80%) - gradually increase batch size
                $new_size = floor($batch_size * 1.1);
                if ($new_size == $batch_size) {
                    $new_size = $batch_size + 1; // Ensure at least +1 if multiplier doesn't change
                }
                $batch_size = min(500, $new_size);
                $time_adjusted = true;
            }

            // Log time-based adjustments
            if ($time_adjusted) {
                PuntWorkLogger::debug('Time-based batch size adjustment applied', PuntWorkLogger::CONTEXT_BATCH, [
                    'previous_batch_time' => $previous_batch_time,
                    'current_batch_time' => $current_batch_time,
                    'ratio' => $current_batch_time / $previous_batch_time,
                    'new_batch_size' => $batch_size
                ]);
            }
        }

        // Minimum batch size recovery mechanism - simplified to prevent oscillations
        if ($batch_size <= 2) {
            try {
                $consecutive_small_batches = get_option('job_import_consecutive_small_batches', 0);

                // Only attempt recovery if we have consistent small batches AND good memory conditions
                if ($consecutive_small_batches >= 5 && $last_memory_ratio < 0.6) {
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

        // Ensure batch size never goes below 1 or above 500
        $batch_size = max(1, min(500, $batch_size));

        // Log batch size changes for debugging
        if ($batch_size != $old_batch_size) {
            $reason = '';
            if ($last_memory_ratio > 0.85) {
                $reason = 'high memory usage';
            } elseif ($last_memory_ratio > 0.75) {
                $reason = 'moderate high memory usage';
            } elseif ($last_memory_ratio < 0.4) {
                $reason = 'low memory usage';
            } elseif ($previous_batch_time > 0 && $current_batch_time > 0) {
                if ($current_batch_time > $previous_batch_time) {
                    $reason = 'current batch slower than previous';
                } elseif ($current_batch_time < $previous_batch_time) {
                    $reason = 'current batch faster than previous';
                }
            }

            if (empty($reason) && $batch_size === 1) {
                $reason = 'minimum batch size recovery attempt';
            }

            // Add detailed log message for user-visible logs
            $detailed_reason = '';
            if ($last_memory_ratio > 0.85) {
                $detailed_reason = 'high memory usage detected';
            } elseif ($last_memory_ratio > 0.75) {
                $detailed_reason = 'moderate memory usage detected';
            } elseif ($last_memory_ratio < 0.4) {
                $detailed_reason = 'low memory usage allows larger batches';
            } elseif ($previous_batch_time > 0 && $current_batch_time > 0) {
                if ($current_batch_time > $previous_batch_time) {
                    $detailed_reason = 'current batch took longer than previous - decreasing batch size';
                } elseif ($current_batch_time < $previous_batch_time) {
                    $detailed_reason = 'current batch took less time than previous - increasing batch size';
                }
            }

            if (empty($detailed_reason) && $batch_size === 1) {
                $detailed_reason = 'attempting recovery from minimum batch size';
            } elseif (empty($detailed_reason) && $batch_size === 2) {
                $detailed_reason = 'attempting recovery from small batch size';
            }

            PuntWorkLogger::info('Batch size adjusted', PuntWorkLogger::CONTEXT_BATCH, [
                'old_batch_size' => $old_batch_size,
                'new_batch_size' => $batch_size,
                'reason' => $reason,
                'memory_ratio' => $last_memory_ratio,
                'current_batch_time' => $current_batch_time,
                'previous_batch_time' => $previous_batch_time
            ]);

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
            'peak_memory' => $peak_memory
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