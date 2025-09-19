<?php
/**
 * Batch processing utilities
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
 * Batch processing logic
 * Handles the core batch processing operations for job imports
 */

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

        // Store batch timing data for status retrieval
        update_option('job_import_last_batch_time', $time_elapsed, false);
        update_option('job_import_last_batch_processed', $result['processed_count'], false);

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

/**
 * Process batch data including duplicates and item processing.
 *
 * @param array $batch_guids Array of GUIDs in batch.
 * @param array $batch_items Array of batch items.
 * @param array &$logs Reference to logs array.
 * @param int &$created Reference to created count.
 * @param int &$updated Reference to updated count.
 * @param int &$skipped Reference to skipped count.
 * @param int &$duplicates_drafted Reference to duplicates drafted count.
 * @return array Processing result.
 */
function process_batch_data($batch_guids, $batch_items, &$logs, &$created, &$updated, &$skipped, &$duplicates_drafted) {
    global $wpdb;

    // Bulk existing post_ids
    $guid_placeholders = implode(',', array_fill(0, count($batch_guids), '%s'));
    $existing_meta = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_value AS guid FROM $wpdb->postmeta WHERE meta_key = 'guid' AND meta_value IN ($guid_placeholders)",
        $batch_guids
    ));
    $existing_by_guid = [];
    foreach ($existing_meta as $row) {
        $existing_by_guid[$row->guid][] = $row->post_id;
    }

    $post_ids_by_guid = [];
    handle_duplicates($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);

    // Bulk fetch for all existing in batch
    $post_ids = array_values($post_ids_by_guid);
    $max_chunk_size = 50;
    $post_id_chunks = array_chunk($post_ids, $max_chunk_size);
    $last_updates = [];
    $all_hashes_by_post = [];

    if (!empty($post_ids)) {
        foreach ($post_id_chunks as $chunk) {
            if (empty($chunk)) continue;
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $chunk_last = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_last_import_update' AND post_id IN ($placeholders)",
                $chunk
            ), OBJECT_K);
            $last_updates += (array)$chunk_last;
        }

        foreach ($post_id_chunks as $chunk) {
            if (empty($chunk)) continue;
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $chunk_hashes = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_import_hash' AND post_id IN ($placeholders)",
                $chunk
            ), OBJECT_K);
            foreach ($chunk_hashes as $id => $obj) {
                $all_hashes_by_post[$id] = $obj->meta_value;
            }
        }
    }

    $processed_count = 0;
    $acf_fields = get_acf_fields();
    $zero_empty_fields = get_zero_empty_fields();

    process_batch_items($batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, $logs, $updated, $created, $skipped, $processed_count);

    return ['processed_count' => $processed_count];
}

/**
 * Load a batch of items from JSONL file.
 *
 * @param string $json_path Path to JSONL file.
 * @param int $start_index Starting index.
 * @param int $batch_size Batch size.
 * @return array Array of JSON items.
 */
function load_json_batch($json_path, $start_index, $batch_size) {
    $items = [];
    $count = 0;
    $current_index = 0;

    if (($handle = fopen($json_path, "r")) !== false) {
        while (($line = fgets($handle)) !== false) {
            if ($current_index >= $start_index && $count < $batch_size) {
                $line = trim($line);
                if (!empty($line)) {
                    $item = json_decode($line, true);
                    if ($item !== null) {
                        $items[] = $item;
                        $count++;
                    }
                }
            } elseif ($current_index >= $start_index + $batch_size) {
                break;
            }
            $current_index++;
        }
        fclose($handle);
    }

    return $items;
}