<?php
/**
 * Database optimization utilities
 *
 * @package    Puntwork
 * @subpackage Database
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create database indexes for performance optimization
 */
function create_database_indexes(): void {
    global $wpdb;

    // Index for GUID lookups (critical for duplicate detection)
    $wpdb->query("
        CREATE INDEX IF NOT EXISTS idx_postmeta_guid
        ON {$wpdb->postmeta} (meta_key, meta_value(50))
        WHERE meta_key = 'guid'
    ");

    // Index for import hash lookups
    $wpdb->query("
        CREATE INDEX IF NOT EXISTS idx_postmeta_import_hash
        ON {$wpdb->postmeta} (meta_key, meta_value(32))
        WHERE meta_key = '_import_hash'
    ");

    // Index for last import update timestamps
    $wpdb->query("
        CREATE INDEX IF NOT EXISTS idx_postmeta_last_update
        ON {$wpdb->postmeta} (meta_key, post_id)
        WHERE meta_key = '_last_import_update'
    ");

    // Composite index for post status and type (for job queries)
    $wpdb->query("
        CREATE INDEX IF NOT EXISTS idx_posts_job_status
        ON {$wpdb->posts} (post_type, post_status, post_modified)
        WHERE post_type = 'job'
    ");

    // Index for feed URL lookups
    $wpdb->query("
        CREATE INDEX IF NOT EXISTS idx_postmeta_feed_url
        ON {$wpdb->postmeta} (meta_key, meta_value(255))
        WHERE meta_key = 'feed_url'
    ");
}

/**
 * Bulk update post meta values for better performance
 *
 * @param int $post_id Post ID
 * @param array $meta_data Array of meta_key => meta_value pairs
 */
function bulk_update_post_meta(int $post_id, array $meta_data): void {
    global $wpdb;

    if (empty($meta_data)) {
        return;
    }

    $values = [];
    $placeholders = [];

    foreach ($meta_data as $key => $value) {
        $values[] = $post_id;
        $values[] = $key;
        $values[] = $value;
        $placeholders[] = '(%d, %s, %s)';
    }

    $query = $wpdb->prepare("
        INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
        VALUES " . implode(', ', $placeholders) . "
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
    ", $values);

    $wpdb->query($query);
}

/**
 * Bulk fetch post statuses to avoid N+1 queries
 *
 * @param array $post_ids Array of post IDs
 * @return array Post ID => status mapping
 */
function bulk_get_post_statuses(array $post_ids): array {
    global $wpdb;

    if (empty($post_ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
    $query = $wpdb->prepare("
        SELECT ID, post_status
        FROM {$wpdb->posts}
        WHERE ID IN ({$placeholders})
    ", $post_ids);

    $results = $wpdb->get_results($query, OBJECT_K);
    $statuses = [];

    foreach ($results as $post_id => $post) {
        $statuses[$post_id] = $post->post_status;
    }

    return $statuses;
}

/**
 * Optimized function to get posts by GUID with status
 *
 * @param array $guids Array of GUIDs to look up
 * @return array GUID => post data mapping
 */
function get_posts_by_guids_with_status(array $guids): array {
    global $wpdb;

    if (empty($guids)) {
        return [];
    }

    $guid_placeholders = implode(',', array_fill(0, count($guids), '%s'));
    $query = $wpdb->prepare("
        SELECT pm.meta_value AS guid, p.ID, p.post_status, p.post_modified
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE pm.meta_key = 'guid'
        AND pm.meta_value IN ({$guid_placeholders})
        AND p.post_type = 'job'
    ", $guids);

    $results = $wpdb->get_results($query);
    $posts_by_guid = [];

    foreach ($results as $row) {
        if (!isset($posts_by_guid[$row->guid])) {
            $posts_by_guid[$row->guid] = [];
        }
        $posts_by_guid[$row->guid][] = [
            'id' => (int)$row->ID,
            'status' => $row->post_status,
            'modified' => $row->post_modified
        ];
    }

    return $posts_by_guid;
}

/**
 * Get database optimization status
 *
 * @return array Status information
 */
function get_database_optimization_status(): array {
    global $wpdb;

    $indexes = [
        'idx_postmeta_guid' => false,
        'idx_postmeta_import_hash' => false,
        'idx_postmeta_last_update' => false,
        'idx_posts_job_status' => false,
        'idx_postmeta_feed_url' => false,
    ];

    // Check which indexes exist
    $existing_indexes = $wpdb->get_col("
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('{$wpdb->postmeta}', '{$wpdb->posts}')
        AND INDEX_NAME IN ('" . implode("','", array_keys($indexes)) . "')
    ");

    foreach ($existing_indexes as $index) {
        $indexes[$index] = true;
    }

    $missing_indexes = array_filter($indexes, function($exists) {
        return !$exists;
    });

    return [
        'indexes_created' => count($indexes) - count($missing_indexes),
        'total_indexes' => count($indexes),
        'missing_indexes' => array_keys($missing_indexes),
        'optimization_complete' => empty($missing_indexes)
    ];
}