<?php
/**
 * Streaming import processing architecture
 *
 * @package    Puntwork
 * @subpackage Import
 * @since      1.1.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/../utilities/options-utilities.php';
require_once __DIR__ . '/../utilities/puntwork-logger.php';

/**
 * Streaming architecture to replace batch processing
 * Processes items one by one from the feed stream with adaptive resource management
 */

/**
 * Main streaming import function with composite key system
 *
 * @param bool $preserve_status Whether to preserve existing status for continuation
 * @return array Import result data
 */
function import_jobs_streaming($preserve_status = false) {
    $start_time = microtime(true);
    $composite_keys_processed = 0;
    $processed = 0;
    $published = 0;
    $updated = 0;
    $skipped = 0;
    $all_logs = [];
    $streaming_stats = [
        'start_time' => $start_time,
        'end_time' => null,
        'items_streamed' => 0,
        'memory_peaks' => [],
        'time_per_item' => [],
        'adaptive_resources' => []
    ];

    // Initialize streaming status
    $status_key = 'streaming_import_status';
    if (!$preserve_status) {
        $streaming_status = [
            'phase' => 'initializing',
            'processed' => 0,
            'total' => 0,
            'published' => 0,
            'updated' => 0,
            'skipped' => 0,
            'streaming_metrics' => $streaming_stats,
            'composite_key_cache' => [],
            'resource_limits' => get_adaptive_resource_limits(),
            'circuit_breaker' => ['failures' => 0, 'last_failure' => null, 'state' => 'closed'],
            'start_time' => $start_time,
            'complete' => false,
            'success' => false,
            'last_update' => microtime(true),
            'logs' => ['Streaming import initialized']
        ];
        update_option($status_key, $streaming_status, false);
    } else {
        $streaming_status = get_option($status_key, []);
        if (empty($streaming_status)) {
            return ['success' => false, 'message' => 'No streaming status to preserve', 'logs' => []];
        }
        // Resume from previous state
        $composite_keys_processed = $streaming_status['processed'] ?? 0;
        $streaming_stats = $streaming_status['streaming_metrics'] ?? $streaming_stats;
    }

    PuntWorkLogger::info('Streaming import started', PuntWorkLogger::CONTEXT_IMPORT, [
        'preserve_status' => $preserve_status,
        'start_position' => $composite_keys_processed,
        'resource_limits' => $streaming_status['resource_limits']
    ]);

    try {
        // Prepare streaming environment
        $stream_setup = prepare_streaming_environment();
        if (is_wp_error($stream_setup)) {
            return ['success' => false, 'message' => $stream_setup->get_error_message(), 'logs' => ['Stream setup failed']];
        }

        $json_path = $stream_setup['json_path'];
        $total_items = $stream_setup['total_items'];

        // Update status with total
        $streaming_status['total'] = $total_items;
        $streaming_status['phase'] = 'streaming';
        update_option($status_key, $streaming_status, false);

        // Initialize composite key cache for duplicate detection
        $composite_key_cache = $streaming_status['composite_key_cache'] ?? [];
        if (empty($composite_key_cache)) {
            $composite_key_cache = initialize_composite_key_cache($json_path);
            $streaming_status['composite_key_cache'] = $composite_key_cache;
            update_option($status_key, $streaming_status, false);
        }

        PuntWorkLogger::info('Starting item streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'total_items' => $total_items,
            'composite_keys_cached' => count($composite_key_cache),
            'starting_from' => $composite_keys_processed
        ]);

        // Begin true streaming processing - process items one by one from stream
        $streaming_result = process_feed_stream_optimized(
            $json_path,
            $composite_keys_processed,
            $streaming_status,
            $status_key,
            $streaming_stats,
            $processed,
            $published,
            $updated,
            $skipped,
            $all_logs
        );

        // Finalize streaming import
        $streaming_stats['end_time'] = microtime(true);
        $total_duration = $streaming_stats['end_time'] - $start_time;

        $final_result = [
            'success' => $streaming_result['success'],
            'processed' => $processed,
            'total' => $total_items,
            'published' => $published,
            'updated' => $updated,
            'skipped' => $skipped,
            'time_elapsed' => $total_duration,
            'complete' => $streaming_result['complete'],
            'logs' => $all_logs,
            'streaming_metrics' => $streaming_stats,
            'resource_adaptations' => $streaming_status['resource_limits']['adaptations'] ?? [],
            'message' => $streaming_result['success'] ? 'Streaming import completed successfully' : 'Streaming import encountered issues'
        ];

        // Clean up streaming status
        delete_option($status_key);

        PuntWorkLogger::info('Streaming import completed', PuntWorkLogger::CONTEXT_IMPORT, [
            'total_duration' => $total_duration,
            'items_processed' => $processed,
            'avg_time_per_item' => $processed > 0 ? $total_duration / $processed : 0,
            'success_rate' => $processed > 0 ? ($published + $updated) / $processed : 1.0
        ]);

        // INTEGRATE AUTOMATIC CLEANUP: Run after fully successful import
        if ($final_result['success'] && $final_result['complete']) {
            PuntWorkLogger::info('Import completed successfully, running automatic cleanup', PuntWorkLogger::CONTEXT_IMPORT, [
                'processed' => $final_result['processed'],
                'published' => $final_result['published'],
                'updated' => $final_result['updated']
            ]);

            // Import the finalization functions
            require_once __DIR__ . '/import-finalization.php';

            // Run cleanup with safeguard validations
            $final_result = \Puntwork\finalize_import_with_cleanup($final_result);
        }

        return $final_result;

    } catch (\Exception $e) {
        PuntWorkLogger::error('Streaming import failed', PuntWorkLogger::CONTEXT_IMPORT, [
            'error' => $e->getMessage(),
            'processed_so_far' => $processed,
            'trace' => $e->getTraceAsString()
        ]);

        // Update status with error
        $streaming_status['success'] = false;
        $streaming_status['complete'] = true;
        $streaming_status['error_message'] = $e->getMessage();
        $streaming_status['last_update'] = microtime(true);
        $streaming_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Streaming import failed: ' . $e->getMessage();
        update_option($status_key, $streaming_status, false);

        return [
            'success' => false,
            'message' => 'Streaming import failed: ' . $e->getMessage(),
            'logs' => $all_logs,
            'processed' => $processed
        ];
    }
}

