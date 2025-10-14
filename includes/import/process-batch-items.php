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
require_once plugin_dir_path(__FILE__) . '../utilities/database-utilities.php';

if (!function_exists('process_batch_items')) {
    function process_batch_items($batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, &$logs, &$updated, &$published, &$skipped, &$processed_count) {
        PuntWorkLogger::info('Starting individual item processing', PuntWorkLogger::CONTEXT_BATCH, [
            'batch_guids_count' => count($batch_guids),
            'batch_items_count' => count($batch_items)
        ]);

        $user_id = get_user_by('login', 'admin') ? get_user_by('login', 'admin')->ID : get_current_user_id();
        $error_message = '';

        foreach ($batch_guids as $guid) {
            try {
                $item = $batch_items[$guid]['item'];
                $xml_updated = isset($item['updated']) ? $item['updated'] : '';
                $xml_updated_ts = strtotime($xml_updated);
                $post_id = isset($post_ids_by_guid[$guid]) ? $post_ids_by_guid[$guid] : null;

                PuntWorkLogger::debug('Processing individual item', PuntWorkLogger::CONTEXT_BATCH, [
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
                            // Republish immediately
                            $update_result = retry_database_operation(function() use ($post_id) {
                                return wp_update_post([
                                    'ID' => $post_id,
                                    'post_status' => 'publish'
                                ]);
                            }, [$post_id], [
                                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                                'operation' => 'republish_post',
                                'post_id' => $post_id,
                                'guid' => $guid
                            ]);

                            if (is_wp_error($update_result)) {
                                PuntWorkLogger::error('Failed to republish post', PuntWorkLogger::CONTEXT_BATCH, [
                                    'post_id' => $post_id,
                                    'guid' => $guid,
                                    'error' => $update_result->get_error_message()
                                ]);
                                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to republish ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $update_result->get_error_message();
                            } else {
                                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Republished ID: ' . $post_id . ' GUID: ' . $guid . ' - Found in active feed';
                            }
                        }

                        // Update meta immediately
                        $current_time = current_time('mysql');
                        update_post_meta($post_id, '_last_import_update', $current_time);
                        update_post_meta($post_id, 'guid', $guid);

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

                        // Update post immediately
                        $error_message = '';
                        $update_result = update_job_post($post_id, $item, $acf_fields, $zero_empty_fields, $logs, $error_message);
                        if (is_wp_error($update_result)) {
                            PuntWorkLogger::error('Failed to update post', PuntWorkLogger::CONTEXT_BATCH, [
                                'post_id' => $post_id,
                                'guid' => $guid,
                                'error' => $error_message
                            ]);
                            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to update ID: ' . $post_id . ' - ' . $error_message;
                        }

                        $updated++;

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
                    // Create new post immediately
                    $error_message = '';
                    $create_result = create_job_post($item, $acf_fields, $zero_empty_fields, $user_id, $logs, $error_message);
                    if (is_wp_error($create_result)) {
                        PuntWorkLogger::error('Failed to create post', PuntWorkLogger::CONTEXT_BATCH, [
                            'guid' => $guid,
                            'error' => $error_message
                        ]);
                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to create GUID: ' . $guid . ' - ' . $error_message;
                    } else {
                        $published++;
                    }
                }

                $processed_count++;

                if ($processed_count % 5 === 0) {
                    PuntWorkLogger::debug('Individual processing progress', PuntWorkLogger::CONTEXT_BATCH, [
                        'processed_count' => $processed_count
                    ]);
                    // Only flush output buffer if one is active
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }

            } catch (\Exception $e) {
                PuntWorkLogger::error('Critical error processing individual item', PuntWorkLogger::CONTEXT_BATCH, [
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

        PuntWorkLogger::info('Individual item processing completed', PuntWorkLogger::CONTEXT_BATCH, [
            'total_processed' => $processed_count,
            'published' => $published,
            'updated' => $updated,
            'skipped' => $skipped
        ]);
    }
}


