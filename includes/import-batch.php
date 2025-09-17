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
        $json_path = ABSPATH . 'feeds/combined-jobs.jsonl'; // Fixed: .jsonl to match combine output
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
            error_log("Starting batch from $start_index to $end_index (size $batch_size)");

            // Load batch from JSONL
            $batch_json_items = load_json_batch($json_path, $start_index, $batch_size);
            error_log("Loaded " . count($batch_json_items) . " items for batch starting at $start_index");
            $batch_items = [];
            $batch_guids = [];
            foreach ($batch_json_items as $item) {
                $guid = $item['guid'] ?? '';
                if ($guid) {
                    $batch_items[$guid] = ['item' => $item];
                    $batch_guids[] = $guid;
                } else {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Skipped item without GUID';
                    error_log('Skipped item without GUID');
                }
            }
            if (empty($batch_guids)) {
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'No valid GUIDs in batch';
                return [
                    'success' => true,
                    'processed' => $start_index,
                    'total' => $total,
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'duplicates_drafted' => $duplicates_drafted,
                    'drafted_old' => $drafted_old,
                    'time_elapsed' => 0,
                    'complete' => ($start_index >= $total),
                    'logs' => $logs,
                    'batch_size' => $batch_size,
                    'inferred_languages' => $inferred_languages,
                    'inferred_benefits' => $inferred_benefits,
                    'schema_generated' => $schema_generated,
                    'batch_time' => 0,
                    'batch_processed' => 0
                ];
            }

            // Get existing data for batch
            $post_ids_by_guid = [];
            $last_updates = [];
            $all_hashes_by_post = [];
            if (!empty($batch_guids)) {
                $placeholders = implode(',', array_fill(0, count($batch_guids), '%s'));
                $existing_posts = $wpdb->get_results($wpdb->prepare(
                    "SELECT p.ID, pm.meta_value AS guid, pum1.meta_value AS last_update, pum2.meta_value AS hash
                     FROM $wpdb->posts p
                     JOIN $wpdb->postmeta pm ON p.ID = pm.post_id
                     LEFT JOIN $wpdb->postmeta pum1 ON p.ID = pum1.post_id AND pum1.meta_key = '_last_import_update'
                     LEFT JOIN $wpdb->postmeta pum2 ON p.ID = pum2.post_id AND pum2.meta_key = '_import_hash'
                     WHERE p.post_type = 'job' AND pm.meta_key = 'guid' AND pm.meta_value IN ($placeholders)",
                    $batch_guids
                ));
                foreach ($existing_posts as $post) {
                    $post_ids_by_guid[$post->guid] = $post->ID;
                    $last_updates[$post->ID] = (object)['meta_value' => $post->last_update];
                    $all_hashes_by_post[$post->ID] = $post->hash;
                }
            }

            // Process the batch
            $processed_count = 0;
            process_batch_items($batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, $logs, $updated, $created, $skipped, $processed_count, $duplicates_drafted, $drafted_old, $inferred_languages, $inferred_benefits, $schema_generated);

            $new_progress = $start_index + $processed_count;
            update_option('job_import_progress', $new_progress, false);
            $processed_guids = array_merge($processed_guids, $batch_guids);
            update_option('job_import_processed_guids', array_unique($processed_guids), false);

            $batch_time = microtime(true) - $start_time;
            $status = [
                'success' => true,
                'processed' => $new_progress,
                'total' => $total,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'duplicates_drafted' => $duplicates_drafted,
                'drafted_old' => $drafted_old,
                'time_elapsed' => $batch_time, // Per-batch for accumulation
                'complete' => ($new_progress >= $total),
                'logs' => $logs,
                'batch_size' => $batch_size,
                'inferred_languages' => $inferred_languages,
                'inferred_benefits' => $inferred_benefits,
                'schema_generated' => $schema_generated,
                'batch_time' => $batch_time,
                'batch_processed' => $processed_count
            ];

            // Update global status
            $global_status = get_option('job_import_status', []);
            $global_status['processed'] = $new_progress;
            $global_status['created'] += $created;
            $global_status['updated'] += $updated;
            $global_status['skipped'] += $skipped;
            $global_status['duplicates_drafted'] += $duplicates_drafted;
            $global_status['drafted_old'] += $drafted_old;
            $global_status['inferred_languages'] += $inferred_languages;
            $global_status['inferred_benefits'] += $inferred_benefits;
            $global_status['schema_generated'] += $schema_generated;
            $global_status['time_elapsed'] += $batch_time;
            $global_status['complete'] = $status['complete'];
            $global_status['logs'] = array_merge($global_status['logs'] ?? [], $logs);
            $global_status['last_update'] = time();
            if ($status['complete']) {
                $global_status['end_time'] = microtime(true);
            }
            update_option('job_import_status', $global_status, false);

            error_log('Batch complete: processed ' . $processed_count . ' items in ' . $batch_time . 's');
            return $status;

        } catch (Exception $e) {
            error_log('Import batch error: ' . $e->getMessage());
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Batch error: ' . $e->getMessage();
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'logs' => $logs
            ];
        }
    }
}
