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
    $time_limit = apply_filters('puntwork_import_time_limit', 120); // Reduced to 2 minutes for Hostinger
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
            // Resume cache invalidation on setup failure
            wp_suspend_cache_invalidation(false);
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
        $accumulated_time = 0; // Track total elapsed time across continuations
        $all_action_ids = []; // Track all Action Scheduler action IDs for waiting

        // Get existing batch count if preserving status (resuming paused import)
        if ($preserve_status) {
            $existing_status = get_import_status([]);
            $batch_count = $existing_status['batch_count'] ?? 0;
            $accumulated_time = $existing_status['time_elapsed'] ?? 0; // Preserve previous elapsed time
            error_log('[PUNTWORK] Resuming import from batch ' . ($batch_count + 1) . ', accumulated time: ' . $accumulated_time . ' seconds');
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
                'last_update' => microtime(true),
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
            $initial_status['phase'] = 'job-importing'; // Set phase for job importing
            error_log('[PUNTWORK] Setting import status...');
            set_import_status($initial_status);
        } else {
            error_log('[PUNTWORK] Preserving existing status...');
            // Update existing status to indicate import is resuming (don't reset start_time to preserve elapsed time)
            $existing_status = get_import_status([]);
            if (!is_array($existing_status['logs'] ?? null)) {
                $existing_status['logs'] = [];
            }
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

            // Check for cancellation at the start of each batch loop iteration
            if (get_transient('import_cancel') === true || get_transient('import_force_cancel') === true || get_transient('import_emergency_stop') === true) {
                $cancel_type = get_transient('import_emergency_stop') === true ? 'emergency stopped' :
                              (get_transient('import_force_cancel') === true ? 'force cancelled' : 'cancelled');
                error_log('[PUNTWORK] Import ' . $cancel_type . ' during batch processing loop');
                PuntWorkLogger::info('Import ' . $cancel_type . ' during batch loop', PuntWorkLogger::CONTEXT_BATCH, [
                    'batches_completed' => $batch_count,
                    'items_processed' => $total_processed,
                    'action' => 'terminated_batch_loop',
                    'cancel_type' => $cancel_type
                ]);

                // Update status to show cancellation
                $cancelled_status = get_import_status([]);
                $cancelled_status['success'] = false;
                $cancelled_status['complete'] = true;
                $cancelled_status['error_message'] = 'Import ' . $cancel_type . ' by user';
                $cancelled_status['end_time'] = microtime(true);
                $cancelled_status['last_update'] = microtime(true);
                if (!is_array($cancelled_status['logs'] ?? null)) {
                    $cancelled_status['logs'] = [];
                }
                $cancelled_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Import ' . $cancel_type . ' by user during batch processing';
                set_import_status($cancelled_status);

                return [
                    'success' => false,
                    'processed' => $total_processed,
                    'total' => $total_items,
                    'published' => $total_published,
                    'updated' => $total_updated,
                    'skipped' => $total_skipped,
                    'duplicates_drafted' => $total_duplicates_drafted,
                    'time_elapsed' => microtime(true) - $start_time + $accumulated_time,
                    'complete' => true,
                    'message' => 'Import ' . $cancel_type . ' by user'
                ];
            }

            // Check if we should continue processing (time/memory limits)
            if (!should_continue_batch_processing()) {
                error_log('[PUNTWORK] Batch processing should stop due to limits');

                // Check for cancellation before scheduling continuation
                if (get_transient('import_cancel') === true || get_transient('import_force_cancel') === true || get_transient('import_emergency_stop') === true) {
                    $cancel_type = get_transient('import_emergency_stop') === true ? 'emergency stopped' :
                                  (get_transient('import_force_cancel') === true ? 'force cancelled' : 'cancelled');
                    error_log('[PUNTWORK] Import was ' . $cancel_type . ' - not scheduling continuation');
                    PuntWorkLogger::info('Cancelled import continuation prevented', PuntWorkLogger::CONTEXT_BATCH, [
                        'reason' => 'import_cancel_transient_set_during_limits_check',
                        'action' => 'skipped_continuation_scheduling',
                        'cancel_type' => $cancel_type
                    ]);

                    // Update status to show cancellation
                    $current_status = get_import_status([]);
                    $current_status['success'] = false;
                    $current_status['complete'] = true;
                    $current_status['error_message'] = 'Import ' . $cancel_type . ' by user';
                    $current_status['end_time'] = microtime(true);
                    $current_status['last_update'] = microtime(true);
                    if (!is_array($current_status['logs'] ?? null)) {
                        $current_status['logs'] = [];
                    }
                    $current_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Import ' . $cancel_type . ' by user - stopping continuation';
                    set_import_status($current_status);

                    return [
                        'success' => false,
                        'processed' => $total_processed,
                        'total' => $total_items,
                        'published' => $total_published,
                        'updated' => $total_updated,
                        'skipped' => $total_skipped,
                        'duplicates_drafted' => $total_duplicates_drafted,
                        'time_elapsed' => microtime(true) - $start_time + $accumulated_time,
                        'complete' => true,
                        'message' => 'Import ' . $cancel_type . ' by user'
                    ];
                }

                // Pause processing and schedule continuation
                $current_status = get_import_status([]);
                $current_status['paused'] = true;
                $current_status['pause_reason'] = 'time_limit_exceeded';
                $current_status['last_update'] = microtime(true);
                if (!is_array($current_status['logs'] ?? null)) {
                    $current_status['logs'] = [];
                }
                $current_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Import paused due to time/memory limits - will continue automatically';
                set_import_status($current_status);

                // IMPLEMENT FALLBACK MECHANISMS FOR CRON-BASED CONTINUATION
                $pause_time = microtime(true);
                $current_status['pause_time'] = $pause_time;
                $current_status['continuation_attempts'] = 0;
                $current_status['last_continuation_attempt'] = null;
                $current_status['continuation_strategy'] = 'cron_fallback';
                set_import_status($current_status);

                // Schedule multiple fallback continuation mechanisms
                schedule_import_continuation_with_fallbacks($pause_time);

                return [
                    'success' => true,
                    'processed' => $total_processed,
                    'total' => $total_items,
                    'published' => $total_published,
                    'updated' => $total_updated,
                    'skipped' => $total_skipped,
                    'duplicates_drafted' => $total_duplicates_drafted,
                    'time_elapsed' => microtime(true) - $start_time + $accumulated_time,
                    'complete' => false,
                    'paused' => true,
                    'message' => 'Import paused due to time limits - multiple continuation mechanisms scheduled'
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
                // Resume cache invalidation on setup failure
                wp_suspend_cache_invalidation(false);
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
                // Resume cache invalidation on batch exception
                wp_suspend_cache_invalidation(false);
                return ['success' => false, 'message' => $error_msg, 'logs' => [$error_msg]];
            }

            // Accumulate results
            $total_processed = max($total_processed, $result['processed']);
            $total_published += $result['published'] ?? 0;
            $total_updated += $result['updated'] ?? 0;
            $total_skipped += $result['skipped'] ?? 0;
            $total_duplicates_drafted += $result['duplicates_drafted'] ?? 0;

            // Collect Action Scheduler action IDs for waiting
            if (isset($result['action_ids']) && is_array($result['action_ids'])) {
                $all_action_ids = array_merge($all_action_ids, $result['action_ids']);
            }

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

            // ===== BATCH LOOP DECISION POINT =====
            error_log('[PUNTWORK] ===== BATCH LOOP DECISION POINT =====');
            error_log('[PUNTWORK] Checking if import is complete...');
            error_log('[PUNTWORK] Current batch count: ' . $batch_count);
            error_log('[PUNTWORK] Total processed so far: ' . $total_processed);
            error_log('[PUNTWORK] Total items: ' . $total_items);
            error_log('[PUNTWORK] Batch start index: ' . $batch_start);
            error_log('[PUNTWORK] Batch result complete flag: ' . ($result['complete'] ?? 'not set'));
            error_log('[PUNTWORK] Setup complete flag: ' . ($setup['complete'] ?? 'not set'));

            // Check if this batch completed the import
            if (isset($result['complete']) && $result['complete']) {
                error_log('[PUNTWORK] Batch result indicates import complete - breaking loop');
                break;
            }

            // Check if we've reached the end of data
            if ($batch_start >= $total_items) {
                error_log('[PUNTWORK] Reached end of data (batch_start >= total_items) - breaking loop');
                break;
            }

            // Check if setup indicates completion
            if (isset($setup['success']) && isset($setup['complete']) && $setup['complete']) {
                error_log('[PUNTWORK] Setup indicates import complete - breaking loop');
                break;
            }

            error_log('[PUNTWORK] Continuing to next batch iteration...');
            error_log('[PUNTWORK] ===== END OF BATCH ' . $batch_count . ' =====');

            // Update import status for UI tracking
            $current_status = get_import_status($initial_status);
            $current_status['processed'] = $total_processed;
            $current_status['published'] = $total_published;
            $current_status['updated'] = $total_updated;
            $current_status['skipped'] = $total_skipped;
            $current_status['duplicates_drafted'] = $total_duplicates_drafted;
            $current_status['batch_count'] = $batch_count;
            $current_status['time_elapsed'] = microtime(true) - $start_time + $accumulated_time;
            $current_status['last_update'] = microtime(true);
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
                // Resume cache invalidation on infinite loop detection
                wp_suspend_cache_invalidation(false);
                return ['success' => false, 'message' => $error_msg, 'logs' => $all_logs];
            }

            // Small delay between batches to prevent overwhelming the server
            usleep(100000); // 0.1 seconds
        }

        $end_time = microtime(true);
        $total_duration = $end_time - $start_time + $accumulated_time;

        // Wait for all concurrent Action Scheduler jobs to complete before marking import as complete
        if (!empty($all_action_ids) && function_exists('ActionScheduler') && class_exists('ActionScheduler')) {
            PuntWorkLogger::info('Waiting for concurrent processing to complete', PuntWorkLogger::CONTEXT_BATCH, [
                'total_actions' => count($all_action_ids),
                'action_ids_sample' => array_slice($all_action_ids, 0, 5)
            ]);

            // Wait for all actions to complete (with timeout)
            $wait_start = microtime(true);
            $max_wait_time = 60; // 1 minute max wait on Hostinger
            $check_interval = 2; // Check every 2 seconds
            $all_complete = false;
            $consecutive_complete_checks = 0;
            $required_consecutive_complete = 3; // Require 3 consecutive complete checks for stability

            while (!$all_complete && (microtime(true) - $wait_start) < $max_wait_time) {
                $all_complete = true;
                $pending_count = 0;
                $completed_count = 0;
                $failed_count = 0;

                foreach ($all_action_ids as $action_id) {
                    try {
                        $action = ActionScheduler::store()->fetch_action($action_id);
                        if ($action && !$action->is_finished()) {
                            $all_complete = false;
                            $pending_count++;
                        } elseif ($action && \ActionScheduler::store()->get_status($action_id) === \ActionScheduler_Store::STATUS_FAILED) {
                            // Failed actions are considered finished, but log them
                            $failed_count++;
                            PuntWorkLogger::warning('Action Scheduler job failed', PuntWorkLogger::CONTEXT_BATCH, [
                                'action_id' => $action_id,
                                'status' => 'failed'
                            ]);
                        } else {
                            $completed_count++;
                        }
                    } catch (\Exception $e) {
                        PuntWorkLogger::warning('Error checking action status', PuntWorkLogger::CONTEXT_BATCH, [
                            'action_id' => $action_id,
                            'error' => $e->getMessage()
                        ]);
                        // Assume completed on error to avoid infinite waiting
                        $completed_count++;
                    }
                }

                if ($all_complete) {
                    $consecutive_complete_checks++;
                    if ($consecutive_complete_checks >= $required_consecutive_complete) {
                        // Require multiple consecutive complete checks for stability
                        break;
                    }
                    // Reset counter if we find pending actions again
                } else {
                    $consecutive_complete_checks = 0;
                }

                if (!$all_complete || $consecutive_complete_checks < $required_consecutive_complete) {
                    PuntWorkLogger::debug('Waiting for concurrent actions to complete', PuntWorkLogger::CONTEXT_BATCH, [
                        'pending' => $pending_count,
                        'completed' => $completed_count,
                        'failed' => $failed_count,
                        'wait_time' => round(microtime(true) - $wait_start, 1),
                        'consecutive_complete_checks' => $consecutive_complete_checks
                    ]);
                    sleep($check_interval);
                }
            }

            $wait_duration = microtime(true) - $wait_start;
            if (!$all_complete) {
                PuntWorkLogger::warning('Timeout waiting for concurrent actions to complete', PuntWorkLogger::CONTEXT_BATCH, [
                    'total_actions' => count($all_action_ids),
                    'wait_time' => round($wait_duration, 1),
                    'max_wait_time' => $max_wait_time,
                    'pending_count' => $pending_count ?? 0
                ]);
            } else {
                PuntWorkLogger::info('All concurrent actions completed successfully', PuntWorkLogger::CONTEXT_BATCH, [
                    'total_actions' => count($all_action_ids),
                    'wait_time' => round($wait_duration, 1),
                    'consecutive_checks' => $consecutive_complete_checks
                ]);
            }
        } elseif (!empty($all_action_ids)) {
            PuntWorkLogger::warning('Action Scheduler not available for waiting on concurrent actions', PuntWorkLogger::CONTEXT_BATCH, [
                'total_actions' => count($all_action_ids),
                'action_scheduler_available' => function_exists('ActionScheduler') && class_exists('ActionScheduler')
            ]);
        }
        
        // Calculate performance metrics
        $overall_time_per_item = $total_processed > 0 ? $total_duration / $total_processed : 0;
        $overall_items_per_second = $total_duration > 0 ? $total_processed / $total_duration : 0;
        
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

        // Add timeout protection for cleanup phase
        $cleanup_start_time = microtime(true);
        $cleanup_timeout = 60; // 1 minute max for cleanup on Hostinger

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
        // SUCCESS RATE TRACKING: Calculate and include overall import success rate
        $overall_success_rate = $total_items > 0 ? ($total_processed - $total_skipped) / $total_items : 1.0;
        $concurrent_success_rate = get_concurrent_success_rate();
        $sequential_success_rate = get_sequential_success_rate();

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
            'last_update' => microtime(true),
            'logs' => array_slice($all_logs, -50),
            'cleanup_phase' => false, // Cleanup complete
            'cleanup_total' => $deleted_count,
            'cleanup_processed' => $deleted_count,
            'import_completion_locked' => true, // CRITICAL: Lock to prevent further status updates
            // Success rate tracking
            'overall_success_rate' => $overall_success_rate,
            'concurrent_success_rate' => $concurrent_success_rate,
            'sequential_success_rate' => $sequential_success_rate,
            'processing_mode_used' => $concurrent_success_rate > $sequential_success_rate ? 'concurrent' : 'sequential',
            'total_successful' => $total_processed - $total_skipped,
            'total_failed' => $total_skipped
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

        // Resume cache invalidation that was suspended at import start
        wp_suspend_cache_invalidation(false);

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
            $failed_status['last_update'] = microtime(true);
            if (!is_array($failed_status['logs'] ?? null)) {
                $failed_status['logs'] = [];
            }
            $failed_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] FATAL ERROR: ' . $fatal_error;
            set_import_status($failed_status);
            
            // Resume cache invalidation even on fatal error
            wp_suspend_cache_invalidation(false);
            
            return ['success' => false, 'message' => $fatal_error, 'logs' => [$fatal_error]];
        }
    }
}

