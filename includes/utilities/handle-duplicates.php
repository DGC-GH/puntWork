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

    PuntWorkLogger::info('Starting duplicate handling', PuntWorkLogger::CONTEXT_BATCH, [
        'batch_guids_count' => count($batch_guids),
        'existing_by_guid_count' => count($existing_by_guid)
    ]);

    foreach ($batch_guids as $guid) {
        try {
            if (isset($existing_by_guid[$guid])) {
                $ids = $existing_by_guid[$guid];

                if (count($ids) > 1) {
                    try {
                        $existing = get_posts([
                            'post_type' => 'job',
                            'post__in' => $ids,
                            'posts_per_page' => -1,
                            'post_status' => 'any',
                            'fields' => 'ids',
                        ]) ?: [];

                        if (empty($existing)) {
                            PuntWorkLogger::warning('No posts found for duplicate GUID', PuntWorkLogger::CONTEXT_BATCH, [
                                'guid' => $guid,
                                'ids' => $ids
                            ]);
                            continue;
                        }

                        $post_to_keep = null;
                        $duplicates_to_draft = [];
                        $hashes = [];

                        // Get hashes for all posts
                        foreach ($existing as $post_id) {
                            try {
                                $hashes[$post_id] = get_post_meta($post_id, '_import_hash', true);
                            } catch (\Exception $e) {
                                PuntWorkLogger::error('Failed to get hash for post', PuntWorkLogger::CONTEXT_BATCH, [
                                    'post_id' => $post_id,
                                    'guid' => $guid,
                                    'error' => $e->getMessage()
                                ]);
                                $hashes[$post_id] = ''; // Default to empty hash
                            }
                        }

                        // Determine which post to keep and which to draft
                        foreach ($existing as $post_id) {
                            if ($post_to_keep === null) {
                                $post_to_keep = $post_id;
                            } else {
                                try {
                                    // If hashes are identical, draft the duplicate
                                    if ($hashes[$post_to_keep] === $hashes[$post_id]) {
                                        $duplicates_to_draft[] = $post_id;
                                    } else {
                                        // If hashes differ, keep the most recently modified
                                        $current_modified = strtotime(get_post_field('post_modified', $post_id));
                                        $keep_modified = strtotime(get_post_field('post_modified', $post_to_keep));

                                        if ($current_modified > $keep_modified) {
                                            $duplicates_to_draft[] = $post_to_keep;
                                            $post_to_keep = $post_id;
                                        } else {
                                            $duplicates_to_draft[] = $post_id;
                                        }
                                    }
                                } catch (\Exception $e) {
                                    PuntWorkLogger::error('Error comparing posts for duplicate handling', PuntWorkLogger::CONTEXT_BATCH, [
                                        'post_id' => $post_id,
                                        'keep_id' => $post_to_keep,
                                        'guid' => $guid,
                                        'error' => $e->getMessage()
                                    ]);
                                    // Default to keeping the first post and drafting this one
                                    $duplicates_to_draft[] = $post_id;
                                }
                            }
                        }

                        // Draft duplicates instead of deleting them, and append reason to title
                        foreach ($duplicates_to_draft as $dup_id) {
                            try {
                                // Get current title
                                $current_title = get_post_field('post_title', $dup_id);

                                if ($current_title === false) {
                                    PuntWorkLogger::error('Failed to get post title for duplicate', PuntWorkLogger::CONTEXT_BATCH, [
                                        'dup_id' => $dup_id,
                                        'guid' => $guid
                                    ]);
                                    continue;
                                }

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
                                $update_result = wp_update_post([
                                    'ID' => $dup_id,
                                    'post_title' => $new_title,
                                    'post_status' => 'draft'
                                ]);

                                if (is_wp_error($update_result)) {
                                    PuntWorkLogger::error('Failed to draft duplicate post', PuntWorkLogger::CONTEXT_BATCH, [
                                        'dup_id' => $dup_id,
                                        'guid' => $guid,
                                        'error' => $update_result->get_error_message()
                                    ]);
                                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Failed to draft duplicate ID: ' . $dup_id . ' GUID: ' . $guid . ' - ' . $update_result->get_error_message();
                                    continue;
                                }

                                $duplicates_drafted++;
                                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Drafted duplicate ID: ' . $dup_id . ' GUID: ' . $guid . ' - ' . $reason;

                            } catch (\Exception $e) {
                                PuntWorkLogger::error('Error drafting duplicate post', PuntWorkLogger::CONTEXT_BATCH, [
                                    'dup_id' => $dup_id,
                                    'guid' => $guid,
                                    'error' => $e->getMessage()
                                ]);
                                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Error drafting duplicate ID: ' . $dup_id . ' GUID: ' . $guid . ' - ' . $e->getMessage();
                                continue;
                            }
                        }

                        $post_ids_by_guid[$guid] = $post_to_keep;

                    } catch (\Exception $e) {
                        PuntWorkLogger::error('Error processing duplicates for GUID', PuntWorkLogger::CONTEXT_BATCH, [
                            'guid' => $guid,
                            'ids' => $ids,
                            'error' => $e->getMessage()
                        ]);
                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Error processing duplicates for GUID: ' . $guid . ' - ' . $e->getMessage();
                        // Fallback: use the first ID
                        $post_ids_by_guid[$guid] = $ids[0];
                        continue;
                    }

                } else {
                    $post_ids_by_guid[$guid] = $ids[0];
                }
            }
        } catch (\Exception $e) {
            PuntWorkLogger::error('Critical error in duplicate handling', PuntWorkLogger::CONTEXT_BATCH, [
                'guid' => $guid,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Critical error handling duplicates for GUID: ' . $guid . ' - ' . $e->getMessage();
            continue;
        }
    }

    PuntWorkLogger::info('Duplicate handling completed', PuntWorkLogger::CONTEXT_BATCH, [
        'duplicates_drafted' => $duplicates_drafted
    ]);
}
