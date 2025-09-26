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
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function handle_duplicates($batch_guids, $existing_by_guid, &$logs, &$duplicates_drafted, &$post_ids_by_guid) {
    global $wpdb;
    foreach ($batch_guids as $guid) {
        if (isset($existing_by_guid[$guid])) {
            $ids = $existing_by_guid[$guid];
            if (count($ids) > 1) {
                $existing = get_posts([
                    'post_type' => 'job',
                    'post__in' => $ids,
                    'posts_per_page' => -1,
                    'post_status' => 'any',
                    'fields' => 'ids',
                ]) ?: [];
                $post_to_keep = null;
                $duplicates_to_draft = [];
                $hashes = [];
                foreach ($existing as $post_id) {
                    $hashes[$post_id] = get_post_meta($post_id, '_import_hash', true);
                }
                foreach ($existing as $post_id) {
                    if ($post_to_keep === null) {
                        $post_to_keep = $post_id;
                    } else {
                        // If hashes are identical, draft the duplicate
                        if ($hashes[$post_to_keep] === $hashes[$post_id]) {
                            $duplicates_to_draft[] = $post_id;
                        } else {
                            // If hashes differ, keep the most recently modified
                            if (strtotime(get_post_field('post_modified', $post_id)) > strtotime(get_post_field('post_modified', $post_to_keep))) {
                                $duplicates_to_draft[] = $post_to_keep;
                                $post_to_keep = $post_id;
                            } else {
                                $duplicates_to_draft[] = $post_id;
                            }
                        }
                    }
                }

                // Draft duplicates instead of deleting them, and append reason to title
                foreach ($duplicates_to_draft as $dup_id) {
                    // Get current title
                    $current_title = get_post_field('post_title', $dup_id);

                    // Determine reason for drafting
                    $reason = 'Duplicate - ';
                    if ($hashes[$dup_id] === $hashes[$post_to_keep]) {
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
                    wp_update_post([
                        'ID' => $dup_id,
                        'post_title' => $new_title,
                        'post_status' => 'draft'
                    ]);

                    $duplicates_drafted++;
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Drafted duplicate ID: ' . $dup_id . ' GUID: ' . $guid . ' - ' . $reason;
                    error_log('Drafted duplicate ID: ' . $dup_id . ' GUID: ' . $guid . ' - ' . $reason);
                }
                $post_ids_by_guid[$guid] = $post_to_keep;
            } else {
                $post_ids_by_guid[$guid] = $ids[0];
            }
        }
    }
}