/**
 * Continue a paused import process
 * Called by WordPress cron when import needs to resume after timeout
 */
function continue_paused_import() {
    $attempt_time = microtime(true);
    error_log('[PUNTWORK] ===== PRIMARY CONTINUATION ATTEMPT STARTING =====');
    error_log('[PUNTWORK] Attempt timestamp: ' . date('Y-m-d H:i:s', $attempt_time));
    error_log('[PUNTWORK] Current memory: ' . memory_get_usage(true) / 1024 / 1024 . ' MB');
    error_log('[PUNTWORK] Peak memory: ' . memory_get_peak_usage(true) / 1024 / 1024 . ' MB');

    // Update continuation attempt tracking
    $status = get_import_status([]);
    error_log('[PUNTWORK] Current import status before continuation: ' . json_encode([
        'paused' => $status['paused'] ?? false,
        'complete' => $status['complete'] ?? false,
        'processed' => $status['processed'] ?? 0,
        'total' => $status['total'] ?? 0,
        'continuation_attempts' => $status['continuation_attempts'] ?? 0
    ]));

    $status['continuation_attempts'] = ($status['continuation_attempts'] ?? 0) + 1;
    $status['last_continuation_attempt'] = $attempt_time;
    $status['continuation_method'] = 'primary_cron';
    set_import_status($status);

    PuntWorkLogger::info('Primary continuation attempt initiated', PuntWorkLogger::CONTEXT_BATCH, [
        'attempt_number' => $status['continuation_attempts'],
        'pause_time' => $status['pause_time'] ?? null,
        'time_since_pause' => $status['pause_time'] ? ($attempt_time - $status['pause_time']) : null,
        'method' => 'primary_cron'
    ]);

    // Check for cancellation before resuming
    if (get_transient('import_cancel') === true || get_transient('import_force_cancel') === true || get_transient('import_emergency_stop') === true) {
        $cancel_type = get_transient('import_emergency_stop') === true ? 'emergency stopped' :
                      (get_transient('import_force_cancel') === true ? 'force cancelled' : 'cancelled');
        error_log('[PUNTWORK] Import was ' . $cancel_type . ' - not resuming paused import');
        PuntWorkLogger::info('Cancelled import continuation prevented', PuntWorkLogger::CONTEXT_BATCH, [
            'reason' => 'import_cancel_transient_set',
            'action' => 'skipped_continuation',
            'cancel_type' => $cancel_type,
            'method' => 'primary_cron'
        ]);

        // Update status to show cancellation
        $status = get_import_status([]);
        $status['success'] = false;
        $status['complete'] = true;
        $status['error_message'] = 'Import ' . $cancel_type . ' by user';
        $status['end_time'] = microtime(true);
        $status['last_update'] = microtime(true);
        if (!is_array($status['logs'] ?? null)) {
            $status['logs'] = [];
        }
        $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Import ' . $cancel_type . ' by user - not resuming';
        set_import_status($status);

        // Clear remaining fallback schedules
        clear_import_continuation_schedules();

        return;
    }

    // Check if import is actually paused
    $status = get_import_status([]);
    if (!isset($status['paused']) || !$status['paused']) {
        error_log('[PUNTWORK] No paused import found - skipping continuation');
        error_log('[PUNTWORK] Import status details: ' . json_encode($status));
        PuntWorkLogger::info('Continuation skipped - import not paused', PuntWorkLogger::CONTEXT_BATCH, [
            'current_status' => $status,
            'method' => 'primary_cron'
        ]);

        // Clear remaining fallback schedules
        clear_import_continuation_schedules();

        return;
    }

    error_log('[PUNTWORK] Import is paused, proceeding with continuation...');

    // Reset pause status
    $status['paused'] = false;
    unset($status['pause_reason']);
    if (!is_array($status['logs'] ?? null)) {
        $status['logs'] = [];
    }
    $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Resuming paused import (primary continuation)';
    set_import_status($status);

    error_log('[PUNTWORK] Pause status reset, calling import_all_jobs_from_json(true)...');

    // Continue the import
    $result = import_all_jobs_from_json(true); // preserve status for continuation

    error_log('[PUNTWORK] Continuation result: ' . json_encode([
        'success' => $result['success'] ?? false,
        'processed' => $result['processed'] ?? 0,
        'total' => $result['total'] ?? 0,
        'complete' => $result['complete'] ?? false,
        'message' => $result['message'] ?? 'No message'
    ]));

    if ($result['success']) {
        PuntWorkLogger::info('Primary continuation completed successfully', PuntWorkLogger::CONTEXT_BATCH, [
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0,
            'time_elapsed' => $result['time_elapsed'] ?? 0,
            'attempts_used' => $status['continuation_attempts'] ?? 1
        ]);

        // Clear remaining fallback schedules since we succeeded
        clear_import_continuation_schedules();
    } else {
        PuntWorkLogger::error('Primary continuation failed', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $result['message'] ?? 'Unknown error',
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0,
            'attempts_used' => $status['continuation_attempts'] ?? 1,
            'fallback_available' => true
        ]);

        // Don't clear schedules - let fallback mechanisms try
        error_log('[PUNTWORK] Primary continuation failed - fallback mechanisms still active');
    }

    error_log('[PUNTWORK] ===== PRIMARY CONTINUATION ATTEMPT COMPLETED =====');
}

