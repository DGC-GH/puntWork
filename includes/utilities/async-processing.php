<?php
/**
 * Async processing utilities using Action Scheduler
 *
 * @package    Puntwork
 * @subpackage Async
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Check if Action Scheduler is available
 *
 * @return bool True if Action Scheduler is available
 */
function is_action_scheduler_available(): bool {
    return function_exists('as_schedule_single_action') ||
           function_exists('as_schedule_recurring_action') ||
           class_exists('ActionScheduler');
}

/**
 * Schedule an async batch processing job
 *
 * @param array $batch_data Batch data to process
 * @param int $priority Priority (default: 10)
 * @return string|null Action ID if scheduled, null if failed
 */
function schedule_async_batch_job(array $batch_data, int $priority = 10): ?string {
    $hook = 'puntwork_process_batch_async';

    if (is_action_scheduler_available() && function_exists('as_schedule_single_action')) {
        // Use Action Scheduler if available (preferred)
        return as_schedule_single_action(time(), $hook, [$batch_data], 'puntwork', false, $priority);
    } elseif (function_exists('wp_schedule_single_event')) {
        // Fallback to WP-Cron
        $job_id = wp_generate_uuid4();
        $args = ['job_id' => $job_id, 'batch_data' => $batch_data];

        if (wp_schedule_single_event(time(), $hook, $args)) {
            return $job_id;
        }
    }

    return null;
}

/**
 * Schedule multiple async batch jobs for large imports
 *
 * @param string $json_path Path to JSONL file
 * @param int $total_items Total items to process
 * @param int $batch_size Size of each batch
 * @param array $setup Import setup data
 * @return array Array of job IDs scheduled
 */
function schedule_async_import_batches(string $json_path, int $total_items, int $batch_size, array $setup): array {
    $job_ids = [];
    $start_index = 0;

    while ($start_index < $total_items) {
        $end_index = min($start_index + $batch_size, $total_items);
        $batch_data = [
            'json_path' => $json_path,
            'start_index' => $start_index,
            'end_index' => $end_index,
            'total' => $total_items,
            'setup' => $setup,
            'batch_id' => wp_generate_uuid4()
        ];

        $job_id = schedule_async_batch_job($batch_data);
        if ($job_id) {
            $job_ids[] = $job_id;
        }

        $start_index = $end_index;
    }

    return $job_ids;
}

/**
 * Process a single async batch job
 *
 * @param array $batch_data Batch data from the scheduled job
 * @return array Processing result
 */
function process_async_batch_job(array $batch_data): array {
    try {
        $json_path = $batch_data['json_path'];
        $start_index = $batch_data['start_index'];
        $end_index = $batch_data['end_index'];
        $total = $batch_data['total'];
        $setup = $batch_data['setup'];
        $batch_id = $batch_data['batch_id'];

        // Update setup for this specific batch
        $setup['start_index'] = $start_index;
        $setup['total'] = $total;

        // Process the batch
        $result = process_batch_items_logic($setup);

        // Store batch result for aggregation
        $batch_results = get_option('puntwork_async_batch_results', []);
        $batch_results[$batch_id] = [
            'result' => $result,
            'processed_range' => [$start_index, $end_index],
            'timestamp' => time()
        ];
        update_option('puntwork_async_batch_results', $batch_results);

        return $result;

    } catch (\Exception $e) {
        error_log('[PUNTWORK] Async batch processing error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Async batch failed: ' . $e->getMessage(),
            'batch_id' => $batch_data['batch_id'] ?? null
        ];
    }
}

/**
 * Aggregate results from multiple async batch jobs
 *
 * @param array $job_ids Array of job IDs to aggregate
 * @return array Aggregated results
 */
function aggregate_async_batch_results(array $job_ids): array {
    $batch_results = get_option('puntwork_async_batch_results', []);
    $aggregated = [
        'success' => true,
        'total_processed' => 0,
        'total_published' => 0,
        'total_updated' => 0,
        'total_skipped' => 0,
        'total_duplicates_drafted' => 0,
        'total_time' => 0,
        'batches_completed' => 0,
        'batches_total' => count($job_ids),
        'logs' => [],
        'errors' => []
    ];

    foreach ($job_ids as $job_id) {
        if (isset($batch_results[$job_id])) {
            $batch_result = $batch_results[$job_id]['result'];

            if ($batch_result['success']) {
                $aggregated['total_processed'] += $batch_result['processed'] ?? 0;
                $aggregated['total_published'] += $batch_result['published'] ?? 0;
                $aggregated['total_updated'] += $batch_result['updated'] ?? 0;
                $aggregated['total_skipped'] += $batch_result['skipped'] ?? 0;
                $aggregated['total_duplicates_drafted'] += $batch_result['duplicates_drafted'] ?? 0;
                $aggregated['total_time'] += $batch_result['batch_time'] ?? 0;
                $aggregated['batches_completed']++;

                if (isset($batch_result['logs']) && is_array($batch_result['logs'])) {
                    $aggregated['logs'] = array_merge($aggregated['logs'], $batch_result['logs']);
                }
            } else {
                $aggregated['success'] = false;
                $aggregated['errors'][] = $batch_result['message'] ?? 'Unknown batch error';
            }
        }
    }

    // Clean up old batch results (keep only last 24 hours)
    $cutoff_time = time() - (24 * 60 * 60);
    $filtered_results = array_filter($batch_results, function($result) use ($cutoff_time) {
        return ($result['timestamp'] ?? 0) > $cutoff_time;
    });
    update_option('puntwork_async_batch_results', $filtered_results);

    $aggregated['complete'] = ($aggregated['batches_completed'] === $aggregated['batches_total']);

    return $aggregated;
}

