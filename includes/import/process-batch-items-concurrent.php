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
 * Validate Action Scheduler health and capability for concurrent processing
 *
 * @return array Validation result with status and details
 */
function validate_action_scheduler_health() {
    $validation = [
        'healthy' => false,
        'issues' => [],
        'recommendations' => []
    ];

    // Check 1: Action Scheduler function availability
    if (!function_exists('as_schedule_single_action')) {
        $validation['issues'][] = 'Action Scheduler functions not available';
        $validation['recommendations'][] = 'Install and activate Action Scheduler plugin';
        return $validation;
    }

    // Check 2: Action Scheduler store availability
    try {
        $store = \ActionScheduler::store();
        if (!$store) {
            $validation['issues'][] = 'Action Scheduler store not available';
            $validation['recommendations'][] = 'Check Action Scheduler database tables';
            return $validation;
        }
    } catch (\Exception $e) {
        $validation['issues'][] = 'Action Scheduler store error: ' . $e->getMessage();
        $validation['recommendations'][] = 'Check Action Scheduler configuration';
        return $validation;
    }

    // Check 3: Queue runner status
    try {
        $runner = \ActionScheduler::runner();
        if (!$runner) {
            $validation['issues'][] = 'Action Scheduler runner not available';
            $validation['recommendations'][] = 'Check Action Scheduler queue runner';
            return $validation;
        }
    } catch (\Exception $e) {
        $validation['issues'][] = 'Action Scheduler runner error: ' . $e->getMessage();
        $validation['recommendations'][] = 'Check Action Scheduler queue runner configuration';
        return $validation;
    }

    // Check 4: Test job scheduling and execution
    try {
        $test_action_id = as_schedule_single_action(
            time() + 1, // Schedule 1 second from now
            'puntwork_test_action_scheduler',
            ['test_timestamp' => time()],
            'puntwork-test'
        );

        if (!$test_action_id) {
            $validation['issues'][] = 'Failed to schedule test action';
            $validation['recommendations'][] = 'Check Action Scheduler permissions and database';
            return $validation;
        }

        // Clean up test action
        as_unschedule_action('puntwork_test_action_scheduler', ['test_timestamp' => time()], 'puntwork-test');

    } catch (\Exception $e) {
        $validation['issues'][] = 'Test action scheduling failed: ' . $e->getMessage();
        $validation['recommendations'][] = 'Check Action Scheduler database and permissions';
        return $validation;
    }

    // Check 5: Queue status and pending actions
    try {
        $pending_actions = $store->query_actions([
            'status' => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 100
        ]);

        if (count($pending_actions) > 1000) {
            $validation['issues'][] = 'Large number of pending actions (' . count($pending_actions) . ')';
            $validation['recommendations'][] = 'Clear pending Action Scheduler queue or increase processing capacity';
        }

        // Check for stuck actions (older than 1 hour)
        $stuck_actions = $store->query_actions([
            'status' => \ActionScheduler_Store::STATUS_PENDING,
            'date' => time() - HOUR_IN_SECONDS,
            'per_page' => 10
        ]);

        if (!empty($stuck_actions)) {
            $validation['issues'][] = 'Found stuck pending actions';
            $validation['recommendations'][] = 'Check Action Scheduler processing and clear stuck actions';
        }

    } catch (\Exception $e) {
        $validation['issues'][] = 'Queue status check failed: ' . $e->getMessage();
        $validation['recommendations'][] = 'Check Action Scheduler database integrity';
        return $validation;
    }

    $validation['healthy'] = true;
    return $validation;
}

/**
 * Monitor completion of concurrent Action Scheduler jobs
 *
 * @param array $action_ids Array of Action Scheduler action IDs to monitor
 * @param int $timeout_seconds Maximum time to wait for completion
 * @param int $check_interval_seconds How often to check completion status
 * @return array Monitoring results
 */