// Register the continuation hook
add_action('puntwork_continue_import', __NAMESPACE__ . '\\continue_paused_import');
add_action('puntwork_continue_import_retry', __NAMESPACE__ . '\\continue_paused_import_retry');
add_action('puntwork_continue_import_manual', __NAMESPACE__ . '\\continue_paused_import_manual');
add_action('puntwork_check_continuation_status', __NAMESPACE__ . '\\check_continuation_status');

/**
 * Schedule multiple fallback mechanisms for import continuation
 *
 * @param float $pause_time When the import was paused
 */
function schedule_import_continuation_with_fallbacks($pause_time) {
    $current_time = time();

    // Clear any existing continuation schedules first
    wp_clear_scheduled_hook('puntwork_continue_import');
    wp_clear_scheduled_hook('puntwork_continue_import_retry');
    wp_clear_scheduled_hook('puntwork_continue_import_manual');
    wp_clear_scheduled_hook('puntwork_check_continuation_status');

    // PRIMARY: Immediate cron continuation (10 seconds)
    if (!wp_next_scheduled('puntwork_continue_import')) {
        wp_schedule_single_event($current_time + 10, 'puntwork_continue_import');
        PuntWorkLogger::info('Scheduled primary import continuation', PuntWorkLogger::CONTEXT_BATCH, [
            'delay_seconds' => 10,
            'scheduled_time' => date('Y-m-d H:i:s', $current_time + 10),
            'pause_time' => $pause_time
        ]);
    }

    // FALLBACK 1: Retry continuation (2 minutes) - in case primary cron fails
    if (!wp_next_scheduled('puntwork_continue_import_retry')) {
        wp_schedule_single_event($current_time + 120, 'puntwork_continue_import_retry');
        PuntWorkLogger::info('Scheduled fallback retry continuation', PuntWorkLogger::CONTEXT_BATCH, [
            'delay_seconds' => 120,
            'scheduled_time' => date('Y-m-d H:i:s', $current_time + 120),
            'purpose' => 'retry_if_primary_fails'
        ]);
    }

    // FALLBACK 2: Manual trigger continuation (5 minutes) - for admin intervention
    if (!wp_next_scheduled('puntwork_continue_import_manual')) {
        wp_schedule_single_event($current_time + 300, 'puntwork_continue_import_manual');
        PuntWorkLogger::info('Scheduled manual trigger continuation', PuntWorkLogger::CONTEXT_BATCH, [
            'delay_seconds' => 300,
            'scheduled_time' => date('Y-m-d H:i:s', $current_time + 300),
            'purpose' => 'admin_manual_trigger'
        ]);
    }

    // MONITORING: Status check (every 30 seconds for first 10 minutes, then hourly)
    for ($i = 1; $i <= 20; $i++) { // 20 checks = 10 minutes
        $check_time = $current_time + ($i * 30);
        if ($check_time < $current_time + 3600) { // Within first hour
            wp_schedule_single_event($check_time, 'puntwork_check_continuation_status');
        }
    }
    // Then hourly checks for up to 24 hours
    for ($i = 1; $i <= 24; $i++) {
        wp_schedule_single_event($current_time + 3600 + ($i * 3600), 'puntwork_check_continuation_status');
    }

    PuntWorkLogger::info('Multiple continuation mechanisms scheduled', PuntWorkLogger::CONTEXT_BATCH, [
        'mechanisms' => ['primary_cron', 'retry_fallback', 'manual_trigger', 'status_monitoring'],
        'total_scheduled_events' => 3 + 20 + 24, // primary + retry + manual + status checks
        'monitoring_duration_hours' => 24
    ]);
}

