<?php

/**
 * Database optimization utilities
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.0
 */

// Prevent direct access
if (! defined('ABSPATH') ) {
    exit;
}

use Puntwork\Utilities\CacheManager;

/**
 * Create database indexes for performance optimization
 */
function create_database_indexes(): void
{
    global $wpdb;

    // Index for GUID lookups (critical for duplicate detection)
    $wpdb->query(
        "
        CREATE INDEX IF NOT EXISTS idx_postmeta_guid
        ON {$wpdb->postmeta} (meta_key, meta_value(50))
        WHERE meta_key = 'guid'
    "
    );

    // Index for import hash lookups
    $wpdb->query(
        "
        CREATE INDEX IF NOT EXISTS idx_postmeta_import_hash
        ON {$wpdb->postmeta} (meta_key, meta_value(32))
        WHERE meta_key = '_import_hash'
    "
    );

    // Index for last import update timestamps
    $wpdb->query(
        "
        CREATE INDEX IF NOT EXISTS idx_postmeta_last_update
        ON {$wpdb->postmeta} (meta_key, post_id)
        WHERE meta_key = '_last_import_update'
    "
    );

    // Composite index for post status and type (for job queries)
    $wpdb->query(
        "
        CREATE INDEX IF NOT EXISTS idx_posts_job_status
        ON {$wpdb->posts} (post_type, post_status, post_modified)
        WHERE post_type = 'job'
    "
    );

    // Index for feed URL lookups
    $wpdb->query(
        "
        CREATE INDEX IF NOT EXISTS idx_postmeta_feed_url
        ON {$wpdb->postmeta} (meta_key, meta_value(255))
        WHERE meta_key = 'feed_url'
    "
    );

    // Additional performance indexes
    $wpdb->query(
        "
        CREATE INDEX IF NOT EXISTS idx_posts_job_date
        ON {$wpdb->posts} (post_type, post_date, post_modified)
        WHERE post_type = 'job'
    "
    );

    // Index for job title searches
    $wpdb->query(
        "
        CREATE INDEX IF NOT EXISTS idx_posts_job_title
        ON {$wpdb->posts} (post_type, post_title(100))
        WHERE post_type = 'job'
    "
    );

    // Index for performance logs queries
    $performance_table = $wpdb->prefix . 'puntwork_performance_logs';
    $wpdb->query(
        "
        CREATE INDEX IF NOT EXISTS idx_performance_operation_time
        ON {$performance_table} (operation, created_at)
    "
    );

    $wpdb->query(
        "
        CREATE INDEX IF NOT EXISTS idx_performance_duration
        ON {$performance_table} (total_time, items_per_second)
    "
    );
}

/**
 * Bulk update post meta values for better performance
 *
 * @param int   $post_id   Post ID
 * @param array $meta_data Array of meta_key => meta_value pairs
 */
function bulk_update_post_meta( int $post_id, array $meta_data ): void
{
    global $wpdb;

    if (empty($meta_data) ) {
        return;
    }

    $values       = array();
    $placeholders = array();

    foreach ( $meta_data as $key => $value ) {
        $values[]       = $post_id;
        $values[]       = $key;
        $values[]       = $value;
        $placeholders[] = '(%d, %s, %s)';
    }

    $query = $wpdb->prepare(
        "
        INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
        VALUES " . implode(', ', $placeholders) . '
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
    ',
        $values
    );

    $wpdb->query($query);
}

/**
 * Bulk fetch post statuses to avoid N+1 queries
 *
 * @param  array $post_ids Array of post IDs
 * @return array Post ID => status mapping
 */
function bulk_get_post_statuses( array $post_ids ): array
{
    global $wpdb;

    if (empty($post_ids) ) {
        return array();
    }

    error_log('[PUNTWORK] [DB-DEBUG] bulk_get_post_statuses called with ' . count($post_ids) . ' post IDs');

    // Create cache key from sorted post IDs
    sort($post_ids);
    $cache_key     = 'post_statuses_' . md5(implode(',', $post_ids));
    $cached_result = CacheManager::get($cache_key, CacheManager::GROUP_ANALYTICS);

    if ($cached_result !== false ) {
        error_log('[PUNTWORK] [DB-DEBUG] Returning cached result for post statuses');
        return $cached_result;
    }

    error_log('[PUNTWORK] [DB-DEBUG] Cache miss, querying post statuses');

    $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
    $query        = $wpdb->prepare(
        "
        SELECT ID, post_status
        FROM {$wpdb->posts}
        WHERE ID IN ({$placeholders})
    ",
        $post_ids
    );

    error_log('[PUNTWORK] [DB-DEBUG] Executing post status query');

    $start_time = microtime(true);
    $results    = $wpdb->get_results($query, OBJECT_K);
    $query_time = microtime(true) - $start_time;

    error_log('[PUNTWORK] [DB-DEBUG] Post status query returned ' . count($results) . ' results in ' . number_format($query_time, 4) . ' seconds');

    $statuses = array();

    foreach ( $results as $post_id => $post ) {
        $statuses[ $post_id ] = $post->post_status;
    }

    error_log('[PUNTWORK] [DB-DEBUG] Processed ' . count($statuses) . ' post statuses');

    // Cache for 15 minutes - post statuses change less frequently
    CacheManager::set($cache_key, $statuses, CacheManager::GROUP_ANALYTICS, 15 * MINUTE_IN_SECONDS);

    return $statuses;
}

