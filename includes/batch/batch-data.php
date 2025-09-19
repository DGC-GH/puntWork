<?php
/**
 * Batch data processing functions
 * Handles batch data processing, duplicates, and item processing
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