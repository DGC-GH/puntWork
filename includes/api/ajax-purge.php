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