/**
 * Retry continuation fallback mechanism
 */
function continue_paused_import_retry() {
    $attempt_time = microtime(true);
    error_log('[PUNTWORK] Retry continuation fallback starting');

    // Check if primary already succeeded
    $status = get_import_status([]);
    if (isset($status['complete']) && $status['complete'] && isset($status['success']) && $status['success']) {
        PuntWorkLogger::info('Retry continuation skipped - import already completed', PuntWorkLogger::CONTEXT_BATCH, [
            'method' => 'retry_fallback',
            'reason' => 'already_completed'
        ]);
        return;
    }

    // Update continuation attempt tracking
    $status['continuation_attempts'] = ($status['continuation_attempts'] ?? 0) + 1;
    $status['last_continuation_attempt'] = $attempt_time;
    $status['continuation_method'] = 'retry_fallback';
    set_import_status($status);

    PuntWorkLogger::info('Retry continuation fallback initiated', PuntWorkLogger::CONTEXT_BATCH, [
        'attempt_number' => $status['continuation_attempts'],
        'pause_time' => $status['pause_time'] ?? null,
        'time_since_pause' => $status['pause_time'] ? ($attempt_time - $status['pause_time']) : null,
        'method' => 'retry_fallback'
    ]);

    // Check for cancellation
    if (get_transient('import_cancel') === true || get_transient('import_force_cancel') === true || get_transient('import_emergency_stop') === true) {
        $cancel_type = get_transient('import_emergency_stop') === true ? 'emergency stopped' :
                      (get_transient('import_force_cancel') === true ? 'force cancelled' : 'cancelled');
        PuntWorkLogger::info('Retry continuation cancelled', PuntWorkLogger::CONTEXT_BATCH, [
            'reason' => 'import_cancel_transient_set',
            'cancel_type' => $cancel_type,
            'method' => 'retry_fallback'
        ]);
        clear_import_continuation_schedules();
        return;
    }

    // Check if still paused
    if (!isset($status['paused']) || !$status['paused']) {
        PuntWorkLogger::info('Retry continuation skipped - import not paused', PuntWorkLogger::CONTEXT_BATCH, [
            'method' => 'retry_fallback'
        ]);
        clear_import_continuation_schedules();
        return;
    }

    // Reset pause status
    $status['paused'] = false;
    unset($status['pause_reason']);
    if (!is_array($status['logs'] ?? null)) {
        $status['logs'] = [];
    }
    $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Resuming paused import (retry fallback)';
    set_import_status($status);

    // DON'T reset start time for continuation - allow full time limit for resumed import
    // set_import_start_time(microtime(true)); // Commented out - preserve original start time

    // Continue the import
    $result = import_all_jobs_from_json(true);

    if ($result['success']) {
        PuntWorkLogger::info('Retry continuation completed successfully', PuntWorkLogger::CONTEXT_BATCH, [
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0,
            'time_elapsed' => $result['time_elapsed'] ?? 0,
            'method' => 'retry_fallback'
        ]);
        clear_import_continuation_schedules();
    } else {
        PuntWorkLogger::error('Retry continuation failed', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $result['message'] ?? 'Unknown error',
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0,
            'method' => 'retry_fallback',
            'next_fallback' => 'manual_trigger'
        ]);
    }
}