/**
 * Check if async processing is enabled and available
 *
 * @return bool True if async processing can be used
 */
function is_async_processing_enabled(): bool {
    // Check if explicitly disabled
    if (defined('PUNTWORK_DISABLE_ASYNC') && PUNTWORK_DISABLE_ASYNC) {
        return false;
    }

    // Check if Action Scheduler or WP-Cron is available
    return is_action_scheduler_available() || function_exists('wp_schedule_single_event');
}

/**
 * Get recommended batch size for async processing
 *
 * @param int $total_items Total items to process
 * @return int Recommended batch size
 */
function get_async_batch_size(int $total_items): int {
    $memory_limit = get_memory_limit_bytes();
    $base_batch_size = 50; // Conservative starting point

    // Adjust based on memory availability
    if ($memory_limit >= 256 * 1024 * 1024) { // 256MB+
        $base_batch_size = 100;
    } elseif ($memory_limit >= 128 * 1024 * 1024) { // 128MB+
        $base_batch_size = 75;
    }

    // For very large imports, use smaller batches to prevent timeouts
    if ($total_items > 10000) {
        $base_batch_size = max(25, $base_batch_size / 2);
    } elseif ($total_items > 5000) {
        $base_batch_size = max(35, $base_batch_size * 0.75);
    }

    return (int) $base_batch_size;
}

/**
 * Initialize async processing hooks
 */
function init_async_processing(): void {
    // Hook for Action Scheduler / WP-Cron
    add_action('puntwork_process_batch_async', __NAMESPACE__ . '\\handle_async_batch_job', 10, 1);

    // Hook for WP-Cron fallback
    add_action('puntwork_process_batch_async', __NAMESPACE__ . '\\handle_async_batch_job_cron', 10, 1);
}

/**
 * Handle async batch job (Action Scheduler)
 *
 * @param array $batch_data Batch data
 */
function handle_async_batch_job(array $batch_data): void {
    process_async_batch_job($batch_data);
}

/**
 * Prepare feeds for import (create JSONL file)
 *
 * @return array Result of feed preparation
 */
function prepare_feeds_for_import(): array {
    try {
        // Use existing feed processing logic
        if (!function_exists('process_feeds_to_jsonl')) {
            return [
                'success' => false,
                'message' => 'Feed processing function not available'
            ];
        }

        $result = process_feeds_to_jsonl();

        if (!$result['success']) {
            return $result;
        }

        $jsonl_path = ABSPATH . 'feeds/combined-jobs.jsonl';
        if (!file_exists($jsonl_path)) {
            return [
                'success' => false,
                'message' => 'JSONL file was not created'
            ];
        }

        $total_items = get_json_item_count($jsonl_path);

        return [
            'success' => true,
            'jsonl_path' => $jsonl_path,
            'total_items' => $total_items,
            'message' => "Prepared {$total_items} items for import"
        ];

    } catch (\Exception $e) {
        return [
            'success' => false,
            'message' => 'Failed to prepare feeds: ' . $e->getMessage()
        ];
    }
}

/**
 * Check status of async import jobs
 *
 * @return array Current status of async import
 */
function check_async_import_status(): array {
    $async_jobs = get_option('puntwork_async_import_jobs', []);
    $import_status = get_option('job_import_status', []);

    if (empty($async_jobs) || !isset($async_jobs['job_ids'])) {
        return [
            'active' => false,
            'status' => 'no_async_import',
            'message' => 'No active async import found'
        ];
    }

    $job_ids = $async_jobs['job_ids'];
    $aggregated_results = aggregate_async_batch_results($job_ids);

    // Update main import status with aggregated results
    if ($aggregated_results['complete']) {
        $import_status['processed'] = $aggregated_results['total_processed'];
        $import_status['published'] = $aggregated_results['total_published'];
        $import_status['updated'] = $aggregated_results['total_updated'];
        $import_status['skipped'] = $aggregated_results['total_skipped'];
        $import_status['duplicates_drafted'] = $aggregated_results['total_duplicates_drafted'];
        $import_status['complete'] = true;
        $import_status['success'] = $aggregated_results['success'];
        $import_status['time_elapsed'] = $aggregated_results['total_time'];
        $import_status['end_time'] = microtime(true);
        $import_status['logs'] = array_slice($aggregated_results['logs'], -50);

        if (!$aggregated_results['success']) {
            $import_status['error_message'] = implode('; ', $aggregated_results['errors']);
        }

        // Clean up async job tracking
        delete_option('puntwork_async_import_jobs');

        update_option('job_import_status', $import_status, false);
    }

    return [
        'active' => !$aggregated_results['complete'],
        'status' => $aggregated_results['complete'] ? 'completed' : 'running',
        'progress' => $aggregated_results,
        'message' => $aggregated_results['complete'] ?
            'Async import completed' : 'Async import in progress'
    ];
}

/**
 * Get async processing status information
 *
 * @return array Status information
 */
function get_async_processing_status(): array {
    $enabled = get_option('puntwork_async_enabled', true); // Default to enabled
    $available = is_async_processing_enabled();
    $action_scheduler = function_exists('as_schedule_single_action');

    return [
        'enabled' => $enabled,
        'available' => $available,
        'action_scheduler' => $action_scheduler,
        'fallback_cron' => function_exists('wp_schedule_single_event') && !$action_scheduler,
        'recommended_batch_size' => get_async_batch_size(1000), // Example for 1000 items
        'max_memory_mb' => get_memory_limit_bytes() / 1024 / 1024
    ];
}