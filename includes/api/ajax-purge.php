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

add_action('wp_ajax_job_import_cleanup_duplicates', __NAMESPACE__ . '\\job_import_cleanup_duplicates_ajax');
function job_import_cleanup_duplicates_ajax() {
    error_log('job_import_cleanup_duplicates_ajax called');
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        error_log('Nonce verification failed for job_import_cleanup_duplicates');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        error_log('Permission denied for job_import_cleanup_duplicates');
        wp_send_json_error(['message' => 'Permission denied']);
    }

    global $wpdb;

    // Get batch parameters
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $is_continue = isset($_POST['is_continue']) && $_POST['is_continue'] === 'true';

    // Initialize progress tracking for first batch
    if (!$is_continue) {
        update_option('job_import_cleanup_progress', [
            'total_processed' => 0,
            'total_deleted' => 0,
            'current_offset' => 0,
            'complete' => false,
            'start_time' => microtime(true),
            'logs' => []
        ], false);
    }

    $progress = get_option('job_import_cleanup_progress', [
        'total_processed' => 0,
        'total_deleted' => 0,
        'current_offset' => 0,
        'complete' => false,
        'start_time' => microtime(true),
        'logs' => []
    ]);

    // Set lock for this batch
    $lock_start = microtime(true);
    while (get_transient('job_import_cleanup_lock')) {
        usleep(50000);
        if (microtime(true) - $lock_start > 30) {
            error_log('Cleanup lock timeout');
            wp_send_json_error(['message' => 'Cleanup lock timeout']);
        }
    }
    set_transient('job_import_cleanup_lock', true, 30);

    try {
        $logs = $progress['logs'];
        $deleted_count = 0;

        // Get total count for progress calculation (only on first batch)
        if (!$is_continue) {
            $total_jobs = $wpdb->get_var("
                SELECT COUNT(*) FROM {$wpdb->posts} p
                WHERE p.post_type = 'job'
                AND p.post_status IN ('publish', 'draft')
            ");
            $progress['total_jobs'] = $total_jobs;
            update_option('job_import_cleanup_progress', $progress, false);
        }

        // Get batch of jobs
        $batch_jobs = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_status, p.post_modified, pm.meta_value AS guid, pm2.meta_value AS import_hash
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_import_hash'
            WHERE p.post_type = 'job'
            AND p.post_status IN ('publish', 'draft')
            ORDER BY p.ID
            LIMIT %d OFFSET %d
        ", $batch_size, $offset));

        if (empty($batch_jobs)) {
            // No more jobs to process
            $progress['complete'] = true;
            $progress['end_time'] = microtime(true);
            $progress['time_elapsed'] = $progress['end_time'] - $progress['start_time'];
            update_option('job_import_cleanup_progress', $progress, false);
            delete_transient('job_import_cleanup_lock');

            $message = "Cleanup completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} duplicates";
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
        $jobs_by_guid = [];
        foreach ($batch_jobs as $job) {
            if (!empty($job->guid)) {
                $jobs_by_guid[$job->guid][] = $job;
            }
        }

        foreach ($jobs_by_guid as $guid => $jobs) {
            if (count($jobs) > 1) {
                // Sort by modification date (newest first)
                usort($jobs, function($a, $b) {
                    return strtotime($b->post_modified) - strtotime($a->post_modified);
                });

                // Keep the first (newest) job as published
                $keep_job = $jobs[0];
                if ($keep_job->post_status !== 'publish') {
                    $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $keep_job->ID]);
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Republished newest job ID: ' . $keep_job->ID . ' GUID: ' . $guid;
                }

                // Delete all others
                for ($i = 1; $i < count($jobs); $i++) {
                    $delete_job = $jobs[$i];
                    wp_delete_post($delete_job->ID, true); // Force delete
                    $deleted_count++;
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Deleted duplicate job ID: ' . $delete_job->ID . ' GUID: ' . $guid;
                    error_log('Cleanup: Deleted duplicate job ID: ' . $delete_job->ID . ' GUID: ' . $guid);
                }
            }
        }

        // Update progress
        $progress['total_processed'] += count($batch_jobs);
        $progress['total_deleted'] += $deleted_count;
        $progress['current_offset'] = $offset + $batch_size;
        $progress['logs'] = $logs;
        update_option('job_import_cleanup_progress', $progress, false);

        delete_transient('job_import_cleanup_lock');

        // Calculate progress percentage
        $progress_percentage = $progress['total_jobs'] > 0 ? round(($progress['total_processed'] / $progress['total_jobs']) * 100, 1) : 0;

        wp_send_json_success([
            'message' => "Batch processed: {$progress['total_processed']}/{$progress['total_jobs']} jobs ({$progress_percentage}%), deleted {$deleted_count} duplicates this batch",
            'complete' => false,
            'next_offset' => $progress['current_offset'],
            'batch_size' => $batch_size,
            'total_processed' => $progress['total_processed'],
            'total_deleted' => $progress['total_deleted'],
            'progress_percentage' => $progress_percentage,
            'logs' => array_slice($logs, -20) // Return last 20 log entries for this batch
        ]);

    } catch (\Exception $e) {
        delete_transient('job_import_cleanup_lock');
        error_log('Cleanup failed: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Cleanup failed: ' . $e->getMessage()]);
    }
}

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

            if ($import_progress['processed'] < $import_progress['total'] || $import_progress['total'] == 0) {
                delete_transient('job_import_purge_lock');
                error_log('Purge skipped: import not complete');
                wp_send_json_error(['message' => 'Import not complete or empty; purge skipped']);
            }

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

add_action('wp_ajax_job_import_cleanup_continue', __NAMESPACE__ . '\\job_import_cleanup_continue_ajax');
function job_import_cleanup_continue_ajax() {
    error_log('job_import_cleanup_continue_ajax called');

    // Check permissions and nonce
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        error_log('Nonce verification failed for job_import_cleanup_continue');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        error_log('Permission denied for job_import_cleanup_continue');
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $progress = get_option('job_import_cleanup_progress');
    if (!$progress || $progress['complete']) {
        wp_send_json_error(['message' => 'No active cleanup operation found']);
    }

    // Call the main cleanup function with continue parameters
    $_POST['batch_size'] = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
    $_POST['offset'] = $progress['current_offset'];
    $_POST['is_continue'] = 'true';

    job_import_cleanup_duplicates_ajax();
}

add_action('wp_ajax_job_import_purge_continue', __NAMESPACE__ . '\\job_import_purge_continue_ajax');
function job_import_purge_continue_ajax() {
    error_log('job_import_purge_continue_ajax called');

    // Check permissions and nonce
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        error_log('Nonce verification failed for job_import_purge_continue');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        error_log('Permission denied for job_import_purge_continue');
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $progress = get_option('job_import_purge_progress');
    if (!$progress || $progress['complete']) {
        wp_send_json_error(['message' => 'No active purge operation found']);
    }

    // Call the main purge function with continue parameters
    $_POST['batch_size'] = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
    $_POST['offset'] = $progress['current_offset'];
    $_POST['is_continue'] = 'true';

    job_import_purge_ajax();
}