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

    // Memory-based adjustment
    if ($last_memory_ratio > 0.85) {
        $batch_size = max(1, floor($batch_size * 0.7));
    } elseif ($last_memory_ratio < 0.5 && $avg_time_per_item < 1.0) {
        $batch_size = min(50, floor($batch_size * 1.5));
    }

    // Time-based adjustment
    if ($prev_time_per_item > 0) {
        $time_ratio = $avg_time_per_item / $prev_time_per_item;
        if ($time_ratio > 1.2) {
            $batch_size = max(1, floor($batch_size / (1 + ($time_ratio - 1) * 0.5)));
        } elseif ($time_ratio < 0.8) {
            $batch_size = min(50, floor($batch_size * (1 + (1 - $time_ratio) * 1.5)));
        }
    }

    return $batch_size;
}

/**
 * Get memory limit in bytes.
 *
 * @return int Memory limit in bytes.
 */
function get_memory_limit_bytes() {
    $memory_limit = ini_get('memory_limit');
    if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
        $value = (int)$matches[1];
        $unit = strtolower($matches[2]);
        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }
        return $value;
    }
    return 134217728; // Default 128MB
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
}