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

    $lock_start = microtime(true);
    while (get_transient('job_import_cleanup_lock')) {
        usleep(50000);
        if (microtime(true) - $lock_start > 30) { // Longer timeout for cleanup
            error_log('Cleanup lock timeout');
            wp_send_json_error(['message' => 'Cleanup lock timeout']);
        }
    }
    set_transient('job_import_cleanup_lock', true, 30);

    try {
        $logs = [];
        $deleted_count = 0;

        // Get all job posts (published and draft)
        $all_jobs = $wpdb->get_results("
            SELECT p.ID, p.post_status, pm.meta_value AS guid, pm2.meta_value AS import_hash
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_import_hash'
            WHERE p.post_type = 'job'
            AND p.post_status IN ('publish', 'draft')
            ORDER BY p.post_modified DESC
        ");

        $jobs_by_guid = [];
        foreach ($all_jobs as $job) {
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

        delete_transient('job_import_cleanup_lock');
        wp_cache_flush();

        $message = "Cleanup completed: Deleted {$deleted_count} duplicate jobs";
        error_log($message);

        wp_send_json_success([
            'message' => $message,
            'deleted_count' => $deleted_count,
            'logs' => array_slice($logs, -50) // Return last 50 log entries
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
        $progress = get_option('job_import_status') ?: [
            'total' => 0,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'duplicates_drafted' => 0,
            'drafted_old' => 0,
            'time_elapsed' => 0,
            'complete' => false,
            'logs' => []
        ];
        $progress['logs'] = is_array($progress['logs']) ? $progress['logs'] : [];
        if ($progress['processed'] < $progress['total'] || $progress['total'] == 0) {
            delete_transient('job_import_purge_lock');
            error_log('Purge skipped: import not complete');
            wp_send_json_error(['message' => 'Import not complete or empty; purge skipped']);
        }
        $drafted_old = 0;
        $all_jobs = get_option('job_existing_guids');
        if (false === $all_jobs) {
            $all_jobs = $wpdb->get_results("SELECT p.ID, pm.meta_value AS guid FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID = pm.post_id WHERE p.post_type = 'job' AND pm.meta_key = 'guid'");
        }
        $old_jobs = [];
        foreach ($all_jobs as $job) {
            if (!in_array($job->guid, $processed_guids)) {
                $old_jobs[] = $job;
            }
        }
        $old_ids = array_column($old_jobs, 'ID');
        if (!empty($old_ids)) {
            $placeholders = implode(',', array_fill(0, count($old_ids), '%d'));
            $wpdb->query($wpdb->prepare("UPDATE $wpdb->posts SET post_status = 'draft' WHERE ID IN ($placeholders)", ...$old_ids));
            $drafted_old = count($old_ids);
            foreach ($old_jobs as $job) {
                $progress['logs'][] = 'Drafted ID: ' . $job->ID . ' GUID: ' . $job->guid . ' - No longer in feed';
                error_log('Drafted ID: ' . $job->ID . ' GUID: ' . $job->guid . ' - No longer in feed');
            }
            wp_cache_flush();
        }
        $progress['drafted_old'] += $drafted_old;
        delete_option('job_import_processed_guids');
        delete_option('job_existing_guids');
        $progress['end_time'] = microtime(true);
        update_option('job_import_status', $progress, false);
        delete_transient('job_import_purge_lock');
        wp_send_json_success(['message' => 'Purge completed, drafted ' . $drafted_old . ' old jobs']);
    } catch (\Exception $e) {
        delete_transient('job_import_purge_lock');
        error_log('Purge failed: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Purge failed: ' . $e->getMessage()]);
    }
}