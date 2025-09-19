<?php
/**
 * Core batch processing logic
 * Handles the main batch processing operations and workflow
 *
 * @package    Puntwork
 * @subpackage Batch
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Process batch items and handle imports.
 *
 * @param array $setup Setup data from prepare_import_setup.
 * @return array Processing results.
 */
function process_batch_items_logic($setup) {
    extract($setup);

    $memory_limit_bytes = get_memory_limit_bytes();
    $threshold = 0.6 * $memory_limit_bytes;
    $batch_size = get_option('job_import_batch_size') ?: 20;
    $old_batch_size = $batch_size;
    $prev_time_per_item = get_option('job_import_time_per_job', 0);
    $avg_time_per_item = get_option('job_import_avg_time_per_job', $prev_time_per_item);
    $last_peak_memory = get_option('job_import_last_peak_memory', $memory_limit_bytes);
    $last_memory_ratio = $last_peak_memory / $memory_limit_bytes;

    $batch_size = adjust_batch_size($batch_size, $memory_limit_bytes, $last_memory_ratio, $prev_time_per_item, $avg_time_per_item);

    // Only update and log if changed
    if ($batch_size != $old_batch_size) {
        update_option('job_import_batch_size', $batch_size, false);
        $reason = ($last_memory_ratio > 0.85 ? 'high previous memory' : ($last_memory_ratio < 0.5 ? 'low previous memory and low avg time' : ($time_ratio > 1.2 ? 'high time ratio' : 'low time ratio')));
        $logs = [];
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Batch size adjusted to ' . $batch_size . ' due to ' . $reason;
    } else {
        $logs = [];
    }

    // Re-align start_index with new batch_size to avoid skips
    if ($start_index % $batch_size !== 0) {
        $start_index = floor($start_index / $batch_size) * $batch_size;
    }

    $end_index = min($start_index + $batch_size, $total);
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $duplicates_drafted = 0;
    $drafted_old = 0;
    $inferred_languages = 0;
    $inferred_benefits = 0;
    $schema_generated = 0;

    try {
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Starting batch from $start_index to $end_index (size $batch_size)";

        // Load batch from JSONL
        $batch_json_items = load_json_batch($json_path, $start_index, $batch_size);
        $batch_items = [];
        $batch_guids = [];
        $loaded_count = count($batch_json_items);

        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Loaded $loaded_count items from JSONL (batch size: $batch_size)";

        for ($i = 0; $i < count($batch_json_items); $i++) {
            $current_index = $start_index + $i;

            if (get_transient('import_cancel') === true) {
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Import cancelled at #' . ($current_index + 1);
                update_option('job_import_progress', $current_index, false);
                return ['success' => false, 'message' => 'Import cancelled by user', 'logs' => $logs];
            }

            $item = $batch_json_items[$i];
            $guid = $item['guid'] ?? '';

            if (empty($guid)) {
                $skipped++;
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Skipped #' . ($current_index + 1) . ': Empty GUID';
                continue;
            }

            $processed_guids[] = $guid;
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Processing #' . ($current_index + 1) . ' GUID: ' . $guid;
            $batch_items[$guid] = ['item' => $item, 'index' => $current_index];
            $batch_guids[] = $guid;

            if (!empty($item['job_languages'])) $inferred_languages++;
            $benefit_count = (!empty($item['job_car']) ? 1 : 0) + (!empty($item['job_remote']) ? 1 : 0) + (!empty($item['job_meal_vouchers']) ? 1 : 0) + (!empty($item['job_flex_hours']) ? 1 : 0);
            $inferred_benefits += $benefit_count;
            if (!empty($item['job_posting']) || !empty($item['job_ecommerce'])) $schema_generated++;

            if (memory_get_usage(true) > $threshold) {
                $batch_size = max(1, (int)($batch_size * 0.8));
                update_option('job_import_batch_size', $batch_size, false);
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Memory high, reduced batch to ' . $batch_size;
                $end_index = min($start_index + $batch_size, $total);
            }

            if ($i % 5 === 0) {
                ob_flush();
                flush();
            }
            unset($batch_json_items[$i]);
        }
        unset($batch_json_items);

        $valid_items_count = count($batch_guids);
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Prepared $valid_items_count valid items for processing (skipped " . ($loaded_count - $valid_items_count) . " items)";

        if (empty($batch_guids)) {
            update_option('job_import_progress', $end_index, false);
            update_option('job_import_processed_guids', $processed_guids, false);
            $time_elapsed = microtime(true) - $start_time;
            return [
                'success' => true,
                'processed' => $end_index,
                'total' => $total,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'duplicates_drafted' => $duplicates_drafted,
                'drafted_old' => $drafted_old,
                'time_elapsed' => $time_elapsed,
                'complete' => ($end_index >= $total),
                'logs' => $logs,
                'batch_size' => $batch_size,
                'inferred_languages' => $inferred_languages,
                'inferred_benefits' => $inferred_benefits,
                'schema_generated' => $schema_generated,
                'batch_time' => $time_elapsed,
                'batch_processed' => 0,
                'message' => '' // No error message for success
            ];
        }

        // Process batch items
        $result = process_batch_data($batch_guids, $batch_items, $logs, $created, $updated, $skipped, $duplicates_drafted);

        unset($batch_items, $batch_guids);

        update_option('job_import_progress', $end_index, false);
        update_option('job_import_processed_guids', $processed_guids, false);
        $time_elapsed = microtime(true) - $start_time;
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Batch complete: Processed {$result['processed_count']} items (created: $created, updated: $updated, skipped: $skipped, duplicates: $duplicates_drafted)";

        // Update performance metrics
        update_batch_metrics($time_elapsed, $result['processed_count'], $batch_size);

        return [
            'success' => true,
            'processed' => $end_index,
            'total' => $total,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'duplicates_drafted' => $duplicates_drafted,
            'drafted_old' => $drafted_old,
            'time_elapsed' => $time_elapsed,
            'complete' => ($end_index >= $total),
            'logs' => $logs,
            'batch_size' => $batch_size,
            'inferred_languages' => $inferred_languages,
            'inferred_benefits' => $inferred_benefits,
            'schema_generated' => $schema_generated,
            'batch_time' => $time_elapsed,  // Time for this specific batch
            'batch_processed' => $result['processed_count'],  // Items processed in this batch
            'start_time' => $start_time,
            'message' => '' // No error message for success
        ];
    } catch (\Exception $e) {
        $error_msg = 'Batch import error: ' . $e->getMessage();
        error_log($error_msg);
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $error_msg;
        return ['success' => false, 'message' => 'Batch failed: ' . $e->getMessage(), 'logs' => $logs];
    }
}