/**
 * Prepare the streaming environment with adaptive resource management
 */
function prepare_streaming_environment() {
    do_action('qm/cease'); // Disable Query Monitor
    ini_set('memory_limit', '512M');
    set_time_limit(1800);
    ignore_user_abort(true);

    global $wpdb;
    if (!defined('WP_IMPORTING')) {
        define('WP_IMPORTING', true);
    }
    wp_suspend_cache_invalidation(true);

    $json_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';

    if (!file_exists($json_path) || !is_readable($json_path)) {
        return new \WP_Error('stream_setup_failed', 'Feed file not accessible');
    }

    // Count items efficiently for streaming
    $total_items = get_json_item_count($json_path);

    if ($total_items == 0) {
        return new \WP_Error('stream_setup_failed', 'Empty feed file');
    }

    return [
        'json_path' => $json_path,
        'total_items' => $total_items
    ];
}

/**
 * Initialize composite key cache for duplicate detection
 * Uses "source_feed_slug + GUID + pubdate" format
 */
function initialize_composite_key_cache($json_path) {
    global $wpdb;

    PuntWorkLogger::info('Initializing composite key cache', PuntWorkLogger::CONTEXT_IMPORT);

    // Get existing jobs with their composite keys
    $existing_jobs = $wpdb->get_results("
        SELECT p.ID, p.post_title,
               pm_guid.meta_value as guid,
               pm_pubdate.meta_value as pubdate,
               pm_source.meta_value as source_feed_slug
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm_guid ON p.ID = pm_guid.post_id AND pm_guid.meta_key = 'guid'
        LEFT JOIN {$wpdb->postmeta} pm_pubdate ON p.ID = pm_pubdate.post_id AND pm_pubdate.meta_key = 'pubdate'
        LEFT JOIN {$wpdb->postmeta} pm_source ON p.ID = pm_source.post_id AND pm_source.meta_key = 'source_feed_slug'
        WHERE p.post_type = 'job'
        AND p.post_status IN ('publish', 'draft')
        AND pm_guid.meta_value IS NOT NULL
    ");

    $composite_cache = [];
    foreach ($existing_jobs as $job) {
        $composite_key = generate_composite_key(
            $job->source_feed_slug ?? 'unknown',
            $job->guid,
            $job->pubdate ?? ''
        );
        $composite_cache[$composite_key] = $job->ID;
    }

    PuntWorkLogger::info('Composite key cache initialized', PuntWorkLogger::CONTEXT_IMPORT, [
        'cached_keys' => count($composite_cache),
        'sample_keys' => array_slice(array_keys($composite_cache), 0, 3)
    ]);

    return $composite_cache;
}

/**
 * Generate composite key from source feed slug, GUID, and pubdate
 */
function generate_composite_key($source_feed_slug, $guid, $pubdate) {
    // Normalize components
    $normalized_slug = strtolower(trim($source_feed_slug));
    $normalized_guid = trim($guid);
    $normalized_pubdate = $pubdate ? date('Y-m-d', strtotime($pubdate)) : '';

    // Create deterministic composite key
    $composite_key = sprintf(
        '%s|%s|%s',
        $normalized_slug,
        $normalized_guid,
        $normalized_pubdate
    );

    return $composite_key;
}

/**
 * Process feed stream item by item with adaptive resource management and memory optimization
 */
/**
 * OPTIMIZED STREAMING PROCESSING with memory efficiency and performance optimizations
 * True streaming: processes one item at a time without loading full file into memory
 */
function process_feed_stream_optimized($json_path, &$composite_keys_processed, &$streaming_status, $status_key, &$streaming_stats, &$processed, &$published, &$updated, &$skipped, &$all_logs) {
    $bookmarks = get_bookmarks_for_streaming(); // Pre-load common ACF fields and reduce function calls
    $resource_limits = get_adaptive_resource_limits();
    $circuit_breaker = $streaming_status['circuit_breaker'];

    // True streaming: process one item at a time from file stream
    $handle = fopen($json_path, 'r');
    if (!$handle) {
        return ['success' => false, 'complete' => false, 'message' => 'Failed to open feed stream'];
    }

    // Skip to resume position
    if ($composite_keys_processed > 0) {
        for ($i = 0; $i < $composite_keys_processed; $i++) {
            if (fgets($handle) === false) break; // EOF reached
        }
    }

    $item_index = $composite_keys_processed;
    $should_continue = true;
    $batch_start_time = microtime(true);

    // Batch operations for ACF field updates (group to reduce individual update_field() calls)
    $acf_update_queue = [];
    $acf_create_queue = [];
    $batch_size = 50; // Process ACF in batches to reduce overhead

    // Memory optimization: Use generator/iterator pattern to reduce memory footprint
    while ($should_continue && ($line = fgets($handle)) !== false) {
        $item_start_time = microtime(true);

        $line = trim($line);
        if (empty($line)) {
            $item_index++;
            continue;
        }

        // Parse JSON item efficiently
        $item = json_decode($line, true, 4, JSON_INVALID_UTF8_IGNORE); // Faster decoding with UTF8 ignore
        if ($item === null || !isset($item['guid'])) {
            $skipped++;
            $item_index++;
            continue;
        }

        // DUPLICATE DETECTION OPTIMIZATION: Use composite keys for O(1) lookup
        $composite_key = generate_composite_key(
            $item['source_feed_slug'] ?? 'unknown',
            $item['guid'],
            $item['pubdate'] ?? ''
        );

        if (isset($streaming_status['composite_key_cache'][$composite_key])) {
            // Existing job - check if update needed (smart update logic)
            $existing_post_id = $streaming_status['composite_key_cache'][$composite_key];

            // SMART UPDATE CHECK: Only update if content actually changed
            if (should_update_existing_job_smart($existing_post_id, $item)) {
                // Queue ACF updates in batches
                $acf_update_queue[$existing_post_id] = $item;

                // Process ACF queue when it reaches batch size
                if (count($acf_update_queue) >= $batch_size) {
                    process_acf_queue_batch($acf_update_queue, $acf_create_queue, 'update');
                    $acf_update_queue = [];
                }

                $updated++;
                // Queued log entry
                $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Updated job ' . $existing_post_id . ' (composite key: ' . $composite_key . ')';
            } else {
                $skipped++;
                // Minimal logging for performance
            }
        } else {
            // New job - create with batch ACF processing
            $post_data = [
                'post_title' => $item['title'] ?? '',
                'post_content' => $item['description'] ?? '',
                'post_status' => 'publish',
                'post_type' => 'job',
                'post_author' => ''
            ];

            $post_id = wp_insert_post($post_data);
            if (!is_wp_error($post_id)) {
                $streaming_status['composite_key_cache'][$composite_key] = $post_id;

                // Queue ACF creation in batches
                $acf_create_queue[$post_id] = $item;

                // Process ACF queue when it reaches batch size
                if (count($acf_create_queue) >= $batch_size) {
                    process_acf_queue_batch($acf_update_queue, $acf_create_queue, 'create');
                    $acf_create_queue = [];
                }

                $published++;
                // Queued log entry
                $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Created new job ' . $post_id . ' (composite key: ' . $composite_key . ')';
            } else {
                $skipped++;
                $circuit_breaker = handle_circuit_breaker_failure($circuit_breaker, 'create_failure');
            }
        }

        // MEMORY OPTIMIZATION: Explicit garbage collection every 100 items
        if (($processed + 1) % 100 === 0) {
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            // Clear any temporary variables
            unset($line, $item);
        }

        $processed++;
        $item_index++;
        $composite_keys_processed = $item_index;

        // PERFORMANCE METRICS: Optimized tracking
        $item_duration = microtime(true) - $item_start_time;
        if ($processed % 10 === 0) { // Sample every 10 items instead of all
            $streaming_stats['time_per_item'][] = $item_duration;
            $streaming_stats['memory_peaks'][] = memory_get_peak_usage(true);
        }

        // ADAPTIVE RESOURCE MANAGEMENT: Optimized checks
        $should_continue = check_streaming_resources_optimized($resource_limits, $streaming_stats, $circuit_breaker);

        // Check for emergency stop (less frequent)
        if ($processed % 50 === 0 && get_transient('import_emergency_stop') === true) {
            $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Emergency stop requested';
            $should_continue = false;
        }

        // PROGRESS MONITORING: Optimized reporting
        if ($processed % 100 === 0) { // Progress every 100 items instead of every batch processing
            emit_progress_event($streaming_status, $processed, $published, $updated, $skipped, $streaming_stats);

            // Update status efficiently
            $streaming_status['processed'] = $processed;
            $streaming_status['published'] = $published;
            $streaming_status['updated'] = $updated;
            $streaming_status['skipped'] = $skipped;
            $streaming_status['streaming_metrics'] = $streaming_stats;
            $streaming_status['circuit_breaker'] = $circuit_breaker;
            $streaming_status['last_update'] = microtime(true);
            update_option($status_key, $streaming_status, false);
        }
    }

    fclose($handle);

    // Final ACF batch processing
    process_acf_queue_batch($acf_update_queue, $acf_create_queue, 'final');

    // Final status update
    $streaming_status['complete'] = true;
    $streaming_status['success'] = true;
    $streaming_status['last_update'] = microtime(true);

    PuntWorkLogger::info('Streaming processing completed successfully', PuntWorkLogger::CONTEXT_IMPORT, [
        'total_processed' => $processed,
        'published' => $published,
        'updated' => $updated,
        'skipped' => $skipped,
        'total_time' => microtime(true) - $batch_start_time,
        'avg_time_per_item' => $processed > 0 ? (microtime(true) - $batch_start_time) / $processed : 0
    ]);

    update_option($status_key, $streaming_status, false);

    return ['success' => true, 'complete' => true];
}

/**
 * Enhanced duplicate detection with smart update checking
 */
function should_update_existing_job_smart($post_id, $item) {
    static $update_check_cache = [];
    $cache_key = $post_id . '_' . md5(serialize($item));

    if (isset($update_check_cache[$cache_key])) {
        return $update_check_cache[$cache_key];
    }

    // Quick pubdate comparison first (most common change)
    $current_pubdate = get_post_meta($post_id, 'pubdate', true);
    $new_pubdate = $item['pubdate'] ?? '';
    if ($new_pubdate && strtotime($new_pubdate) > strtotime($current_pubdate)) {
        $update_check_cache[$cache_key] = true;
        return true;
    }

    // Check if title or content changed (use hash comparison for speed)
    $current_title = get_post_field('post_title', $post_id);
    $new_title = $item['title'] ?? $item['functiontitle'] ?? '';
    if ($current_title !== $new_title) {
        $update_check_cache[$cache_key] = true;
        return true;
    }

    $update_check_cache[$cache_key] = false;
    return false;
}

/**
 * Batch process ACF field updates for performance
 */
function process_acf_queue_batch(&$update_queue, &$create_queue, $operation = 'batch') {
    static $acf_fields = null;

    // Lazy load ACF field mappings once
    if ($acf_fields === null) {
        $acf_fields = get_acf_fields();
    }

    // Process update queue
    foreach ($update_queue as $post_id => $item) {
        $selective_fields = get_acf_fields_selective($item);
        foreach ($selective_fields as $key => $value) {
            if (function_exists('update_field')) {
                update_field($key, $value, $post_id);
            }
        }

        // Update metadata in batch
        update_post_meta($post_id, '_last_import_update', current_time('mysql'));
        update_post_meta($post_id, 'guid', $item['guid']);
        update_post_meta($post_id, 'pubdate', $item['pubdate'] ?? '');
    }

    // Process create queue
    foreach ($create_queue as $post_id => $item) {
        $selective_fields = get_acf_fields_selective($item);
        foreach ($selective_fields as $key => $value) {
            if (function_exists('update_field')) {
                update_field($key, $value, $post_id);
            }
        }

        // Update metadata in batch
        update_post_meta($post_id, 'guid', $item['guid']);
        update_post_meta($post_id, 'pubdate', $item['pubdate'] ?? '');
        update_post_meta($post_id, 'source_feed_slug', $item['source_feed_slug'] ?? 'unknown');
        update_post_meta($post_id, '_last_import_update', current_time('mysql'));
    }

    // Clear queues after processing
    $update_queue = [];
    $create_queue = [];
}

/**
 * Optimized resource checking with reduced frequency
 */
function check_streaming_resources_optimized($resource_limits, $streaming_stats, $circuit_breaker) {
    static $last_check = 0;
    static $check_interval = 10; // Check every 10 seconds instead of 5
    $now = microtime(true);

    if ($now - $last_check < $check_interval) {
        return true;
    }
    $last_check = $now;

    // Memory check
    $current_memory = memory_get_usage(true);
    if ($current_memory > $resource_limits['max_memory_usage']) {
        PuntWorkLogger::warn('Memory limit approached in optimized streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'current_memory_mb' => $current_memory / 1024 / 1024,
            'limit_mb' => $resource_limits['max_memory_usage'] / 1024 / 1024,
            'triggered_shutdown' => true
        ]);
        return false;
    }

    // Time check
    $elapsed = $now - $streaming_stats['start_time'];
    if ($elapsed > $resource_limits['max_execution_time']) {
        PuntWorkLogger::warn('Time limit approached in optimized streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'elapsed_seconds' => $elapsed,
            'limit_seconds' => $resource_limits['max_execution_time'],
            'triggered_shutdown' => true
        ]);
        return false;
    }

    return true;
}

