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

    $status = get_option('job_import_status') ?: [
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
        'last_update' => time(),
        'logs' => [],
    ];

    // Ensure start_time is set properly
    if (!isset($status['start_time']) || $status['start_time'] == 0) {
        $status['start_time'] = $result['start_time'] ?? microtime(true);
    }

    $status['processed'] = $result['processed'];
    $status['published'] += $result['published'];
    $status['updated'] += $result['updated'];
    $status['skipped'] += $result['skipped'];
    $status['duplicates_drafted'] += $result['duplicates_drafted'];

    // Calculate total elapsed time from start to now
    $current_time = microtime(true);
    $total_elapsed = $current_time - $status['start_time'];
    $status['time_elapsed'] = $total_elapsed;

    $status['complete'] = $result['complete'];
    $status['success'] = $result['success']; // Set success status
    $status['error_message'] = $result['message'] ?? ''; // Set error message if any
    $status['batch_size'] = $result['batch_size'];
    $status['inferred_languages'] += $result['inferred_languages'];
    $status['inferred_benefits'] += $result['inferred_benefits'];
    $status['schema_generated'] += $result['schema_generated'];
    $status['last_update'] = time();

    update_option('job_import_status', $status, false);

    // Log completed manual imports to history (only when in AJAX context and import is complete)
    if ($result['complete'] && $result['success'] && function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        $import_details = [
            'success' => $result['success'],
            'duration' => $total_elapsed,
            'processed' => $result['processed'],
            'total' => $result['total'],
            'published' => $result['published'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'error_message' => $result['message'] ?? '',
            'timestamp' => time()
        ];

        // Import the function if not already available
        if (!function_exists('Puntwork\log_import_run')) {
            require_once __DIR__ . '/../scheduling/scheduling-history.php';
        }

        \Puntwork\log_import_run($import_details, 'manual');

        PuntWorkLogger::info('Logged completed manual import to history', PuntWorkLogger::CONTEXT_BATCH, [
            'processed' => $result['processed'],
            'total' => $result['total'],
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
    delete_option('job_import_progress');
    delete_option('job_import_processed_guids');
    delete_option('job_existing_guids');

    // Reset performance metrics for next import
    delete_option('job_import_time_per_job');
    delete_option('job_import_avg_time_per_job');
    delete_option('job_import_last_peak_memory');
    delete_option('job_import_batch_size');
    delete_option('job_import_consecutive_small_batches');
    delete_option('job_import_consecutive_batches');

    // Clean up batch timing data
    delete_option('job_import_last_batch_time');
    delete_option('job_import_last_batch_processed');
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
        'import_start_time' => date('Y-m-d H:i:s', $import_start_time)
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Starting cleanup of old job posts';

    // Get all current GUIDs from the combined JSONL file
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

    if (($handle = fopen($json_path, "r")) !== false) {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (!empty($line)) {
                $item = json_decode($line, true);
                if ($item !== null && isset($item['guid'])) {
                    $current_guids[] = $item['guid'];
                }
            }
        }
        fclose($handle);
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

    PuntWorkLogger::info('Found current GUIDs in feed', PuntWorkLogger::CONTEXT_BATCH, [
        'guid_count' => count($current_guids),
        'sample_guids' => array_slice($current_guids, 0, 5)
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Found ' . count($current_guids) . ' current GUIDs in feed';

    // Get all published job GUIDs and compare against current feed GUIDs
    $published_jobs = $wpdb->get_results($wpdb->prepare("
        SELECT DISTINCT p.ID, pm.meta_value as guid
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
        WHERE p.post_type = 'job'
        AND p.post_status = 'publish'
    "));

    $old_post_ids = [];
    $current_guids_set = array_flip($current_guids); // For fast lookup

    foreach ($published_jobs as $job) {
        if (!isset($current_guids_set[$job->guid])) {
            $old_post_ids[] = $job->ID;
        }
    }

    $total_old_posts = count($old_post_ids);

    PuntWorkLogger::info('Total old job posts to clean up', PuntWorkLogger::CONTEXT_BATCH, [
        'total_old_posts' => $total_old_posts,
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
    $cleanup_start_status = get_option('job_import_status', []);
    $cleanup_start_status['cleanup_total'] = $total_old_posts;
    $cleanup_start_status['cleanup_processed'] = 0;
    $cleanup_start_status['last_update'] = time();
    update_option('job_import_status', $cleanup_start_status, false);

    // Process deletions in batches to avoid memory issues and timeouts
    $batch_size = 100; // Delete 100 posts at a time
    $total_deleted = 0;
    $batches_processed = 0;

    while (!empty($old_post_ids)) {
        $batches_processed++;
        $batch_ids = array_splice($old_post_ids, 0, $batch_size);

        PuntWorkLogger::debug('Processing deletion batch', PuntWorkLogger::CONTEXT_BATCH, [
            'batch' => $batches_processed,
            'batch_size' => count($batch_ids),
            'remaining' => count($old_post_ids)
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
        $cleanup_progress_status = get_option('job_import_status', []);
        $cleanup_progress_status['cleanup_processed'] = $total_deleted;
        $cleanup_progress_status['last_update'] = time();
        $cleanup_progress_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Cleanup progress: ' . $total_deleted . '/' . $total_old_posts . ' old jobs deleted';
        update_option('job_import_status', $cleanup_progress_status, false);

        PuntWorkLogger::debug('Batch deletion completed', PuntWorkLogger::CONTEXT_BATCH, [
            'batch' => $batches_processed,
            'deleted_in_batch' => $deleted_in_batch,
            'total_deleted' => $total_deleted,
            'remaining' => count($old_post_ids)
        ]);

        // Clean up memory and allow other processes to run
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        // Small delay between batches to prevent overwhelming the server
        usleep(10000); // 0.01 seconds
    }

    // Final cleanup status update
    $final_cleanup_status = get_option('job_import_status', []);
    $final_cleanup_status['cleanup_processed'] = $total_deleted;
    $final_cleanup_status['last_update'] = time();
    update_option('job_import_status', $final_cleanup_status, false);

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

/**
 * Calculate estimated time remaining for import.
 *
 * @param array $status Current import status.
 * @return float Estimated time remaining in seconds.
 */
function calculate_estimated_time_remaining($status) {
    if ($status['complete'] || $status['processed'] == 0 || $status['job_importing_time_elapsed'] == 0) {
        return 0;
    }

    $items_remaining = $status['total'] - $status['processed'];
    $time_per_item = $status['job_importing_time_elapsed'] / $status['processed'];
    $estimated_seconds = $items_remaining * $time_per_item;

    // PuntWorkLogger::debug('PHP time calculation', PuntWorkLogger::CONTEXT_BATCH, [
    //     'total' => $status['total'],
    //     'processed' => $status['processed'],
    //     'job_importing_time_elapsed' => $status['job_importing_time_elapsed'],
    //     'items_remaining' => $items_remaining,
    //     'time_per_item' => $time_per_item,
    //     'estimated_seconds' => $estimated_seconds
    // ]);

    return $estimated_seconds;
}