/**
 * Optimized function to get posts by GUID with status
 *
 * @param  array $guids Array of GUIDs to look up
 * @return array GUID => post data mapping
 */
function get_posts_by_guids_with_status( array $guids ): array
{
    global $wpdb;

    if (empty($guids) ) {
        return array();
    }

    error_log('[PUNTWORK] [DB-DEBUG] get_posts_by_guids_with_status called with ' . count($guids) . ' GUIDs');

    // Create cache key from sorted GUIDs to ensure consistency
    sort($guids);
    $cache_key     = 'posts_by_guids_' . md5(implode(',', $guids));
    $cached_result = CacheManager::get($cache_key, CacheManager::GROUP_ANALYTICS);

    if ($cached_result !== false ) {
        error_log('[PUNTWORK] [DB-DEBUG] Returning cached result for GUID lookup');
        return $cached_result;
    }

    error_log('[PUNTWORK] [DB-DEBUG] Cache miss, querying database');

    $guid_placeholders = implode(',', array_fill(0, count($guids), '%s'));
    $query             = $wpdb->prepare(
        "
        SELECT pm.meta_value AS guid, p.ID, p.post_status, p.post_modified
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE pm.meta_key = 'guid'
        AND pm.meta_value IN ({$guid_placeholders})
        AND p.post_type = 'job'
    ",
        $guids
    );

    error_log('[PUNTWORK] [DB-DEBUG] Executing query: ' . $query);

    $start_time = microtime(true);
    $results    = $wpdb->get_results($query);
    $query_time = microtime(true) - $start_time;

    error_log('[PUNTWORK] [DB-DEBUG] Query returned ' . count($results) . ' results in ' . number_format($query_time, 4) . ' seconds');

    $posts_by_guid = array();

    foreach ( $results as $row ) {
        if (! isset($posts_by_guid[ $row->guid ]) ) {
            $posts_by_guid[ $row->guid ] = array();
        }
        $posts_by_guid[ $row->guid ][] = array(
        'id'       => (int) $row->ID,
        'status'   => $row->post_status,
        'modified' => $row->post_modified,
        );
    }

    error_log('[PUNTWORK] [DB-DEBUG] Processed ' . count($posts_by_guid) . ' unique GUIDs');

    // Cache for 10 minutes - GUID lookups change relatively frequently during imports
    CacheManager::set($cache_key, $posts_by_guid, CacheManager::GROUP_ANALYTICS, 10 * MINUTE_IN_SECONDS);

    return $posts_by_guid;
}

/**
 * Get database optimization status
 *
 * @return array Status information
 */
function get_database_optimization_status(): array
{
    global $wpdb;

    $indexes = array(
    'idx_postmeta_guid'              => false,
    'idx_postmeta_import_hash'       => false,
    'idx_postmeta_last_update'       => false,
    'idx_posts_job_status'           => false,
    'idx_postmeta_feed_url'          => false,
    'idx_posts_job_date'             => false,
    'idx_posts_job_title'            => false,
    'idx_performance_operation_time' => false,
    'idx_performance_duration'       => false,
    );

    // Check which indexes exist
    $existing_indexes = $wpdb->get_col(
        "
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('{$wpdb->postmeta}', '{$wpdb->posts}', '{$wpdb->prefix}puntwork_performance_logs')
        AND INDEX_NAME IN ('" . implode("','", array_keys($indexes)) . "')
    "
    );

    foreach ( $existing_indexes as $index ) {
        $indexes[ $index ] = true;
    }

    $missing_indexes = array_filter(
        $indexes,
        function ( $exists ) {
            return ! $exists;
        }
    );

    return array(
    'indexes_created'       => count($indexes) - count($missing_indexes),
    'total_indexes'         => count($indexes),
    'missing_indexes'       => array_keys($missing_indexes),
    'optimization_complete' => empty($missing_indexes),
    );
}