/**
 * Get bookmarks for streaming to preload common data
 */
function get_bookmarks_for_streaming() {
    return [
        'acf_available' => function_exists('update_field'),
        'admin_user_id' => get_user_by('login', 'admin') ? get_user_by('login', 'admin')->ID : 1,
        'memory_limit_bytes' => get_memory_limit_bytes()
    ];
}

function process_feed_stream($json_path, &$composite_keys_processed, &$streaming_status, $status_key, &$streaming_stats, &$processed, &$published, &$updated, &$skipped, &$all_logs) {
    $resource_limits = get_adaptive_resource_limits();
    $circuit_breaker = $streaming_status['circuit_breaker'];

    // Open stream
    $handle = fopen($json_path, 'r');
    if (!$handle) {
        return ['success' => false, 'complete' => false, 'message' => 'Failed to open feed stream'];
    }

    $item_index = 0;
    $should_continue = true;

    while ($should_continue && ($line = fgets($handle)) !== false) {
        $item_start_time = microtime(true);

        // Skip to current position if resuming
        if ($item_index < $composite_keys_processed) {
            $item_index++;
            continue;
        }

        $line = trim($line);
        if (empty($line)) {
            $item_index++;
            continue;
        }

        // Parse JSON item
        $item = json_decode($line, true);
        if ($item === null || !isset($item['guid'])) {
            $skipped++;
            $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Skipped item ' . ($item_index + 1) . ': Invalid JSON or missing GUID';
            $item_index++;
            continue;
        }

        // Check composite key for duplicates
        $composite_key = generate_composite_key(
            $item['source_feed_slug'] ?? 'unknown',
            $item['guid'],
            $item['pubdate'] ?? ''
        );

        if (isset($streaming_status['composite_key_cache'][$composite_key])) {
            // Existing job - check if update needed
            $existing_post_id = $streaming_status['composite_key_cache'][$composite_key];

            if (should_update_existing_job($existing_post_id, $item)) {
                $update_result = update_job_streaming($existing_post_id, $item);
                if ($update_result['success']) {
                    $updated++;
                    $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Updated job ' . $existing_post_id . ' (composite key: ' . $composite_key . ')';
                } else {
                    $skipped++;
                    $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Failed to update job ' . $existing_post_id . ': ' . $update_result['message'];
                }
            } else {
                $skipped++;
                $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Skipped existing job ' . $existing_post_id . ' (no changes needed)';
            }
        } else {
            // New job - create it
            $create_result = create_job_streaming($item);
            if ($create_result['success']) {
                $published++;
                $streaming_status['composite_key_cache'][$composite_key] = $create_result['post_id'];
                $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Created new job ' . $create_result['post_id'] . ' (composite key: ' . $composite_key . ')';
            } else {
                $skipped++;
                $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Failed to create job: ' . $create_result['message'];
                // Trigger circuit breaker for creation failures
                $circuit_breaker = handle_circuit_breaker_failure($circuit_breaker, 'create_failure');
            }
        }

        $processed++;
        $item_index++;
        $composite_keys_processed = $item_index;

        // Record streaming metrics
        $item_duration = microtime(true) - $item_start_time;
        $streaming_stats['time_per_item'][] = $item_duration;
        $streaming_stats['memory_peaks'][] = memory_get_peak_usage(true);

        // Adaptive resource checking
        $should_continue = check_streaming_resources($resource_limits, $streaming_stats, $circuit_breaker);

        // Event-driven progress reporting (every 10 items)
        if ($processed % 10 === 0) {
            emit_progress_event($streaming_status, $processed, $published, $updated, $skipped, $streaming_stats);
        }

        // Check for emergency stop
        if (get_transient('import_emergency_stop') === true) {
            $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Emergency stop requested';
            $should_continue = false;
        }

        // Save progress periodically (every 100 items)
        if ($processed % 100 === 0) {
            $streaming_status['processed'] = $processed;
            $streaming_status['published'] = $published;
            $streaming_status['updated'] = $updated;
            $streaming_status['skipped'] = $skipped;
            $streaming_status['streaming_metrics'] = $streaming_stats;
            $streaming_status['circuit_breaker'] = $circuit_breaker;
            $streaming_status['last_update'] = microtime(true);
            update_option($status_key, $streaming_status, false);
        }
    }

    fclose($handle);

    // Final status update
    $streaming_status['complete'] = true;
    $streaming_status['success'] = true;
    $streaming_status['last_update'] = microtime(true);
    update_option($status_key, $streaming_status, false);

    return ['success' => true, 'complete' => true];
}

