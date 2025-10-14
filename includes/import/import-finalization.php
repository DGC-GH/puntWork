<?php
/**
 * Import finalization utilities
 *
 * @package    Puntwork
 * @subpackage Import
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/../utilities/options-utilities.php';

/**
 * Import finalization and status management
 * Handles completion of import batches and status updates
 */

/**
 * Finalize batch import and update status.
 *
 * @param array $result Processing result.
 * @return array Final result.
 */
function finalize_batch_import($result) {
    if (is_wp_error($result) || !$result['success']) {
        return $result;
    }

    $status = get_import_status() ?: [
        'total' => $result['total'],
        'processed' => 0,
        'published' => 0,
        'updated' => 0,
        'skipped' => 0,
        'duplicates_drafted' => 0,
        'time_elapsed' => 0,
        'complete' => false,
        'batch_size' => $result['batch_size'],
        'inferred_languages' => 0,
        'inferred_benefits' => 0,
        'schema_generated' => 0,
        'start_time' => $result['start_time'],
        'last_update' => microtime(true),
        'logs' => [],
    ];

    // Ensure start_time is set properly
    if (!isset($status['start_time']) || $status['start_time'] == 0) {
        $status['start_time'] = $result['start_time'] ?? microtime(true);
    }

    $status['processed'] = $result['processed'] ?? 0;
    $status['published'] += $result['published'] ?? 0;
    $status['updated'] += $result['updated'] ?? 0;
    $status['skipped'] += $result['skipped'] ?? 0;
    $status['duplicates_drafted'] += $result['duplicates_drafted'] ?? 0;

    // Calculate total elapsed time from start to now
    $current_time = microtime(true);
    $total_elapsed = $current_time - $status['start_time'];
    $status['time_elapsed'] = $total_elapsed;

    $status['complete'] = $result['complete'] ?? false;
    $status['success'] = $result['success'] ?? false; // Set success status
    $status['error_message'] = $result['message'] ?? ''; // Set error message if any
    $status['batch_size'] = $result['batch_size'] ?? $status['batch_size'];
    $status['inferred_languages'] += $result['inferred_languages'] ?? 0;
    $status['inferred_benefits'] += $result['inferred_benefits'] ?? 0;
    $status['schema_generated'] += $result['schema_generated'] ?? 0;
    $status['last_update'] = microtime(true);

    set_import_status($status);

    // Log completed manual imports to history (only when in AJAX context and import is complete)
    if (($result['complete'] ?? false) && ($result['success'] ?? false) && function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        $import_details = [
            'success' => $result['success'] ?? false,
            'duration' => $total_elapsed,
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0,
            'published' => $result['published'] ?? 0,
            'updated' => $result['updated'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
            'error_message' => $result['message'] ?? '',
            'timestamp' => microtime(true)
        ];

        // Import the function if not already available
        if (!function_exists(__NAMESPACE__ . '\\log_import_run')) {
            require_once __DIR__ . '/../scheduling/scheduling-history.php';
        }

        log_import_run($import_details, 'manual');

        PuntWorkLogger::info('Logged completed manual import to history', PuntWorkLogger::CONTEXT_BATCH, [
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0,
            'duration' => $total_elapsed
        ]);
    }

    return $result;
}

/**
 * Clean up import transients and temporary data.
 *
 * @return void
 */
function cleanup_import_data() {
    // Clean up transients
    delete_transient('import_cancel');

    // Clean up options that are no longer needed after successful import
    delete_import_progress();
    delete_processed_guids();
    delete_existing_guids();

    // Reset performance metrics for next import
    delete_time_per_job();
    delete_avg_time_per_job();
    delete_last_peak_memory();
    delete_batch_size();
    delete_consecutive_small_batches();
    delete_consecutive_batches();

    // Clean up batch timing data
    delete_last_batch_time();
    delete_last_batch_processed();
}

/**
 * Clean up old job posts that are no longer in the feed.
 * OPTIMIZED VERSION: Uses bulk operations to avoid database sync errors and improve performance
 *
 * @param float $import_start_time The timestamp when the import started.
 * @return array Array with deleted_count and logs.
 */
function cleanup_old_job_posts($import_start_time) {
    global $wpdb;

    $logs = []; // Initialize logs array

    PuntWorkLogger::info('Starting cleanup of old job posts based on current feed GUIDs', PuntWorkLogger::CONTEXT_BATCH, [
        'import_start_time' => date('Y-m-d H:i:s', (int)$import_start_time)
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Starting cleanup of old job posts';

    // Get all current GUIDs from the combined JSONL file with memory-safe chunked processing
    $json_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';
    $current_guids = [];

    if (!file_exists($json_path)) {
        PuntWorkLogger::error('Combined jobs file not found during cleanup - cannot proceed safely', PuntWorkLogger::CONTEXT_BATCH, [
            'json_path' => $json_path
        ]);
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ERROR: Combined jobs file not found - skipping cleanup to prevent unintended deletions';
        return [
            'deleted_count' => 0,
            'logs' => $logs
        ];
    }

    // MEMORY-SAFE: Load GUIDs in chunks to prevent memory exhaustion
    $guid_chunk_size = 1000;
    $guid_offset = 0;
    $total_guids_loaded = 0;

    PuntWorkLogger::info('Starting memory-safe GUID collection for finalization cleanup', PuntWorkLogger::CONTEXT_BATCH, [
        'json_path' => $json_path,
        'chunk_size' => $guid_chunk_size
    ]);

    while (true) {
        $chunk_guids = [];
        if (($handle = fopen($json_path, "r")) !== false) {
            $current_index = 0;
            $guids_in_chunk = 0;

            while (($line = fgets($handle)) !== false) {
                if ($current_index >= $guid_offset && $guids_in_chunk < $guid_chunk_size) {
                    $line = trim($line);
                    if (!empty($line)) {
                        $item = json_decode($line, true);
                        if ($item !== null && isset($item['guid'])) {
                            $chunk_guids[] = $item['guid'];
                            $guids_in_chunk++;
                        }
                    }
                } elseif ($guids_in_chunk >= $guid_chunk_size) {
                    break;
                }
                $current_index++;
            }
            fclose($handle);
        }

        if (empty($chunk_guids)) {
            break;
        }

        $current_guids = array_merge($current_guids, $chunk_guids);
        $guid_offset += $guid_chunk_size;
        $total_guids_loaded += count($chunk_guids);

        // MEMORY CHECK: Prevent excessive memory usage during GUID collection
        $current_memory = memory_get_usage(true);
        $memory_ratio = $current_memory / get_memory_limit_bytes();

        if ($memory_ratio > 0.6) {
            PuntWorkLogger::warning('High memory usage during finalization GUID collection, reducing chunk size', PuntWorkLogger::CONTEXT_BATCH, [
                'guids_loaded' => $total_guids_loaded,
                'memory_ratio' => $memory_ratio,
                'chunk_size_reduced' => true
            ]);
            $guid_chunk_size = max(200, $guid_chunk_size / 2);
        }

        // Force cleanup between chunks
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    if (empty($current_guids)) {
        PuntWorkLogger::error('No valid GUIDs found in combined jobs file - cannot proceed safely', PuntWorkLogger::CONTEXT_BATCH, [
            'json_path' => $json_path
        ]);
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ERROR: No valid GUIDs found in feed file - skipping cleanup to prevent unintended deletions';
        return [
            'deleted_count' => 0,
            'logs' => $logs
        ];
    }

    PuntWorkLogger::info('Found current GUIDs in feed with memory-safe loading', PuntWorkLogger::CONTEXT_BATCH, [
        'guid_count' => count($current_guids),
        'total_guids_loaded' => $total_guids_loaded,
        'chunks_processed' => ceil($total_guids_loaded / 1000),
        'memory_usage_mb' => memory_get_usage(true) / (1024 * 1024),
        'sample_guids' => array_slice($current_guids, 0, 5)
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Found ' . count($current_guids) . ' current GUIDs in feed';

    // Get all published job GUIDs and compare against current feed GUIDs with memory-safe processing
    $published_jobs = [];
    $guid_chunks = array_chunk($current_guids, 2000); // Process in chunks of 2000 for SQL IN() clauses

    foreach ($guid_chunks as $chunk_index => $guid_chunk) {
        $placeholders = implode(',', array_fill(0, count($guid_chunk), '%s'));
        $chunk_jobs = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT p.ID, pm.meta_value as guid
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
            WHERE p.post_type = 'job'
            AND p.post_status = 'publish'
            AND pm.meta_value IN ({$placeholders})
        ", $guid_chunk));

        $published_jobs = array_merge($published_jobs, $chunk_jobs);

        PuntWorkLogger::debug('Published jobs chunk processed', PuntWorkLogger::CONTEXT_BATCH, [
            'chunk_index' => $chunk_index,
            'chunk_size' => count($guid_chunk),
            'jobs_found_in_chunk' => count($chunk_jobs),
            'total_jobs_found' => count($published_jobs),
            'memory_usage_mb' => memory_get_usage(true) / (1024 * 1024)
        ]);

        // Memory cleanup between chunks
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    $current_guids_set = array_flip($current_guids); // For fast lookup
    $old_post_ids = [];

    foreach ($published_jobs as $job) {
        if (!isset($current_guids_set[$job->guid])) {
            $old_post_ids[] = $job->ID;
        }
    }

    $total_old_posts = count($old_post_ids);

    PuntWorkLogger::info('Total old job posts to clean up in finalization', PuntWorkLogger::CONTEXT_BATCH, [
        'total_old_posts' => $total_old_posts,
        'current_feed_jobs' => count($current_guids),
        'published_jobs_found' => count($published_jobs),
        'memory_usage_mb' => memory_get_usage(true) / (1024 * 1024),
        'sample_old_post_ids' => array_slice($old_post_ids, 0, 5)
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Found ' . $total_old_posts . ' old published job posts to clean up';

    if ($total_old_posts === 0) {
        PuntWorkLogger::info('No old posts to clean up', PuntWorkLogger::CONTEXT_BATCH);
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] No old posts to clean up';
        return [
            'deleted_count' => 0,
            'logs' => $logs
        ];
    }

    // Update status to show cleanup progress starting
    $cleanup_start_status = get_import_status([]);
    $cleanup_start_status['cleanup_total'] = $total_old_posts;
    $cleanup_start_status['cleanup_processed'] = 0;
    $cleanup_start_status['last_update'] = microtime(true);
    set_import_status($cleanup_start_status);

    // Process deletions in batches with memory monitoring to avoid memory issues and timeouts
    $batch_size = 100; // Start with 100 posts at a time, will adjust based on memory usage
    $total_deleted = 0;
    $batches_processed = 0;
    $memory_warnings = 0;

    while (!empty($old_post_ids)) {
        $batches_processed++;

        // MEMORY CHECK: Monitor memory usage and adjust batch size dynamically
        $current_memory = memory_get_usage(true);
        $memory_ratio = $current_memory / get_memory_limit_bytes();

        // Reduce batch size if memory usage is high
        if ($memory_ratio > 0.7) {
            $old_batch_size = $batch_size;
            $batch_size = max(10, intval($batch_size * 0.5)); // Reduce to 50%, minimum 10

            PuntWorkLogger::warning('High memory usage during finalization cleanup, reducing batch size', PuntWorkLogger::CONTEXT_BATCH, [
                'memory_ratio' => $memory_ratio,
                'old_batch_size' => $old_batch_size,
                'new_batch_size' => $batch_size,
                'batches_processed' => $batches_processed,
                'memory_warnings' => ++$memory_warnings
            ]);

            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] WARNING: High memory usage (' . round($memory_ratio * 100, 1) . '%), reducing batch size to ' . $batch_size;
        }

        // Log memory status periodically or when high
        if ($memory_ratio > 0.85 || $batches_processed % 10 === 0) {
            PuntWorkLogger::info('Memory status during finalization cleanup', PuntWorkLogger::CONTEXT_BATCH, [
                'memory_ratio' => $memory_ratio,
                'batch_size' => $batch_size,
                'batches_processed' => $batches_processed,
                'remaining_posts' => count($old_post_ids),
                'memory_usage_mb' => $current_memory / (1024 * 1024)
            ]);
        }

        $batch_ids = array_splice($old_post_ids, 0, $batch_size);

        PuntWorkLogger::debug('Processing deletion batch with memory monitoring', PuntWorkLogger::CONTEXT_BATCH, [
            'batch' => $batches_processed,
            'batch_size' => count($batch_ids),
            'remaining' => count($old_post_ids),
            'memory_ratio' => $memory_ratio
        ]);

        // Delete posts in this batch
        $deleted_in_batch = 0;
        foreach ($batch_ids as $post_id) {
            // Verify post still exists and is published before deletion
            $post_status = $wpdb->get_var($wpdb->prepare(
                "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'job'",
                $post_id
            ));

            if ($post_status === 'publish') {
                $result = wp_delete_post($post_id, true); // true = force delete, skip trash
                if ($result) {
                    $deleted_in_batch++;
                    $total_deleted++;
                } else {
                    PuntWorkLogger::warning('Failed to delete post', PuntWorkLogger::CONTEXT_BATCH, [
                        'post_id' => $post_id
                    ]);
                }
            }

            // Free result set after each query to prevent sync errors
            if ($wpdb->result instanceof mysqli_result) {
                $wpdb->result->free();
            }
        }

        // Update progress every batch (every 100 deletions)
        $cleanup_progress_status = get_import_status([]);
        $cleanup_progress_status['cleanup_processed'] = $total_deleted;
        $cleanup_progress_status['last_update'] = microtime(true);
        if (!is_array($cleanup_progress_status['logs'] ?? null)) {
            $cleanup_progress_status['logs'] = [];
        }
        $cleanup_progress_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Cleanup progress: ' . $total_deleted . '/' . $total_old_posts . ' old jobs deleted';
        set_import_status($cleanup_progress_status);

        PuntWorkLogger::debug('Batch deletion completed', PuntWorkLogger::CONTEXT_BATCH, [
            'batch' => $batches_processed,
            'deleted_in_batch' => $deleted_in_batch,
            'total_deleted' => $total_deleted,
            'remaining' => count($old_post_ids)
        ]);

        // Aggressive memory cleanup between batches to prevent memory exhaustion
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        // Flush WordPress object cache to free memory
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Force cleanup of any lingering references
        unset($batch_ids);

        // Small delay between batches to prevent overwhelming the server
        usleep(10000); // 0.01 seconds
    }

    // Final cleanup status update
    $final_cleanup_status = get_import_status([]);
    $final_cleanup_status['cleanup_processed'] = $total_deleted;
    $final_cleanup_status['last_update'] = microtime(true);
    set_import_status($final_cleanup_status);

    PuntWorkLogger::info('Cleanup of old published job posts completed', PuntWorkLogger::CONTEXT_BATCH, [
        'deleted_count' => $total_deleted,
        'current_feed_jobs' => count($current_guids),
        'batches_processed' => $batches_processed
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Cleanup completed: ' . $total_deleted . ' old published jobs deleted';

    return [
        'deleted_count' => $total_deleted,
        'logs' => $logs
    ];
}

/**
 * Clean up old job posts using direct SQL for maximum performance.
 * WARNING: This bypasses WordPress hooks and should only be used when wp_delete_post is too slow.
 * Use with extreme caution and thorough testing.
 *
 * @param array $post_ids Array of post IDs to delete
 * @return int Number of posts deleted
 */
function bulk_delete_job_posts_sql($post_ids) {
    global $wpdb;

    if (empty($post_ids)) {
        return 0;
    }

    $post_ids = array_map('intval', $post_ids);
    $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));

    // Begin transaction for data integrity
    $wpdb->query('START TRANSACTION');

    try {
        // Delete postmeta first (required before deleting posts)
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->postmeta}
            WHERE post_id IN ({$placeholders})
        ", $post_ids));

        // Delete term relationships
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->term_relationships}
            WHERE object_id IN ({$placeholders})
        ", $post_ids));

        // Delete posts
        $result = $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->posts}
            WHERE ID IN ({$placeholders}) AND post_type = 'job'
        ", $post_ids));

        // Commit transaction
        $wpdb->query('COMMIT');

        return $result;

    } catch (Exception $e) {
        // Rollback on error
        $wpdb->query('ROLLBACK');
        PuntWorkLogger::error('Bulk delete failed, rolled back', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage(),
            'post_count' => count($post_ids)
        ]);
        return 0;
    }
}