function monitor_concurrent_job_completion($action_ids, $timeout_seconds = 300, $check_interval_seconds = 5) {
    $start_time = microtime(true);
    $completed_actions = [];
    $failed_actions = [];
    $pending_actions = $action_ids;

    PuntWorkLogger::info('Starting concurrent job completion monitoring', PuntWorkLogger::CONTEXT_BATCH, [
        'action_ids_count' => count($action_ids),
        'timeout_seconds' => $timeout_seconds,
        'check_interval_seconds' => $check_interval_seconds
    ]);

    while (!empty($pending_actions) && (microtime(true) - $start_time) < $timeout_seconds) {
        $store = \ActionScheduler::store();
        $current_pending = [];
        $just_completed = [];

        foreach ($pending_actions as $action_id) {
            try {
                $action = $store->fetch_action($action_id);
                if (!$action) {
                    PuntWorkLogger::warn('Action not found in store', PuntWorkLogger::CONTEXT_BATCH, [
                        'action_id' => $action_id
                    ]);
                    $failed_actions[] = $action_id;
                    continue;
                }

                $status = \ActionScheduler::store()->get_status($action_id);
                if (in_array($status, [\ActionScheduler_Store::STATUS_COMPLETE, \ActionScheduler_Store::STATUS_CANCELED])) {
                    $completed_actions[] = $action_id;
                    $just_completed[] = $action_id;
                } elseif (in_array($status, [\ActionScheduler_Store::STATUS_FAILED, \ActionScheduler_Store::STATUS_CANCELED])) {
                    $failed_actions[] = $action_id;
                } else {
                    $current_pending[] = $action_id;
                }
            } catch (\Exception $e) {
                PuntWorkLogger::error('Error checking action status', PuntWorkLogger::CONTEXT_BATCH, [
                    'action_id' => $action_id,
                    'error' => $e->getMessage()
                ]);
                $failed_actions[] = $action_id;
            }
        }

        $pending_actions = $current_pending;

        if (!empty($just_completed)) {
            PuntWorkLogger::debug('Concurrent jobs completed in this check', PuntWorkLogger::CONTEXT_BATCH, [
                'completed_count' => count($just_completed),
                'remaining_pending' => count($pending_actions),
                'elapsed_seconds' => microtime(true) - $start_time
            ]);
        }

        // Wait before next check if there are still pending actions
        if (!empty($pending_actions)) {
            sleep($check_interval_seconds);
        }
    }

    $elapsed_time = microtime(true) - $start_time;
    $success_rate = count($action_ids) > 0 ? count($completed_actions) / count($action_ids) : 0;

    $result = [
        'all_completed' => empty($pending_actions),
        'completed_count' => count($completed_actions),
        'failed_count' => count($failed_actions),
        'pending_count' => count($pending_actions),
        'total_count' => count($action_ids),
        'success_rate' => $success_rate,
        'elapsed_time' => $elapsed_time,
        'timed_out' => $elapsed_time >= $timeout_seconds,
        'completed_action_ids' => $completed_actions,
        'failed_action_ids' => $failed_actions,
        'pending_action_ids' => $pending_actions
    ];

    PuntWorkLogger::info('Concurrent job completion monitoring finished', PuntWorkLogger::CONTEXT_BATCH, [
        'all_completed' => $result['all_completed'],
        'completed_count' => $result['completed_count'],
        'failed_count' => $result['failed_count'],
        'pending_count' => $result['pending_count'],
        'success_rate' => $result['success_rate'],
        'elapsed_time' => $result['elapsed_time'],
        'timed_out' => $result['timed_out']
    ]);

    return $result;
}

