<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if (!function_exists('import_jobs_from_json')) {
    function import_jobs_from_json($is_batch = false, $batch_start = 0) {
        do_action( 'qm/cease' ); // Disable Query Monitor data collection to reduce memory usage
        ini_set('memory_limit', '512M');
        set_time_limit(1800);
        ignore_user_abort(true);
        global $wpdb;
        $acf_fields = get_acf_fields();
        $zero_empty_fields = get_zero_empty_fields();

        define('WP_IMPORTING', true);
        wp_suspend_cache_invalidation(true);
        remove_action('post_updated', 'wp_save_post_revision');
        $start_time = microtime(true);
        $json_path = ABSPATH . 'feeds/combined-jobs.jsonl';
        if (!file_exists($json_path)) {
            error_log('JSONL file not found: ' . $json_path);
            return ['success' => false, 'message' => 'JSONL file not found', 'logs' => ['JSONL file not found']];
        }
        $total = get_json_item_count($json_path);
        if ($total == 0) {
            return ['success' => true, 'processed' => 0, 'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'duplicates_drafted' => 0, 'drafted_old' => 0, 'time_elapsed' => 0, 'complete' => true, 'logs' => [], 'batch_size' => 0, 'inferred_languages' => 0, 'inferred_benefits' => 0, 'schema_generated' => 0, 'batch_time' => 0, 'batch_processed' => 0];
        }
        if (false === get_option('job_existing_guids')) {
            $all_jobs = $wpdb->get_results("SELECT p.ID, pm.meta_value AS guid FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID = pm.post_id WHERE p.post_type = 'job' AND pm.meta_key = 'guid'");
            update_option('job_existing_guids', $all_jobs, false);
        }
        $processed_guids = get_option('job_import_processed_guids') ?: [];
        $start_index = max((int) get_option('job_import_progress'), $batch_start);
        if ($start_index >= $total) {
            return ['success' => true, 'processed' => $total, 'total' => $total, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'duplicates_drafted' => 0, 'drafted_old' => 0, 'time_elapsed' => 0, 'complete' => true, 'logs' => ['Start index beyond total items'], 'batch_size' => 0, 'inferred_languages' => 0, 'inferred_benefits' => 0, 'schema_generated' => 0, 'batch_time' => 0, 'batch_processed' => 0];
        }
        $memory_limit_bytes = get_memory_limit_bytes();
        $threshold = 0.6 * $memory_limit_bytes;
        $batch_size = get_option('job_import_batch_size') ?: 20; // Increased default from 10 to 20 for faster initial processing
        update_option('job_import_batch_size', $batch_size, false);
        if ($start_index % $batch_size !== 0) {
            $start_index = floor($start_index / $batch_size) * $batch_size;
        }
        $end_index = min($start_index + $batch_size, $total);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $logs = [];
        $duplicates_drafted = 0;
        $drafted_old = 0;
        $inferred_languages = 0;
        $inferred_benefits = 0;
        $schema_generated = 0;

        try {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Starting batch from $start_index to $end_index (size $batch_size)";
            //error_log("Starting batch from $start_index to $end_index (size $batch_size)");

            // Load batch from JSONL
            $batch_json_items = load_json_batch($json_path, $start_index, $batch_size);
            //error_log("Loaded " . count($batch_json_items) . " items for batch starting at $start_index");
            $batch_items = [];
            $batch_guids = [];
            for ($i = 0; $i < count($batch_json_items); $i++) {
                $current_index = $start_index + $i;
                if (get_transient('import_cancel') === true) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Import cancelled at #' . ($current_index + 1);
                    update_option('job_import_progress', $current_index, false);
                    return ['success' => false, 'message' => 'Import cancelled', 'logs' => $logs];
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
                    $batch_size = max(1, (int)($batch_size * 0.8)); // Changed to 80% reduction instead of halving for more gradual adjustment
                    update_option('job_import_batch_size', $batch_size, false);
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Memory high, reduced batch to ' . $batch_size;
                    $end_index = min($start_index + $batch_size, $total);
                }
                if ($i % 5 === 0) {
                    //error_log("Processed $i items in batch");
                    ob_flush();
                    flush();
                }
                unset($batch_json_items[$i]);
            }
            unset($batch_json_items);

            if (empty($batch_guids)) {
                update_option('job_import_progress', $end_index, false);
                update_option('job_import_processed_guids', $processed_guids, false);
                $time_elapsed = microtime(true) - $start_time;
                return ['success' => true, 'processed' => $end_index, 'total' => $total, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'duplicates_drafted' => $duplicates_drafted, 'drafted_old' => $drafted_old, 'time_elapsed' => $time_elapsed, 'complete' => ($end_index >= $total), 'logs' => $logs, 'batch_size' => $batch_size, 'inferred_languages' => $inferred_languages, 'inferred_benefits' => $inferred_benefits, 'schema_generated' => $schema_generated, 'batch_time' => $time_elapsed, 'batch_processed' => 0];
            }

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

            $post_ids_by_guid = []; // Initialize to prevent null reference error
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

            $processed_count = 0; // Initialize to prevent null reference error
            process_batch_items($batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, $logs, $updated, $created, $skipped, $processed_count);

            unset($batch_items, $batch_guids);

            update_option('job_import_progress', $end_index, false);
            update_option('job_import_processed_guids', $processed_guids, false);
            $time_elapsed = microtime(true) - $start_time;
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Batch complete: Processed $processed_count items";

            // Dynamic batch size adjustment with improvements
            $time_per_item = $processed_count > 0 ? $time_elapsed / $processed_count : 0;
            $prev_time_per_item = get_option('job_import_time_per_job', 0);
            update_option('job_import_time_per_job', $time_per_item, false);
            $peak_memory = memory_get_peak_usage(true);
            $memory_ratio = $peak_memory / $memory_limit_bytes;

            // Use rolling average for time_per_item to stabilize adjustments
            $avg_time_per_item = get_option('job_import_avg_time_per_job', $time_per_item);
            $avg_time_per_item = ($avg_time_per_item * 0.7) + ($time_per_item * 0.3); // Weighted average, favoring history
            update_option('job_import_avg_time_per_job', $avg_time_per_item, false);

            // Memory-based adjustment: More gradual
            if ($memory_ratio > 0.85) { // Raised threshold from 0.8 for allowing higher usage before reduction
                $batch_size = max(1, floor($batch_size * 0.7)); // Slower reduction (70% instead of 75%)
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Batch size reduced to ' . $batch_size . ' due to high memory ratio: ' . number_format($memory_ratio, 2);
            } elseif ($memory_ratio < 0.5 && $avg_time_per_item < 1.0) { // Adjusted threshold and time condition
                $batch_size = min(50, floor($batch_size * 1.5)); // Capped at 50 for safety, more aggressive increase (150%)
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Batch size increased to ' . $batch_size . ' due to low memory ratio: ' . number_format($memory_ratio, 2) . ' and low avg time: ' . number_format($avg_time_per_item, 2);
            }

            // Time-based adjustment using average
            if ($prev_time_per_item > 0) {
                $time_ratio = $avg_time_per_item / $prev_time_per_item;
                if ($time_ratio > 1.2) { // Lowered threshold from 1.5 for quicker response to slowdowns
                    $batch_size = max(1, floor($batch_size / (1 + ($time_ratio - 1) * 0.5))); // Gradual reduction based on excess ratio
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Batch size reduced to ' . $batch_size . ' due to high time ratio: ' . number_format($time_ratio, 2);
                } elseif ($time_ratio < 0.8) { // Adjusted from 0.7 for more opportunities to increase
                    $batch_size = min(50, floor($batch_size * (1 + (1 - $time_ratio) * 1.5))); // Capped at 50, scaled increase based on improvement
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Batch size increased to ' . $batch_size . ' due to low time ratio: ' . number_format($time_ratio, 2);
                }
            }

            update_option('job_import_batch_size', $batch_size, false);

            // Update cumulative status
            $status = get_option('job_import_status') ?: [
                'total' => $total,
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'duplicates_drafted' => 0,
                'drafted_old' => 0,
                'time_elapsed' => 0,
                'complete' => false,
                'batch_size' => $batch_size,
                'inferred_languages' => 0,
                'inferred_benefits' => 0,
                'schema_generated' => 0,
                'start_time' => $start_time,
                'last_update' => time(),
                'logs' => [], // Ensure logs key exists
            ];
            if (!isset($status['start_time'])) {
                $status['start_time'] = $start_time;
            }
            $status['processed'] = $end_index;
            $status['created'] += $created;
            $status['updated'] += $updated;
            $status['skipped'] += $skipped;
            $status['duplicates_drafted'] += $duplicates_drafted;
            $status['drafted_old'] += $drafted_old;
            $status['time_elapsed'] += $time_elapsed;
            $status['complete'] = ($end_index >= $total);
            $status['batch_size'] = $batch_size;
            $status['inferred_languages'] += $inferred_languages;
            $status['inferred_benefits'] += $inferred_benefits;
            $status['schema_generated'] += $schema_generated;
            $status['last_update'] = time();
            update_option('job_import_status', $status, false);

            // Return with cumulative stats
            return [
                'success' => true,
                'processed' => $status['processed'],
                'total' => $status['total'],
                'created' => $status['created'],
                'updated' => $status['updated'],
                'skipped' => $status['skipped'],
                'duplicates_drafted' => $status['duplicates_drafted'],
                'drafted_old' => $status['drafted_old'],
                'time_elapsed' => $status['time_elapsed'],
                'complete' => $status['complete'],
                'logs' => $logs,
                'batch_size' => $status['batch_size'],
                'inferred_languages' => $status['inferred_languages'],
                'inferred_benefits' => $status['inferred_benefits'],
                'schema_generated' => $status['schema_generated'],
                'batch_time' => $time_elapsed,
                'batch_processed' => $processed_count
            ];
        } catch (Exception $e) {
            $error_msg = 'Batch import error: ' . $e->getMessage();
            error_log($error_msg);
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $error_msg;
            return ['success' => false, 'message' => 'Batch failed: ' . $e->getMessage(), 'logs' => $logs];
        }
    }
}