/**
 * Manual trigger continuation fallback
 */
function continue_paused_import_manual() {
    $attempt_time = microtime(true);
    error_log('[PUNTWORK] Manual trigger continuation starting');

    // Check if already completed
    $status = get_import_status([]);
    if (isset($status['complete']) && $status['complete'] && isset($status['success']) && $status['success']) {
        PuntWorkLogger::info('Manual continuation skipped - import already completed', PuntWorkLogger::CONTEXT_BATCH, [
            'method' => 'manual_trigger',
            'reason' => 'already_completed'
        ]);
        return;
    }

    // Update continuation attempt tracking
    $status['continuation_attempts'] = ($status['continuation_attempts'] ?? 0) + 1;
    $status['last_continuation_attempt'] = $attempt_time;
    $status['continuation_method'] = 'manual_trigger';
    set_import_status($status);

    PuntWorkLogger::info('Manual trigger continuation initiated', PuntWorkLogger::CONTEXT_BATCH, [
        'attempt_number' => $status['continuation_attempts'],
        'pause_time' => $status['pause_time'] ?? null,
        'time_since_pause' => $status['pause_time'] ? ($attempt_time - $status['pause_time']) : null,
        'method' => 'manual_trigger'
    ]);

    // Check for cancellation
    if (get_transient('import_cancel') === true || get_transient('import_force_cancel') === true || get_transient('import_emergency_stop') === true) {
        $cancel_type = get_transient('import_emergency_stop') === true ? 'emergency stopped' :
                      (get_transient('import_force_cancel') === true ? 'force cancelled' : 'cancelled');
        PuntWorkLogger::info('Manual continuation cancelled', PuntWorkLogger::CONTEXT_BATCH, [
            'reason' => 'import_cancel_transient_set',
            'cancel_type' => $cancel_type,
            'method' => 'manual_trigger'
        ]);
        clear_import_continuation_schedules();
        return;
    }

    // Check if still paused
    if (!isset($status['paused']) || !$status['paused']) {
        PuntWorkLogger::info('Manual continuation skipped - import not paused', PuntWorkLogger::CONTEXT_BATCH, [
            'method' => 'manual_trigger'
        ]);
        clear_import_continuation_schedules();
        return;
    }

    // Reset pause status
    $status['paused'] = false;
    unset($status['pause_reason']);
    if (!is_array($status['logs'] ?? null)) {
        $status['logs'] = [];
    }
    $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Resuming paused import (manual trigger fallback)';
    set_import_status($status);

    // DON'T reset start time for continuation - allow full time limit for resumed import
    // set_import_start_time(microtime(true)); // Commented out - preserve original start time

    // Continue the import
    $result = import_all_jobs_from_json(true);

    if ($result['success']) {
        PuntWorkLogger::info('Manual trigger continuation completed successfully', PuntWorkLogger::CONTEXT_BATCH, [
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0,
            'time_elapsed' => $result['time_elapsed'] ?? 0,
            'method' => 'manual_trigger'
        ]);
        clear_import_continuation_schedules();
    } else {
        PuntWorkLogger::error('Manual trigger continuation failed - no more fallbacks available', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $result['message'] ?? 'Unknown error',
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0,
            'method' => 'manual_trigger',
            'final_attempt' => true
        ]);

        // Mark as failed since all fallbacks exhausted
        $status = get_import_status([]);
        $status['success'] = false;
        $status['complete'] = true;
        $status['error_message'] = 'All continuation attempts failed - manual intervention required';
        $status['end_time'] = microtime(true);
        $status['last_update'] = microtime(true);
        if (!is_array($status['logs'] ?? null)) {
            $status['logs'] = [];
        }
        $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] CRITICAL: All continuation mechanisms failed';
        set_import_status($status);

        clear_import_continuation_schedules();
    }
}

