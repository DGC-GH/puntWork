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
        PuntWorkLogger::info('Starting batch item processing', PuntWorkLogger::CONTEXT_BATCH, [
            'batch_guids_count' => count($batch_guids),
            'batch_items_count' => count($batch_items)
        ]);

        $user_id = get_user_by('login', 'admin') ? get_user_by('login', 'admin')->ID : get_current_user_id();
        $error_message = '';

        // Initialize batch operation collections
        $posts_to_republish = [];
        $posts_to_update = [];
        $posts_to_create = [];
        $meta_operations = [];

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
                            // Collect for batch republishing
                            $posts_to_republish[] = [
                                'post_id' => $post_id,
                                'guid' => $guid
                            ];
                        }

                        // Collect meta operations for batch update
                        $current_time = current_time('mysql');
                        $meta_operations[] = [
                            'post_id' => $post_id,
                            'guid' => $guid,
                            'operations' => [
                                ['key' => '_last_import_update', 'value' => $current_time],
                                ['key' => 'guid', 'value' => $guid]
                            ]
                        ];

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

                        // Collect for batch update
                        $posts_to_update[] = [
                            'post_id' => $post_id,
                            'guid' => $guid,
                            'item' => $item,
                            'item_hash' => $item_hash,
                            'acf_fields' => $acf_fields,
                            'zero_empty_fields' => $zero_empty_fields
                        ];

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
                    // Collect for batch creation
                    $posts_to_create[] = [
                        'guid' => $guid,
                        'item' => $item,
                        'acf_fields' => $acf_fields,
                        'zero_empty_fields' => $zero_empty_fields,
                        'user_id' => $user_id
                    ];

                    $published++;
                }

                $processed_count++;

                if ($processed_count % 5 === 0) {
                    PuntWorkLogger::debug('Batch processing progress', PuntWorkLogger::CONTEXT_BATCH, [
                        'processed_count' => $processed_count
                    ]);
                    // Only flush output buffer if one is active
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
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

        // Execute batch operations
        try {
            // Batch republish posts
            if (!empty($posts_to_republish)) {
                batch_republish_posts($posts_to_republish, $logs);
            }

            // Batch update meta operations
            if (!empty($meta_operations)) {
                batch_update_meta_operations($meta_operations, $logs);
            }

            // Batch update existing posts
            if (!empty($posts_to_update)) {
                batch_update_job_posts($posts_to_update, $logs);
            }

            // Batch create new posts
            if (!empty($posts_to_create)) {
                batch_create_job_posts($posts_to_create, $logs);
            }

        } catch (\Exception $e) {
            PuntWorkLogger::error('Error in batch operations', PuntWorkLogger::CONTEXT_BATCH, [
                'error' => $e->getMessage(),
                'republish_count' => count($posts_to_republish),
                'meta_ops_count' => count($meta_operations),
                'update_count' => count($posts_to_update),
                'create_count' => count($posts_to_create)
            ]);
            // Continue with logging even if batch operations fail
        }

        PuntWorkLogger::info('Batch item processing completed', PuntWorkLogger::CONTEXT_BATCH, [
            'total_processed' => $processed_count,
            'published' => $published,
            'updated' => $updated,
            'skipped' => $skipped,
            'republished' => count($posts_to_republish),
            'meta_operations' => count($meta_operations)
        ]);
    }
}

/**
 * Batch republish posts that are not currently published
 *
 * @param array $posts_to_republish Array of posts to republish
 * @param array &$logs Reference to logs array
 */
function batch_republish_posts($posts_to_republish, &$logs) {
    if (empty($posts_to_republish)) {
        return;
    }

    PuntWorkLogger::info('Starting batch republish operations', PuntWorkLogger::CONTEXT_BATCH, [
        'count' => count($posts_to_republish)
    ]);

    foreach ($posts_to_republish as $post_data) {
        $post_id = $post_data['post_id'];
        $guid = $post_data['guid'];

        $update_result = retry_database_operation(function() use ($post_id) {
            return wp_update_post([
                'ID' => $post_id,
                'post_status' => 'publish'
            ]);
        }, [$post_id], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'batch_republish_post',
            'post_id' => $post_id,
            'guid' => $guid
        ]);

        if (is_wp_error($update_result)) {
            PuntWorkLogger::error('Failed to batch republish post', PuntWorkLogger::CONTEXT_BATCH, [
                'post_id' => $post_id,
                'guid' => $guid,
                'error' => $update_result->get_error_message()
            ]);
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to republish ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $update_result->get_error_message();
        } else {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Republished ID: ' . $post_id . ' GUID: ' . $guid . ' - Found in active feed';
        }
    }

    PuntWorkLogger::info('Batch republish operations completed', PuntWorkLogger::CONTEXT_BATCH, [
        'processed' => count($posts_to_republish)
    ]);
}

