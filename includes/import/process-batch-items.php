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

// Include retry utility
require_once plugin_dir_path(__FILE__) . '../utilities/retry-utility.php';

if (!function_exists('process_batch_items')) {
    function process_batch_items($batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, &$logs, &$updated, &$published, &$skipped, &$processed_count) {
        PuntWorkLogger::info('Starting batch item processing', PuntWorkLogger::CONTEXT_BATCH, [
            'batch_guids_count' => count($batch_guids),
            'batch_items_count' => count($batch_items)
        ]);

        $user_id = get_user_by('login', 'admin') ? get_user_by('login', 'admin')->ID : get_current_user_id();

        foreach ($batch_guids as $guid) {
            try {
                $item = $batch_items[$guid]['item'];
                $xml_updated = isset($item['updated']) ? $item['updated'] : '';
                $xml_updated_ts = strtotime($xml_updated);
                $post_id = isset($post_ids_by_guid[$guid]) ? $post_ids_by_guid[$guid] : null;

                PuntWorkLogger::debug('Processing batch item', PuntWorkLogger::CONTEXT_BATCH, [
                    'guid' => $guid,
                    'post_id' => $post_id,
                    'xml_updated' => $xml_updated
                ]);

                // If post exists, check if it needs updating
                if ($post_id) {
                    try {
                        // First, ensure the job is published if it's in the feed
                        $current_post = get_post($post_id);
                        if ($current_post && $current_post->post_status !== 'publish') {
                            $update_result = retry_database_operation(function() use ($post_id) {
                                return wp_update_post([
                                    'ID' => $post_id,
                                    'post_status' => 'publish'
                                ]);
                            }, [$post_id], [
                                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                                'operation' => 'republish_existing_post',
                                'post_id' => $post_id,
                                'guid' => $guid
                            ]);

                            if (is_wp_error($update_result)) {
                                PuntWorkLogger::error('Failed to republish existing post', PuntWorkLogger::CONTEXT_BATCH, [
                                    'post_id' => $post_id,
                                    'guid' => $guid,
                                    'error' => $update_result->get_error_message()
                                ]);
                                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to republish ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $update_result->get_error_message();
                                $skipped++;
                                $processed_count++;
                                continue;
                            }

                            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Republished ID: ' . $post_id . ' GUID: ' . $guid . ' - Found in active feed';
                        }

                        // Mark this post as still active in the feed
                        $current_time = current_time('mysql');
                        retry_database_operation(function() use ($post_id, $current_time) {
                            return update_post_meta($post_id, '_last_import_update', $current_time);
                        }, [$post_id, $current_time], [
                            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                            'operation' => 'mark_post_active',
                            'post_id' => $post_id,
                            'guid' => $guid
                        ]);

                        // Ensure GUID meta is set for compatibility with purge feature
                        retry_database_operation(function() use ($post_id, $guid) {
                            return update_post_meta($post_id, 'guid', $guid);
                        }, [$post_id, $guid], [
                            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                            'operation' => 'set_guid_meta',
                            'post_id' => $post_id,
                            'guid' => $guid
                        ]);

                        $current_last_update = isset($last_updates[$post_id]) ? $last_updates[$post_id]->meta_value : '';
                        $current_last_ts = $current_last_update ? strtotime($current_last_update) : 0;

                        // Skip if no update timestamp or if current version is newer/equal
                        // Temporarily disabled to ensure full processing
                        // if ($xml_updated_ts && $current_last_ts >= $xml_updated_ts) {
                        //     $skipped++;
                        //     $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Skipped ID: ' . $post_id . ' GUID: ' . $guid . ' - Not updated';
                        //     $processed_count++;
                        //     continue;
                        // }

                        $current_hash = $all_hashes_by_post[$post_id] ?? '';
                        $item_hash = md5(json_encode($item));

                        // Skip if content hasn't changed
                        // Temporarily disabled to ensure full processing
                        // if ($current_hash === $item_hash) {
                        //     $skipped++;
                        //     $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Skipped ID: ' . $post_id . ' GUID: ' . $guid . ' - No changes';
                        //     $processed_count++;
                        //     continue;
                        // }

                        // Update existing post
                        $xml_title = isset($item['functiontitle']) ? $item['functiontitle'] : '';
                        $xml_validfrom = isset($item['validfrom']) ? $item['validfrom'] : '';
                        $post_modified = $xml_updated ?: current_time('mysql');

                        $update_result = retry_database_operation(function() use ($post_id, $xml_title, $guid, $xml_validfrom, $post_modified) {
                            return wp_update_post([
                                'ID' => $post_id,
                                'post_title' => $xml_title,
                                'post_name' => sanitize_title($xml_title . '-' . $guid),
                                'post_status' => 'publish', // Ensure updated posts are published
                                'post_date' => $xml_validfrom,
                                'post_modified' => $post_modified,
                            ]);
                        }, [$post_id, $xml_title, $guid, $xml_validfrom, $post_modified], [
                            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                            'operation' => 'update_existing_post',
                            'post_id' => $post_id,
                            'guid' => $guid
                        ]);

                        if (is_wp_error($update_result)) {
                            PuntWorkLogger::error('Failed to update existing post', PuntWorkLogger::CONTEXT_BATCH, [
                                'post_id' => $post_id,
                                'guid' => $guid,
                                'error' => $update_result->get_error_message()
                            ]);
                            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to update ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $update_result->get_error_message();
                            $skipped++;
                            $processed_count++;
                            continue;
                        }

                        try {
                            $current_time = current_time('mysql');
                            retry_database_operation(function() use ($post_id, $current_time) {
                                return update_post_meta($post_id, '_last_import_update', $current_time);
                            }, [$post_id, $current_time], [
                                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                                'operation' => 'update_last_import_meta',
                                'post_id' => $post_id,
                                'guid' => $guid
                            ]);

                            $item_hash = md5(json_encode($item));
                            retry_database_operation(function() use ($post_id, $item_hash) {
                                return update_post_meta($post_id, '_import_hash', $item_hash);
                            }, [$post_id, $item_hash], [
                                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                                'operation' => 'update_import_hash_meta',
                                'post_id' => $post_id,
                                'guid' => $guid
                            ]);

                            foreach ($acf_fields as $field) {
                                $value = $item[$field] ?? '';
                                $is_special = in_array($field, $zero_empty_fields);
                                $set_value = $is_special && $value === '0' ? '' : $value;
                                retry_database_operation(function() use ($post_id, $field, $set_value) {
                                    return update_post_meta($post_id, $field, $set_value);
                                }, [$post_id, $field, $set_value], [
                                    'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                                    'operation' => 'update_acf_field_meta',
                                    'post_id' => $post_id,
                                    'field' => $field,
                                    'guid' => $guid
                                ]);
                            }
                        } catch (\Exception $e) {
                            PuntWorkLogger::error('Failed to update post meta for existing post', PuntWorkLogger::CONTEXT_BATCH, [
                                'post_id' => $post_id,
                                'guid' => $guid,
                                'error' => $e->getMessage()
                            ]);
                            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Meta update failed for ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $e->getMessage();
                            $skipped++;
                            $processed_count++;
                            continue;
                        }

                        $updated++;
                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Updated ID: ' . $post_id . ' GUID: ' . $guid;

                    } catch (\Exception $e) {
                        PuntWorkLogger::error('Error processing existing post', PuntWorkLogger::CONTEXT_BATCH, [
                            'post_id' => $post_id,
                            'guid' => $guid,
                            'error' => $e->getMessage()
                        ]);
                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Error processing existing post ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $e->getMessage();
                        $skipped++;
                        $processed_count++;
                        continue;
                    }

                } else {
                    // Create new post only if it doesn't exist
                    try {
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

                        $post_id = retry_database_operation(function() use ($post_data) {
                            return wp_insert_post($post_data);
                        }, [$post_data], [
                            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                            'operation' => 'create_new_post',
                            'guid' => $guid,
                            'title' => $xml_title
                        ]);

                        if (is_wp_error($post_id)) {
                            PuntWorkLogger::error('Failed to create new post', PuntWorkLogger::CONTEXT_BATCH, [
                                'guid' => $guid,
                                'error' => $post_id->get_error_message()
                            ]);
                            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Create failed GUID: ' . $guid . ' - ' . $post_id->get_error_message();
                            $skipped++;
                            $processed_count++;
                            continue;
                        }

                        try {
                            $published++;
                            $current_time = current_time('mysql');
                            retry_database_operation(function() use ($post_id, $current_time) {
                                return update_post_meta($post_id, '_last_import_update', $current_time);
                            }, [$post_id, $current_time], [
                                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                                'operation' => 'set_last_import_meta_new',
                                'post_id' => $post_id,
                                'guid' => $guid
                            ]);

                            // Set GUID meta for new posts
                            retry_database_operation(function() use ($post_id, $guid) {
                                return update_post_meta($post_id, 'guid', $guid);
                            }, [$post_id, $guid], [
                                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                                'operation' => 'set_guid_meta_new',
                                'post_id' => $post_id,
                                'guid' => $guid
                            ]);

                            $item_hash = md5(json_encode($item));
                            retry_database_operation(function() use ($post_id, $item_hash) {
                                return update_post_meta($post_id, '_import_hash', $item_hash);
                            }, [$post_id, $item_hash], [
                                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                                'operation' => 'set_import_hash_meta_new',
                                'post_id' => $post_id,
                                'guid' => $guid
                            ]);

                            foreach ($acf_fields as $field) {
                                $value = $item[$field] ?? '';
                                $is_special = in_array($field, $zero_empty_fields);
                                $set_value = $is_special && $value === '0' ? '' : $value;
                                retry_database_operation(function() use ($post_id, $field, $set_value) {
                                    return update_post_meta($post_id, $field, $set_value);
                                }, [$post_id, $field, $set_value], [
                                    'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                                    'operation' => 'set_acf_field_meta_new',
                                    'post_id' => $post_id,
                                    'field' => $field,
                                    'guid' => $guid
                                ]);
                            }
                        } catch (\Exception $e) {
                            PuntWorkLogger::error('Failed to set post meta for new post', PuntWorkLogger::CONTEXT_BATCH, [
                                'post_id' => $post_id,
                                'guid' => $guid,
                                'error' => $e->getMessage()
                            ]);
                            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Meta setup failed for new post ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $e->getMessage();
                            // Try to delete the post since meta setup failed
                            wp_delete_post($post_id, true);
                            $skipped++;
                            $processed_count++;
                            continue;
                        }

                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Published ID: ' . $post_id . ' GUID: ' . $guid;

                    } catch (\Exception $e) {
                        PuntWorkLogger::error('Error creating new post', PuntWorkLogger::CONTEXT_BATCH, [
                            'guid' => $guid,
                            'error' => $e->getMessage()
                        ]);
                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Error creating new post GUID: ' . $guid . ' - ' . $e->getMessage();
                        $skipped++;
                        $processed_count++;
                        continue;
                    }
                }

                $processed_count++;

                if ($processed_count % 5 === 0) {
                    PuntWorkLogger::debug('Batch processing progress', PuntWorkLogger::CONTEXT_BATCH, [
                        'processed_count' => $processed_count
                    ]);
                    ob_flush();
                    flush();
                }

            } catch (\Exception $e) {
                PuntWorkLogger::error('Critical error processing batch item', PuntWorkLogger::CONTEXT_BATCH, [
                    'guid' => $guid,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Critical error processing GUID: ' . $guid . ' - ' . $e->getMessage();
                $skipped++;
                $processed_count++;
                continue;
            }
        }

        PuntWorkLogger::info('Batch item processing completed', PuntWorkLogger::CONTEXT_BATCH, [
            'total_processed' => $processed_count,
            'published' => $published,
            'updated' => $updated,
            'skipped' => $skipped
        ]);
    }
}
