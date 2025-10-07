<?php
/**
 * AJAX handlers for purge operations
 *
 * @package    Puntwork
 * @subpackage AJAX
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AJAX handlers for purge operations
 * Handles cleanup of old/unprocessed job posts
 */

add_action('wp_ajax_job_import_purge', __NAMESPACE__ . '\\job_import_purge_ajax');
function job_import_purge_ajax() {
    error_log('job_import_purge_ajax called');
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        error_log('Nonce verification failed for job_import_purge');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        error_log('Permission denied for job_import_purge');
        wp_send_json_error(['message' => 'Permission denied']);
    }
    global $wpdb;

    // Get batch parameters
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $is_continue = isset($_POST['is_continue']) && $_POST['is_continue'] === 'true';

    // Initialize progress tracking for first batch
    if (!$is_continue) {
        update_option('job_import_purge_progress', [
            'total_processed' => 0,
            'total_deleted' => 0,
            'current_offset' => 0,
            'complete' => false,
            'start_time' => microtime(true),
            'logs' => []
        ], false);
    }

    $progress = get_option('job_import_purge_progress', [
        'total_processed' => 0,
        'total_deleted' => 0,
        'current_offset' => 0,
        'complete' => false,
        'start_time' => microtime(true),
        'logs' => []
    ]);

    // Set lock for this batch
    $lock_start = microtime(true);
    while (get_transient('job_import_purge_lock')) {
        usleep(50000);
        if (microtime(true) - $lock_start > 10) {
            error_log('Purge lock timeout');
            wp_send_json_error(['message' => 'Purge lock timeout']);
        }
    }
    set_transient('job_import_purge_lock', true, 10);

    try {
        $processed_guids = get_option('job_import_processed_guids') ?: [];
        $logs = $progress['logs'];

        // Check if import is complete (only on first batch)
        if (!$is_continue) {
            $import_progress = get_option('job_import_status') ?: [
                'total' => 0,
                'processed' => 0,
                'complete' => false
            ];

            // More permissive check - allow purge if we have processed GUIDs or if total > 0
            $processed_guids = get_option('job_import_processed_guids') ?: [];
            $has_processed_data = !empty($processed_guids) || $import_progress['total'] > 0;

            if (!$has_processed_data) {
                delete_transient('job_import_purge_lock');
                error_log('Purge skipped: no processed data found');
                wp_send_json_error(['message' => 'No import data found. Please run an import first before purging.']);
            }

            // Log the current state for debugging
            error_log('Purge check - Import progress: ' . json_encode($import_progress));
            error_log('Purge check - Processed GUIDs count: ' . count($processed_guids));
            error_log('Purge check - Has processed data: ' . ($has_processed_data ? 'yes' : 'no'));

            // Get total count for progress calculation
            $total_jobs = $wpdb->get_var("
                SELECT COUNT(*) FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
                WHERE p.post_type = 'job'
            ");
            $progress['total_jobs'] = $total_jobs;
            update_option('job_import_purge_progress', $progress, false);
        }

        // Get batch of jobs to check
        $batch_jobs = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, pm.meta_value AS guid
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
            WHERE p.post_type = 'job'
            ORDER BY p.ID
            LIMIT %d OFFSET %d
        ", $batch_size, $offset));

        if (empty($batch_jobs)) {
            // No more jobs to process
            $progress['complete'] = true;
            $progress['end_time'] = microtime(true);
            $progress['time_elapsed'] = $progress['end_time'] - $progress['start_time'];
            update_option('job_import_purge_progress', $progress, false);
            delete_transient('job_import_purge_lock');

            // Clean up options
            delete_option('job_import_processed_guids');
            delete_option('job_existing_guids');

            $message = "Purge completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} old jobs";
            error_log($message);

            wp_send_json_success([
                'message' => $message,
                'complete' => true,
                'total_processed' => $progress['total_processed'],
                'total_deleted' => $progress['total_deleted'],
                'time_elapsed' => $progress['time_elapsed'],
                'logs' => array_slice($logs, -50)
            ]);
        }

        // Process this batch
        $deleted_count = 0;
        foreach ($batch_jobs as $job) {
            if (!in_array($job->guid, $processed_guids)) {
                // This job is no longer in the feed, delete it
                $result = wp_delete_post($job->ID, true); // true = force delete, skip trash
                if ($result) {
                    $deleted_count++;
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Permanently deleted ID: ' . $job->ID . ' GUID: ' . $job->guid . ' - No longer in feed';
                    error_log('Purge: Permanently deleted ID: ' . $job->ID . ' GUID: ' . $job->guid . ' - No longer in feed');
                } else {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to delete ID: ' . $job->ID . ' GUID: ' . $job->guid;
                    error_log('Purge: Failed to delete ID: ' . $job->ID . ' GUID: ' . $job->guid);
                }
            }
        }

        // Update progress
        $progress['total_processed'] += count($batch_jobs);
        $progress['total_deleted'] += $deleted_count;
        $progress['current_offset'] = $offset + $batch_size;
        $progress['logs'] = $logs;
        update_option('job_import_purge_progress', $progress, false);

        delete_transient('job_import_purge_lock');

        // Calculate progress percentage
        $progress_percentage = $progress['total_jobs'] > 0 ? round(($progress['total_processed'] / $progress['total_jobs']) * 100, 1) : 0;

        wp_send_json_success([
            'message' => "Batch processed: {$progress['total_processed']}/{$progress['total_jobs']} jobs ({$progress_percentage}%), deleted {$deleted_count} old jobs this batch",
            'complete' => false,
            'next_offset' => $progress['current_offset'],
            'batch_size' => $batch_size,
            'total_processed' => $progress['total_processed'],
            'total_deleted' => $progress['total_deleted'],
            'progress_percentage' => $progress_percentage,
            'logs' => array_slice($logs, -20) // Return last 20 log entries for this batch
        ]);

    } catch (\Exception $e) {
        delete_transient('job_import_purge_lock');
        error_log('Purge failed: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Purge failed: ' . $e->getMessage()]);
    }
}