/**
 * Get adaptive resource limits based on server capacity
 */
function get_adaptive_resource_limits() {
    $memory_limit = get_memory_limit_bytes();
    $time_limit = ini_get('max_execution_time');

    // Calculate adaptive limits based on server capacity
    $adaptive_limits = [
        'max_memory_usage' => $memory_limit * 0.8, // 80% of available memory
        'max_execution_time' => max(600, $time_limit - 60), // Leave 60 seconds buffer
        'items_per_memory_check' => 50,
        'items_per_time_check' => 25,
        'acceptable_failure_rate' => 0.05, // 5% failure rate threshold
        'circuit_breaker_threshold' => 3, // 3 consecutive failures trigger circuit breaker
        'adaptations' => []
    ];

    // Detect server capacity indicators
    $cpu_count = function_exists('shell_exec') ? (int)shell_exec('nproc 2>/dev/null') : 2;
    $adaptive_limits['cpu_cores'] = $cpu_count;

    if ($cpu_count >= 8) {
        $adaptive_limits['high_performance'] = true;
        $adaptive_limits['max_memory_usage'] = $memory_limit * 0.9; // Use more memory on powerful servers
        $adaptive_limits['adaptations'][] = 'high_performance_detected';
    }

    PuntWorkLogger::info('Adaptive resource limits calculated', PuntWorkLogger::CONTEXT_IMPORT, [
        'limits' => $adaptive_limits,
        'server_memory_mb' => $memory_limit / 1024 / 1024,
        'server_time_limit' => $time_limit,
        'cpu_cores' => $cpu_count
    ]);

    return $adaptive_limits;
}

