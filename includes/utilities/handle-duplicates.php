<?php

/**
 * Duplicate handling utilities
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

function handle_duplicates($batch_guids, $existing_by_guid, &$logs, &$duplicates_drafted, &$post_ids_by_guid)
{
    global $wpdb;
    foreach ($batch_guids as $guid) {
        if (isset($existing_by_guid[ $guid ])) {
            $posts_data = $existing_by_guid[ $guid ];
            if (count($posts_data) > 1) {
                // Extract post IDs for duplicate processing - handle both formats
                $post_ids = array();
                foreach ($posts_data as $item) {
                    if (is_array($item) && isset($item['id'])) {
                        $post_ids[] = $item['id'];
                    } else {
                        $post_ids[] = $item;
                    }
                }

                $existing = get_posts(
                    array(
                        'post_type'      => 'job',
                        'post__in'       => $post_ids,
                        'posts_per_page' => -1,
                        'post_status'    => 'any',
                        'fields'         => 'ids',
                    )
                ) ?: array();

                if (empty($existing)) {
                        continue; // No posts found, skip
                }

                // BATCH LOAD all required metadata and post fields to avoid N+1 queries
                $placeholders   = implode(',', array_fill(0, count($existing), '%d'));
                $hashes_query   = $wpdb->prepare(
                    "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_import_hash' AND post_id IN ($placeholders)",
                    $existing
                );
                $hashes_results = $wpdb->get_results($hashes_query, OBJECT_K);
                $hashes         = array();
                foreach ($hashes_results as $post_id => $row) {
                        $hashes[ $post_id ] = $row->meta_value;
                }

                // Batch load post_modified and post_title
                $posts_query   = $wpdb->prepare(
                    "SELECT ID, post_modified, post_title FROM $wpdb->posts WHERE ID IN ($placeholders)",
                    $existing
                );
                $posts_results = $wpdb->get_results($posts_query, OBJECT_K);
                $post_modified = array();
                $post_titles   = array();
                foreach ($posts_results as $post_id => $post) {
                        $post_modified[ $post_id ] = $post->post_modified;
                        $post_titles[ $post_id ]   = $post->post_title;
                }

                $post_to_keep        = null;
                $duplicates_to_draft = array();

                foreach ($existing as $post_id) {
                    if ($post_to_keep === null) {
                        $post_to_keep = $post_id;
                    } else {
                        // If hashes are identical, draft the duplicate
                        if ($hashes[ $post_to_keep ] === $hashes[ $post_id ]) {
                                $duplicates_to_draft[] = $post_id;
                        } else {
                                // If hashes differ, keep the most recently modified
                            if (strtotime($post_modified[ $post_id ]) > strtotime($post_modified[ $post_to_keep ])) {
                                $duplicates_to_draft[] = $post_to_keep;
                                $post_to_keep          = $post_id;
                            } else {
                                $duplicates_to_draft[] = $post_id;
                            }
                        }
                    }
                }

                // Draft duplicates instead of deleting them, and append reason to title
                foreach ($duplicates_to_draft as $dup_id) {
                    // Get current title from batched data
                    $current_title = $post_titles[ $dup_id ] ?? '';

                    // Determine reason for drafting
                    $reason = 'Duplicate - ';
                    if ($hashes[ $dup_id ] === $hashes[ $post_to_keep ]) {
                        $reason .= 'Identical content';
                    } else {
                        $reason .= 'Older version kept';
                    }

                    // Append reason to title if not already present
                    if (strpos($current_title, $reason) === false) {
                        $new_title = $current_title . ' [' . $reason . ']';
                    } else {
                        $new_title = $current_title;
                    }

                    // Update post to draft status and modify title
                    wp_update_post(
                        array(
                            'ID'          => $dup_id,
                            'post_title'  => $new_title,
                            'post_status' => 'draft',
                        )
                    );

                    ++$duplicates_drafted;
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Drafted duplicate ID: ' . $dup_id . ' GUID: ' . $guid . ' - ' . $reason;
                    error_log('Drafted duplicate ID: ' . $dup_id . ' GUID: ' . $guid . ' - ' . $reason);
                }
                $post_ids_by_guid[ $guid ] = $post_to_keep;
            } else {
                // Handle both old format (array of IDs) and new format (array of post data)
                $first_item = $posts_data[0];
                if (is_array($first_item) && isset($first_item['id'])) {
                    $post_ids_by_guid[ $guid ] = $first_item['id'];
                } else {
                    $post_ids_by_guid[ $guid ] = $first_item;
                }
            }
        }
    }
}