/**
 * Status monitoring function to check continuation progress
 */
function check_continuation_status() {
    $status = get_import_status([]);
    $current_time = microtime(true);

    // Skip if import completed
    if (isset($status['complete']) && $status['complete']) {
        return;
    }

    $pause_time = $status['pause_time'] ?? null;
    $attempts = $status['continuation_attempts'] ?? 0;
    $last_attempt = $status['last_continuation_attempt'] ?? null;

    $time_since_pause = $pause_time ? ($current_time - $pause_time) : null;
    $time_since_last_attempt = $last_attempt ? ($current_time - $last_attempt) : null;

    // Alert if import has been paused too long without continuation attempts
    if ($time_since_pause && $time_since_pause > 1800 && $attempts === 0) { // 30 minutes
        PuntWorkLogger::error('Import continuation failure detected', PuntWorkLogger::CONTEXT_BATCH, [
            'issue' => 'no_continuation_attempts',
            'time_since_pause' => round($time_since_pause / 60, 1) . ' minutes',
            'continuation_attempts' => $attempts,
            'recommendation' => 'check_cron_system'
        ]);
    }

    // Alert if last attempt was too long ago
    if ($time_since_last_attempt && $time_since_last_attempt > 3600 && !isset($status['complete'])) { // 1 hour
        PuntWorkLogger::warning('Import continuation may be stalled', PuntWorkLogger::CONTEXT_BATCH, [
            'time_since_last_attempt' => round($time_since_last_attempt / 60, 1) . ' minutes',
            'continuation_attempts' => $attempts,
            'paused' => $status['paused'] ?? false,
            'recommendation' => 'manual_intervention_may_be_needed'
        ]);
    }

    // Log status for monitoring
    PuntWorkLogger::debug('Continuation status check', PuntWorkLogger::CONTEXT_BATCH, [
        'paused' => $status['paused'] ?? false,
        'complete' => $status['complete'] ?? false,
        'continuation_attempts' => $attempts,
        'time_since_pause_minutes' => $time_since_pause ? round($time_since_pause / 60, 1) : null,
        'time_since_last_attempt_minutes' => $time_since_last_attempt ? round($time_since_last_attempt / 60, 1) : null,
        'cron_scheduled' => wp_next_scheduled('puntwork_continue_import') ? 'yes' : 'no'
    ]);
}

