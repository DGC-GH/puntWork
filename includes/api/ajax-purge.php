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

require_once __DIR__ . '/../utilities/ajax-utilities.php';
require_once __DIR__ . '/../utilities/database-utilities.php';
require_once __DIR__ . '/../utilities/progress-utilities.php';
require_once __DIR__ . '/../utilities/options-utilities.php';

/**
 * AJAX handlers for purge operations
 * Handles cleanup of old/unprocessed job posts
 */

add_action('wp_ajax_job_import_purge', __NAMESPACE__ . '\\job_import_purge_ajax');
function job_import_purge_ajax() {
    if (!validate_ajax_request('job_import_purge')) {
        return;
    }
    global $wpdb;

    // Get batch parameters
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $is_continue = isset($_POST['is_continue']) && $_POST['is_continue'] === 'true';

    // Initialize progress tracking for first batch
    if (!$is_continue) {
        initialize_progress_tracking('purge', [
            'total_jobs' => get_jobs_count(['guid' => '']), // Count jobs with GUID meta
        ]);
    }

    $progress = get_progress('purge', [
        'total_processed' => 0,
        'total_deleted' => 0,
        'current_offset' => 0,
        'complete' => false,
        'start_time' => microtime(true),
        'logs' => []
    ]);

    // Acquire lock
    if (!acquire_operation_lock('purge', 30)) {
        send_ajax_error('job_import_purge', 'Purge operation already in progress');
        return;
    }

    try {
        $processed_guids = PuntWork\get_processed_guids() ?: [];
        $logs = $progress['logs'];

        // Check if import is complete (only on first batch)
        if (!$is_continue) {
            $import_progress = PuntWork\get_import_status() ?: [
                'total' => 0,
                'processed' => 0,
                'complete' => false
            ];

            // More permissive check - allow purge if we have processed GUIDs or if total > 0
            $processed_guids = PuntWork\get_processed_guids() ?: [];
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
            $total_jobs = get_jobs_count(['guid' => '']); // Jobs with GUID meta
            update_progress('purge', ['total_jobs' => $total_jobs]);
        }

        // Get batch of jobs to check
        $batch_jobs = get_jobs_with_meta('guid', $batch_size, $offset);

        if (empty($batch_jobs)) {
            // No more jobs to process
            complete_progress('purge', [], "Purge completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} old jobs");

            release_operation_lock('purge');

            // Clean up options
            PuntWork\delete_processed_guids();
            PuntWork\delete_existing_guids();

            $final_progress = get_progress('purge');
            cleanup_progress('purge');

            $message = "Purge completed: Processed {$final_progress['total_processed']} jobs, deleted {$final_progress['total_deleted']} old jobs";

            send_ajax_success('job_import_purge', [
                'message' => $message,
                'complete' => true,
                'total_processed' => $final_progress['total_processed'],
                'total_deleted' => $final_progress['total_deleted'],
                'time_elapsed' => $final_progress['time_elapsed'],
                'logs' => array_slice($final_progress['logs'], -50)
            ]);
        }

        // Process this batch
        $deleted_count = 0;
        $jobs_to_delete = [];

        foreach ($batch_jobs as $job) {
            if (!in_array($job->meta_value, $processed_guids)) {
                // This job is no longer in the feed, mark for deletion
                $jobs_to_delete[] = $job->ID;
            }
        }

        // Delete jobs in batch
        if (!empty($jobs_to_delete)) {
            $delete_results = delete_jobs_by_ids($jobs_to_delete, true);
            $deleted_count = $delete_results['success'];

            // Log results
            foreach ($jobs_to_delete as $job_id) {
                if (in_array($job_id, $delete_results['failed'])) {
                    update_progress('purge', [], "Failed to delete ID: {$job_id}");
                } else {
                    update_progress('purge', [], "Permanently deleted ID: {$job_id} - No longer in feed");
                }
            }
        }

        // Update progress
        $new_processed = $progress['total_processed'] + count($batch_jobs);
        $new_deleted = $progress['total_deleted'] + $deleted_count;
        $new_offset = $offset + $batch_size;

        update_progress('purge', [
            'total_processed' => $new_processed,
            'total_deleted' => $new_deleted,
            'current_offset' => $new_offset
        ]);

        release_operation_lock('purge');

        // Calculate progress percentage
        $current_progress = get_progress('purge');
        $progress_percentage = calculate_progress_percentage($current_progress);

        send_ajax_success('job_import_purge', [
            'message' => "Batch processed: {$new_processed}/{$current_progress['total_jobs']} jobs ({$progress_percentage}%), deleted {$deleted_count} old jobs this batch",
            'complete' => false,
            'next_offset' => $new_offset,
            'batch_size' => $batch_size,
            'total_processed' => $new_processed,
            'total_deleted' => $new_deleted,
            'progress_percentage' => $progress_percentage,
            'logs' => array_slice($current_progress['logs'], -20)
        ]);

    } catch (\Exception $e) {
        release_operation_lock('purge');
        error_log('Purge failed: ' . $e->getMessage());
        send_ajax_error('job_import_purge', 'Purge failed: ' . $e->getMessage());
    }
}