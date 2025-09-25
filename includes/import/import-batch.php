<?php
/**
 * Batch import processing
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
                'batch_size' => get_option('job_import_batch_size') ?: 5,
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
            // Update existing status to indicate import is starting
            $existing_status = get_option('job_import_status', []);
            $existing_status['logs'][] = 'Scheduled import started - processing batches...';
            $existing_status['start_time'] = $start_time;
            update_option('job_import_status', $existing_status, false);
        }

        while (true) {
            $batch_start = (int) get_option('job_import_progress', 0);

            // Prepare setup for this batch
            $setup = prepare_import_setup($batch_start);
            if (is_wp_error($setup)) {
                $error_msg = 'Setup failed: ' . $setup->get_error_message();
                error_log('[PUNTWORK] ' . $error_msg);
                return ['success' => false, 'message' => $error_msg, 'logs' => [$error_msg]];
            }

            // Capture total items from first setup
            if ($batch_count === 0) {
                $total_items = $setup['total'] ?? 0;
                // Update status with total items
                $initial_status['total'] = $total_items;
                update_option('job_import_status', $initial_status, false);
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
                error_log('[PUNTWORK] ' . $error_msg);
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

            // Check if this batch completed the import
            if (isset($result['complete']) && $result['complete']) {
                error_log('[PUNTWORK] Import completed in batch ' . $batch_count);
                break;
            }

            // Safety check to prevent infinite loops
            if ($batch_count > 1000) {
                $error_msg = 'Import aborted - too many batches processed (possible infinite loop)';
                error_log('[PUNTWORK] ' . $error_msg);
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
            'message' => sprintf(
                'Full import completed successfully - Processed: %d/%d items (Published: %d, Updated: %d, Skipped: %d) in %.1f seconds',
                $total_processed,
                $total_items,
                $total_published,
                $total_updated,
                $total_skipped,
                $total_duration
            )
        ];

        error_log(sprintf(
            '[PUNTWORK] Full import completed - Duration: %.2fs, Batches: %d, Total: %d, Processed: %d, Published: %d, Updated: %d, Skipped: %d',
            $total_duration,
            $batch_count,
            $total_items,
            $total_processed,
            $total_published,
            $total_updated,
            $total_skipped
        ));

        // Ensure final status is updated for UI
        $final_status = [
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
            'batch_size' => get_option('job_import_batch_size') ?: 5,
            'inferred_languages' => 0,
            'inferred_benefits' => 0,
            'schema_generated' => 0,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'last_update' => time(),
            'logs' => array_slice($all_logs, -50),
        ];
        update_option('job_import_status', $final_status, false);
        
        // Ensure cache is cleared so AJAX can see the updated status
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        return finalize_batch_import($final_result);
    }
}