/**
 * Clear all import continuation schedules
 */
function clear_import_continuation_schedules() {
    wp_clear_scheduled_hook('puntwork_continue_import');
    wp_clear_scheduled_hook('puntwork_continue_import_retry');
    wp_clear_scheduled_hook('puntwork_continue_import_manual');
    wp_clear_scheduled_hook('puntwork_check_continuation_status');

    PuntWorkLogger::info('Import continuation schedules cleared', PuntWorkLogger::CONTEXT_BATCH, [
        'action' => 'cleanup_completed'
    ]);
}

/**
 * Manually resume a stuck import (admin function)
 */
function manually_resume_stuck_import() {
    error_log('[PUNTWORK] Manual import resume triggered by admin');

    // Check current status
    $status = get_import_status([]);
    if (isset($status['complete']) && $status['complete']) {
        error_log('[PUNTWORK] Import already completed - not resuming');
        return ['success' => false, 'message' => 'Import already completed'];
    }

    if (!isset($status['paused']) || !$status['paused']) {
        error_log('[PUNTWORK] Import not paused - not resuming');
        return ['success' => false, 'message' => 'Import not paused'];
    }

    // Clear any existing continuation schedules
    clear_import_continuation_schedules();

    // Reset pause status
    $status['paused'] = false;
    unset($status['pause_reason']);
    $status['continuation_attempts'] = ($status['continuation_attempts'] ?? 0) + 1;
    $status['last_continuation_attempt'] = microtime(true);
    $status['continuation_method'] = 'manual_admin_resume';
    if (!is_array($status['logs'] ?? null)) {
        $status['logs'] = [];
    }
    $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Manually resuming stuck import (admin intervention)';
    set_import_status($status);

    // DON'T reset start time - allow full time limit for resumed import
    // set_import_start_time(microtime(true)); // Commented out - preserve original start time

    PuntWorkLogger::info('Manual import resume initiated', PuntWorkLogger::CONTEXT_BATCH, [
        'attempt_number' => $status['continuation_attempts'],
        'processed' => $status['processed'] ?? 0,
        'total' => $status['total'] ?? 0,
        'method' => 'manual_admin_resume'
    ]);

    // Continue the import
    $result = import_all_jobs_from_json(true);

    if ($result['success']) {
        PuntWorkLogger::info('Manual import resume completed successfully', PuntWorkLogger::CONTEXT_BATCH, [
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0,
            'time_elapsed' => $result['time_elapsed'] ?? 0,
            'method' => 'manual_admin_resume'
        ]);
        return ['success' => true, 'message' => 'Import resumed and completed successfully', 'result' => $result];
    } else {
        PuntWorkLogger::error('Manual import resume failed', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $result['message'] ?? 'Unknown error',
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0,
            'method' => 'manual_admin_resume'
        ]);
        return ['success' => false, 'message' => 'Import resume failed: ' . ($result['message'] ?? 'Unknown error'), 'result' => $result];
    }
}