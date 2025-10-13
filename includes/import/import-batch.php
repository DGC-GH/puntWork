<?php
/**
 * Batch import processing with timeout protection
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
 * Main import batch processing file
 * Includes all import-related modules and provides the main import function
 */

// Include batch size management
require_once __DIR__ . '/../batch/batch-size-management.php';

// Include import setup
require_once __DIR__ . '/import-setup.php';

// Include batch processing
require_once __DIR__ . '/../batch/batch-processing.php';

// Include import finalization
require_once __DIR__ . '/import-finalization.php';

// Include logger
require_once __DIR__ . '/../utilities/puntwork-logger.php';

/**
 * Check if the current import process has exceeded time limits
 * Similar to WooCommerce's time_exceeded() method
 *
 * @return bool True if time limit exceeded
 */
function import_time_exceeded() {
    $start_time = get_option('job_import_start_time', microtime(true));
    $time_limit = apply_filters('puntwork_import_time_limit', 600); // 600 seconds (10 minutes) default - increased for better performance with large feeds
    $current_time = microtime(true);

    if (($current_time - $start_time) >= $time_limit) {
        return true;
    }

    return apply_filters('puntwork_import_time_exceeded', false);
}

/**
 * Check if the current import process has exceeded memory limits
 * Similar to WooCommerce's memory_exceeded() method
 *
 * @return bool True if memory limit exceeded
 */
function import_memory_exceeded() {
    $memory_limit = get_memory_limit_bytes() * 0.9; // 90% of max memory
    $current_memory = memory_get_usage(true);

    if ($current_memory >= $memory_limit) {
        return true;
    }

    return apply_filters('puntwork_import_memory_exceeded', false);
}

/**
 * Check if batch processing should continue
 * Returns false if time or memory limits exceeded
 *
 * @return bool True if processing should continue
 */
function should_continue_batch_processing() {
    if (import_time_exceeded()) {
        error_log('[PUNTWORK] Import time limit exceeded - pausing batch processing');
        return false;
    }

    if (import_memory_exceeded()) {
        error_log('[PUNTWORK] Import memory limit exceeded - pausing batch processing');
        return false;
    }

    return true;
}

if (!function_exists('import_jobs_from_json')) {
    /**
     * Import jobs from JSONL file in batches.
     *
     * @param bool $is_batch Whether this is a batch import.
     * @param int $batch_start Starting index for batch.
     * @return array Import result data.
     */
    function import_jobs_from_json($is_batch = false, $batch_start = 0) {
        $setup = prepare_import_setup($batch_start);
        if (is_wp_error($setup)) {
            return ['success' => false, 'message' => $setup->get_error_message(), 'logs' => ['Setup failed: ' . $setup->get_error_message()]];
        }
        if (isset($setup['success'])) {
            return $setup; // Early return for empty or completed cases
        }

        $result = process_batch_items_logic($setup);
        return finalize_batch_import($result);
    }
}