/**
 * Check if streaming should continue based on adaptive resource limits
 */
function check_streaming_resources($resource_limits, $streaming_stats, $circuit_breaker) {
    static $last_check = 0;
    $now = microtime(true);

    // Only check every few seconds to avoid overhead
    if ($now - $last_check < 5) {
        return true;
    }
    $last_check = $now;

    // Memory check
    $current_memory = memory_get_usage(true);
    if ($current_memory > $resource_limits['max_memory_usage']) {
        PuntWorkLogger::warn('Memory limit exceeded in streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'current_memory_mb' => $current_memory / 1024 / 1024,
            'limit_mb' => $resource_limits['max_memory_usage'] / 1024 / 1024
        ]);
        return false;
    }

    // Time check
    $elapsed = $now - $streaming_stats['start_time'];
    if ($elapsed > $resource_limits['max_execution_time']) {
        PuntWorkLogger::warn('Time limit exceeded in streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'elapsed_seconds' => $elapsed,
            'limit_seconds' => $resource_limits['max_execution_time']
        ]);
        return false;
    }

    // Circuit breaker check
    if ($circuit_breaker['state'] === 'open') {
        PuntWorkLogger::warn('Circuit breaker open - stopping streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'failures' => $circuit_breaker['failures'],
            'last_failure' => $circuit_breaker['last_failure']
        ]);
        return false;
    }

    return true;
}

