<?php
/**
 * AJAX handlers for import control operations
 * Handles batch processing, cancellation, and status retrieval
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
 * AJAX handlers for import control operations
 * Handles batch processing, cancellation, and status retrieval
 */

add_action('wp_ajax_run_job_import_batch', __NAMESPACE__ . '\\run_job_import_batch_ajax');
function run_job_import_batch_ajax() {
    PuntWorkLogger::logAjaxRequest('run_job_import_batch', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for run_job_import_batch', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for run_job_import_batch', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $start = intval($_POST['start']);
    PuntWorkLogger::info("Starting batch import at index: {$start}", PuntWorkLogger::CONTEXT_BATCH);

    // For fresh imports (start = 0), pre-initialize the status to prevent synchronization issues
    if ($start === 0) {
        $json_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';
        if (file_exists($json_path)) {
            // Count items quickly to set initial total
            $total = 0;
            if (($handle = fopen($json_path, "r")) !== false) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if (!empty($line)) {
                        $item = json_decode($line, true);
                        if ($item !== null) {
                            $total++;
                        }
                    }
                }
                fclose($handle);
            }

            // Initialize status immediately to prevent frontend polling issues
            $initial_status = [
                'total' => $total,
                'processed' => 0,
                'published' => 0,
                'updated' => 0,
                'skipped' => 0,
                'duplicates_drafted' => 0,
                'time_elapsed' => 0,
                'complete' => false,
                'success' => false,
                'error_message' => '',
                'batch_size' => get_option('job_import_batch_size') ?: 100,
                'inferred_languages' => 0,
                'inferred_benefits' => 0,
                'schema_generated' => 0,
                'start_time' => microtime(true),
                'end_time' => null,
                'last_update' => time(),
                'logs' => ['Manual import started - preparing to process items...'],
            ];
            update_option('job_import_status', $initial_status, false);
            PuntWorkLogger::info('Pre-initialized import status for fresh import', PuntWorkLogger::CONTEXT_BATCH, [
                'total' => $total,
                'start_time' => $initial_status['start_time']
            ]);
        }
    }

    try {
        $result = import_jobs_from_json(true, $start);
    } catch (\Exception $e) {
        PuntWorkLogger::error('Import batch processing failed', PuntWorkLogger::CONTEXT_AJAX, [
            'start' => $start,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        wp_send_json_error(['message' => 'Import failed: ' . $e->getMessage()]);
        return;
    }

    // Check if the import result indicates failure
    if (!$result['success']) {
        PuntWorkLogger::error('Import batch failed with result', PuntWorkLogger::CONTEXT_AJAX, [
            'start' => $start,
            'message' => $result['message'] ?? 'Unknown error',
            'logs' => $result['logs'] ?? []
        ]);
        wp_send_json_error(['message' => $result['message'] ?? 'Import failed: Unknown error']);
        return;
    }

    // If import is complete, perform cleanup of old published jobs
    if (isset($result['complete']) && $result['complete'] && isset($result['success']) && $result['success']) {
        PuntWorkLogger::info('Manual import completed, starting automatic cleanup of old published jobs', PuntWorkLogger::CONTEXT_BATCH);
        
        try {
            // Import the cleanup function
            require_once __DIR__ . '/../import/import-finalization.php';
            
            $cleanup_start_time = microtime(true);
            $deleted_count = \Puntwork\cleanup_old_job_posts($cleanup_start_time);
            
            // Add cleanup results to the response
            $result['deleted_old_posts'] = $deleted_count;
            $result['cleanup_completed'] = true;
            
            PuntWorkLogger::info('Automatic cleanup completed for manual import', PuntWorkLogger::CONTEXT_BATCH, [
                'deleted_old_posts' => $deleted_count
            ]);
        } catch (\Exception $e) {
            PuntWorkLogger::error('Automatic cleanup failed for manual import', PuntWorkLogger::CONTEXT_BATCH, [
                'error' => $e->getMessage()
            ]);
            // Don't fail the import if cleanup fails
            $result['cleanup_error'] = $e->getMessage();
        }
    }

    // Log summary instead of full result to prevent large debug logs
    $log_summary = [
        'success' => isset($result['success']) && $result['success'],
        'processed' => $result['processed'] ?? 0,
        'total' => $result['total'] ?? 0,
        'published' => $result['published'] ?? 0,
        'updated' => $result['updated'] ?? 0,
        'skipped' => $result['skipped'] ?? 0,
        'complete' => $result['complete'] ?? false,
        'logs_count' => isset($result['logs']) && is_array($result['logs']) ? count($result['logs']) : 0,
        'has_error' => !empty($result['message']),
        'deleted_old_posts' => $result['deleted_old_posts'] ?? 0,
        'cleanup_completed' => $result['cleanup_completed'] ?? false
    ];

    PuntWorkLogger::logAjaxResponse('run_job_import_batch', $log_summary, isset($result['success']) && $result['success']);
    wp_send_json_success($result);
}

add_action('wp_ajax_cancel_job_import', __NAMESPACE__ . '\\cancel_job_import_ajax');
function cancel_job_import_ajax() {
    PuntWorkLogger::logAjaxRequest('cancel_job_import', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for cancel_job_import', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for cancel_job_import', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    set_transient('import_cancel', true, 3600);
    // Also clear the import status to reset the UI
    delete_option('job_import_status');
    delete_option('job_import_batch_size');
    PuntWorkLogger::info('Import cancelled and status cleared', PuntWorkLogger::CONTEXT_BATCH);

    PuntWorkLogger::logAjaxResponse('cancel_job_import', ['message' => 'Import cancelled']);
    wp_send_json_success();
}

add_action('wp_ajax_clear_import_cancel', __NAMESPACE__ . '\\clear_import_cancel_ajax');
function clear_import_cancel_ajax() {
    PuntWorkLogger::logAjaxRequest('clear_import_cancel', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for clear_import_cancel', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for clear_import_cancel', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    delete_transient('import_cancel');
    PuntWorkLogger::info('Import cancellation flag cleared', PuntWorkLogger::CONTEXT_BATCH);

    PuntWorkLogger::logAjaxResponse('clear_import_cancel', ['message' => 'Cancellation cleared']);
    wp_send_json_success();
}

add_action('wp_ajax_reset_job_import', __NAMESPACE__ . '\\reset_job_import_ajax');
function reset_job_import_ajax() {
    PuntWorkLogger::logAjaxRequest('reset_job_import', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for reset_job_import', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for reset_job_import', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    // Clear all import-related data
    delete_option('job_import_status');
    delete_option('job_import_progress');
    delete_option('job_import_processed_guids');
    delete_option('job_import_last_batch_time');
    delete_option('job_import_last_batch_processed');
    delete_option('job_import_batch_size');
    delete_option('job_import_consecutive_small_batches');
    delete_transient('import_cancel');

    PuntWorkLogger::info('Import system completely reset', PuntWorkLogger::CONTEXT_BATCH);

    PuntWorkLogger::logAjaxResponse('reset_job_import', ['message' => 'Import system reset']);
    wp_send_json_success();
}

add_action('wp_ajax_get_job_import_status', __NAMESPACE__ . '\\get_job_import_status_ajax');
function get_job_import_status_ajax() {
    PuntWorkLogger::logAjaxRequest('get_job_import_status', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for get_job_import_status', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for get_job_import_status', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $progress = get_option('job_import_status') ?: [
        'total' => 0,
        'processed' => 0,
        'published' => 0,
        'updated' => 0,
        'skipped' => 0,
        'duplicates_drafted' => 0,
        'time_elapsed' => 0,
        'complete' => true, // Fresh state is complete
        'success' => false, // Add success status
        'error_message' => '', // Add error message for failures
        'batch_size' => 100,
        'inferred_languages' => 0,
        'inferred_benefits' => 0,
        'schema_generated' => 0,
        'start_time' => microtime(true),
        'end_time' => null,
        'last_update' => time(),
        'logs' => [],
    ];

    PuntWorkLogger::debug('Retrieved import status', PuntWorkLogger::CONTEXT_BATCH, [
        'total' => $progress['total'],
        'processed' => $progress['processed'],
        'complete' => $progress['complete'] ?? null
    ]);

    // Check for stuck or stale imports and clear them
    if (isset($progress['complete']) && !$progress['complete'] && isset($progress['total']) && $progress['total'] > 0) {
        $current_time = time();
        $time_elapsed = 0;
        $last_update = isset($progress['last_update']) ? $progress['last_update'] : 0;
        $time_since_last_update = $current_time - $last_update;

        if (isset($progress['start_time']) && $progress['start_time'] > 0) {
            $time_elapsed = microtime(true) - $progress['start_time'];
        } elseif (isset($progress['time_elapsed'])) {
            $time_elapsed = $progress['time_elapsed'];
        }

        // Detect stuck imports with multiple criteria:
        // 1. No progress for 5+ minutes (300 seconds)
        // 2. Import running for more than 2 hours without completion (7200 seconds)
        // 3. No status update for 10+ minutes (600 seconds)
        $is_stuck = false;
        $stuck_reason = '';

        if ($progress['processed'] == 0 && $time_elapsed > 300) {
            $is_stuck = true;
            $stuck_reason = 'no progress for 5+ minutes';
        } elseif ($time_elapsed > 7200) { // 2 hours
            $is_stuck = true;
            $stuck_reason = 'running for more than 2 hours';
        } elseif ($time_since_last_update > 600) { // 10 minutes since last update
            $is_stuck = true;
            $stuck_reason = 'no status update for 10+ minutes';
        }

        if ($is_stuck) {
            PuntWorkLogger::info('Detected stuck import in status check, clearing status', PuntWorkLogger::CONTEXT_BATCH, [
                'processed' => $progress['processed'],
                'total' => $progress['total'],
                'time_elapsed' => $time_elapsed,
                'time_since_last_update' => $time_since_last_update,
                'reason' => $stuck_reason
            ]);
            delete_option('job_import_status');
            delete_option('job_import_progress');
            delete_option('job_import_processed_guids');
            delete_option('job_import_last_batch_time');
            delete_option('job_import_last_batch_processed');
            delete_option('job_import_batch_size');
            delete_option('job_import_consecutive_small_batches');
            delete_transient('import_cancel');

            // Return fresh status
            $progress = [
                'total' => 0,
                'processed' => 0,
                'published' => 0,
                'updated' => 0,
                'skipped' => 0,
                'duplicates_drafted' => 0,
                'time_elapsed' => 0,
                'complete' => true, // Fresh state is complete
                'success' => false,
                'error_message' => '',
                'batch_size' => 100,
                'inferred_languages' => 0,
                'inferred_benefits' => 0,
                'schema_generated' => 0,
                'start_time' => microtime(true),
                'end_time' => null,
                'last_update' => time(),
                'logs' => [],
            ];
        }
    }

    // Add resume_progress for JavaScript
    $progress['resume_progress'] = (int) get_option('job_import_progress', 0);

    // Track job importing start time
    if ($progress['total'] > 1 && !isset($progress['job_import_start_time'])) {
        $progress['job_import_start_time'] = microtime(true);
        update_option('job_import_status', $progress);
    }

    // Calculate job importing elapsed time
    $progress['job_importing_time_elapsed'] = isset($progress['job_import_start_time']) ? microtime(true) - $progress['job_import_start_time'] : $progress['time_elapsed'];

    // Add batch timing data for accurate time calculations
    $progress['batch_time'] = (float) get_option('job_import_last_batch_time', 0);
    $progress['batch_processed'] = (int) get_option('job_import_last_batch_processed', 0);

    // Add estimated time remaining calculation from PHP
    $progress['estimated_time_remaining'] = calculate_estimated_time_remaining($progress);

    // Add a last_modified timestamp for client-side caching (use microtime for better precision)
    $progress['last_modified'] = microtime(true);

    // Log response summary instead of full data to prevent large debug logs
    $log_summary = [
        'total' => $progress['total'],
        'processed' => $progress['processed'],
        'published' => $progress['published'],
        'updated' => $progress['updated'],
        'skipped' => $progress['skipped'],
        'complete' => $progress['complete'],
        'success' => $progress['success'],
        'time_elapsed' => $progress['time_elapsed'],
        'job_importing_time_elapsed' => $progress['job_importing_time_elapsed'],
        'estimated_time_remaining' => $progress['estimated_time_remaining'],
        'batch_time' => $progress['batch_time'],
        'batch_processed' => $progress['batch_processed'],
        'logs_count' => is_array($progress['logs']) ? count($progress['logs']) : 0,
        'has_error' => !empty($progress['error_message']),
        'last_modified' => $progress['last_modified']
    ];

    PuntWorkLogger::logAjaxResponse('get_job_import_status', $log_summary);
    wp_send_json_success($progress);
}

add_action('wp_ajax_cleanup_trashed_jobs', __NAMESPACE__ . '\\cleanup_trashed_jobs_ajax');
function cleanup_trashed_jobs_ajax() {
    PuntWorkLogger::logAjaxRequest('cleanup_trashed_jobs', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for cleanup_trashed_jobs', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for cleanup_trashed_jobs', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    global $wpdb;

    // Get batch parameters
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $is_continue = isset($_POST['is_continue']) && $_POST['is_continue'] === 'true';

    // Initialize progress tracking for first batch
    if (!$is_continue) {
        // Get total count first
        $total_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'job' AND post_status = 'trash'
        "));

        update_option('job_cleanup_trashed_progress', [
            'total_processed' => 0,
            'total_deleted' => 0,
            'total_jobs' => $total_count,
            'current_offset' => 0,
            'complete' => false,
            'start_time' => microtime(true),
            'logs' => []
        ], false);
    }

    $progress = get_option('job_cleanup_trashed_progress', [
        'total_processed' => 0,
        'total_deleted' => 0,
        'total_jobs' => 0,
        'current_offset' => 0,
        'complete' => false,
        'start_time' => microtime(true),
        'logs' => []
    ]);

    try {
        // Get batch of trashed jobs to process
        $trashed_posts = $wpdb->get_results($wpdb->prepare("
            SELECT ID, post_title
            FROM {$wpdb->posts}
            WHERE post_type = 'job' AND post_status = 'trash'
            ORDER BY ID
            LIMIT %d OFFSET %d
        ", $batch_size, $offset));

        if (empty($trashed_posts)) {
            // No more jobs to process
            $progress['complete'] = true;
            $progress['end_time'] = microtime(true);
            $progress['time_elapsed'] = $progress['end_time'] - $progress['start_time'];
            update_option('job_cleanup_trashed_progress', $progress, false);

            $message = "Cleanup completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} trashed jobs";
            PuntWorkLogger::info('Cleanup of trashed jobs completed', PuntWorkLogger::CONTEXT_BATCH, [
                'total_processed' => $progress['total_processed'],
                'total_deleted' => $progress['total_deleted']
            ]);

            wp_send_json_success([
                'message' => $message,
                'complete' => true,
                'total_processed' => $progress['total_processed'],
                'total_deleted' => $progress['total_deleted'],
                'time_elapsed' => $progress['time_elapsed'],
                'logs' => array_slice($progress['logs'], -50)
            ]);
        }

        // Process this batch
        $deleted_count = 0;
        $logs = $progress['logs'];

        foreach ($trashed_posts as $post) {
            $result = wp_delete_post($post->ID, true); // true = force delete, skip trash
            if ($result) {
                $deleted_count++;
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Permanently deleted trashed job: ' . $post->post_title . ' (ID: ' . $post->ID . ')';
            } else {
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Failed to delete trashed job: ' . $post->post_title . ' (ID: ' . $post->ID . ')';
            }

            // Clean up memory after each deletion
            if ($deleted_count % 10 === 0) {
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }

        // Update progress
        $progress['total_processed'] += count($trashed_posts);
        $progress['total_deleted'] += $deleted_count;
        $progress['current_offset'] = $offset + $batch_size;
        $progress['logs'] = $logs;
        update_option('job_cleanup_trashed_progress', $progress, false);

        // Calculate progress percentage
        $progress_percentage = $progress['total_jobs'] > 0 ? round(($progress['total_processed'] / $progress['total_jobs']) * 100, 1) : 0;

        wp_send_json_success([
            'message' => "Batch processed: {$progress['total_processed']}/{$progress['total_jobs']} jobs ({$progress_percentage}%), deleted {$deleted_count} trashed jobs this batch",
            'complete' => false,
            'next_offset' => $progress['current_offset'],
            'batch_size' => $batch_size,
            'total_processed' => $progress['total_processed'],
            'total_deleted' => $progress['total_deleted'],
            'progress_percentage' => $progress_percentage,
            'logs' => array_slice($logs, -20) // Return last 20 log entries for this batch
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Cleanup of trashed jobs failed', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage()
        ]);
        wp_send_json_error(['message' => 'Cleanup failed: ' . $e->getMessage()]);
    }
}

add_action('wp_ajax_cleanup_drafted_jobs', __NAMESPACE__ . '\\cleanup_drafted_jobs_ajax');
function cleanup_drafted_jobs_ajax() {
    PuntWorkLogger::logAjaxRequest('cleanup_drafted_jobs', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for cleanup_drafted_jobs', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for cleanup_drafted_jobs', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    global $wpdb;

    // Get batch parameters
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $is_continue = isset($_POST['is_continue']) && $_POST['is_continue'] === 'true';

    PuntWorkLogger::debug('Cleanup drafted jobs function called', PuntWorkLogger::CONTEXT_AJAX, [
        'batch_size' => $batch_size,
        'offset' => $offset,
        'is_continue' => $is_continue
    ]);

    // Initialize progress tracking for first batch
    if (!$is_continue) {
        // Get all draft job IDs first to avoid offset issues during deletion
        $draft_job_ids = $wpdb->get_col($wpdb->prepare("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'job' AND post_status = 'draft'
            ORDER BY ID DESC
        "));

        $total_count = count($draft_job_ids);

        PuntWorkLogger::info('Draft cleanup initialized', PuntWorkLogger::CONTEXT_BATCH, [
            'total_draft_jobs_found' => $total_count,
            'first_5_ids' => array_slice($draft_job_ids, 0, 5),
            'last_5_ids' => array_slice($draft_job_ids, -5)
        ]);

        update_option('job_cleanup_drafted_progress', [
            'total_processed' => 0,
            'total_deleted' => 0,
            'total_jobs' => $total_count,
            'draft_job_ids' => $draft_job_ids,
            'current_index' => 0,
            'complete' => false,
            'start_time' => microtime(true),
            'logs' => []
        ], false);
    }

    $progress = get_option('job_cleanup_drafted_progress', [
        'total_processed' => 0,
        'total_deleted' => 0,
        'total_jobs' => 0,
        'draft_job_ids' => [],
        'current_index' => 0,
        'complete' => false,
        'start_time' => microtime(true),
        'logs' => []
    ]);

    // If already completed, return completion response
    if ($progress['complete']) {
        $message = "Cleanup completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} drafted jobs";
        PuntWorkLogger::debug('Returning cached completion response for drafted jobs', PuntWorkLogger::CONTEXT_BATCH);
        
        wp_send_json_success([
            'message' => $message,
            'complete' => false,
            'status' => 'completed',
            'processed' => $progress['total_processed'],
            'deleted' => $progress['total_deleted'],
            'total' => $progress['total_jobs'],
            'offset' => $progress['current_index'],
            'progress_percentage' => 100,
            'time_elapsed' => $progress['time_elapsed'] ?? 0,
            'logs' => array_slice($progress['logs'], -50)
        ]);
        return;
    }

    PuntWorkLogger::debug('Retrieved progress data', PuntWorkLogger::CONTEXT_BATCH, [
        'total_processed' => $progress['total_processed'],
        'total_jobs' => $progress['total_jobs'],
        'current_index' => $progress['current_index'],
        'complete' => $progress['complete']
    ]);

    $draft_job_ids = $progress['draft_job_ids'] ?? [];
    $current_index = $progress['current_index'] ?? 0;

    try {
        // Process batch of drafted jobs from collected IDs
        $batch_posts = array_slice($draft_job_ids, $current_index, $batch_size);

        if (empty($batch_posts)) {
            // No more jobs to process
            $progress['complete'] = true;
            $progress['end_time'] = microtime(true);
            $progress['time_elapsed'] = $progress['end_time'] - $progress['start_time'];
            update_option('job_cleanup_drafted_progress', $progress, false);

            $message = "Cleanup completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} drafted jobs";

            // Verify final count
            $final_count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE post_type = 'job' AND post_status = 'draft'
            "));

            PuntWorkLogger::info('Cleanup of drafted jobs completed', PuntWorkLogger::CONTEXT_BATCH, [
                'total_processed' => $progress['total_processed'],
                'total_deleted' => $progress['total_deleted'],
                'final_draft_count' => $final_count,
                'expected_remaining' => $progress['total_jobs'] - $progress['total_deleted']
            ]);

            PuntWorkLogger::debug('Sending completion progress update', PuntWorkLogger::CONTEXT_BATCH, [
                'processed' => $progress['total_processed'],
                'total' => $progress['total_jobs'],
                'deleted' => $progress['total_deleted'],
                'offset' => $progress['current_index'],
                'progress_percentage' => 100,
                'complete' => false,
                'status' => 'completed'
            ]);

            wp_send_json_success([
                'message' => $message,
                'complete' => false,
                'status' => 'completed',
                'processed' => $progress['total_processed'],
                'deleted' => $progress['total_deleted'],
                'total' => $progress['total_jobs'],
                'offset' => $progress['current_index'],
                'progress_percentage' => 100,
                'time_elapsed' => $progress['time_elapsed'],
                'logs' => array_slice($progress['logs'], -50)
            ]);
        }

        // Get post details for this batch
        $placeholders = implode(',', array_fill(0, count($batch_posts), '%d'));
        $posts_details = $wpdb->get_results($wpdb->prepare("
            SELECT ID, post_title FROM {$wpdb->posts}
            WHERE ID IN ({$placeholders})
        ", $batch_posts));

        // Process this batch
        $deleted_count = 0;
        $logs = $progress['logs'];

        foreach ($posts_details as $post) {
            // Check if post still exists before deletion
            $post_exists_before = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $post->ID));

            // Check if post is locked
            $locked = wp_check_post_lock($post->ID);
            if ($locked) {
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Skipped locked draft job: ' . $post->post_title . ' (ID: ' . $post->ID . ', locked by user: ' . $locked . ')';
                PuntWorkLogger::debug('Skipped locked post', PuntWorkLogger::CONTEXT_BATCH, [
                    'post_id' => $post->ID,
                    'locked_by' => $locked
                ]);
                continue;
            }

            $result = wp_delete_post($post->ID, true); // true = force delete, skip trash

            // Verify deletion
            $post_exists_after = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $post->ID));

            if ($result && !$post_exists_after) {
                $deleted_count++;
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Successfully deleted draft job: ' . $post->post_title . ' (ID: ' . $post->ID . ')';
                PuntWorkLogger::debug('Post deletion verified', PuntWorkLogger::CONTEXT_BATCH, [
                    'post_id' => $post->ID,
                    'post_title' => $post->post_title,
                    'existed_before' => $post_exists_before ? 'yes' : 'no',
                    'exists_after' => $post_exists_after ? 'yes' : 'no'
                ]);
            } else {
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Failed to delete draft job: ' . $post->post_title . ' (ID: ' . $post->ID . ') - wp_delete_post returned: ' . ($result ? 'true' : 'false') . ', still exists: ' . ($post_exists_after ? 'yes' : 'no');
                PuntWorkLogger::error('Post deletion failed', PuntWorkLogger::CONTEXT_BATCH, [
                    'post_id' => $post->ID,
                    'post_title' => $post->post_title,
                    'wp_delete_result' => $result ? 'true' : 'false',
                    'existed_before' => $post_exists_before ? 'yes' : 'no',
                    'exists_after' => $post_exists_after ? 'yes' : 'no'
                ]);
            }

            // Clean up memory after each deletion
            if ($deleted_count % 10 === 0) {
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }

        // Update progress
        $progress['total_processed'] += count($posts_details);
        $progress['total_deleted'] += $deleted_count;
        $progress['current_index'] = $current_index + $batch_size;
        $progress['logs'] = $logs;
        update_option('job_cleanup_drafted_progress', $progress, false);

        // Calculate progress percentage
        $progress_percentage = $progress['total_jobs'] > 0 ? round(($progress['total_processed'] / $progress['total_jobs']) * 100, 1) : 0;

        PuntWorkLogger::debug('Sending batch progress update', PuntWorkLogger::CONTEXT_BATCH, [
            'processed' => $progress['total_processed'],
            'total' => $progress['total_jobs'],
            'deleted' => $progress['total_deleted'],
            'offset' => $progress['current_index'],
            'progress_percentage' => $progress_percentage,
            'batch_size' => $batch_size
        ]);

        wp_send_json_success([
            'message' => "Batch processed: {$progress['total_processed']}/{$progress['total_jobs']} jobs ({$progress_percentage}%), deleted {$deleted_count} drafted jobs this batch",
            'complete' => false,
            'offset' => $progress['current_index'], // Changed from next_offset for frontend compatibility
            'batch_size' => $batch_size,
            'processed' => $progress['total_processed'],
            'deleted' => $progress['total_deleted'],
            'total' => $progress['total_jobs'],
            'progress_percentage' => $progress_percentage,
            'logs' => array_slice($logs, -20) // Return last 20 log entries for this batch
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Cleanup of drafted jobs failed', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage()
        ]);
        wp_send_json_error(['message' => 'Cleanup failed: ' . $e->getMessage()]);
    }
}

add_action('wp_ajax_cleanup_old_published_jobs', __NAMESPACE__ . '\\cleanup_old_published_jobs_ajax');
function cleanup_old_published_jobs_ajax() {
    PuntWorkLogger::logAjaxRequest('cleanup_old_published_jobs', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for cleanup_old_published_jobs', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for cleanup_old_published_jobs', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    global $wpdb;

    // Get batch parameters
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $is_continue = isset($_POST['is_continue']) && $_POST['is_continue'] === 'true';

    PuntWorkLogger::debug('Cleanup old published jobs function called', PuntWorkLogger::CONTEXT_AJAX, [
        'batch_size' => $batch_size,
        'offset' => $offset,
        'is_continue' => $is_continue
    ]);

    // Initialize progress tracking for first batch
    if (!$is_continue) {
        // Check if combined-jobs.jsonl exists
        $json_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';
        if (!file_exists($json_path)) {
            wp_send_json_error(['message' => 'No current feed data found. Please run an import first to generate feed data.']);
        }

        // Get all current GUIDs from the combined JSONL file
        $current_guids = [];
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
            wp_send_json_error(['message' => 'No valid job data found in current feeds.']);
        }

        // Store GUIDs in option for batch processing
        update_option('job_cleanup_guids', $current_guids, false);

        // Get total count of posts to process
        $placeholders = implode(',', array_fill(0, count($current_guids), '%s'));
        $total_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
            WHERE p.post_type = 'job'
            AND p.post_status = 'publish'
            AND pm.meta_value NOT IN ({$placeholders})
        ", $current_guids));

        PuntWorkLogger::info('Old published cleanup initialized', PuntWorkLogger::CONTEXT_BATCH, [
            'current_guids_count' => count($current_guids),
            'total_old_jobs_found' => $total_count
        ]);

        update_option('job_cleanup_old_published_progress', [
            'total_processed' => 0,
            'total_deleted' => 0,
            'total_jobs' => $total_count,
            'current_offset' => 0,
            'complete' => false,
            'start_time' => microtime(true),
            'logs' => []
        ], false);
    }

    $progress = get_option('job_cleanup_old_published_progress', [
        'total_processed' => 0,
        'total_deleted' => 0,
        'total_jobs' => 0,
        'current_offset' => 0,
        'complete' => false,
        'start_time' => microtime(true),
        'logs' => []
    ]);

    // If already completed, return completion response
    if ($progress['complete']) {
        $message = "Cleanup completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} old published jobs";
        PuntWorkLogger::debug('Returning cached completion response for old published jobs', PuntWorkLogger::CONTEXT_BATCH);
        
        wp_send_json_success([
            'message' => $message,
            'complete' => false,
            'status' => 'completed',
            'processed' => $progress['total_processed'],
            'deleted' => $progress['total_deleted'],
            'total' => $progress['total_jobs'],
            'offset' => $progress['current_offset'],
            'progress_percentage' => 100,
            'time_elapsed' => $progress['time_elapsed'] ?? 0,
            'logs' => array_slice($progress['logs'], -50)
        ]);
        return;
    }

    PuntWorkLogger::debug('Retrieved old published progress data', PuntWorkLogger::CONTEXT_BATCH, [
        'total_processed' => $progress['total_processed'],
        'total_jobs' => $progress['total_jobs'],
        'current_offset' => $progress['current_offset'],
        'complete' => $progress['complete']
    ]);

    $current_guids = get_option('job_cleanup_guids', []);

    try {
        // Get batch of old published jobs to process
        $placeholders = implode(',', array_fill(0, count($current_guids), '%s'));
        $old_published_posts = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, pm.meta_value as guid
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
            WHERE p.post_type = 'job'
            AND p.post_status = 'publish'
            AND pm.meta_value NOT IN ({$placeholders})
            ORDER BY p.ID
            LIMIT %d OFFSET %d
        ", array_merge($current_guids, [$batch_size, $offset])));

        PuntWorkLogger::debug('Old published jobs query results', PuntWorkLogger::CONTEXT_BATCH, [
            'current_guids_count' => count($current_guids),
            'batch_size' => $batch_size,
            'offset' => $offset,
            'found_jobs_count' => count($old_published_posts),
            'first_few_job_ids' => array_slice(array_column($old_published_posts, 'ID'), 0, 5)
        ]);

        if (empty($old_published_posts)) {
            // No more jobs to process
            $progress['complete'] = true;
            $progress['end_time'] = microtime(true);
            $progress['time_elapsed'] = $progress['end_time'] - $progress['start_time'];
            update_option('job_cleanup_old_published_progress', $progress, false);

            // Clean up temporary options
            delete_option('job_cleanup_guids');

            $message = "Cleanup completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} old published jobs";
            PuntWorkLogger::info('Cleanup of old published jobs completed', PuntWorkLogger::CONTEXT_BATCH, [
                'total_processed' => $progress['total_processed'],
                'total_deleted' => $progress['total_deleted'],
                'current_feed_jobs' => count($current_guids)
            ]);

            PuntWorkLogger::debug('Sending completion progress update for old published jobs', PuntWorkLogger::CONTEXT_BATCH, [
                'processed' => $progress['total_processed'],
                'total' => $progress['total_jobs'],
                'deleted' => $progress['total_deleted'],
                'offset' => $progress['current_offset'],
                'progress_percentage' => 100,
                'complete' => false,
                'status' => 'completed'
            ]);

            wp_send_json_success([
                'message' => $message,
                'complete' => false,
                'status' => 'completed',
                'processed' => $progress['total_processed'],
                'deleted' => $progress['total_deleted'],
                'total' => $progress['total_jobs'],
                'offset' => $progress['current_offset'],
                'progress_percentage' => 100,
                'time_elapsed' => $progress['time_elapsed'],
                'logs' => array_slice($progress['logs'], -50)
            ]);
        }

        // Process this batch
        $deleted_count = 0;
        $logs = $progress['logs'];

        foreach ($old_published_posts as $post) {
            $result = wp_delete_post($post->ID, true); // true = force delete, skip trash
            if ($result) {
                $deleted_count++;
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Permanently deleted old published job: ' . $post->post_title . ' (ID: ' . $post->ID . ', GUID: ' . $post->guid . ')';
            } else {
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Failed to delete old published job: ' . $post->post_title . ' (ID: ' . $post->ID . ', GUID: ' . $post->guid . ')';
            }

            // Clean up memory after each deletion
            if ($deleted_count % 10 === 0) {
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }

        // Update progress
        $progress['total_processed'] += count($old_published_posts);
        $progress['total_deleted'] += $deleted_count;
        $progress['current_offset'] = $offset + $batch_size;
        $progress['logs'] = $logs;
        update_option('job_cleanup_old_published_progress', $progress, false);

        // Calculate progress percentage
        $progress_percentage = $progress['total_jobs'] > 0 ? round(($progress['total_processed'] / $progress['total_jobs']) * 100, 1) : 0;

        PuntWorkLogger::debug('Sending batch progress update for old published jobs', PuntWorkLogger::CONTEXT_BATCH, [
            'processed' => $progress['total_processed'],
            'total' => $progress['total_jobs'],
            'deleted' => $progress['total_deleted'],
            'offset' => $progress['current_offset'],
            'progress_percentage' => $progress_percentage,
            'batch_size' => $batch_size
        ]);

        wp_send_json_success([
            'message' => "Batch processed: {$progress['total_processed']}/{$progress['total_jobs']} jobs ({$progress_percentage}%), deleted {$deleted_count} old published jobs this batch",
            'complete' => false,
            'offset' => $progress['current_offset'],
            'batch_size' => $batch_size,
            'processed' => $progress['total_processed'],
            'deleted' => $progress['total_deleted'],
            'total' => $progress['total_jobs'],
            'progress_percentage' => $progress_percentage,
            'logs' => array_slice($logs, -20) // Return last 20 log entries for this batch
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Cleanup of old published jobs failed', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage()
        ]);
        wp_send_json_error(['message' => 'Cleanup failed: ' . $e->getMessage()]);
    }
}