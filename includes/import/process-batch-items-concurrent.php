<?php
/**
 * Concurrent batch item processing utilities
 *
 * @package    Puntwork
 * @subpackage Processing
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include retry utility
require_once plugin_dir_path(__FILE__) . '../utilities/retry-utility.php';
require_once plugin_dir_path(__FILE__) . '../utilities/database-utilities.php';

/**
 * Process batch items concurrently using Action Scheduler
 *
 * @param array $batch_guids Array of GUIDs in batch
 * @param array $batch_items Array of batch items
 * @param array $last_updates Last update timestamps by post ID
 * @param array $all_hashes_by_post Import hashes by post ID
 * @param array $acf_fields ACF fields to process
 * @param array $zero_empty_fields Fields that should be empty when value is '0'
 * @param array $post_ids_by_guid Post IDs indexed by GUID
 * @param array &$logs Reference to logs array
 * @param int &$updated Reference to updated count
 * @param int &$published Reference to published count
 * @param int &$skipped Reference to skipped count
 * @param int &$processed_count Reference to processed count
 * @return array Processing results with timing data
 */
function process_batch_items_concurrent($batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, &$logs, &$updated, &$published, &$skipped, &$processed_count) {
    $start_time = microtime(true);

    PuntWorkLogger::info('Starting concurrent item processing', PuntWorkLogger::CONTEXT_BATCH, [
        'batch_guids_count' => count($batch_guids),
        'batch_items_count' => count($batch_items),
        'concurrency_enabled' => true
    ]);

    $user_id = get_user_by('login', 'admin') ? get_user_by('login', 'admin')->ID : get_current_user_id();

    // Calculate optimal concurrency based on system resources
    $concurrency = calculate_optimal_concurrency(count($batch_guids));
    $batch_chunks = array_chunk($batch_guids, ceil(count($batch_guids) / $concurrency));

    PuntWorkLogger::debug('Concurrent processing setup', PuntWorkLogger::CONTEXT_BATCH, [
        'total_items' => count($batch_guids),
        'concurrency_level' => $concurrency,
        'chunks_count' => count($batch_chunks),
        'avg_chunk_size' => count($batch_guids) / $concurrency
    ]);

    $action_ids = [];
    $chunk_results = [];

    // Schedule concurrent processing for each chunk
    foreach ($batch_chunks as $chunk_index => $chunk_guids) {
        $action_id = as_schedule_single_action(
            time(),
            'puntwork_process_item_chunk',
            [
                'chunk_guids' => $chunk_guids,
                'batch_items' => array_intersect_key($batch_items, array_flip($chunk_guids)),
                'last_updates' => $last_updates,
                'all_hashes_by_post' => $all_hashes_by_post,
                'acf_fields' => $acf_fields,
                'zero_empty_fields' => $zero_empty_fields,
                'post_ids_by_guid' => array_intersect_key($post_ids_by_guid, array_flip($chunk_guids)),
                'user_id' => $user_id,
                'chunk_index' => $chunk_index,
                'total_chunks' => count($batch_chunks)
            ],
            'puntwork-import'
        );

        if ($action_id) {
            $action_ids[] = $action_id;
            PuntWorkLogger::debug('Scheduled concurrent chunk', PuntWorkLogger::CONTEXT_BATCH, [
                'chunk_index' => $chunk_index,
                'chunk_size' => count($chunk_guids),
                'action_id' => $action_id
            ]);
        } else {
            PuntWorkLogger::error('Failed to schedule concurrent chunk', PuntWorkLogger::CONTEXT_BATCH, [
                'chunk_index' => $chunk_index,
                'chunk_size' => count($chunk_guids)
            ]);
        }
    }

    // Wait for all chunks to complete with timeout
    $timeout = 300; // 5 minutes timeout
    $start_wait = microtime(true);
    $completed_actions = 0;
    $total_expected = count($action_ids);

    while ($completed_actions < $total_expected && (microtime(true) - $start_wait) < $timeout) {
        $completed_actions = 0;
        $chunk_results = [];

        foreach ($action_ids as $action_id) {
            $action = ActionScheduler::store()->fetch_action($action_id);
            if ($action && $action->get_status() === ActionScheduler_Store::STATUS_COMPLETE) {
                $completed_actions++;
                // Get the result from action args or transient
                $result_transient = get_transient('puntwork_chunk_result_' . $action_id);
                if ($result_transient) {
                    $chunk_results[] = $result_transient;
                    delete_transient('puntwork_chunk_result_' . $action_id);
                }
            } elseif ($action && in_array($action->get_status(), [ActionScheduler_Store::STATUS_FAILED, ActionScheduler_Store::STATUS_CANCELED])) {
                PuntWorkLogger::error('Concurrent chunk failed', PuntWorkLogger::CONTEXT_BATCH, [
                    'action_id' => $action_id,
                    'status' => $action->get_status()
                ]);
                $completed_actions++; // Count as completed even if failed
            }
        }

        if ($completed_actions < $total_expected) {
            sleep(1); // Wait 1 second before checking again
        }
    }

    // Process results from all chunks
    $item_timings = [];
    foreach ($chunk_results as $chunk_result) {
        if (isset($chunk_result['logs'])) {
            $logs = array_merge($logs, $chunk_result['logs']);
        }
        if (isset($chunk_result['updated'])) {
            $updated += $chunk_result['updated'];
        }
        if (isset($chunk_result['published'])) {
            $published += $chunk_result['published'];
        }
        if (isset($chunk_result['skipped'])) {
            $skipped += $chunk_result['skipped'];
        }
        if (isset($chunk_result['processed_count'])) {
            $processed_count += $chunk_result['processed_count'];
        }
        if (isset($chunk_result['item_timings'])) {
            $item_timings = array_merge($item_timings, $chunk_result['item_timings']);
        }
    }

    $total_time = microtime(true) - $start_time;
    $avg_time_per_item = !empty($item_timings) ? array_sum($item_timings) / count($item_timings) : 0;

    PuntWorkLogger::info('Concurrent item processing completed', PuntWorkLogger::CONTEXT_BATCH, [
        'total_processed' => $processed_count,
        'published' => $published,
        'updated' => $updated,
        'skipped' => $skipped,
        'total_time' => $total_time,
        'avg_time_per_item' => $avg_time_per_item,
        'concurrency_level' => $concurrency,
        'chunks_completed' => count($chunk_results),
        'timeout_exceeded' => (microtime(true) - $start_wait) >= $timeout
    ]);

    return [
        'processed_count' => $processed_count,
        'total_time' => $total_time,
        'avg_time_per_item' => $avg_time_per_item,
        'item_timings' => $item_timings,
        'concurrency_used' => $concurrency
    ];
}

