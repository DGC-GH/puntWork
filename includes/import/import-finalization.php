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

    // Clean up batch timing data
    delete_option('job_import_last_batch_time');
    delete_option('job_import_last_batch_processed');
}

/**
 * Clean up old job posts that are no longer in the feed.
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

    if (file_exists($json_path)) {
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
    }

    if (empty($current_guids)) {
        PuntWorkLogger::warning('No current GUIDs found in feed file - skipping cleanup', PuntWorkLogger::CONTEXT_BATCH);
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] No current GUIDs found in feed file - skipping cleanup';
        return [
            'deleted_count' => 0,
            'logs' => $logs
        ];
    }

    PuntWorkLogger::info('Found current GUIDs in feed', PuntWorkLogger::CONTEXT_BATCH, [
        'guid_count' => count($current_guids)
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Found ' . count($current_guids) . ' current GUIDs in feed';

    // Get total count of old posts to be deleted for progress tracking
    $total_old_posts = 0;
    $chunk_size = 500; // Process in chunks of 500 GUIDs
    $guid_chunks = array_chunk($current_guids, $chunk_size);

    // First pass: count total old posts
    foreach ($guid_chunks as $chunk_index => $guid_chunk) {
        $placeholders = implode(',', array_fill(0, count($guid_chunk), '%s'));
        $chunk_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
            WHERE p.post_type = 'job'
            AND p.post_status = 'publish'
            AND pm.meta_value NOT IN ({$placeholders})
        ", $guid_chunk));
        $total_old_posts += $chunk_count;
    }

    PuntWorkLogger::info('Total old job posts to clean up', PuntWorkLogger::CONTEXT_BATCH, [
        'total_old_posts' => $total_old_posts
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Found ' . $total_old_posts . ' old published job posts to clean up';

    // Update status to show cleanup progress starting
    $cleanup_start_status = get_option('job_import_status', []);
    $cleanup_start_status['cleanup_total'] = $total_old_posts;
    $cleanup_start_status['cleanup_processed'] = 0;
    $cleanup_start_status['last_update'] = time();
    update_option('job_import_status', $cleanup_start_status, false);

    $total_deleted = 0;

    foreach ($guid_chunks as $chunk_index => $guid_chunk) {
        PuntWorkLogger::debug('Processing GUID chunk', PuntWorkLogger::CONTEXT_BATCH, [
            'chunk' => $chunk_index + 1,
            'total_chunks' => count($guid_chunks),
            'chunk_size' => count($guid_chunk)
        ]);

        // Get published job posts whose GUID is not in this chunk
        $placeholders = implode(',', array_fill(0, count($guid_chunk), '%s'));
        $old_posts = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, pm.meta_value as guid
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
            WHERE p.post_type = 'job'
            AND p.post_status = 'publish'
            AND pm.meta_value NOT IN ({$placeholders})
        ", $guid_chunk));

        PuntWorkLogger::debug('Found old published job posts in chunk', PuntWorkLogger::CONTEXT_BATCH, [
            'chunk' => $chunk_index + 1,
            'old_posts_count' => count($old_posts)
        ]);

        foreach ($old_posts as $post) {
            // Double-check the post still exists and is published
            $post_status = $wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $post->ID));
            if ($post_status !== 'publish') {
                continue; // Skip if no longer published
            }

            $result = wp_delete_post($post->ID, true); // true = force delete, skip trash
            if ($result) {
                $total_deleted++;

                // Update cleanup progress every 10 deletions
                if ($total_deleted % 10 === 0) {
                    $cleanup_progress_status = get_option('job_import_status', []);
                    $cleanup_progress_status['cleanup_processed'] = $total_deleted;
                    $cleanup_progress_status['last_update'] = time();
                    $cleanup_progress_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Cleanup progress: ' . $total_deleted . '/' . $total_old_posts . ' old jobs deleted';
                    update_option('job_import_status', $cleanup_progress_status, false);
                }

                PuntWorkLogger::debug('Deleted old published job post', PuntWorkLogger::CONTEXT_BATCH, [
                    'post_id' => $post->ID,
                    'title' => $post->post_title,
                    'guid' => $post->guid,
                    'chunk' => $chunk_index + 1,
                    'progress' => $total_deleted . '/' . $total_old_posts
                ]);
            } else {
                PuntWorkLogger::error('Failed to delete old published job post', PuntWorkLogger::CONTEXT_BATCH, [
                    'post_id' => $post->ID,
                    'title' => $post->post_title,
                    'guid' => $post->guid
                ]);
            }

            // Clean up memory after each deletion
            if ($total_deleted % 10 === 0) {
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }
    }

    // Final cleanup status update
    $final_cleanup_status = get_option('job_import_status', []);
    $final_cleanup_status['cleanup_processed'] = $total_deleted;
    $final_cleanup_status['last_update'] = time();
    update_option('job_import_status', $final_cleanup_status, false);

    PuntWorkLogger::info('Cleanup of old published job posts completed', PuntWorkLogger::CONTEXT_BATCH, [
        'deleted_count' => $total_deleted,
        'current_feed_jobs' => count($current_guids),
        'chunks_processed' => count($guid_chunks)
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Cleanup completed: ' . $total_deleted . ' old published jobs deleted';

    return [
        'deleted_count' => $total_deleted,
        'logs' => $logs
    ];
}

/**
 * Get import status summary.
 *
 * @return array Status summary.
 */
function get_import_status_summary() {
    $status = get_option('job_import_status', []);

    return [
        'total' => $status['total'] ?? 0,
        'processed' => $status['processed'] ?? 0,
        'published' => $status['published'] ?? 0,
        'updated' => $status['updated'] ?? 0,
        'skipped' => $status['skipped'] ?? 0,
        'duplicates_drafted' => $status['duplicates_drafted'] ?? 0,
        'complete' => $status['complete'] ?? false,
        'progress_percentage' => $status['total'] > 0 ? round(($status['processed'] / $status['total']) * 100, 2) : 0,
        'time_elapsed' => $status['time_elapsed'] ?? 0,
        'estimated_time_remaining' => calculate_estimated_time_remaining($status),
        'last_update' => $status['last_update'] ?? null,
    ];
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