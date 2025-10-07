<?php
/**
 * Batch item processing utilities
 *
 * @package    Puntwork
 * @subpackage Processing
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if (!function_exists('process_batch_items')) {
    function process_batch_items($batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, &$logs, &$updated, &$published, &$skipped, &$processed_count) {
        error_log('process_batch_items called with ' . count($batch_guids) . ' GUIDs');
        $user_id = get_user_by('login', 'admin') ? get_user_by('login', 'admin')->ID : get_current_user_id();
        foreach ($batch_guids as $guid) {
            $item = $batch_items[$guid]['item'];
            $xml_updated = isset($item['updated']) ? $item['updated'] : '';
            $xml_updated_ts = strtotime($xml_updated);
            $post_id = isset($post_ids_by_guid[$guid]) ? $post_ids_by_guid[$guid] : null;

            error_log("Processing GUID: $guid, post_id: " . ($post_id ?: 'null'));

            // If post exists, check if it needs updating
            if ($post_id) {
                // First, ensure the job is published if it's in the feed
                $current_post = get_post($post_id);
                if ($current_post && $current_post->post_status !== 'publish') {
                    wp_update_post([
                        'ID' => $post_id,
                        'post_status' => 'publish'
                    ]);
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Republished ID: ' . $post_id . ' GUID: ' . $guid . ' - Found in active feed';
                    error_log('Republished ID: ' . $post_id . ' GUID: ' . $guid . ' - Found in active feed');
                }

                $current_last_update = isset($last_updates[$post_id]) ? $last_updates[$post_id]->meta_value : '';
                $current_last_ts = $current_last_update ? strtotime($current_last_update) : 0;

                // Skip if no update timestamp or if current version is newer/equal
                if ($xml_updated_ts && $current_last_ts >= $xml_updated_ts) {
                    $skipped++;
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Skipped ID: ' . $post_id . ' GUID: ' . $guid . ' - Not updated';
                    $processed_count++;
                    continue;
                }

                $current_hash = $all_hashes_by_post[$post_id] ?? '';
                $item_hash = md5(json_encode($item));

                // Skip if content hasn't changed
                if ($current_hash === $item_hash) {
                    $skipped++;
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Skipped ID: ' . $post_id . ' GUID: ' . $guid . ' - No changes';
                    $processed_count++;
                    continue;
                }

                // Update existing post
                $xml_title = isset($item['functiontitle']) ? $item['functiontitle'] : '';
                $xml_validfrom = isset($item['validfrom']) ? $item['validfrom'] : '';
                $post_modified = $xml_updated ?: current_time('mysql');

                wp_update_post([
                    'ID' => $post_id,
                    'post_title' => $xml_title,
                    'post_name' => sanitize_title($xml_title . '-' . $guid),
                    'post_status' => 'publish', // Ensure updated posts are published
                    'post_date' => $xml_validfrom,
                    'post_modified' => $post_modified,
                ]);

                update_post_meta($post_id, '_last_import_update', $xml_updated);
                update_post_meta($post_id, '_import_hash', $item_hash);

                foreach ($acf_fields as $field) {
                    $value = $item[$field] ?? '';
                    $is_special = in_array($field, $zero_empty_fields);
                    $set_value = $is_special && $value === '0' ? '' : $value;
                    update_post_meta($post_id, $field, $set_value);
                }

                $updated++;
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Updated ID: ' . $post_id . ' GUID: ' . $guid;
                error_log('Updated ID: ' . $post_id . ' GUID: ' . $guid);

            } else {
                // Create new post only if it doesn't exist
                $xml_title = isset($item['functiontitle']) ? $item['functiontitle'] : '';
                $xml_validfrom = isset($item['validfrom']) ? $item['validfrom'] : current_time('mysql');
                $post_modified = $xml_updated ?: current_time('mysql');

                $post_data = [
                    'post_type' => 'job',
                    'post_title' => $xml_title,
                    'post_name' => sanitize_title($xml_title . '-' . $guid),
                    'post_status' => 'publish',
                    'post_date' => $xml_validfrom,
                    'post_modified' => $post_modified,
                    'comment_status' => 'closed',
                    'post_author' => $user_id,
                ];

                $post_id = wp_insert_post($post_data);
                if (is_wp_error($post_id)) {
                    $error_msg = 'Create failed GUID: ' . $guid . ' - ' . $post_id->get_error_message();
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $error_msg;
                    error_log($error_msg);
                    continue;
                }

                $published++;
                update_post_meta($post_id, '_last_import_update', $xml_updated);
                $item_hash = md5(json_encode($item));
                update_post_meta($post_id, '_import_hash', $item_hash);

                foreach ($acf_fields as $field) {
                    $value = $item[$field] ?? '';
                    $is_special = in_array($field, $zero_empty_fields);
                    $set_value = $is_special && $value === '0' ? '' : $value;
                    update_post_meta($post_id, $field, $set_value);
                }

                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Published ID: ' . $post_id . ' GUID: ' . $guid;
                error_log('Published ID: ' . $post_id . ' GUID: ' . $guid);
            }

            $processed_count++;
            unset($batch_items[$guid]);

            if ($processed_count % 5 === 0) {
                error_log("Processed $processed_count items in batch");
                ob_flush();
                flush();
            }
        }
    }
}
