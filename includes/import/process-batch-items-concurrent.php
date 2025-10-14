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
 * @param string $json_path Path to JSONL file
 * @param int $start_index Starting index in JSONL file
 * @param array &$logs Reference to logs array
 * @param int &$updated Reference to updated count
 * @param int &$published Reference to published count
 * @param int &$skipped Reference to skipped count
 * @param int &$processed_count Reference to processed count
 * @return array Processing results with timing data
 */
function process_batch_items_concurrent($batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, $json_path, $start_index, &$logs, &$updated, &$published, &$skipped, &$processed_count) {
    $start_time = microtime(true);

    PuntWorkLogger::info('Starting concurrent item processing', PuntWorkLogger::CONTEXT_BATCH, [
        'batch_guids_count' => count($batch_guids),
        'batch_items_count' => count($batch_items),
        'concurrency_enabled' => true
    ]);

    $user_id = get_user_by('login', 'admin') ? get_user_by('login', 'admin')->ID : get_current_user_id();

    $action_ids = [];

    // Schedule concurrent processing for each item in the batch
    foreach ($batch_guids as $guid) {
        $action_id = as_schedule_single_action(
            time(),
            'puntwork_process_single_item',
            [
                'guid' => $guid,
                'json_path' => $json_path,
                'start_index' => $start_index,
                'acf_fields' => $acf_fields,
                'zero_empty_fields' => $zero_empty_fields,
                'user_id' => $user_id
            ],
            'puntwork-import'
        );

        if ($action_id) {
            $action_ids[] = $action_id;
            PuntWorkLogger::debug('Scheduled concurrent item', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'action_id' => $action_id
            ]);
        } else {
            PuntWorkLogger::error('Failed to schedule concurrent item', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid
            ]);
        }
    }

    // Return immediately with async processing info
    PuntWorkLogger::info('Concurrent item processing scheduled asynchronously', PuntWorkLogger::CONTEXT_BATCH, [
        'total_items' => count($batch_guids),
        'actions_scheduled' => count($action_ids),
        'async' => true
    ]);

    return [
        'processed_count' => count($batch_guids), // Estimated
        'total_time' => 0,
        'avg_time_per_item' => 0,
        'item_timings' => [],
        'concurrency_used' => count($batch_guids),
        'async' => true,
        'action_ids' => $action_ids
    ];
}

/**
 * Calculate optimal concurrency level based on system resources and batch size
 * Includes validation to ensure concurrent processing is working before increasing levels
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

    // VALIDATION: Check if concurrent processing has been working before allowing higher levels
    $last_concurrency_level = get_last_concurrency_level();
    $concurrent_success_rate = get_concurrent_success_rate();

    // If we've used concurrent processing before, validate it's working
    if ($last_concurrency_level > 1) {
        // Only allow concurrency increase if:
        // 1. Success rate is 100% (all chunks completed successfully)
        // 2. Or we're maintaining the same level (not increasing)
        if ($optimal_concurrency > $last_concurrency_level && $concurrent_success_rate < 1.0) {
            PuntWorkLogger::warning('Reducing concurrency due to low success rate', PuntWorkLogger::CONTEXT_BATCH, [
                'requested_concurrency' => $optimal_concurrency,
                'last_concurrency' => $last_concurrency_level,
                'success_rate' => $concurrent_success_rate,
                'reason' => 'insufficient concurrent processing success'
            ]);

            // Reduce to last successful level or 1, whichever is higher
            $optimal_concurrency = max(1, $last_concurrency_level - 1);
        }

        // If concurrent processing has completely failed recently, fall back to sequential
        if ($concurrent_success_rate < 0.5) {
            PuntWorkLogger::warning('Falling back to sequential processing due to repeated concurrent failures', PuntWorkLogger::CONTEXT_BATCH, [
                'success_rate' => $concurrent_success_rate,
                'last_concurrency' => $last_concurrency_level
            ]);
            $optimal_concurrency = 1;
        }
    }

    // Cap at reasonable maximum
    $optimal_concurrency = min($optimal_concurrency, 20);

    PuntWorkLogger::debug('Calculated optimal concurrency with validation', PuntWorkLogger::CONTEXT_BATCH, [
        'batch_size' => $batch_size,
        'memory_based' => $memory_based_concurrency,
        'cpu_based' => $cpu_based_concurrency,
        'batch_based' => $batch_based_concurrency,
        'last_concurrency_level' => $last_concurrency_level,
        'concurrent_success_rate' => $concurrent_success_rate,
        'optimal' => $optimal_concurrency,
        'available_memory_mb' => $available_memory / (1024 * 1024),
        'cpu_cores' => $cpu_cores,
        'validation_applied' => $last_concurrency_level > 1 && $optimal_concurrency < min($memory_based_concurrency, $cpu_based_concurrency, $batch_based_concurrency)
    ]);

    return $optimal_concurrency;
}

/**
 * Action Scheduler callback for processing a single item concurrently
 */