/**
 * Batch update meta operations for posts
 *
 * @param array $meta_operations Array of meta operations to perform
 * @param array &$logs Reference to logs array
 */
function batch_update_meta_operations($meta_operations, &$logs) {
    if (empty($meta_operations)) {
        return;
    }

    PuntWorkLogger::info('Starting batch meta operations', PuntWorkLogger::CONTEXT_BATCH, [
        'operations_count' => count($meta_operations)
    ]);

    global $wpdb;

    // Prepare bulk insert data for meta operations
    $meta_inserts = [];
    $current_time = current_time('mysql');

    foreach ($meta_operations as $operation) {
        $post_id = $operation['post_id'];
        $guid = $operation['guid'];

        foreach ($operation['operations'] as $meta_op) {
            $meta_key = $meta_op['key'];
            $meta_value = $meta_op['value'];

            // Check if meta already exists to decide between INSERT and UPDATE
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s",
                $post_id, $meta_key
            ));

            if ($existing) {
                // Update existing meta
                $wpdb->query($wpdb->prepare(
                    "UPDATE $wpdb->postmeta SET meta_value = %s WHERE post_id = %d AND meta_key = %s",
                    $meta_value, $post_id, $meta_key
                ));
            } else {
                // Insert new meta
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
                    $post_id, $meta_key, $meta_value
                ));
            }
        }
    }

    PuntWorkLogger::info('Batch meta operations completed', PuntWorkLogger::CONTEXT_BATCH, [
        'operations_processed' => count($meta_operations)
    ]);
}

/**
 * Batch update existing job posts
 *
 * @param array $posts_to_update Array of posts to update
 * @param array &$logs Reference to logs array
 */
function batch_update_job_posts($posts_to_update, &$logs) {
    if (empty($posts_to_update)) {
        return;
    }

    PuntWorkLogger::info('Starting batch update operations', PuntWorkLogger::CONTEXT_BATCH, [
        'count' => count($posts_to_update)
    ]);

    global $wpdb;

    // Prepare bulk operations
    $post_updates = [];
    $meta_updates = [];

    foreach ($posts_to_update as $post_data) {
        $post_id = $post_data['post_id'];
        $guid = $post_data['guid'];
        $item = $post_data['item'];
        $item_hash = $post_data['item_hash'];
        $acf_fields = $post_data['acf_fields'];
        $zero_empty_fields = $post_data['zero_empty_fields'];

        // Prepare post update data
        $xml_title = $item['functiontitle'] ?? '';
        $xml_validfrom = $item['validfrom'] ?? '';
        $xml_updated = $item['updated'] ?? null;
        $post_modified = $xml_updated ?: current_time('mysql');

        $post_updates[] = [
            'ID' => $post_id,
            'post_title' => $xml_title,
            'post_name' => sanitize_title($xml_title . '-' . $guid),
            'post_status' => 'publish',
            'post_date' => $xml_validfrom,
            'post_modified' => $post_modified,
            'guid' => $guid, // Add GUID for logging
        ];

        // Collect ACF field updates
        foreach ($acf_fields as $field) {
            $value = $item[$field] ?? '';
            $is_special = in_array($field, $zero_empty_fields);
            $set_value = $is_special && $value === '0' ? '' : $value;

            // Serialize arrays if needed
            if (is_array($set_value)) {
                $set_value = serialize($set_value);
            }

            $meta_updates[] = [
                'post_id' => $post_id,
                'meta_key' => $field,
                'meta_value' => $set_value
            ];
        }

        // Add import hash
        $meta_updates[] = [
            'post_id' => $post_id,
            'meta_key' => '_import_hash',
            'meta_value' => $item_hash
        ];
    }

    // Execute batch post updates
    foreach ($post_updates as $post_data) {
        $update_result = retry_database_operation(function() use ($post_data) {
            return wp_update_post($post_data);
        }, [$post_data], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'batch_update_post',
            'post_id' => $post_data['ID']
        ]);

        if (is_wp_error($update_result)) {
            PuntWorkLogger::error('Failed to batch update post', PuntWorkLogger::CONTEXT_BATCH, [
                'post_id' => $post_data['ID'],
                'error' => $update_result->get_error_message()
            ]);
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to update ID: ' . $post_data['ID'] . ' - ' . $update_result->get_error_message();
        } else {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Updated ID: ' . $post_data['ID'] . ' GUID: ' . $post_data['guid'];
        }
    }

    // Execute batch meta updates
    foreach ($meta_updates as $meta_data) {
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s",
            $meta_data['post_id'], $meta_data['meta_key']
        ));

        if ($existing) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $wpdb->postmeta SET meta_value = %s WHERE post_id = %d AND meta_key = %s",
                $meta_data['meta_value'], $meta_data['post_id'], $meta_data['meta_key']
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
                $meta_data['post_id'], $meta_data['meta_key'], $meta_data['meta_value']
            ));
        }
    }

    PuntWorkLogger::info('Batch update operations completed', PuntWorkLogger::CONTEXT_BATCH, [
        'posts_updated' => count($post_updates),
        'meta_operations' => count($meta_updates)
    ]);
}

