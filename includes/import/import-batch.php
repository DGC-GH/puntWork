<?php

/**
 * Batch import processing with timeout protection
 *
 * @package    Puntwork
 * @subpackage Import
 * @since      1.0.0
 */

// Prevent direct access
if (! defined('ABSPATH') ) {
    exit;
}

error_log(
    '[PUNTWORK] import-batch.php loaded - is_admin: ' . ( is_admin() ? 'true' : 'false' ) .
    ', DOING_AJAX: ' . ( defined('DOING_AJAX') && DOING_AJAX ? 'true' : 'false' )
);

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

/**
 * Check if the current import process has exceeded time limits
 * Similar to WooCommerce's time_exceeded() method
 *
 * @return bool True if time limit exceeded
 */
function import_time_exceeded(): bool
{
    $start_time = get_option('job_import_start_time', microtime(true));
    $time_limit = apply_filters('puntwork_import_time_limit', 240); // 240 seconds (4 minutes) default
    // (increased from 120 for better performance)
    $current_time = microtime(true);
    $elapsed_time = $current_time - $start_time;

    // Debug logging
    error_log(
        sprintf(
            '[PUNTWORK] [TIME-DEBUG] import_time_exceeded check: start_time=%.6f, current_time=%.6f, ' .
            'elapsed=%.2fs, limit=%ds, exceeded=%s',
            $start_time,
            $current_time,
            $elapsed_time,
            $time_limit,
            ( $elapsed_time >= $time_limit ? 'YES' : 'NO' )
        )
    );

    if ($elapsed_time >= $time_limit ) {
        error_log(
            sprintf(
                '[PUNTWORK] [TIME-LIMIT] Import time limit exceeded: %.2fs elapsed, limit was %ds',
                $elapsed_time,
                $time_limit
            )
        );
        return true;
    }

    // Log remaining time for debugging
    $remaining_time = $time_limit - $elapsed_time;
    if ($remaining_time <= 30 ) { // Log when less than 30 seconds remaining
        error_log(
            sprintf(
                '[PUNTWORK] [TIME-WARNING] Import time limit approaching: %.1fs remaining (elapsed: %.2fs, limit: %ds)',
                $remaining_time,
                $elapsed_time,
                $time_limit
            )
        );
    }

    return apply_filters('puntwork_import_time_exceeded', false);
}

/**
 * Check if the current import process has exceeded memory limits
 * Similar to WooCommerce's memory_exceeded() method
 *
 * @return bool True if memory limit exceeded
 */