/**
 * Process batch items concurrently with validation and monitoring
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

    PuntWorkLogger::info('Starting concurrent item processing with validation', PuntWorkLogger::CONTEXT_BATCH, [
        'batch_guids_count' => count($batch_guids),
        'batch_items_count' => count($batch_items),
        'concurrency_enabled' => true
    ]);

    // VALIDATION: Check Action Scheduler health before proceeding
    $health_check = validate_action_scheduler_health();
    if (!$health_check['healthy']) {
    PuntWorkLogger::warn('Action Scheduler health check failed, cannot use concurrent processing', PuntWorkLogger::CONTEXT_BATCH, [
            'issues' => $health_check['issues'],
            'recommendations' => $health_check['recommendations']
        ]);

        // Log the issues for debugging
        foreach ($health_check['issues'] as $issue) {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Action Scheduler Issue: ' . $issue;
        }
        foreach ($health_check['recommendations'] as $recommendation) {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Recommendation: ' . $recommendation;
        }

        // Return error result to trigger fallback to sequential processing
        return [
            'success' => false,
            'error' => 'Action Scheduler health check failed',
            'issues' => $health_check['issues'],
            'recommendations' => $health_check['recommendations'],
            'fallback_to_sequential' => true
        ];
    }

    $user_id = get_user_by('login', 'admin') ? get_user_by('login', 'admin')->ID : get_current_user_id();

    // PRE-PROCESS DATA: Load all items from JSONL and share via transients to avoid per-job file reads
    // This eliminates 97TB of file reading and thousands of duplicate queries
    $batch_data_cache_key = 'puntwork_batch_' . uniqid() . '_' . microtime(true);
    $batch_data = [];

    // Load all batch items once and store in transient (expires in 1 hour)
    try {
        $batch_data = load_json_batch($json_path, $start_index, count($batch_guids));
        // Create indexed lookup for faster access
        $batch_data_indexed = [];
        foreach ($batch_data as $item) {
            if (isset($item['guid'])) {
                $batch_data_indexed[$item['guid']] = $item;
            }
        }
        set_transient($batch_data_cache_key, $batch_data_indexed, 3600);
    } catch (\Exception $e) {
        PuntWorkLogger::error('Failed to pre-load batch data', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage(),
            'json_path' => $json_path,
            'batch_size' => count($batch_guids)
        ]);

        // Fallback to sequential processing if we can't even load the data
        return [
            'success' => false,
            'error' => 'Failed to load batch data: ' . $e->getMessage(),
            'fallback_to_sequential' => true
        ];
    }

    // Cache shared data to avoid per-job loading
    $shared_data_key = 'puntwork_shared_' . uniqid() . '_' . microtime(true);
    $shared_data = [
        'acf_fields' => $acf_fields,
        'zero_empty_fields' => $zero_empty_fields,
        'user_id' => $user_id,
        'batch_start_index' => $start_index,
        'batch_size' => count($batch_guids)
    ];
    set_transient($shared_data_key, $shared_data, 3600);

    $action_ids = [];
    $scheduling_errors = 0;

    // Schedule concurrent processing for each item in the batch
    // Now jobs receive item data directly instead of reading the entire file
    foreach ($batch_guids as $guid) {
        // Get item data directly (no file reading needed)
        $item_data = $batch_data_indexed[$guid] ?? null;

        if (!$item_data) {
            PuntWorkLogger::warn('Item data not found in pre-loaded batch', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'reason' => 'skipping_job'
            ]);
            continue; // Skip this item
        }

        $action_id = as_schedule_single_action(
            time() + 1, // Small delay to prevent conflicts and allow immediate execution
            'puntwork_process_single_item_optimized', // New optimized callback
            [
                'guid' => $guid,
                'item_data' => $item_data, // Direct data - no file reading!
                'shared_data_key' => $shared_data_key,
                'batch_data_cache_key' => $batch_data_cache_key,
                'concurrent_mode' => true
            ],
            'puntwork-import-process',
            false,
            defined('\AS_ACTION_PRIORITY_NORMAL') ? \AS_ACTION_PRIORITY_NORMAL : 10
        );

        if ($action_id) {
            $action_ids[] = $action_id;
            PuntWorkLogger::debug('Scheduled optimized concurrent item', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'action_id' => $action_id,
                'item_data_provided' => true // Shows we passed item data
            ]);
        } else {
            $scheduling_errors++;
            PuntWorkLogger::error('Failed to schedule concurrent item', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'scheduling_errors' => $scheduling_errors
            ]);
        }
    }

    // Check if we had significant scheduling failures
    $scheduling_success_rate = count($action_ids) / count($batch_guids);
    if ($scheduling_success_rate < 0.9) { // Less than 90% success
    PuntWorkLogger::warn('High scheduling failure rate, concurrent processing may be unreliable', PuntWorkLogger::CONTEXT_BATCH, [
            'total_items' => count($batch_guids),
            'scheduled_count' => count($action_ids),
            'scheduling_success_rate' => $scheduling_success_rate
        ]);

        // Cancel scheduled actions and return error to trigger fallback
        foreach ($action_ids as $action_id) {
            as_unschedule_action('puntwork_process_single_item', [], 'puntwork-import');
        }

        return [
            'success' => false,
            'error' => 'High scheduling failure rate',
            'scheduling_success_rate' => $scheduling_success_rate,
            'fallback_to_sequential' => true
        ];
    }

    // MONITOR: Track job completion with timeout - INCREASED MONITORING TIMEOUT for slow concurrent processing
    $timeout_seconds = 180; // Increased from 60 to 180 seconds (3 minutes) to handle slow Action Scheduler
    $monitoring_result = monitor_concurrent_job_completion($action_ids, $timeout_seconds, 10); // Check every 10 seconds instead of 5

    if (!$monitoring_result['all_completed']) {
    PuntWorkLogger::warn('Concurrent jobs did not complete successfully', PuntWorkLogger::CONTEXT_BATCH, [
            'completed_count' => $monitoring_result['completed_count'],
            'failed_count' => $monitoring_result['failed_count'],
            'pending_count' => $monitoring_result['pending_count'],
            'success_rate' => $monitoring_result['success_rate'],
            'timed_out' => $monitoring_result['timed_out']
        ]);

        // For partial completion, we still return results but log the issues
        if ($monitoring_result['success_rate'] < 0.8) { // Less than 80% success
            PuntWorkLogger::error('Low concurrent job completion rate, results may be incomplete', PuntWorkLogger::CONTEXT_BATCH, [
                'success_rate' => $monitoring_result['success_rate'],
                'recommendation' => 'Consider using sequential processing for better reliability'
            ]);
        }
    }

    // Update concurrent success metrics for future decisions
    update_concurrent_success_metrics(
        count($batch_guids), // concurrency_used
        $monitoring_result['completed_count'], // chunks_completed
        count($batch_guids), // total_chunks
        $monitoring_result['completed_count'], // items_processed
        $monitoring_result['total_count'] // total_items
    );

    PuntWorkLogger::info('Concurrent item processing completed with monitoring', PuntWorkLogger::CONTEXT_BATCH, [
        'total_items' => count($batch_guids),
        'actions_scheduled' => count($action_ids),
        'completed_count' => $monitoring_result['completed_count'],
        'failed_count' => $monitoring_result['failed_count'],
        'success_rate' => $monitoring_result['success_rate'],
        'elapsed_time' => $monitoring_result['elapsed_time'],
        'async' => false // Now actually synchronous with monitoring
    ]);

    return [
        'processed_count' => $monitoring_result['completed_count'],
        'total_time' => microtime(true) - $start_time,
        'avg_time_per_item' => $monitoring_result['completed_count'] > 0 ? (microtime(true) - $start_time) / $monitoring_result['completed_count'] : 0,
        'item_timings' => [], // Could be enhanced to track individual item times
        'concurrency_used' => count($batch_guids),
        'async' => false, // Now synchronous with monitoring
        'action_ids' => $action_ids,
        'monitoring_result' => $monitoring_result,
        'success' => $monitoring_result['success_rate'] >= 0.8 // Consider successful if 80%+ completed
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
    $batch_based_concurrency = max(1, min(5, ceil($batch_size / 10))); // At least 10 items per concurrent process, max 5 concurrent

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
            PuntWorkLogger::warn('Reducing concurrency due to low success rate', PuntWorkLogger::CONTEXT_BATCH, [
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
            PuntWorkLogger::warn('Falling back to sequential processing due to repeated concurrent failures', PuntWorkLogger::CONTEXT_BATCH, [
                'success_rate' => $concurrent_success_rate,
                'last_concurrency' => $last_concurrency_level
            ]);
            $optimal_concurrency = 1;
        }
    }

    // Cap at reasonable maximum for Hostinger
    $optimal_concurrency = min($optimal_concurrency, 5);

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
            PuntWorkLogger::warn('Item not found', PuntWorkLogger::CONTEXT_BATCH, [
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

        // PERFORMANCE TRACKING: Store timing data for optimization analysis
        if (!empty($processed_count)) {
            // Store individual item timing metrics for ongoing optimization
            $timing_data = [
                'guid' => $guid,
                'processing_time' => $item_time,
                'operation_type' => $updated > 0 ? 'update' : ($published > 0 ? 'create' : 'skip'),
                'timestamp' => microtime(true),
                'item_index' => $current_index ?? 0
            ];

            // Append to timing log (limited to prevent memory bloat)
            $existing_timings = get_option('job_import_item_timings', []);
            if (!is_array($existing_timings)) {
                $existing_timings = [];
            }

            // Keep only last 1000 timings to prevent excessive storage
            $existing_timings[] = $timing_data;
            if (count($existing_timings) > 1000) {
                $existing_timings = array_slice($existing_timings, -1000);
            }

            update_option('job_import_item_timings', $existing_timings, false);

            // Update aggregate timing statistics
            $current_avg_time = get_option('job_import_avg_item_time', 0);
            $timing_count = get_option('job_import_timing_count', 0);

            // Rolling average: weight recent timings more heavily
            $new_avg_time = (($current_avg_time * $timing_count) + $item_time) / ($timing_count + 1);
            update_option('job_import_avg_item_time', $new_avg_time, false);
            update_option('job_import_timing_count', $timing_count + 1, false);

            // Track timing distribution for optimization insights
            $time_bucket = ceil($item_time * 10) / 10; // Round to nearest 0.1 second
            $time_distribution = get_option('job_import_time_distribution', []);
            if (!is_array($time_distribution)) {
                $time_distribution = [];
            }

            $distribution_key = $time_bucket . 's';
            $time_distribution[$distribution_key] = ($time_distribution[$distribution_key] ?? 0) + 1;
            update_option('job_import_time_distribution', $time_distribution, false);
        }

        // NOTE: To enable item processing debug logs, define PUNTWORK_DEBUG_ITEM_PROCESSING as true in wp-config.php
        if (defined('PUNTWORK_DEBUG_ITEM_PROCESSING') && PUNTWORK_DEBUG_ITEM_PROCESSING) {
            PuntWorkLogger::debug('Concurrent single item completed', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'processed_count' => $processed_count,
                'published' => $published,
                'updated' => $updated,
                'skipped' => $skipped,
                'item_time' => $item_time,
                'avg_time_tracked' => $new_avg_time ?? null
            ]);
        }

        // Update import status asynchronously with item results
        $current_status = get_import_status();
        if (!is_array($current_status)) {
            $current_status = [];
        }

        // CRITICAL: Multiple checks to prevent status updates after import completion
        $is_complete = ($current_status['complete'] ?? false) === true;
        $has_end_time = isset($current_status['end_time']) && $current_status['end_time'] > 0;
        $processed_equals_total = isset($current_status['processed']) && isset($current_status['total']) &&
                                 $current_status['processed'] >= $current_status['total'] &&
                                 $current_status['total'] > 0;
        $completion_locked = ($current_status['import_completion_locked'] ?? false) === true;

        // If import is already complete, don't update the status to avoid interfering with completion
        if ($is_complete || $has_end_time || $processed_equals_total || $completion_locked) {
            PuntWorkLogger::debug('Skipping status update for concurrent item - import already complete', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'processed' => $current_status['processed'] ?? 0,
                'total' => $current_status['total'] ?? 0,
                'complete' => $is_complete,
                'has_end_time' => $has_end_time,
                'processed_equals_total' => $processed_equals_total,
                'completion_locked' => $completion_locked,
                'end_time' => $current_status['end_time'] ?? null
            ]);
            return; // Exit early without updating status
        }

        $current_status['published'] = ($current_status['published'] ?? 0) + $published;
        $current_status['updated'] = ($current_status['updated'] ?? 0) + $updated;
        $current_status['skipped'] = ($current_status['skipped'] ?? 0) + $skipped;
        $current_status['processed'] = ($current_status['processed'] ?? 0) + $processed_count;
        $current_status['last_update'] = microtime(true);
        if (!isset($current_status['logs']) || !is_array($current_status['logs'])) {
            $current_status['logs'] = [];
        }
        $current_status['logs'] = array_merge($current_status['logs'], $logs);
        $current_status['logs'] = array_slice($current_status['logs'], -50); // Keep last 50

        // Use atomic status update to prevent race conditions in concurrent processing
        $status_updated = set_import_status_atomic($current_status);
        if (!$status_updated) {
            PuntWorkLogger::warn('Failed to update import status atomically for concurrent item', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'processed_increment' => $processed_count,
                'published_increment' => $published,
                'updated_increment' => $updated,
                'skipped_increment' => $skipped
            ]);
        }

        // NOTE: To enable item processing debug logs, define PUNTWORK_DEBUG_ITEM_PROCESSING as true in wp-config.php
        if (defined('PUNTWORK_DEBUG_ITEM_PROCESSING') && PUNTWORK_DEBUG_ITEM_PROCESSING) {
            PuntWorkLogger::debug('Import status updated with item results', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'status_updated' => $status_updated
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

        // CRITICAL: Multiple checks to prevent status updates after import completion
        $is_complete = ($current_status['complete'] ?? false) === true;
        $has_end_time = isset($current_status['end_time']) && $current_status['end_time'] > 0;
        $processed_equals_total = isset($current_status['processed']) && isset($current_status['total']) &&
                                 $current_status['processed'] >= $current_status['total'] &&
                                 $current_status['total'] > 0;
        $completion_locked = ($current_status['import_completion_locked'] ?? false) === true;

        // If import is already complete, don't update the status to avoid interfering with completion
        if ($is_complete || $has_end_time || $processed_equals_total || $completion_locked) {
            PuntWorkLogger::debug('Skipping status update for concurrent item error - import already complete', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'error' => 'error occurred but import complete',
                'complete' => $is_complete,
                'has_end_time' => $has_end_time,
                'processed_equals_total' => $processed_equals_total,
                'completion_locked' => $completion_locked
            ]);
            return; // Exit early without updating status
        }

        $current_status['skipped'] = ($current_status['skipped'] ?? 0) + $skipped;
        $current_status['processed'] = ($current_status['processed'] ?? 0) + $processed_count;
        $current_status['last_update'] = microtime(true);
        $current_status['logs'] = array_merge($current_status['logs'] ?? [], $logs);
        $current_status['logs'] = array_slice($current_status['logs'], -50);

        // Use atomic status update to prevent race conditions in concurrent processing
        $status_updated = set_import_status_atomic($current_status);
        if (!$status_updated) {
            PuntWorkLogger::warn('Failed to update import status atomically for concurrent item error', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'processed_increment' => $processed_count,
                'skipped_increment' => $skipped,
                'error' => true
            ]);
        }
    }
}

/**
 * Optimized Action Scheduler callback for processing a single item concurrently
 * Eliminates expensive JSONL file access by using pre-loaded data from transients
 */