/**
 * Calculate optimal concurrency level based on system resources and batch size
 *
 * @param int $batch_size Number of items in batch
 * @return int Optimal concurrency level
 */
function calculate_optimal_concurrency($batch_size) {
    // Base concurrency on available memory and CPU cores
    $memory_limit = get_memory_limit_bytes();
    $memory_usage = memory_get_usage(true);
    $available_memory = $memory_limit - $memory_usage;

    // Estimate memory per concurrent process (conservative estimate)
    $memory_per_process = 8 * 1024 * 1024; // 8MB per process
    $memory_based_concurrency = max(1, floor($available_memory / $memory_per_process));

    // CPU-based concurrency (use number of CPU cores)
    $cpu_cores = function_exists('shell_exec') ? (int) shell_exec('nproc 2>/dev/null') : 2;
    $cpu_based_concurrency = max(1, $cpu_cores);

    // Batch size based concurrency
    $batch_based_concurrency = max(1, min(10, ceil($batch_size / 5))); // At least 5 items per concurrent process

    // Take the minimum of all constraints
    $optimal_concurrency = min($memory_based_concurrency, $cpu_based_concurrency, $batch_based_concurrency);

    // Cap at reasonable maximum
    $optimal_concurrency = min($optimal_concurrency, 20);

    PuntWorkLogger::debug('Calculated optimal concurrency', PuntWorkLogger::CONTEXT_BATCH, [
        'batch_size' => $batch_size,
        'memory_based' => $memory_based_concurrency,
        'cpu_based' => $cpu_based_concurrency,
        'batch_based' => $batch_based_concurrency,
        'optimal' => $optimal_concurrency,
        'available_memory_mb' => $available_memory / (1024 * 1024),
        'cpu_cores' => $cpu_cores
    ]);

    return $optimal_concurrency;
}

