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

require_once __DIR__ . '/../utilities/options-utilities.php';

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

// Include core structure logic for feed processing
require_once __DIR__ . '/../core/core-structure-logic.php';

/**
 * Check if the current import process has exceeded time limits
 * Similar to WooCommerce's time_exceeded() method
 *
 * @return bool True if time limit exceeded
 */
function import_time_exceeded() {
    $start_time = get_import_start_time(microtime(true));
    $time_limit = apply_filters('puntwork_import_time_limit', 60); // Reduced to 1 minute to trigger pause before server timeout
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
    error_log('[PUNTWORK] Checking import time limit...');
    if (import_time_exceeded()) {
        error_log('[PUNTWORK] Import time limit exceeded - pausing batch processing');
        return false;
    }

    error_log('[PUNTWORK] Checking import memory limit...');
    if (import_memory_exceeded()) {
        error_log('[PUNTWORK] Import memory limit exceeded - pausing batch processing');
        return false;
    }

    error_log('[PUNTWORK] Continue processing checks passed');
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

        // Get existing batch count if preserving status (resuming paused import)
        if ($preserve_status) {
            $existing_status = get_import_status([]);
            $batch_count = $existing_status['batch_count'] ?? 0;
            error_log('[PUNTWORK] Resuming import from batch ' . ($batch_count + 1));
        }

        error_log('[PUNTWORK] ===== STARTING FULL IMPORT =====');
        error_log('[PUNTWORK] PHP Memory limit: ' . ini_get('memory_limit'));
        error_log('[PUNTWORK] PHP Max execution time: ' . ini_get('max_execution_time'));
        error_log('[PUNTWORK] WordPress memory limit: ' . WP_MEMORY_LIMIT);
        error_log('[PUNTWORK] Preserve status: ' . ($preserve_status ? 'true' : 'false'));

        try {

        // Only reset status if not preserving existing status
        if (!$preserve_status) {
            error_log('[PUNTWORK] Resetting import progress...');
            // Reset import progress for fresh start
            set_import_progress(0);
            error_log('[PUNTWORK] Resetting processed GUIDs...');
            set_processed_guids([]);
            error_log('[PUNTWORK] Clearing import status instead of deleting...');
            // Instead of deleting (which hangs), just update with fresh status
            $fresh_status = [
                'total' => 0,
                'processed' => 0,
                'published' => 0,
                'updated' => 0,
                'skipped' => 0,
                'duplicates_drafted' => 0,
                'batch_count' => 0,
                'time_elapsed' => 0,
                'complete' => false,
                'success' => false,
                'error_message' => '',
                'batch_size' => get_batch_size(),
                'inferred_languages' => 0,
                'inferred_benefits' => 0,
                'schema_generated' => 0,
                'start_time' => $start_time,
                'end_time' => null,
                'last_update' => time(),
                'logs' => ['Fresh import started'],
            ];
            update_option('job_import_status', $fresh_status, false);
            error_log('[PUNTWORK] Import status cleared and reset');
        }

        // Initialize import status for UI tracking (only if not preserving)
        $initial_status = [];
        if (!$preserve_status) {
            error_log('[PUNTWORK] Initializing import status...');
            $initial_status = initialize_import_status(0, 'Scheduled import started - preparing feeds...', $start_time);
            $initial_status['batch_count'] = 0;
            error_log('[PUNTWORK] Setting import status...');
            set_import_status($initial_status);
        } else {
            error_log('[PUNTWORK] Preserving existing status...');
            // Update existing status to indicate import is resuming (don't reset start_time to preserve elapsed time)
            $existing_status = get_import_status([]);
            $existing_status['logs'][] = 'Scheduled import resumed - processing batches...';
            // Note: start_time is preserved from original import start
            set_import_status($existing_status);
            $initial_status = $existing_status; // Use existing status as initial_status for later updates
        }

        // Store import start time for timeout checking
        error_log('[PUNTWORK] Setting import start time...');
        set_import_start_time($start_time);

        error_log('[PUNTWORK] Entering main processing loop...');

        while (true) {
            error_log('[PUNTWORK] ===== BATCH LOOP ITERATION =====');
            error_log('[PUNTWORK] Current memory usage: ' . memory_get_usage(true) / 1024 / 1024 . ' MB');
            error_log('[PUNTWORK] Peak memory usage: ' . memory_get_peak_usage(true) / 1024 / 1024 . ' MB');
            error_log('[PUNTWORK] Execution time so far: ' . (microtime(true) - $start_time) . ' seconds');

            // Check if we should continue processing (time/memory limits)
            if (!should_continue_batch_processing()) {
                error_log('[PUNTWORK] Batch processing should stop due to limits');
                // Pause processing and schedule continuation
                $current_status = get_import_status([]);
                $current_status['paused'] = true;
                $current_status['pause_reason'] = 'time_limit_exceeded';
                $current_status['last_update'] = time();
                $current_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Import paused due to time/memory limits - will continue automatically';
                set_import_status($current_status);

                // Schedule continuation via WordPress cron (runs in background)
                if (!wp_next_scheduled('puntwork_continue_import')) {
                    wp_schedule_single_event(time() + 10, 'puntwork_continue_import'); // Continue in 10 seconds (more aggressive)
                    error_log('[PUNTWORK] Scheduled import continuation in 10 seconds');
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

            $batch_start = (int) get_import_progress(0);

            // Get current batch size before processing this batch
            $current_batch_size = get_batch_size();
            error_log(sprintf('[PUNTWORK] Current batch size for batch %d: %d', $batch_count + 1, $current_batch_size));

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

            // Capture total items from setup (not just first batch)
            if ($batch_count === 0 || $total_items === 0) {
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
            error_log(sprintf('[PUNTWORK] ===== PROCESSING BATCH %d =====', $batch_count));
            error_log(sprintf('[PUNTWORK] Processing batch %d starting at index %d', $batch_count, $batch_start));

            try {
                // Process this batch
                error_log('[PUNTWORK] Calling process_batch_items_logic...');
                $result = process_batch_items_logic($setup);
                error_log('[PUNTWORK] process_batch_items_logic completed');
                
                if (!$result['success']) {
                    $error_msg = 'Batch ' . $batch_count . ' failed: ' . ($result['message'] ?? 'Unknown error');
                    error_log('[PUNTWORK] BATCH FAILED: ' . $error_msg);
                    PuntWorkLogger::error('Import process failed during batch processing', PuntWorkLogger::CONTEXT_BATCH, [
                        'error' => $error_msg,
                        'batch' => $batch_count,
                        'batch_start' => $batch_start,
                        'logs' => $result['logs'] ?? []
                    ]);
                    return ['success' => false, 'message' => $error_msg, 'logs' => $result['logs'] ?? []];
                }
                error_log('[PUNTWORK] Batch processing successful');
            } catch (Exception $e) {
                $error_msg = 'Exception in batch ' . $batch_count . ': ' . $e->getMessage();
                error_log('[PUNTWORK] BATCH EXCEPTION: ' . $error_msg);
                error_log('[PUNTWORK] Exception trace: ' . $e->getTraceAsString());
                PuntWorkLogger::error('Import process failed with exception', PuntWorkLogger::CONTEXT_BATCH, [
                    'error' => $error_msg,
                    'batch' => $batch_count,
                    'batch_start' => $batch_start,
                    'trace' => $e->getTraceAsString()
                ]);
                return ['success' => false, 'message' => $error_msg, 'logs' => [$error_msg]];
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

            // Calculate and log time per item for performance optimization
            $batch_processed_count = $result['batch_processed'] ?? $result['processed'] ?? 0;
            $batch_time = $result['batch_time'] ?? 0;
            $time_per_item = $batch_processed_count > 0 ? $batch_time / $batch_processed_count : 0;
            
            error_log(sprintf('[PUNTWORK] ===== BATCH %d PERFORMANCE =====', $batch_count));
            error_log(sprintf('[PUNTWORK] Batch %d completed in %.3f seconds', $batch_count, $batch_time));
            error_log(sprintf('[PUNTWORK] Processed %d items in batch %d', $batch_processed_count, $batch_count));
            error_log(sprintf('[PUNTWORK] Time per item: %.3f seconds', $time_per_item));
            error_log(sprintf('[PUNTWORK] Items per second: %.2f', $time_per_item > 0 ? 1 / $time_per_item : 0));
            
            // Log performance classification
            if ($time_per_item <= 1.0) {
                error_log('[PUNTWORK] Performance: EXCELLENT (≤1.0 sec/item)');
            } elseif ($time_per_item <= 2.0) {
                error_log('[PUNTWORK] Performance: GOOD (1.0-2.0 sec/item)');
            } elseif ($time_per_item <= 3.0) {
                error_log('[PUNTWORK] Performance: MODERATE (2.0-3.0 sec/item)');
            } elseif ($time_per_item <= 5.0) {
                error_log('[PUNTWORK] Performance: SLOW (3.0-5.0 sec/item)');
            } else {
                error_log('[PUNTWORK] Performance: VERY SLOW (>5.0 sec/item)');
            }
            
            // Store time per item for batch size optimization
            PuntWorkLogger::info('Batch performance metrics', PuntWorkLogger::CONTEXT_BATCH, [
                'batch_number' => $batch_count,
                'batch_time' => $batch_time,
                'items_processed' => $batch_processed_count,
                'time_per_item' => $time_per_item,
                'items_per_second' => $time_per_item > 0 ? 1 / $time_per_item : 0,
                'performance_rating' => $time_per_item <= 1.0 ? 'excellent' : 
                                       ($time_per_item <= 2.0 ? 'good' : 
                                       ($time_per_item <= 3.0 ? 'moderate' : 
                                       ($time_per_item <= 5.0 ? 'slow' : 'very_slow'))),
                'batch_size_used' => $result['batch_size'] ?? get_batch_size(),
                'memory_peak_mb' => memory_get_peak_usage(true) / 1024 / 1024
            ]);

            // Update import status for UI tracking
            $current_status = get_import_status($initial_status);
            $current_status['processed'] = $total_processed;
            $current_status['published'] = $total_published;
            $current_status['updated'] = $total_updated;
            $current_status['skipped'] = $total_skipped;
            $current_status['duplicates_drafted'] = $total_duplicates_drafted;
            $current_status['batch_count'] = $batch_count;
            $current_status['time_elapsed'] = microtime(true) - $start_time;
            $current_status['last_update'] = time();
            $current_status['logs'] = array_slice($all_logs, -50); // Keep last 50 log entries for UI
            set_import_status($current_status);
            // Also update the progress option for continuation
            set_import_progress($total_processed);
            error_log(sprintf('[PUNTWORK] Updated import status after batch %d: processed=%d/%d, complete=%s', 
                $batch_count, $total_processed, $total_items, ($total_processed >= $total_items ? 'true' : 'false')));

            // Check if this batch completed the import
            if (isset($result['complete']) && $result['complete']) {
                error_log('[PUNTWORK] Import completed in batch ' . $batch_count);
                break;
            }

            // Check if batch size changed after this batch and log it
            $new_batch_size = get_batch_size();
            if ($new_batch_size != $current_batch_size) {
                error_log(sprintf('[PUNTWORK] Batch size changed from %d to %d after batch %d', 
                    $current_batch_size, $new_batch_size, $batch_count));
                error_log(sprintf('[PUNTWORK] Batch size change: %+.1f (%+.1f%%)', 
                    $new_batch_size - $current_batch_size, 
                    $current_batch_size > 0 ? (($new_batch_size - $current_batch_size) / $current_batch_size) * 100 : 0));
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

        // Calculate overall performance metrics
        $overall_time_per_item = $total_processed > 0 ? $total_duration / $total_processed : 0;
        $overall_items_per_second = $overall_time_per_item > 0 ? 1 / $overall_time_per_item : 0;
        
        error_log('[PUNTWORK] ===== IMPORT PERFORMANCE SUMMARY =====');
        error_log(sprintf('[PUNTWORK] Total import time: %.2f seconds', $total_duration));
        error_log(sprintf('[PUNTWORK] Total items processed: %d', $total_processed));
        error_log(sprintf('[PUNTWORK] Overall time per item: %.3f seconds', $overall_time_per_item));
        error_log(sprintf('[PUNTWORK] Overall items per second: %.2f', $overall_items_per_second));
        error_log(sprintf('[PUNTWORK] Total batches processed: %d', $batch_count));
        error_log(sprintf('[PUNTWORK] Average batch size: %.1f', $batch_count > 0 ? $total_processed / $batch_count : 0));
        
        // Log performance classification
        if ($overall_time_per_item <= 1.0) {
            error_log('[PUNTWORK] Overall Performance: EXCELLENT (≤1.0 sec/item)');
        } elseif ($overall_time_per_item <= 2.0) {
            error_log('[PUNTWORK] Overall Performance: GOOD (1.0-2.0 sec/item)');
        } elseif ($overall_time_per_item <= 3.0) {
            error_log('[PUNTWORK] Overall Performance: MODERATE (2.0-3.0 sec/item)');
        } elseif ($overall_time_per_item <= 5.0) {
            error_log('[PUNTWORK] Overall Performance: SLOW (3.0-5.0 sec/item)');
        } else {
            error_log('[PUNTWORK] Overall Performance: VERY SLOW (>5.0 sec/item)');
        }
        
        PuntWorkLogger::info('Import performance summary', PuntWorkLogger::CONTEXT_IMPORT, [
            'total_duration' => $total_duration,
            'total_items_processed' => $total_processed,
            'overall_time_per_item' => $overall_time_per_item,
            'overall_items_per_second' => $overall_items_per_second,
            'total_batches' => $batch_count,
            'average_batch_size' => $batch_count > 0 ? $total_processed / $batch_count : 0,
            'performance_rating' => $overall_time_per_item <= 1.0 ? 'excellent' : 
                                   ($overall_time_per_item <= 2.0 ? 'good' : 
                                   ($overall_time_per_item <= 3.0 ? 'moderate' : 
                                   ($overall_time_per_item <= 5.0 ? 'slow' : 'very_slow'))),
            'final_memory_peak_mb' => memory_get_peak_usage(true) / 1024 / 1024
        ]);

        // Clean up old job posts that are no longer in the feed BEFORE creating final result
        // Add timeout protection for cleanup phase
        $cleanup_start_time = microtime(true);
        $cleanup_timeout = 300; // 5 minutes max for cleanup

        try {
            $cleanup_result = cleanup_old_job_posts($start_time);
            $deleted_count = $cleanup_result['deleted_count'];
            $cleanup_logs = $cleanup_result['logs'];

            $cleanup_duration = microtime(true) - $cleanup_start_time;
            PuntWorkLogger::info('Cleanup phase completed', PuntWorkLogger::CONTEXT_BATCH, [
                'duration' => $cleanup_duration,
                'deleted_count' => $deleted_count
            ]);

        } catch (Exception $e) {
            PuntWorkLogger::error('Cleanup phase failed', PuntWorkLogger::CONTEXT_BATCH, [
                'error' => $e->getMessage(),
                'duration' => microtime(true) - $cleanup_start_time
            ]);

            // Continue with import even if cleanup fails
            $deleted_count = 0;
            $cleanup_logs = ['[' . date('d-M-Y H:i:s') . ' UTC] Cleanup failed: ' . $e->getMessage()];
        }

        // Add cleanup logs to main logs
        $all_logs = array_merge($all_logs, $cleanup_logs);
        $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Cleanup completed: ' . $deleted_count . ' old published jobs deleted';

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

        // Ensure final status is updated for UI
        $current_status = get_import_status([]);
        $final_status = array_merge($current_status, [
            'total' => $total_items,
            'processed' => $total_processed,
            'published' => $total_published,
            'updated' => $total_updated,
            'skipped' => $total_skipped,
            'duplicates_drafted' => $total_duplicates_drafted,
            'batch_count' => $batch_count,
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
        set_import_status($final_status);
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
        } catch (Exception $e) {
            $fatal_error = 'Fatal error in import_all_jobs_from_json: ' . $e->getMessage();
            error_log('[PUNTWORK] FATAL ERROR: ' . $fatal_error);
            error_log('[PUNTWORK] Fatal exception trace: ' . $e->getTraceAsString());
            
            PuntWorkLogger::error('Fatal import error', PuntWorkLogger::CONTEXT_IMPORT, [
                'error' => $fatal_error,
                'trace' => $e->getTraceAsString(),
                'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . ' MB',
                'memory_current' => memory_get_usage(true) / 1024 / 1024 . ' MB',
                'batches_processed' => $batch_count,
                'total_processed' => $total_processed,
                'execution_time' => microtime(true) - $start_time
            ]);
            
            // Update status to failed
            $failed_status = get_import_status([]);
            $failed_status['success'] = false;
            $failed_status['complete'] = true;
            $failed_status['error_message'] = $fatal_error;
            $failed_status['end_time'] = microtime(true);
            $failed_status['last_update'] = time();
            $failed_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] FATAL ERROR: ' . $fatal_error;
            set_import_status($failed_status);
            
            return ['success' => false, 'message' => $fatal_error, 'logs' => [$fatal_error]];
        }
    }
}

/**
 * Continue a paused import process
 * Called by WordPress cron when import needs to resume after timeout
 */
function continue_paused_import() {
    error_log('[PUNTWORK] Continuing paused import process');

    // Check if import is actually paused
    $status = get_import_status([]);
    if (!isset($status['paused']) || !$status['paused']) {
        error_log('[PUNTWORK] No paused import found - skipping continuation');
        return;
    }

    // Reset pause status
    $status['paused'] = false;
    unset($status['pause_reason']);
    $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Resuming paused import';
    set_import_status($status);

    // Reset start time for new timeout window
    set_import_start_time(microtime(true));

    // Continue the import
    $result = import_all_jobs_from_json(true); // preserve status for continuation

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