function import_memory_exceeded(): bool
{
    $memory_limit   = get_memory_limit_bytes() * 0.9; // 90% of max memory
    $current_memory = memory_get_usage(true);

    if ($current_memory >= $memory_limit ) {
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
function should_continue_batch_processing(): bool
{
    if (import_time_exceeded() ) {
        error_log('[PUNTWORK] Import time limit exceeded - pausing batch processing');
        return false;
    }

    if (import_memory_exceeded() ) {
        error_log('[PUNTWORK] Import memory limit exceeded - pausing batch processing');
        return false;
    }

    return true;
}

if (! function_exists('import_jobs_from_json') ) {
    error_log('[PUNTWORK] Defining import_jobs_from_json function');
    /**
     * Import jobs from JSONL file in batches.
     *
     * @param  bool $is_batch    Whether this is a batch import.
     * @param  int  $batch_start Starting index for batch.
     * @return array Import result data.
     */
    function import_jobs_from_json( bool $is_batch = false, int $batch_start = 0 ): array
    {
        try {
            // Check for concurrent import lock
            $import_lock_key = 'puntwork_import_lock';
            if (get_transient($import_lock_key) ) {
                error_log('[PUNTWORK] Import already running - skipping concurrent import');
                return array(
                 'success' => false,
                 'message' => 'Import already running',
                 'logs'    => array( 'Import already running - concurrent imports not allowed' ),
                );
            }

            // Set import lock
            set_transient($import_lock_key, true, 600); // 10 minutes timeout
            error_log('[PUNTWORK] Import lock set');

            error_log('=== PUNTWORK IMPORT DEBUG: import_jobs_from_json STARTED ===');
            error_log(
                '[PUNTWORK] import_jobs_from_json called with is_batch=' . ( $is_batch ? 'true' : 'false' ) .
                ', batch_start=' . $batch_start
            );
            error_log('[PUNTWORK] import_jobs_from_json: Calling prepare_import_setup...');
            $setup = prepare_import_setup($batch_start);
            error_log(
                '[PUNTWORK] import_jobs_from_json: prepare_import_setup returned: ' . json_encode(
                    array(
                    'success'          => $setup['success'] ?? 'not set',
                    'total'            => $setup['total'] ?? 'not set',
                    'start_index'      => $setup['start_index'] ?? 'not set',
                    'complete'         => $setup['complete'] ?? 'not set',
                    'json_path_exists' => isset($setup['json_path']) ? file_exists($setup['json_path']) : 'no json_path',
                    )
                )
            );
            error_log('[PUNTWORK] import_jobs_from_json: prepare_import_setup completed');

            if (is_wp_error($setup) ) {
                   error_log('[PUNTWORK] prepare_import_setup returned WP_Error: ' . $setup->get_error_message());
                   return array(
                    'success' => false,
                    'message' => $setup->get_error_message(),
                    'logs'    => array( 'Setup failed: ' . $setup->get_error_message() ),
                   );
            }

            if (isset($setup['success']) ) {
                error_log(
                    '[PUNTWORK] [DEBUG] import_jobs_from_json: prepare_import_setup returned early ' .
                    'success/complete'
                );
                error_log('[PUNTWORK] [DEBUG] import_jobs_from_json: Early return details: ' . json_encode($setup));
                return $setup; // Early return for empty or completed cases
            }

            error_log('[PUNTWORK] import_jobs_from_json: Setup successful, calling process_batch_items_logic...');
            $result = process_batch_items_logic($setup);
            error_log(
                '[PUNTWORK] import_jobs_from_json: process_batch_items_logic completed, success=' .
                ( isset($result['success']) ? $result['success'] : 'not set' )
            );

            error_log('[PUNTWORK] import_jobs_from_json: Calling finalize_batch_import...');
            $final_result = finalize_batch_import($result);
            error_log('[PUNTWORK] import_jobs_from_json: finalize_batch_import completed');

            error_log('[PUNTWORK] import_jobs_from_json: Import process completed successfully');
            error_log('=== PUNTWORK IMPORT DEBUG: import_jobs_from_json COMPLETED ===');
            return $final_result;
        } catch ( \Exception $e ) {
            error_log('[PUNTWORK] import_jobs_from_json: Exception caught: ' . $e->getMessage());
            error_log('[PUNTWORK] import_jobs_from_json: Exception file: ' . $e->getFile() . ':' . $e->getLine());
            error_log('[PUNTWORK] import_jobs_from_json: Stack trace: ' . $e->getTraceAsString());
            return array(
            'success' => false,
            'message' => 'Import failed: ' . $e->getMessage(),
            'logs'    => array( 'Exception: ' . $e->getMessage() ),
            );
        } catch ( \Throwable $e ) {
            error_log('[PUNTWORK] import_jobs_from_json: Fatal error caught: ' . $e->getMessage());
            error_log('[PUNTWORK] import_jobs_from_json: Fatal error file: ' . $e->getFile() . ':' . $e->getLine());
            error_log('[PUNTWORK] import_jobs_from_json: Stack trace: ' . $e->getTraceAsString());
            return array(
            'success' => false,
            'message' => 'Import failed with fatal error: ' . $e->getMessage(),
            'logs'    => array( 'Fatal error: ' . $e->getMessage() ),
            );
        } finally {
            // Release import lock
            delete_transient('puntwork_import_lock');
            error_log('[PUNTWORK] Import lock released');
        }
    }
}

if (! function_exists('import_all_jobs_from_json') ) {
    /**
     * Import all jobs from JSONL file (processes all batches sequentially).
     * Used for scheduled imports that need to process the entire dataset.
     *
     * @param  bool $preserve_status Whether to preserve existing import status for UI polling
     * @return array Import result data.
     */
    function import_all_jobs_from_json( bool $preserve_status = false ): array
    {
        $start_time               = microtime(true);
        $total_processed          = 0;
        $total_published          = 0;
        $total_updated            = 0;
        $total_skipped            = 0;
        $total_duplicates_drafted = 0;
        $all_logs                 = array();
        $batch_count              = 0;
        $total_items              = 0;

        // Check for concurrent import lock
        $import_lock_key = 'puntwork_import_lock';
        if (get_transient($import_lock_key) ) {
            error_log('[PUNTWORK] Import already running - skipping concurrent import');
            return array(
            'success' => false,
            'message' => 'Import already running',
            'logs'    => array( 'Import already running - concurrent imports not allowed' ),
            );
        }

        // Set import lock
        set_transient($import_lock_key, true, 600); // 10 minutes timeout
        error_log('[PUNTWORK] Import lock set for import_all_jobs_from_json');

        error_log(
            '[PUNTWORK] import_all_jobs_from_json started with preserve_status=' .
            ( $preserve_status ? 'true' : 'false' )
        );

        try {
            // Only reset status if not preserving existing status
            if (! $preserve_status ) {
                // Reset import progress for fresh start
                update_option('job_import_progress', 0, false);
                update_option('job_import_processed_guids', array(), false);
                delete_option('job_import_status');
            } elseif ($preserve_status && ! get_option('job_import_status') ) {
                // Fresh import with preserve_status = true, but no status exists, so reset progress options
                update_option('job_import_progress', 0, false);
                update_option('job_import_processed_guids', array(), false);
                delete_option('job_import_last_batch_time');
                delete_option('job_import_last_batch_processed');
                delete_option('job_import_batch_size');
                delete_option('job_import_consecutive_small_batches');
            }

            // Initialize import status for UI tracking (only if not preserving)
            if (! $preserve_status ) {
                $initial_status = array(
                'total'              => 0, // Will be updated when we know the total
                'processed'          => 0,
                'published'          => 0,
                'updated'            => 0,
                'skipped'            => 0,
                'duplicates_drafted' => 0,
                'time_elapsed'       => 0,
                'complete'           => false,
                'success'            => false,
                'error_message'      => '',
                'batch_size'         => get_option('job_import_batch_size') ?: 1,
                'inferred_languages' => 0,
                'inferred_benefits'  => 0,
                'schema_generated'   => 0,
                'start_time'         => $start_time,
                'end_time'           => null,
                'last_update'        => time(),
                'logs'               => array( 'Scheduled import started - preparing feeds...' ),
                );
                update_option('job_import_status', $initial_status, false);
            } else {
                // For preserved status, we'll update the existing status as we go
                $initial_status = get_option('job_import_status', array());
            }

            // Store import start time for timeout checking
            update_option('job_import_start_time', $start_time, false);

            while ( true ) {
                error_log('[PUNTWORK] [LOOP-DEBUG] Main import loop iteration starting - batch_count=' . $batch_count);

                // Check if we should continue processing (time/memory limits)
                error_log('[PUNTWORK] [LOOP-DEBUG] Checking if should continue batch processing...');
                if (! should_continue_batch_processing() ) {
                    error_log(
                        '[PUNTWORK] [LOOP-DEBUG] should_continue_batch_processing returned false - ' .
                        'pausing import'
                    );
                    // Pause processing and schedule continuation
                    $current_status                 = get_option('job_import_status', array());
                    $current_status['paused']       = true;
                    $current_status['pause_reason'] = 'time_limit_exceeded';
                    $current_status['last_update']  = time();
                    $current_status['logs'][]       = '[' . date('d-M-Y H:i:s') . ' UTC] Import paused due to ' .
                    'time/memory limits - will continue automatically';
                    update_option('job_import_status', $current_status, false);

                    // Schedule continuation via WordPress cron (runs in background)
                    if (! wp_next_scheduled('puntwork_continue_import') ) {
                              wp_schedule_single_event(time() + 30, 'puntwork_continue_import'); // Continue in 30 seconds
                              error_log('[PUNTWORK] Scheduled import continuation in 30 seconds');
                    }

                    return array(
                    'success'            => true,
                    'processed'          => $total_processed,
                    'total'              => $total_items,
                    'published'          => $total_published,
                    'updated'            => $total_updated,
                    'skipped'            => $total_skipped,
                    'duplicates_drafted' => $total_duplicates_drafted,
                    'time_elapsed'       => microtime(true) - $start_time,
                    'complete'           => false,
                    'paused'             => true,
                    'message'            => 'Import paused due to time limits - will continue automatically in background',
                    );
                }
                error_log('[PUNTWORK] [LOOP-DEBUG] should_continue_batch_processing returned true - continuing');

                $batch_start = (int) get_option('job_import_progress', 0);
                error_log(
                    '[PUNTWORK] [LOOP-DEBUG] import_all_jobs_from_json: batch_start=' . $batch_start .
                    ', total_items=' . $total_items
                );

                // Prepare setup for this batch
                error_log('[PUNTWORK] [LOOP-DEBUG] Calling prepare_import_setup with batch_start=' . $batch_start);
                $setup = prepare_import_setup($batch_start);
                if (is_wp_error($setup) ) {
                    $error_msg = 'Setup failed: ' . $setup->get_error_message();
                    error_log('[PUNTWORK] [LOOP-DEBUG] prepare_import_setup returned WP_Error: ' . $error_msg);
                    return array(
                     'success' => false,
                     'message' => $error_msg,
                     'logs'    => array( $error_msg ),
                    );
                }
                error_log('[PUNTWORK] [LOOP-DEBUG] prepare_import_setup completed successfully');

                // Capture total items from first setup
                if ($batch_count === 0 ) {
                    $total_items = $setup['total'] ?? 0;
                    // Update status with total items
                    $initial_status['total'] = $total_items;
                    update_option('job_import_status', $initial_status, false);
                    // Flush cache to ensure AJAX can see the updated status
                    if (function_exists('wp_cache_flush') ) {
                        wp_cache_flush();
                    }
                    error_log('[PUNTWORK] [LOOP-DEBUG] Set total items for import: ' . $total_items . ' (first batch)');
                }

                error_log(
                    '[PUNTWORK] [LOOP-DEBUG] import_all_jobs_from_json: batch_count=' . $batch_count .
                    ', batch_start=' . $batch_start . ', total_items=' . $total_items .
                    ', setup total=' . ( $setup['total'] ?? 'not set' ) .
                    ', setup start_index=' . ( $setup['start_index'] ?? 'not set' ) .
                    ', setup complete=' . ( isset($setup['complete']) ? $setup['complete'] : 'not set' )
                );

                // Check if import is complete
                if (isset($setup['success']) && isset($setup['complete']) && $setup['complete'] ) {
                    error_log('[PUNTWORK] [LOOP-DEBUG] Import complete - setup returned complete=true');
                    break;
                }

                if ($batch_start >= $total_items ) {
                    error_log(
                        '[PUNTWORK] [LOOP-DEBUG] Import complete - batch_start (' . $batch_start .
                        ') >= total_items (' . $total_items . ')'
                    );
                    break;
                }

                ++$batch_count;
                error_log(
                    '[PUNTWORK] [LOOP-DEBUG] Processing batch ' . $batch_count .
                    ' starting at index ' . $batch_start
                );

                // Process this batch
                error_log('[PUNTWORK] [LOOP-DEBUG] Calling process_batch_items_logic for batch ' . $batch_count);
                $result = process_batch_items_logic($setup);
                error_log(
                    '[PUNTWORK] [LOOP-DEBUG] process_batch_items_logic completed for batch ' .
                    $batch_count . ', success=' . ( $result['success'] ? 'true' : 'false' )
                );

                if (! $result['success'] ) {
                    $error_msg = 'Batch ' . $batch_count . ' failed: ' . ( $result['message'] ?? 'Unknown error' );
                    error_log('[PUNTWORK] [LOOP-DEBUG] Batch ' . $batch_count . ' failed: ' . $error_msg);
                    return array(
                     'success' => false,
                     'message' => $error_msg,
                     'logs'    => $result['logs'] ?? array(),
                    );
                }

                // Accumulate results
                error_log('[PUNTWORK] [LOOP-DEBUG] Accumulating results for batch ' . $batch_count);
                $total_processed           = max($total_processed, $result['processed']);
                $total_published          += $result['published'] ?? 0;
                $total_updated            += $result['updated'] ?? 0;
                $total_skipped            += $result['skipped'] ?? 0;
                $total_duplicates_drafted += $result['duplicates_drafted'] ?? 0;

                if (isset($result['logs']) && is_array($result['logs']) ) {
                    $all_logs = array_merge($all_logs, $result['logs']);
                }

                // Update import status for UI tracking
                error_log('[PUNTWORK] [LOOP-DEBUG] Updating import status after batch ' . $batch_count);
                $current_status                       = get_option('job_import_status', $initial_status);
                $current_status['processed']          = $total_processed;
                $current_status['published']          = $total_published;
                $current_status['updated']            = $total_updated;
                $current_status['skipped']            = $total_skipped;
                $current_status['duplicates_drafted'] = $total_duplicates_drafted;
                $current_status['time_elapsed']       = microtime(true) - $start_time;
                $current_status['last_update']        = time();
                $current_status['logs']               = array_slice($all_logs, -50); // Keep last 50 log entries for UI
                update_option('job_import_status', $current_status, false);
                // Flush cache to ensure AJAX can see the updated status
                if (function_exists('wp_cache_flush') ) {
                    wp_cache_flush();
                }
                error_log(
                    '[PUNTWORK] [LOOP-DEBUG] Updated import status after batch ' . $batch_count .
                    ': processed=' . $total_processed . '/' . $total_items .
                    ', complete=' . ( $total_processed >= $total_items ? 'true' : 'false' )
                );

                // Check if this batch completed the import
                if (isset($result['complete']) && $result['complete'] ) {
                    error_log(
                        '[PUNTWORK] [LOOP-DEBUG] Import completed in batch ' . $batch_count .
                        ' (result complete=true)'
                    );
                       break;
                }

                // Safety check to prevent infinite loops
                if ($batch_count > 1000 ) {
                    $error_msg = 'Import aborted - too many batches processed (possible infinite loop)';
                    error_log(
                        '[PUNTWORK] [LOOP-DEBUG] Safety check triggered: batch_count=' . $batch_count .
                        ' > 1000'
                    );
                    return array(
                    'success' => false,
                    'message' => $error_msg,
                    'logs'    => $all_logs,
                    );
                }

                // Small delay between batches to prevent overwhelming the server
                error_log('[PUNTWORK] [LOOP-DEBUG] Sleeping for 0.1 seconds before next batch');
                usleep(100000); // 0.1 seconds
                error_log('[PUNTWORK] [LOOP-DEBUG] Main import loop iteration completed - batch_count=' . $batch_count);
            }

            $end_time       = microtime(true);
            $total_duration = $end_time - $start_time;

            $final_result = array(
             'success'            => true,
             'processed'          => $total_processed,
             'total'              => $total_items,
             'published'          => $total_published,
             'updated'            => $total_updated,
             'skipped'            => $total_skipped,
             'duplicates_drafted' => $total_duplicates_drafted,
             'time_elapsed'       => $total_duration,
             'complete'           => true,
             'logs'               => $all_logs,
             'batches_processed'  => $batch_count,
            'message'            => sprintf(
                'Full import completed successfully - Processed: %d/%d items ' .
                '(Published: %d, Updated: %d, Skipped: %d) in %.1f seconds',
                $total_processed,
                $total_items,
                $total_published,
                $total_updated,
                $total_skipped,
                $total_duration
            ),
            );

            error_log(
                sprintf(
                    '[PUNTWORK] Full import completed - Duration: %.2fs, Batches: %d, Total: %d, ' .
                    'Processed: %d, Published: %d, Updated: %d, Skipped: %d',
                    $total_duration,
                    $batch_count,
                    $total_items,
                    $total_processed,
                    $total_published,
                    $total_updated,
                    $total_skipped
                )
            );

            // Ensure final status is updated for UI
            $current_status = get_option('job_import_status', array());
            $final_status   = array_merge(
                $current_status,
                array(
                'total'              => $total_items,
                'processed'          => $total_processed,
                'published'          => $total_published,
                'updated'            => $total_updated,
                'skipped'            => $total_skipped,
                'duplicates_drafted' => $total_duplicates_drafted,
                'time_elapsed'       => $total_duration,
                'complete'           => true,
                'success'            => true,
                'error_message'      => '',
                'end_time'           => $end_time,
                'last_update'        => time(),
                'logs'               => array_slice($all_logs, -50),
                )
            );
            // When complete, ensure processed equals total
            if ($final_status['complete'] && $final_status['processed'] < $final_status['total'] ) {
                   $final_status['processed'] = $final_status['total'];
            }
            update_option('job_import_status', $final_status, false);
            error_log(
                '[PUNTWORK] Final import status updated: ' . json_encode(
                    array(
                    'total'     => $total_items,
                    'processed' => $total_processed,
                    'complete'  => true,
                    'success'   => true,
                    )
                )
            );

            // Ensure cache is cleared so AJAX can see the updated status
            if (function_exists('wp_cache_flush') ) {
                   wp_cache_flush();
            }

            return finalize_batch_import($final_result);
        } catch ( \Exception $e ) {
            error_log('[PUNTWORK] import_all_jobs_from_json exception: ' . $e->getMessage());
            // Release lock on error
            delete_transient('puntwork_import_lock');
            error_log('[PUNTWORK] Import lock released due to exception');
            return array(
            'success' => false,
            'message' => 'Import failed: ' . $e->getMessage(),
            'logs'    => $all_logs,
            );
        } finally {
            // Release import lock
            delete_transient('puntwork_import_lock');
            error_log('[PUNTWORK] Import lock released at end of import_all_jobs_from_json');
        }
    }
}

/**
 * Continue a paused import process
 * Called by WordPress cron when import needs to resume after timeout
 *
 * @return void
 */
function continue_paused_import(): void
{
    error_log('[PUNTWORK] Continuing paused import process');

    // Check if import is actually paused
    $status = get_option('job_import_status', array());
    if (! isset($status['paused']) || ! $status['paused'] ) {
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

    if ($result['success'] ) {
        error_log('[PUNTWORK] Paused import continuation completed successfully');
    } else {
        error_log('[PUNTWORK] Paused import continuation failed: ' . ( $result['message'] ?? 'Unknown error' ));
    }
}

// Register the continuation hook
add_action('puntwork_continue_import', 'continue_paused_import');