/**
 * Handle circuit breaker failure
 */
function handle_circuit_breaker_failure($circuit_breaker, $failure_type) {
    $circuit_breaker['failures']++;
    $circuit_breaker['last_failure'] = microtime(true);
    $circuit_breaker['last_failure_type'] = $failure_type;

    // Open circuit breaker after threshold
    if ($circuit_breaker['failures'] >= 3) {
        $circuit_breaker['state'] = 'open';
        PuntWorkLogger::error('Circuit breaker opened', PuntWorkLogger::CONTEXT_IMPORT, [
            'failure_threshold' => 3,
            'failures' => $circuit_breaker['failures'],
            'last_failure_type' => $failure_type
        ]);
    }

    return $circuit_breaker;
}

/**
 * Event-driven progress reporting
 */
function emit_progress_event($streaming_status, $processed, $published, $updated, $skipped, $streaming_stats) {
    $progress_event = [
        'type' => 'streaming_progress',
        'processed' => $processed,
        'published' => $published,
        'updated' => $updated,
        'skipped' => $skipped,
        'memory_usage' => memory_get_usage(true) / 1024 / 1024,
        'avg_time_per_item' => count($streaming_stats['time_per_item']) > 0 ?
            array_sum($streaming_stats['time_per_item']) / count($streaming_stats['time_per_item']) : 0,
        'timestamp' => microtime(true)
    ];

    // Store in status for UI polling (replaces heartbeat polling)
    update_option('streaming_import_status', $streaming_status + ['last_progress_event' => $progress_event], false);

    // Trigger WordPress action for real-time updates
    do_action('puntwork_streaming_progress', $progress_event);
}