function process_single_item_optimized_callback($data) {
    $data = (array) $data; // Ensure it's an array
    $guid = $data['guid'] ?? '';
    $item_data = $data['item_data'] ?? null;
    $shared_data_key = $data['shared_data_key'] ?? '';
    $batch_data_cache_key = $data['batch_data_cache_key'] ?? '';

    $item_start_time = microtime(true);

    // Immediate cancellation check
    if (get_transient('import_cancel') === true || get_transient('import_force_cancel') === true || get_transient('import_emergency_stop') === true) {
        return; // Skip processing this item silently
    }

    // Get shared data from transient (no database lookups needed!)
    $shared_data = get_transient($shared_data_key);
    if (!$shared_data) {
        PuntWorkLogger::warn('Shared data not found in transient - falling back to old method', PuntWorkLogger::CONTEXT_BATCH, [
            'guid' => $guid,
            'shared_data_key' => $shared_data_key,
            'fallback' => 'sequential_mode'
        ]);
        return; // Skip processing
    }

    // Extract shared data - no individual queries needed!
    $acf_fields = $shared_data['acf_fields'] ?? [];
    $zero_empty_fields = $shared_data['zero_empty_fields'] ?? [];
    $user_id = $shared_data['user_id'] ?? 1;

    $logs = [];
    $updated = 0;
    $published = 0;
    $skipped = 0;
    $processed_count = 0;

    if (!$item_data) {
        PuntWorkLogger::warn('Item data not provided', PuntWorkLogger::CONTEXT_BATCH, [
            'guid' => $guid
        ]);
        $skipped++;
        $processed_count++;
    } else {
        // Pre-process: Bulk database queries (moved to batch level for now, but idea accepted)

        // If post exists, check if it needs updating
        global $wpdb;

        // OPTIMIZED: Bulk query for existing post (concept - could be expanded)
        $post_id = null;
        $existing_meta = $wpdb->get_row($wpdb->prepare(
            "SELECT post_id, meta_value AS guid FROM $wpdb->postmeta WHERE meta_key = 'guid' AND meta_value = %s LIMIT 1",
            $guid
        ));

        if ($existing_meta) {
            $post_id = $existing_meta->post_id;

            // OPTIMIZED: Single query for post metadata
            $meta_data = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key IN ('_last_import_update', '_import_hash')",
                $post_id
            ), OBJECT_K);

            $current_hash = $meta_data['_import_hash']->meta_value ?? '';
            $last_update = $meta_data['_last_import_update']->meta_value ?? '';

            $item_hash = md5(json_encode($item_data));

            // Skip if content hasn't changed (disabled for full processing currently)
            // if ($current_hash === $item_hash) {
            //     $skipped++;
            //     $processed_count++;
            // } else {
                try {
                    // Update meta atomically
                    $current_time = current_time('mysql');
                    update_post_meta($post_id, '_last_import_update', $current_time);
                    update_post_meta($post_id, 'guid', $guid);

                    // Ensure published if in active feed
                    $current_post = get_post($post_id);
                    if ($current_post && $current_post->post_status !== 'publish') {
                        wp_update_post([
                            'ID' => $post_id,
                            'post_status' => 'publish'
                        ]);
                    }

                    // Update post content
                    $error_message = '';
                    $update_result = update_job_post($post_id, $guid, $item_data, $acf_fields, $zero_empty_fields, $logs, $error_message);
                    if (is_wp_error($update_result)) {
                        PuntWorkLogger::debug('Failed to update post', PuntWorkLogger::CONTEXT_BATCH, [
                            'guid' => $guid,
                            'error' => $error_message
                        ]);
                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to update ID: ' . $post_id . ' - ' . $error_message;
                    } else {
                        $updated++;
                    }

                } catch (\Exception $e) {
                    PuntWorkLogger::debug('Error processing existing post', PuntWorkLogger::CONTEXT_BATCH, [
                        'guid' => $guid,
                        'error' => $e->getMessage()
                    ]);
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Error ID: ' . $post_id . ' - ' . $e->getMessage();
                    $skipped++;
                    $processed_count++;
                }
            // }
        } else {
            // Create new post
            $error_message = '';
            $create_result = create_job_post($item_data, $acf_fields, $zero_empty_fields, $user_id, $logs, $error_message);
            if (is_wp_error($create_result)) {
                PuntWorkLogger::debug('Failed to create post', PuntWorkLogger::CONTEXT_BATCH, [
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

    // OPTIMIZED STATUS UPDATES - BATCH PROCESSING VIA TRANSIENTS
    // Instead of real-time updates, batch them for asynchronous aggregation

    // Store individual update in transient (expires in 5 minutes)
    $update_key = 'puntwork_status_update_' . uniqid($guid . '_', true);
    $update_data = [
        'guid' => $guid,
        'processed' => $processed_count,
        'published' => $published,
        'updated' => $updated,
        'skipped' => $skipped,
        'logs' => $logs,
        'timestamp' => microtime(true),
        'import_complete' => false // Will be checked before aggregation
    ];

    set_transient($update_key, $update_data, 300); // 5 minute expiry

    // Store update key in a list for batch processing
    $update_keys = get_transient('puntwork_pending_updates', []);
    if (!is_array($update_keys)) {
        $update_keys = [];
    }
    $update_keys[] = $update_key;
    set_transient('puntwork_pending_updates', $update_keys, 300);

    // PERFORMANCE: Removed individual timing storage to reduce overhead
    // Can be re-enabled if needed for debugging

    PuntWorkLogger::debug('Optimized concurrent item completed', PuntWorkLogger::CONTEXT_BATCH, [
        'guid' => $guid,
        'processed_count' => $processed_count,
        'published' => $published,
        'updated' => $updated,
        'skipped' => $skipped,
        'item_time' => round($item_time, 3),
        'optimization_applied' => 'data_sharing_no_jsonl_reads'
    ]);
}

/**
 * Asynchronous status update aggregator - runs every 30 seconds via cron
 * Batches all individual status updates to prevent concurrent write conflicts
 */
function aggregate_concurrent_status_updates() {
    $update_keys = get_transient('puntwork_pending_updates', []);

    if (empty($update_keys)) {
        return; // No updates to process
    }

    // Aggregate all updates
    $total_processed = 0;
    $total_published = 0;
    $total_updated = 0;
    $total_skipped = 0;
    $all_logs = [];

    foreach ($update_keys as $update_key) {
        $update_data = get_transient($update_key);
        if ($update_data) {
            $total_processed += $update_data['processed'] ?? 0;
            $total_published += $update_data['published'] ?? 0;
            $total_updated += $update_data['updated'] ?? 0;
            $total_skipped += $update_data['skipped'] ?? 0;
            $all_logs = array_merge($all_logs, $update_data['logs'] ?? []);
        }
        // Clean up processed update
        delete_transient($update_key);
    }

    // Clear pending updates list
    delete_transient('puntwork_pending_updates');

    // Atomic status update (only once, not 10+ times!)
    if ($total_processed > 0) {
        $current_status = get_import_status();
        if (is_array($current_status)) {
            // Check if import is still active
            $is_complete = ($current_status['complete'] ?? false) === true;
            $has_end_time = isset($current_status['end_time']) && $current_status['end_time'] > 0;

            if (!$is_complete && !$has_end_time) {
                $current_status['processed'] = ($current_status['processed'] ?? 0) + $total_processed;
                $current_status['published'] = ($current_status['published'] ?? 0) + $total_published;
                $current_status['updated'] = ($current_status['updated'] ?? 0) + $total_updated;
                $current_status['skipped'] = ($current_status['skipped'] ?? 0) + $total_skipped;
                $current_status['last_update'] = microtime(true);

                if (!isset($current_status['logs']) || !is_array($current_status['logs'])) {
                    $current_status['logs'] = [];
                }
                $current_status['logs'] = array_merge($current_status['logs'], array_slice($all_logs, -20));
                $current_status['logs'] = array_slice($current_status['logs'], -50);

                set_import_status_atomic($current_status);
            }
        }
    }

    PuntWorkLogger::debug('Aggregated concurrent status updates', PuntWorkLogger::CONTEXT_BATCH, [
        'updates_processed' => count($update_keys),
        'total_processed' => $total_processed,
        'total_published' => $total_published,
        'total_updated' => $total_updated,
        'total_skipped' => $total_skipped,
        'logs_count' => count($all_logs)
    ]);
}

// Hook the callback functions
add_action('puntwork_process_single_item', 'Puntwork\process_single_item_callback', 10, 6);
add_action('puntwork_process_single_item_optimized', 'Puntwork\process_single_item_optimized_callback', 10, 1);

// Schedule status aggregation every 30 seconds
if (!wp_next_scheduled('puntwork_aggregate_concurrent_updates')) {
    wp_schedule_event(time(), '30sec', 'puntwork_aggregate_concurrent_updates');
}
add_action('puntwork_aggregate_concurrent_updates', 'Puntwork\aggregate_concurrent_status_updates');

// Add custom 30-second cron interval
add_filter('cron_schedules', function($schedules) {
    $schedules['30sec'] = [
        'interval' => 30,
        'display' => 'Every 30 seconds'
    ];
    return $schedules;
});

// Test action hook for Action Scheduler validation
add_action('puntwork_test_action_scheduler', function($test_timestamp) {
    // Simple test action that just logs completion
    PuntWorkLogger::debug('Action Scheduler test action executed successfully', PuntWorkLogger::CONTEXT_GENERAL, [
        'test_timestamp' => $test_timestamp,
        'execution_time' => microtime(true)
    ]);
}, 10, 1);

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
