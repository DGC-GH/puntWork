?php
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
                $duplicates_to_delete = [];
                $hashes = [];
                foreach ($existing as $post_id) {
                    $hashes[$post_id] = get_post_meta($post_id, '_import_hash', true);
                }
                foreach ($existing as $post_id) {
                    if ($post_to_keep === null) {
                        $post_to_keep = $post_id;
                    } else {
                        if ($hashes[$post_to_keep] === $hashes[$post_id]) {
                            $duplicates_to_delete[] = $post_id;
                        } else {
                            if (strtotime(get_post_field('post_modified', $post_id)) > strtotime(get_post_field('post_modified', $post_to_keep))) {
                                $duplicates_to_delete[] = $post_to_keep;
                                $post_to_keep = $post_id;
                            } else {
                                $duplicates_to_delete[] = $post_id;
                            }
                        }
                    }
                }
                foreach ($duplicates_to_delete as $dup_id) {
                    $wpdb->update($wpdb->posts, ['post_status' => 'draft'], ['ID' => $dup_id]);
                    $duplicates_drafted++;
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Drafted duplicate ID: ' . $dup_id . ' GUID: ' . $guid;
                    error_log('Drafted duplicate ID: ' . $dup_id . ' GUID: ' . $guid);
                }
                $post_ids_by_guid[$guid] = $post_to_keep;
            } else {
                $post_ids_by_guid[$guid] = $ids[0];
            }
        }
    }
}