/**
 * Check if existing job should be updated based on pubdate
 */
function should_update_existing_job($post_id, $item) {
    $current_pubdate = get_post_meta($post_id, 'pubdate', true);
    $new_pubdate = $item['pubdate'] ?? '';

    if (empty($new_pubdate)) {
        return false; // No pubdate to compare
    }

    // Update if new pubdate is newer or significantly different
    return strtotime($new_pubdate) > strtotime($current_pubdate);
}

/**
 * Update job in streaming context
 */
function update_job_streaming($post_id, $item) {
    try {
        // Get ACF fields efficiently (lazy loading)
        $acf_fields = get_acf_fields();

        $update_result = wp_update_post([
            'ID' => $post_id,
            'post_title' => $item['title'] ?? '',
            'post_content' => $item['description'] ?? '',
            'post_status' => 'publish',
            'post_modified' => current_time('mysql'),
            'post_author' => 0 // Clear author if present
        ]);

        if (is_wp_error($update_result)) {
            return ['success' => false, 'message' => $update_result->get_error_message()];
        }

        // Update ACF fields selectively
        update_job_acf_fields_selective($post_id, $item, $acf_fields);

        // Update metadata
        update_post_meta($post_id, '_last_import_update', current_time('mysql'));
        update_post_meta($post_id, 'guid', $item['guid']);
        update_post_meta($post_id, 'pubdate', $item['pubdate'] ?? '');

        return ['success' => true];
    } catch (\Exception $e) {
        PuntWorkLogger::error('Error updating job in streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'post_id' => $post_id,
            'error' => $e->getMessage()
        ]);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Create job in streaming context
 */
function create_job_streaming($item) {
    try {
        // Get ACF fields efficiently
        $acf_fields = get_acf_fields();

        $post_data = [
            'post_title' => $item['title'] ?? '',
            'post_content' => $item['description'] ?? '',
            'post_status' => 'publish',
            'post_type' => 'job'
            // Removed post_author to ensure no author is set
        ];

        $post_id = wp_insert_post($post_data);
        if (is_wp_error($post_id)) {
            return ['success' => false, 'message' => $post_id->get_error_message()];
        }

        // Set ACF fields
        set_job_acf_fields_selective($post_id, $item, $acf_fields);

        // Set metadata
        update_post_meta($post_id, 'guid', $item['guid']);
        update_post_meta($post_id, 'pubdate', $item['pubdate'] ?? '');
        update_post_meta($post_id, 'source_feed_slug', $item['source_feed_slug'] ?? 'unknown');
        update_post_meta($post_id, '_last_import_update', current_time('mysql'));

        return ['success' => true, 'post_id' => $post_id];
    } catch (\Exception $e) {
        PuntWorkLogger::error('Error creating job in streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'guid' => $item['guid'] ?? 'unknown',
            'error' => $e->getMessage()
        ]);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Selective ACF field loading and updating for efficiency
 */
function get_acf_fields_selective($item) {
    $acf_fields = [];

    // Only load ACF fields that are present in the item
    $field_mappings = [
        'job_location' => $item['location'] ?? null,
        'job_company' => $item['company'] ?? null,
        'job_salary_min' => $item['salary_min'] ?? null,
        'job_salary_max' => $item['salary_max'] ?? null,
        // Add other field mappings as needed
    ];

    foreach ($field_mappings as $acf_key => $value) {
        if ($value !== null) {
            $acf_fields[$acf_key] = $value;
        }
    }

    return $acf_fields;
}

function update_job_acf_fields_selective($post_id, $item, $acf_fields) {
    $selective_fields = get_acf_fields_selective($item);

    foreach ($selective_fields as $key => $value) {
        update_field($key, $value, $post_id);
    }
}

function set_job_acf_fields_selective($post_id, $item, $acf_fields) {
    $selective_fields = get_acf_fields_selective($item);

    foreach ($selective_fields as $key => $value) {
        update_field($key, $value, $post_id);
    }
}