/**
 * Batch create new job posts
 *
 * @param array $posts_to_create Array of posts to create
 * @param array &$logs Reference to logs array
 */
function batch_create_job_posts($posts_to_create, &$logs) {
    if (empty($posts_to_create)) {
        return;
    }

    PuntWorkLogger::info('Starting batch create operations', PuntWorkLogger::CONTEXT_BATCH, [
        'count' => count($posts_to_create)
    ]);

    global $wpdb;

    // Prepare bulk operations
    $post_inserts = [];
    $meta_inserts = [];

    foreach ($posts_to_create as $post_data) {
        $guid = $post_data['guid'];
        $item = $post_data['item'];
        $acf_fields = $post_data['acf_fields'];
        $zero_empty_fields = $post_data['zero_empty_fields'];
        $user_id = $post_data['user_id'];

        $xml_title = $item['title'] ?? '';
        $xml_validfrom = $item['validfrom'] ?? current_time('mysql');
        $xml_updated = $item['updated'] ?? null;
        $post_modified = $xml_updated ?: current_time('mysql');
        $item_hash = md5(json_encode($item));
        $current_time = current_time('mysql');

        $post_inserts[] = [
            'post_data' => [
                'post_type' => 'job',
                'post_title' => $xml_title,
                'post_name' => sanitize_title($xml_title . '-' . $guid),
                'post_status' => 'publish',
                'post_date' => $xml_validfrom,
                'post_modified' => $post_modified,
                'comment_status' => 'closed',
                'post_author' => $user_id,
            ],
            'guid' => $guid,
            'item' => $item,
            'acf_fields' => $acf_fields,
            'zero_empty_fields' => $zero_empty_fields,
            'item_hash' => $item_hash,
            'current_time' => $current_time
        ];
    }

    // Execute batch post creation
    foreach ($post_inserts as $insert_data) {
        $post_data = $insert_data['post_data'];
        $guid = $insert_data['guid'];

        $post_id = retry_database_operation(function() use ($post_data) {
            return wp_insert_post($post_data);
        }, [$post_data], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'batch_create_post',
            'guid' => $guid
        ]);

        if (is_wp_error($post_id)) {
            PuntWorkLogger::error('Failed to batch create post', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'error' => $post_id->get_error_message()
            ]);
            continue;
        }

        // Prepare meta inserts for this post
        $item = $insert_data['item'];
        $acf_fields = $insert_data['acf_fields'];
        $zero_empty_fields = $insert_data['zero_empty_fields'];
        $item_hash = $insert_data['item_hash'];
        $current_time = $insert_data['current_time'];

        // Add standard meta
        $meta_inserts[] = [
            'post_id' => $post_id,
            'meta_key' => '_last_import_update',
            'meta_value' => $current_time
        ];
        $meta_inserts[] = [
            'post_id' => $post_id,
            'meta_key' => 'guid',
            'meta_value' => $guid
        ];
        $meta_inserts[] = [
            'post_id' => $post_id,
            'meta_key' => '_import_hash',
            'meta_value' => $item_hash
        ];

        // Add ACF fields
        foreach ($acf_fields as $field) {
            $value = $item[$field] ?? '';
            $is_special = in_array($field, $zero_empty_fields);
            $set_value = $is_special && $value === '0' ? '' : $value;

            // Serialize arrays if needed
            if (is_array($set_value)) {
                $set_value = serialize($set_value);
            }

            $meta_inserts[] = [
                'post_id' => $post_id,
                'meta_key' => $field,
                'meta_value' => $set_value
            ];
        }

        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Published ID: ' . $post_id . ' GUID: ' . $guid;
    }

    // Execute batch meta inserts
    if (!empty($meta_inserts)) {
        // Use bulk insert for better performance
        $values = [];
        $placeholders = [];
        $params = [];

        foreach ($meta_inserts as $meta) {
            $values[] = "(%d, %s, %s)";
            $params[] = $meta['post_id'];
            $params[] = $meta['meta_key'];
            $params[] = $meta['meta_value'];
        }

        if (!empty($values)) {
            $query = "INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value) VALUES " . implode(', ', $values);
            $wpdb->query($wpdb->prepare($query, $params));
        }
    }

    PuntWorkLogger::info('Batch create operations completed', PuntWorkLogger::CONTEXT_BATCH, [
        'posts_created' => count($post_inserts),
        'meta_operations' => count($meta_inserts)
    ]);
}
