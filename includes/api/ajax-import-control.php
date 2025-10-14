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

require_once __DIR__ . '/../utilities/ajax-utilities.php';
require_once __DIR__ . '/../utilities/file-utilities.php';
require_once __DIR__ . '/../utilities/options-utilities.php';
require_once __DIR__ . '/../batch/batch-size-management.php';
require_once __DIR__ . '/../import/import-finalization.php';
require_once __DIR__ . '/../scheduling/scheduling-history.php';

/**
 * AJAX handlers for import control operations
 * Handles batch processing, cancellation, and status retrieval
 */

add_action('wp_ajax_run_job_import_batch', __NAMESPACE__ . '\\run_job_import_batch_ajax');
function run_job_import_batch_ajax() {
    if (!validate_ajax_request('run_job_import_batch')) {
        return;
    }

    // For manual imports, use the same async approach as scheduled imports
    // This unifies the import process - both manual and scheduled use the same backend logic

    // Check if an import is already running
    $import_status = get_import_status([]);
    if (isset($import_status['complete']) && !$import_status['complete']) {
        // Calculate actual time elapsed
        $time_elapsed = 0;
        if (isset($import_status['start_time']) && $import_status['start_time'] > 0) {
            $time_elapsed = microtime(true) - $import_status['start_time'];
        } elseif (isset($import_status['time_elapsed'])) {
            $time_elapsed = $import_status['time_elapsed'];
        }

        // Check for stuck imports and clear them automatically
        $current_time = time();
        $last_update = isset($import_status['last_update']) ? $import_status['last_update'] : 0;
        $time_since_last_update = $current_time - $last_update;

        // Detect stuck imports with multiple criteria:
        // 1. No progress for 5+ minutes (300 seconds)
        // 2. Import running for more than 2 hours without completion (7200 seconds)
        // 3. No status update for 10+ minutes (600 seconds)
        $is_stuck = false;
        $stuck_reason = '';

        if ($import_status['processed'] == 0 && $time_elapsed > 300) {
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
            PuntWorkLogger::info('Detected stuck import in batch start, clearing status', PuntWorkLogger::CONTEXT_BATCH, [
                'processed' => $import_status['processed'],
                'total' => $import_status['total'],
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

            // Clear the status so we can proceed
            $import_status = [];
        } else {
            send_ajax_error('run_job_import_batch', 'An import is already running');
            return;
        }
    }

    try {
        // Initialize import status for immediate UI feedback
        $initial_status = initialize_import_status(0, 'Manual import started - preparing feeds...');
        set_import_status($initial_status);
        error_log('[PUNTWORK] Initialized import status for manual run: total=0, complete=false');

        // Clear any previous cancellation before starting
        delete_transient('import_cancel');
        error_log('[PUNTWORK] Cleared import_cancel transient for manual run');

        // Schedule the import to run asynchronously (same as scheduled imports)
        if (function_exists('as_schedule_single_action')) {
            // Use Action Scheduler if available
            error_log('[PUNTWORK] Scheduling async manual import using Action Scheduler');
            as_schedule_single_action(time(), 'puntwork_manual_import_async');
        } elseif (function_exists('wp_schedule_single_event')) {
            // Fallback: Use WordPress cron for near-immediate execution
            error_log('[PUNTWORK] Action Scheduler not available, using WordPress cron for manual import');
            wp_schedule_single_event(time() + 1, 'puntwork_manual_import_async');
        } else {
            // Final fallback: Run synchronously (not ideal for UI but maintains functionality)
            error_log('[PUNTWORK] No async scheduling available, running manual import synchronously');
            $result = run_manual_import();

            if ($result['success']) {
                error_log('[PUNTWORK] Synchronous manual import completed successfully');
                send_ajax_success('run_job_import_batch', [
                    'message' => 'Import completed successfully',
                    'result' => $result,
                    'async' => false
                ]);
            } else {
                error_log('[PUNTWORK] Synchronous manual import failed: ' . ($result['message'] ?? 'Unknown error'));
                // Reset import status on failure so future attempts can start
                delete_import_status();
                error_log('[PUNTWORK] Reset job_import_status due to manual import failure');
                send_ajax_error('run_job_import_batch', 'Import failed: ' . ($result['message'] ?? 'Unknown error'));
            }
            return;
        }

        // Return success immediately so UI can start polling
        error_log('[PUNTWORK] Manual import initiated asynchronously');
        send_ajax_success('run_job_import_batch', [
            'message' => 'Import started successfully',
            'async' => true
        ]);

    } catch (\Exception $e) {
        error_log('[PUNTWORK] Run manual import AJAX error: ' . $e->getMessage());
        send_ajax_error('run_job_import_batch', 'Failed to start import: ' . $e->getMessage());
    }
}add_action('wp_ajax_cancel_job_import', __NAMESPACE__ . '\\cancel_job_import_ajax');
function cancel_job_import_ajax() {
    if (!validate_ajax_request('cancel_job_import')) {
        return;
    }

    set_transient('import_cancel', true, 3600);
    // Also clear the import status to reset the UI
    delete_option('job_import_status');
    delete_option('job_import_batch_size');
    PuntWorkLogger::info('Import cancelled and status cleared', PuntWorkLogger::CONTEXT_BATCH);

    send_ajax_success('cancel_job_import', []);
}

add_action('wp_ajax_clear_import_cancel', __NAMESPACE__ . '\\clear_import_cancel_ajax');
function clear_import_cancel_ajax() {
    if (!validate_ajax_request('clear_import_cancel')) {
        return;
    }

    delete_transient('import_cancel');
    PuntWorkLogger::info('Import cancellation flag cleared', PuntWorkLogger::CONTEXT_BATCH);

    send_ajax_success('clear_import_cancel', []);
}

add_action('wp_ajax_reset_job_import', __NAMESPACE__ . '\\reset_job_import_ajax');
function reset_job_import_ajax() {
    if (!validate_ajax_request('reset_job_import')) {
        return;
    }

    // Clear all import-related data
    delete_option('job_import_status');
    delete_option('job_import_progress');
    delete_option('job_import_processed_guids');
    delete_option('job_import_last_batch_time');
    delete_option('job_import_last_batch_processed');
    delete_option('job_import_batch_size');
    delete_option('job_import_consecutive_small_batches');
    delete_option('job_import_consecutive_batches');
    delete_transient('import_cancel');

    PuntWorkLogger::info('Import system completely reset', PuntWorkLogger::CONTEXT_BATCH);

    send_ajax_success('reset_job_import', []);
}

add_action('wp_ajax_get_job_import_status', __NAMESPACE__ . '\\get_job_import_status_ajax');
function get_job_import_status_ajax() {
    try {
        // Get status first to determine if we should log
        $progress = get_import_status() ?: initialize_import_status(0, '', null);
        $total = $progress['total'] ?? 0;
        $processed = $progress['processed'] ?? 0;
        $complete = $progress['complete'] ?? false;
        $should_log = $processed > 0 || $complete === true;

        // Validate request (conditionally log based on import state)
        if (!validate_ajax_request('get_job_import_status', $should_log)) {
            return;
        }

        // Only log debug when import has meaningful progress to reduce log spam
        if ($should_log) {
            PuntWorkLogger::debug('Retrieved import status', PuntWorkLogger::CONTEXT_BATCH, [
                'total' => $total,
                'processed' => $processed,
                'complete' => $complete
            ]);
        }

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
                    'processed' => $progress['processed'] ?? 0,
                    'total' => $progress['total'] ?? 0,
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
                $progress = initialize_import_status(0, '', null);
            }
        }

        // Add resume_progress for JavaScript
        $progress['resume_progress'] = get_import_progress();

        // Track job importing start time
        if (($progress['total'] ?? 0) > 1 && !isset($progress['job_import_start_time'])) {
            $progress['job_import_start_time'] = microtime(true);
            set_import_status($progress);
        }

        // Calculate job importing elapsed time with safe defaults
        $job_import_start_time = $progress['job_import_start_time'] ?? null;
        $time_elapsed = $progress['time_elapsed'] ?? 0;
        $progress['job_importing_time_elapsed'] = $job_import_start_time ? microtime(true) - $job_import_start_time : $time_elapsed;

        // Add batch timing data for accurate time calculations
        $progress['batch_time'] = get_last_batch_time();
        $progress['batch_processed'] = get_last_batch_processed();

        // Add estimated time remaining calculation from PHP with error handling
        try {
            $progress['estimated_time_remaining'] = calculate_estimated_time_remaining($progress);
        } catch (\Exception $e) {
            PuntWorkLogger::error('Error calculating estimated time remaining', PuntWorkLogger::CONTEXT_BATCH, [
                'error' => $e->getMessage(),
                'progress' => $progress
            ]);
            $progress['estimated_time_remaining'] = 0;
        }

        // Add a last_modified timestamp for client-side caching (use microtime for better precision)
        $progress['last_modified'] = microtime(true);

        // Only log AJAX response when import has meaningful progress to reduce log spam
        if ($total > 0 || $processed > 0 || $complete === true) {
            // Create highly condensed log data to prevent extremely long log lines
            $sanitized_log_data = [
                'total' => $progress['total'] ?? 0,
                'processed' => $progress['processed'] ?? 0,
                'published' => $progress['published'] ?? 0,
                'updated' => $progress['updated'] ?? 0,
                'skipped' => $progress['skipped'] ?? 0,
                'duplicates_drafted' => $progress['duplicates_drafted'] ?? 0,
                'complete' => $progress['complete'] ?? false,
                'time_elapsed' => round($progress['time_elapsed'] ?? 0, 2),
                'batch_count' => $progress['batch_count'] ?? 0,
                'logs_count' => count($progress['logs'] ?? []),
                'last_log_entry' => end($progress['logs'] ?? []) ?: null
            ];
            send_ajax_success('get_job_import_status', $progress, $sanitized_log_data);
        } else {
            // For initial polling before import starts, just send response without logging
            wp_send_json_success($progress);
        }

    } catch (\Exception $e) {
        PuntWorkLogger::error('Fatal error in get_job_import_status_ajax', PuntWorkLogger::CONTEXT_AJAX, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        wp_send_json_error(['message' => 'Internal server error occurred while retrieving import status']);
    }
}

add_action('wp_ajax_cleanup_trashed_jobs', __NAMESPACE__ . '\\cleanup_trashed_jobs_ajax');
function cleanup_trashed_jobs_ajax() {
    if (!validate_ajax_request('cleanup_trashed_jobs')) {
        return;
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

        set_cleanup_trashed_progress([
            'total_processed' => 0,
            'total_deleted' => 0,
            'total_jobs' => $total_count,
            'current_offset' => 0,
            'complete' => false,
            'start_time' => microtime(true),
            'logs' => []
        ]);
    }

    $progress = get_cleanup_trashed_progress();

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
            set_cleanup_trashed_progress($progress);

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
        set_cleanup_trashed_progress($progress);

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
    if (!validate_ajax_request('cleanup_drafted_jobs')) {
        return;
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

        set_cleanup_drafted_progress([
            'total_processed' => 0,
            'total_deleted' => 0,
            'total_jobs' => $total_count,
            'draft_job_ids' => $draft_job_ids,
            'current_index' => 0,
            'complete' => false,
            'start_time' => microtime(true),
            'logs' => []
        ]);
    }

    $progress = get_cleanup_drafted_progress();

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
            set_cleanup_drafted_progress($progress);

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
        set_cleanup_drafted_progress($progress);

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
    if (!validate_ajax_request('cleanup_old_published_jobs')) {
        return;
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
        set_cleanup_guids($current_guids);

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

        set_cleanup_old_published_progress([
            'total_processed' => 0,
            'total_deleted' => 0,
            'total_jobs' => $total_count,
            'current_offset' => 0,
            'complete' => false,
            'start_time' => microtime(true),
            'logs' => []
        ]);
    }

    $progress = get_cleanup_old_published_progress();

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

    $current_guids = get_cleanup_guids();

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
            set_cleanup_old_published_progress($progress);

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
        set_cleanup_old_published_progress($progress);

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