if (!function_exists('import_all_jobs_from_json')) {
    /**
     * Import all jobs from JSONL file (processes all batches sequentially).
     * Used for scheduled imports that need to process the entire dataset.
     *
     * @param bool $preserve_status Whether to preserve existing import status for UI polling
     * @return array Import result data.
     */
    function import_all_jobs_from_json($preserve_status = false) {
        $start_time = microtime(true);
        $total_processed = 0;
        $total_published = 0;
        $total_updated = 0;
        $total_skipped = 0;
        $total_duplicates_drafted = 0;
        $all_logs = [];
        $batch_count = 0;
        $total_items = 0;

        error_log('[PUNTWORK] Starting full import - processing all batches without time limit');

        // Only reset status if not preserving existing status
        if (!$preserve_status) {
            // Reset import progress for fresh start
            update_option('job_import_progress', 0, false);
            update_option('job_import_processed_guids', [], false);
            delete_option('job_import_status');
        }

        // Initialize import status for UI tracking (only if not preserving)
        $initial_status = [];
        if (!$preserve_status) {
            $initial_status = [
                'total' => 0, // Will be updated when we know the total
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
                'start_time' => $start_time,
                'end_time' => null,
                'last_update' => time(),
                'logs' => ['Scheduled import started - preparing feeds...'],
            ];
            update_option('job_import_status', $initial_status, false);
        } else {
            // Update existing status to indicate import is resuming (don't reset start_time to preserve elapsed time)
            $existing_status = get_option('job_import_status', []);
            $existing_status['logs'][] = 'Scheduled import resumed - processing batches...';
            // Note: start_time is preserved from original import start
            update_option('job_import_status', $existing_status, false);
            $initial_status = $existing_status; // Use existing status as initial_status for later updates
        }

        // Store import start time for timeout checking
        update_option('job_import_start_time', $start_time, false);

        while (true) {
            // Check if we should continue processing (time/memory limits)
            if (!should_continue_batch_processing()) {
                // Pause processing and schedule continuation
                $current_status = get_option('job_import_status', []);
                $current_status['paused'] = true;
                $current_status['pause_reason'] = 'time_limit_exceeded';
                $current_status['last_update'] = time();
                $current_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Import paused due to time/memory limits - will continue automatically';
                update_option('job_import_status', $current_status, false);

                // Schedule continuation via WordPress cron (runs in background)
                if (!wp_next_scheduled('puntwork_continue_import')) {
                    wp_schedule_single_event(time() + 30, 'puntwork_continue_import'); // Continue in 30 seconds
                    error_log('[PUNTWORK] Scheduled import continuation in 30 seconds');
                }

                return [
                    'success' => true,
                    'processed' => $total_processed,
                    'total' => $total_items,
                    'published' => $total_published,
                    'updated' => $total_updated,
                    'skipped' => $total_skipped,
                    'duplicates_drafted' => $total_duplicates_drafted,
                    'time_elapsed' => microtime(true) - $start_time,
                    'complete' => false,
                    'paused' => true,
                    'message' => 'Import paused due to time limits - will continue automatically in background'
                ];
            }

            $batch_start = (int) get_option('job_import_progress', 0);

            // Prepare setup for this batch
            $setup = prepare_import_setup($batch_start);
            if (is_wp_error($setup)) {
                $error_msg = 'Setup failed: ' . $setup->get_error_message();
                PuntWorkLogger::error('Import process failed during setup', PuntWorkLogger::CONTEXT_BATCH, [
                    'error' => $error_msg,
                    'batch_start' => $batch_start
                ]);
                return ['success' => false, 'message' => $error_msg, 'logs' => [$error_msg]];
            }

            // Capture total items from first setup
            if ($batch_count === 0) {
                $total_items = $setup['total'] ?? 0;
                // Update status with total items
                $initial_status['total'] = $total_items;
                update_option('job_import_status', $initial_status, false);
                error_log('[PUNTWORK] Set total items for import: ' . $total_items);
            }

            // Check if import is complete
            if (isset($setup['success']) && isset($setup['complete']) && $setup['complete']) {
                error_log('[PUNTWORK] Import complete - no more batches to process');
                break;
            }

            if ($batch_start >= $total_items) {
                error_log('[PUNTWORK] Import complete - reached end of data');
                break;
            }

            $batch_count++;
            error_log(sprintf('[PUNTWORK] Processing batch %d starting at index %d', $batch_count, $batch_start));

            // Process this batch
            $result = process_batch_items_logic($setup);

            if (!$result['success']) {
                $error_msg = 'Batch ' . $batch_count . ' failed: ' . ($result['message'] ?? 'Unknown error');
                PuntWorkLogger::error('Import process failed during batch processing', PuntWorkLogger::CONTEXT_BATCH, [
                    'error' => $error_msg,
                    'batch' => $batch_count,
                    'batch_start' => $batch_start,
                    'logs' => $result['logs'] ?? []
                ]);
                return ['success' => false, 'message' => $error_msg, 'logs' => $result['logs'] ?? []];
            }

            // Accumulate results
            $total_processed = max($total_processed, $result['processed']);
            $total_published += $result['published'] ?? 0;
            $total_updated += $result['updated'] ?? 0;
            $total_skipped += $result['skipped'] ?? 0;
            $total_duplicates_drafted += $result['duplicates_drafted'] ?? 0;

            if (isset($result['logs']) && is_array($result['logs'])) {
                $all_logs = array_merge($all_logs, $result['logs']);
            }

            // Update import status for UI tracking
            $current_status = get_option('job_import_status', $initial_status);
            $current_status['processed'] = $total_processed;
            $current_status['published'] = $total_published;
            $current_status['updated'] = $total_updated;
            $current_status['skipped'] = $total_skipped;
            $current_status['duplicates_drafted'] = $total_duplicates_drafted;
            $current_status['time_elapsed'] = microtime(true) - $start_time;
            $current_status['last_update'] = time();
            $current_status['logs'] = array_slice($all_logs, -50); // Keep last 50 log entries for UI
            update_option('job_import_status', $current_status, false);
            error_log(sprintf('[PUNTWORK] Updated import status after batch %d: processed=%d/%d, complete=%s', 
                $batch_count, $total_processed, $total_items, ($total_processed >= $total_items ? 'true' : 'false')));

            // Check if this batch completed the import
            if (isset($result['complete']) && $result['complete']) {
                error_log('[PUNTWORK] Import completed in batch ' . $batch_count);
                break;
            }

            // Safety check to prevent infinite loops
            if ($batch_count > 1000) {
                $error_msg = 'Import aborted - too many batches processed (possible infinite loop)';
                PuntWorkLogger::error('Import process failed due to infinite loop detection', PuntWorkLogger::CONTEXT_BATCH, [
                    'error' => $error_msg,
                    'batches_processed' => $batch_count,
                    'total_items' => $total_items,
                    'processed' => $total_processed
                ]);
                return ['success' => false, 'message' => $error_msg, 'logs' => $all_logs];
            }

            // Small delay between batches to prevent overwhelming the server
            usleep(100000); // 0.1 seconds
        }

        $end_time = microtime(true);
        $total_duration = $end_time - $start_time;

        $final_result = [
            'success' => true,
            'processed' => $total_processed,
            'total' => $total_items,
            'published' => $total_published,
            'updated' => $total_updated,
            'skipped' => $total_skipped,
            'duplicates_drafted' => $total_duplicates_drafted,
            'time_elapsed' => $total_duration,
            'complete' => true,
            'logs' => $all_logs,
            'batches_processed' => $batch_count,
            'deleted_old_posts' => $deleted_count,
            'message' => sprintf(
                'Full import completed successfully - Processed: %d/%d items (Published: %d, Updated: %d, Skipped: %d, Deleted old: %d) in %.1f seconds',
                $total_processed,
                $total_items,
                $total_published,
                $total_updated,
                $total_skipped,
                $deleted_count,
                $total_duration
            )
        ];

        error_log(sprintf(
            '[PUNTWORK] Full import completed - Duration: %.2fs, Batches: %d, Total: %d, Processed: %d, Published: %d, Updated: %d, Skipped: %d, Deleted old: %d',
            $total_duration,
            $batch_count,
            $total_items,
            $total_processed,
            $total_published,
            $total_updated,
            $total_skipped,
            $deleted_count
        ));

        // Log completion using consistent logger
        PuntWorkLogger::info('Import process completed successfully', PuntWorkLogger::CONTEXT_BATCH, [
            'duration' => $total_duration,
            'batches' => $batch_count,
            'total_items' => $total_items,
            'processed' => $total_processed,
            'published' => $total_published,
            'updated' => $total_updated,
            'skipped' => $total_skipped,
            'duplicates_drafted' => $total_duplicates_drafted,
            'deleted_old_posts' => $deleted_count
        ]);

        // Update status to show cleanup phase starting
        $cleanup_status = array_merge($current_status, [
            'total' => $total_items,
            'processed' => $total_processed,
            'published' => $total_published,
            'updated' => $total_updated,
            'skipped' => $total_skipped,
            'duplicates_drafted' => $total_duplicates_drafted,
            'time_elapsed' => $total_duration,
            'complete' => false, // Not complete yet - cleanup phase starting
            'success' => true,
            'error_message' => '',
            'end_time' => null,
            'last_update' => time(),
            'logs' => array_slice($all_logs, -50),
            'cleanup_phase' => true, // Flag to indicate cleanup phase
            'cleanup_total' => 0, // Will be updated during cleanup
            'cleanup_processed' => 0,
        ]);
        update_option('job_import_status', $cleanup_status, false);
        error_log('[PUNTWORK] Starting cleanup phase - status updated: ' . json_encode([
            'cleanup_phase' => true,
            'complete' => false,
            'cleanup_total' => 0,
            'cleanup_processed' => 0
        ]));

        // Clean up old job posts that are no longer in the feed
        $cleanup_result = cleanup_old_job_posts($start_time);
        $deleted_count = $cleanup_result['deleted_count'];
        $cleanup_logs = $cleanup_result['logs'];

        // Add cleanup logs to main logs
        $all_logs = array_merge($all_logs, $cleanup_logs);
        $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Cleanup completed: ' . $deleted_count . ' old published jobs deleted';

        // Ensure final status is updated for UI
        $current_status = get_option('job_import_status', []);
        $final_status = array_merge($current_status, [
            'total' => $total_items,
            'processed' => $total_processed,
            'published' => $total_published,
            'updated' => $total_updated,
            'skipped' => $total_skipped,
            'duplicates_drafted' => $total_duplicates_drafted,
            'time_elapsed' => $total_duration,
            'complete' => true,
            'success' => true,
            'error_message' => '',
            'end_time' => $end_time,
            'last_update' => time(),
            'logs' => array_slice($all_logs, -50),
            'cleanup_phase' => false, // Cleanup complete
            'cleanup_total' => $deleted_count,
            'cleanup_processed' => $deleted_count,
        ]);
        // When complete, ensure processed equals total
        if ($final_status['complete'] && $final_status['processed'] < $final_status['total']) {
            $final_status['processed'] = $final_status['total'];
        }
        update_option('job_import_status', $final_status, false);
        error_log('[PUNTWORK] Import fully completed including cleanup: ' . json_encode([
            'total' => $total_items,
            'processed' => $total_processed,
            'complete' => true,
            'cleanup_total' => $deleted_count,
            'cleanup_processed' => $deleted_count
        ]));
        error_log('[PUNTWORK] Final import status updated: ' . json_encode([
            'total' => $total_items,
            'processed' => $total_processed,
            'complete' => true,
            'success' => true
        ]));
        
        // Ensure cache is cleared so AJAX can see the updated status
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        return finalize_batch_import($final_result);
    }
}

/**
 * Continue a paused import process
 * Called by WordPress cron when import needs to resume after timeout
 */
function continue_paused_import() {
    error_log('[PUNTWORK] Continuing paused import process');

    // Check if import is actually paused
    $status = get_option('job_import_status', []);
    if (!isset($status['paused']) || !$status['paused']) {
        error_log('[PUNTWORK] No paused import found - skipping continuation');
        return;
    }

    // Reset pause status
    $status['paused'] = false;
    unset($status['pause_reason']);
    $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Resuming paused import';
    update_option('job_import_status', $status, false);

    // Reset start time for new timeout window
    update_option('job_import_start_time', microtime(true), false);

    // Continue the import
    $result = import_all_jobs_from_json(true); // preserve status

    if ($result['success']) {
        PuntWorkLogger::info('Paused import continuation completed successfully', PuntWorkLogger::CONTEXT_BATCH, [
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0,
            'time_elapsed' => $result['time_elapsed'] ?? 0
        ]);
    } else {
        PuntWorkLogger::error('Paused import continuation failed', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $result['message'] ?? 'Unknown error',
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0
        ]);
    }
}

// Register the continuation hook
add_action('puntwork_continue_import', __NAMESPACE__ . '\\continue_paused_import');