function process_single_item_callback($guid, $json_path, $start_index, $acf_fields, $zero_empty_fields, $user_id) {
    $item_start_time = microtime(true);

    // Check for cancellation before processing this item
    if (get_transient('import_cancel') === true || get_transient('import_force_cancel') === true || get_transient('import_emergency_stop') === true) {
        $cancel_type = get_transient('import_emergency_stop') === true ? 'emergency stopped' :
                      (get_transient('import_force_cancel') === true ? 'force cancelled' : 'cancelled');
        PuntWorkLogger::info('Concurrent item processing ' . $cancel_type . ' - skipping item', PuntWorkLogger::CONTEXT_BATCH, [
            'guid' => $guid,
            'cancel_type' => $cancel_type,
            'action' => 'skipped_concurrent_item'
        ]);
        return; // Skip processing this item
    }

    // NOTE: To enable item processing debug logs, define PUNTWORK_DEBUG_ITEM_PROCESSING as true in wp-config.php
    if (defined('PUNTWORK_DEBUG_ITEM_PROCESSING') && PUNTWORK_DEBUG_ITEM_PROCESSING) {
        PuntWorkLogger::debug('Processing concurrent single item', PuntWorkLogger::CONTEXT_BATCH, [
            'guid' => $guid
        ]);
    }

    $logs = [];
    $updated = 0;
    $published = 0;
    $skipped = 0;
    $processed_count = 0;

    try {
        // Re-read the specific item from JSONL
        $batch_items = [];
        if (!empty($json_path) && file_exists($json_path)) {
            $handle = fopen($json_path, "r");
            if ($handle) {
                $current_index = 0;
                while (($line = fgets($handle)) !== false) {
                    if ($current_index >= $start_index) {
                        $line = trim($line);
                        if (!empty($line)) {
                            $item = json_decode($line, true);
                            if ($item !== null && isset($item['guid']) && $item['guid'] === $guid) {
                                $batch_items[$guid] = ['item' => $item, 'index' => $current_index];
                                break; // Found the item
                            }
                        }
                    }
                    $current_index++;
                }
                fclose($handle);
            }
        }

        // Re-fetch database data for this item
        global $wpdb;

        // Get existing post_id for this guid
        $existing_meta = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value AS guid FROM $wpdb->postmeta WHERE meta_key = 'guid' AND meta_value = %s",
            $guid
        ));
        $post_ids_by_guid = [];
        if (!empty($existing_meta)) {
            $post_ids_by_guid[$guid] = $existing_meta[0]->post_id;
        }

        // Fetch last updates and hashes for existing post
        $post_id = $post_ids_by_guid[$guid] ?? null;
        $last_updates = [];
        $all_hashes_by_post = [];

        if ($post_id) {
            $last_update = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_last_import_update' AND post_id = %d",
                $post_id
            ));
            if ($last_update) {
                $last_updates[$post_id] = (object)['meta_value' => $last_update];
            }

            $hash = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_import_hash' AND post_id = %d",
                $post_id
            ));
            if ($hash) {
                $all_hashes_by_post[$post_id] = $hash;
            }
        }

    } catch (\Exception $e) {
        PuntWorkLogger::error('Failed to prepare data for concurrent item', PuntWorkLogger::CONTEXT_BATCH, [
            'guid' => $guid,
            'error' => $e->getMessage(),
            'json_path' => $json_path
        ]);
        return;
    }

    try {
        $item_data = $batch_items[$guid]['item'] ?? null;
        if (!$item_data) {
            PuntWorkLogger::warning('Item not found', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid
            ]);
            $skipped++;
            $processed_count++;
        } else {
            $xml_updated = isset($item_data['updated']) ? $item_data['updated'] : '';
            $xml_updated_ts = strtotime($xml_updated);

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
                            'guid' => $guid
                        ]);

                        if (is_wp_error($update_result)) {
                            PuntWorkLogger::error('Failed to republish post in concurrent item', PuntWorkLogger::CONTEXT_BATCH, [
                                'post_id' => $post_id,
                                'guid' => $guid,
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
                    $item_hash = md5(json_encode($item_data));

                    // Skip if content hasn't changed (temporarily disabled for full processing)
                    // if ($current_hash === $item_hash) {
                    //     $skipped++;
                    //     $processed_count++;
                    //     continue;
                    // }

                    // Update post immediately
                    $error_message = '';
                    $update_result = update_job_post($post_id, $guid, $item_data, $acf_fields, $zero_empty_fields, $logs, $error_message);
                    if (is_wp_error($update_result)) {
                        PuntWorkLogger::error('Failed to update post in concurrent item', PuntWorkLogger::CONTEXT_BATCH, [
                            'post_id' => $post_id,
                            'guid' => $guid,
                            'error' => $error_message
                        ]);
                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to update ID: ' . $post_id . ' - ' . $error_message;
                    } else {
                        $updated++;
                    }

                } catch (\Exception $e) {
                    PuntWorkLogger::error('Error processing existing post in concurrent item', PuntWorkLogger::CONTEXT_BATCH, [
                        'post_id' => $post_id,
                        'guid' => $guid,
                        'error' => $e->getMessage()
                    ]);
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Error processing existing post ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $e->getMessage();
                    $skipped++;
                    $processed_count++;
                }

            } else {
                // Create new post immediately
                $error_message = '';
                $create_result = create_job_post($item_data, $acf_fields, $zero_empty_fields, $user_id, $logs, $error_message);
                if (is_wp_error($create_result)) {
                    PuntWorkLogger::error('Failed to create post in concurrent item', PuntWorkLogger::CONTEXT_BATCH, [
                        'guid' => $guid,
                        'error' => $error_message
                    ]);
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to create GUID: ' . $guid . ' - ' . $error_message;
                } else {
                    $published++;
                }
            }

            $processed_count++;
        }

        $item_time = microtime(true) - $item_start_time;

        // NOTE: To enable item processing debug logs, define PUNTWORK_DEBUG_ITEM_PROCESSING as true in wp-config.php
        if (defined('PUNTWORK_DEBUG_ITEM_PROCESSING') && PUNTWORK_DEBUG_ITEM_PROCESSING) {
            PuntWorkLogger::debug('Concurrent single item completed', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'processed_count' => $processed_count,
                'published' => $published,
                'updated' => $updated,
                'skipped' => $skipped,
                'item_time' => $item_time
            ]);
        }

        // Update import status asynchronously with item results
        $current_status = get_import_status();
        if (!is_array($current_status)) {
            $current_status = [];
        }

        // If import is already complete, don't update the status to avoid interfering with completion
        if (($current_status['complete'] ?? false) === true) {
            PuntWorkLogger::debug('Skipping status update for concurrent item - import already complete', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'processed' => $current_status['processed'] ?? 0,
                'total' => $current_status['total'] ?? 0
            ]);
            return; // Exit early without updating status
        }

        $current_status['published'] = ($current_status['published'] ?? 0) + $published;
        $current_status['updated'] = ($current_status['updated'] ?? 0) + $updated;
        $current_status['skipped'] = ($current_status['skipped'] ?? 0) + $skipped;
        $current_status['processed'] = ($current_status['processed'] ?? 0) + $processed_count;
        $current_status['last_update'] = time();
        if (!isset($current_status['logs']) || !is_array($current_status['logs'])) {
            $current_status['logs'] = [];
        }
        $current_status['logs'] = array_merge($current_status['logs'], $logs);
        $current_status['logs'] = array_slice($current_status['logs'], -50); // Keep last 50
        set_import_status($current_status);

        // NOTE: To enable item processing debug logs, define PUNTWORK_DEBUG_ITEM_PROCESSING as true in wp-config.php
        if (defined('PUNTWORK_DEBUG_ITEM_PROCESSING') && PUNTWORK_DEBUG_ITEM_PROCESSING) {
            PuntWorkLogger::debug('Import status updated with item results', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'status_updated' => true
            ]);
        }

    } catch (\Exception $e) {
        PuntWorkLogger::error('Critical error processing item', PuntWorkLogger::CONTEXT_BATCH, [
            'guid' => $guid,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Critical error processing GUID: ' . $guid . ' - ' . $e->getMessage();
        $skipped++;
        $processed_count++;

        // Update status even on error
        $current_status = get_import_status();
        if (!is_array($current_status)) {
            $current_status = [];
        }

        // If import is already complete, don't update the status to avoid interfering with completion
        if (($current_status['complete'] ?? false) === true) {
            PuntWorkLogger::debug('Skipping status update for concurrent item error - import already complete', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'error' => 'error occurred but import complete'
            ]);
            return; // Exit early without updating status
        }

        $current_status['skipped'] = ($current_status['skipped'] ?? 0) + $skipped;
        $current_status['processed'] = ($current_status['processed'] ?? 0) + $processed_count;
        $current_status['last_update'] = time();
        $current_status['logs'] = array_merge($current_status['logs'] ?? [], $logs);
        $current_status['logs'] = array_slice($current_status['logs'], -50);
        set_import_status($current_status);
    }
}

// Hook the callback function
add_action('puntwork_process_single_item', 'Puntwork\process_single_item_callback', 10, 6);

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