/**
 * Action Scheduler callback for processing a chunk of items concurrently
 */
function process_item_chunk_callback($chunk_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, $user_id, $chunk_index, $total_chunks) {
    $chunk_start_time = microtime(true);
    $item_timings = [];

    PuntWorkLogger::debug('Processing concurrent chunk', PuntWorkLogger::CONTEXT_BATCH, [
        'chunk_index' => $chunk_index,
        'total_chunks' => $total_chunks,
        'chunk_size' => count($chunk_guids)
    ]);

    $logs = [];
    $updated = 0;
    $published = 0;
    $skipped = 0;
    $processed_count = 0;

    foreach ($chunk_guids as $guid) {
        $item_start_time = microtime(true);

        try {
            $item = $batch_items[$guid]['item'] ?? null;
            if (!$item) {
                PuntWorkLogger::warning('Item not found in batch', PuntWorkLogger::CONTEXT_BATCH, [
                    'guid' => $guid,
                    'chunk_index' => $chunk_index
                ]);
                $skipped++;
                $processed_count++;
                continue;
            }

            $xml_updated = isset($item['updated']) ? $item['updated'] : '';
            $xml_updated_ts = strtotime($xml_updated);
            $post_id = isset($post_ids_by_guid[$guid]) ? $post_ids_by_guid[$guid] : null;

            // If post exists, check if it needs updating
            if ($post_id) {
                try {
                    // First, ensure the job is published if it's in the feed
                    $current_post = get_post($post_id);
                    if ($current_post && $current_post->post_status !== 'publish') {
                        // Republish immediately
                        $update_result = retry_database_operation(function() use ($post_id) {
                            return wp_update_post([
                                'ID' => $post_id,
                                'post_status' => 'publish'
                            ]);
                        }, [$post_id], [
                            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                            'operation' => 'republish_post_concurrent',
                            'post_id' => $post_id,
                            'guid' => $guid,
                            'chunk_index' => $chunk_index
                        ]);

                        if (is_wp_error($update_result)) {
                            PuntWorkLogger::error('Failed to republish post in concurrent chunk', PuntWorkLogger::CONTEXT_BATCH, [
                                'post_id' => $post_id,
                                'guid' => $guid,
                                'chunk_index' => $chunk_index,
                                'error' => $update_result->get_error_message()
                            ]);
                            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to republish ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $update_result->get_error_message();
                        } else {
                            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Republished ID: ' . $post_id . ' GUID: ' . $guid . ' - Found in active feed';
                        }
                    }

                    // Update meta immediately
                    $current_time = current_time('mysql');
                    update_post_meta($post_id, '_last_import_update', $current_time);
                    update_post_meta($post_id, 'guid', $guid);

                    $current_hash = $all_hashes_by_post[$post_id] ?? '';
                    $item_hash = md5(json_encode($item));

                    // Skip if content hasn't changed (temporarily disabled for full processing)
                    // if ($current_hash === $item_hash) {
                    //     $skipped++;
                    //     $processed_count++;
                    //     continue;
                    // }

                    // Update post immediately
                    $error_message = '';
                    $update_result = update_job_post($post_id, $item, $acf_fields, $zero_empty_fields, $logs, $error_message);
                    if (is_wp_error($update_result)) {
                        PuntWorkLogger::error('Failed to update post in concurrent chunk', PuntWorkLogger::CONTEXT_BATCH, [
                            'post_id' => $post_id,
                            'guid' => $guid,
                            'chunk_index' => $chunk_index,
                            'error' => $error_message
                        ]);
                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to update ID: ' . $post_id . ' - ' . $error_message;
                    } else {
                        $updated++;
                    }

                } catch (\Exception $e) {
                    PuntWorkLogger::error('Error processing existing post in concurrent chunk', PuntWorkLogger::CONTEXT_BATCH, [
                        'post_id' => $post_id,
                        'guid' => $guid,
                        'chunk_index' => $chunk_index,
                        'error' => $e->getMessage()
                    ]);
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Error processing existing post ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $e->getMessage();
                    $skipped++;
                    $processed_count++;
                    continue;
                }

            } else {
                // Create new post immediately
                $error_message = '';
                $create_result = create_job_post($item, $acf_fields, $zero_empty_fields, $user_id, $logs, $error_message);
                if (is_wp_error($create_result)) {
                    PuntWorkLogger::error('Failed to create post in concurrent chunk', PuntWorkLogger::CONTEXT_BATCH, [
                        'guid' => $guid,
                        'chunk_index' => $chunk_index,
                        'error' => $error_message
                    ]);
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to create GUID: ' . $guid . ' - ' . $error_message;
                } else {
                    $published++;
                }
            }

            $processed_count++;
            $item_time = microtime(true) - $item_start_time;
            $item_timings[] = $item_time;

        } catch (\Exception $e) {
            PuntWorkLogger::error('Critical error processing item in concurrent chunk', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'chunk_index' => $chunk_index,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Critical error processing GUID: ' . $guid . ' - ' . $e->getMessage();
            $skipped++;
            $processed_count++;
            continue;
        }
    }

    $chunk_time = microtime(true) - $chunk_start_time;
    $avg_item_time = !empty($item_timings) ? array_sum($item_timings) / count($item_timings) : 0;

    PuntWorkLogger::debug('Concurrent chunk completed', PuntWorkLogger::CONTEXT_BATCH, [
        'chunk_index' => $chunk_index,
        'processed_count' => $processed_count,
        'published' => $published,
        'updated' => $updated,
        'skipped' => $skipped,
        'chunk_time' => $chunk_time,
        'avg_item_time' => $avg_item_time,
        'total_items' => count($chunk_guids)
    ]);

    // Store results in transient for the main process to collect
    $result = [
        'chunk_index' => $chunk_index,
        'logs' => $logs,
        'updated' => $updated,
        'published' => $published,
        'skipped' => $skipped,
        'processed_count' => $processed_count,
        'item_timings' => $item_timings,
        'chunk_time' => $chunk_time,
        'avg_item_time' => $avg_item_time
    ];

    // Get the action ID from the current action
    $current_action = current_action();
    if ($current_action) {
        set_transient('puntwork_chunk_result_' . $current_action->get_schedule()->get_action_id(), $result, 3600); // 1 hour expiry
    }
}

// Hook the callback function
add_action('puntwork_process_item_chunk', 'Puntwork\process_item_chunk_callback', 10, 10);

/**
 * Enhanced batch metrics update with concurrent processing data
 *
 * @param float $batch_time Total batch processing time
 * @param int $processed_count Number of items processed
 * @param int $batch_size Current batch size
 * @param float $avg_time_per_item Average time per item from concurrent processing
 * @param int $concurrency_level Concurrency level used
 * @return void
 */
function update_batch_metrics_concurrent($batch_time, $processed_count, $batch_size, $avg_time_per_item, $concurrency_level) {
    try {
        // Store previous batch time before updating
        $previous_batch_time = get_last_batch_time();
        retry_option_operation(function() use ($previous_batch_time) {
            return set_previous_batch_time($previous_batch_time);
        }, [$previous_batch_time], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_previous_batch_time_concurrent'
        ]);

        // Update stored metrics for next batch
        $time_per_item = $avg_time_per_item > 0 ? $avg_time_per_item : ($processed_count > 0 ? $batch_time / $processed_count : 0);
        $prev_time_per_item = get_time_per_job();

        retry_option_operation(function() use ($time_per_item) {
            return set_time_per_job($time_per_item);
        }, [$time_per_item], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_time_per_job_concurrent'
        ]);

        $peak_memory = memory_get_peak_usage(true);
        retry_option_operation(function() use ($peak_memory) {
            return set_last_peak_memory($peak_memory);
        }, [$peak_memory], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_peak_memory_concurrent'
        ]);

        // Use rolling average for time_per_item to stabilize adjustments
        $current_avg = get_avg_time_per_job();
        $new_avg = ($current_avg * 0.7) + ($time_per_item * 0.3);
        retry_option_operation(function() use ($new_avg) {
            return set_avg_time_per_job($new_avg);
        }, [$new_avg], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_avg_time_per_job_concurrent'
        ]);

        retry_option_operation(function() use ($batch_size) {
            return set_batch_size($batch_size);
        }, [$batch_size], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'update_batch_size_metric_concurrent'
        ]);

        // Store concurrency level for future reference
        update_option('job_import_last_concurrency', $concurrency_level, false);

        // Track consecutive small batches for recovery mechanism
        if ($batch_size <= 3) {
            $consecutive = get_consecutive_small_batches() + 1;
            retry_option_operation(function() use ($consecutive) {
                return set_consecutive_small_batches($consecutive);
            }, [$consecutive], [
                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                'operation' => 'update_consecutive_small_batches_concurrent'
            ]);
        } else {
            retry_option_operation(function() {
                return set_consecutive_small_batches(0);
            }, [], [
                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                'operation' => 'reset_consecutive_small_batches_metric_concurrent'
            ]);
        }

        // Increment consecutive batches counter for aggressive ramp-up logic
        $consecutive_batches = get_consecutive_batches() + 1;
        retry_option_operation(function() use ($consecutive_batches) {
            return set_consecutive_batches($consecutive_batches);
        }, [$consecutive_batches], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'increment_consecutive_batches_concurrent'
        ]);

        PuntWorkLogger::debug('Concurrent batch metrics updated', PuntWorkLogger::CONTEXT_BATCH, [
            'batch_time' => $batch_time,
            'processed_count' => $processed_count,
            'batch_size' => $batch_size,
            'avg_time_per_item' => $time_per_item,
            'concurrency_level' => $concurrency_level,
            'peak_memory' => $peak_memory,
            'memory_limit' => get_memory_limit_bytes(),
            'memory_ratio' => get_memory_limit_bytes() > 0 ? $peak_memory / get_memory_limit_bytes() : 0,
            'prev_time_per_item' => $prev_time_per_item,
            'consecutive_small_batches' => $batch_size <= 3 ? ($consecutive ?? 0) : 0,
            'efficiency_status' => $time_per_item <= 1.0 ? 'highly_efficient' : ($time_per_item <= 1.5 ? 'very_efficient' : ($time_per_item <= 2.0 ? 'efficient' : ($time_per_item <= 3.0 ? 'moderate' : 'inefficient'))),
            'concurrency_efficiency' => $concurrency_level > 1 ? 'concurrent' : 'sequential'
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Failed to update concurrent batch metrics', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage(),
            'batch_time' => $batch_time,
            'processed_count' => $processed_count,
            'batch_size' => $batch_size,
            'concurrency_level' => $concurrency_level
        ]);
        // Continue execution even if metrics update fails
    }
}