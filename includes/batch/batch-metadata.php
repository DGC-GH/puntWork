<?php

/**
 * Batch metadata handling utilities
 *
 * @package    Puntwork
 * @subpackage Batch
 * @since      1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Prepare metadata for batch processing.
 */
function prepare_batch_metadata(array $post_ids_by_guid): array
{
    global $wpdb;

    $post_ids = array_values($post_ids_by_guid);
    if (empty($post_ids)) {
        return array(
            'last_updates'   => array(),
            'hashes_by_post' => array(),
        );
    }

    $max_chunk_size = 50;
    $post_id_chunks = array_chunk($post_ids, $max_chunk_size);

    // Get last updates with caching
    $last_updates = get_cached_last_updates($post_ids, $post_id_chunks);

    // Get import hashes with caching
    $hashes_by_post = get_cached_import_hashes($post_ids, $post_id_chunks);

    return array(
        'last_updates'   => $last_updates,
        'hashes_by_post' => $hashes_by_post,
    );
}

/**
 * Get cached last updates for posts.
 */
function get_cached_last_updates(array $post_ids, array $post_id_chunks): array
{
    global $wpdb;

    sort($post_ids);
    $cache_key = 'batch_last_updates_' . md5(implode(',', $post_ids));
    $cached    = \Puntwork\Utilities\CacheManager::get($cache_key, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS);

    if ($cached !== false) {
        error_log('[PUNTWORK] [DB-DEBUG] Returning cached last updates for ' . count($post_ids) . ' posts');
        return $cached;
    }

    error_log('[PUNTWORK] [DB-DEBUG] Cache miss, querying last updates for ' . count($post_ids) . ' posts');

    $last_updates = array();
    foreach ($post_id_chunks as $chunk) {
        if (empty($chunk)) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
        $query        = $wpdb->prepare(
            "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_last_import_update' AND post_id IN ($placeholders)",
            $chunk
        );

        error_log('[PUNTWORK] [DB-DEBUG] Executing last updates query for chunk of ' . count($chunk) . ' posts');

        $start_time = microtime(true);
        $chunk_last = $wpdb->get_results($query, OBJECT_K);
        $query_time = microtime(true) - $start_time;

        error_log('[PUNTWORK] [DB-DEBUG] Last updates query returned ' . count($chunk_last) . ' results in ' . number_format($query_time, 4) . ' seconds');

        $last_updates += (array) $chunk_last;
    }

    error_log('[PUNTWORK] [DB-DEBUG] Total last updates retrieved: ' . count($last_updates));

    // Cache for 5 minutes during import processing
    \Puntwork\Utilities\CacheManager::set($cache_key, $last_updates, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS, 5 * MINUTE_IN_SECONDS);
    return $last_updates;
}

/**
 * Get cached import hashes for posts.
 */
function get_cached_import_hashes(array $post_ids, array $post_id_chunks): array
{
    global $wpdb;

    $cache_key = 'batch_import_hashes_' . md5(implode(',', $post_ids));
    $cached    = \Puntwork\Utilities\CacheManager::get($cache_key, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS);

    if ($cached !== false) {
        error_log('[PUNTWORK] [DB-DEBUG] Returning cached import hashes for ' . count($post_ids) . ' posts');
        return $cached;
    }

    error_log('[PUNTWORK] [DB-DEBUG] Cache miss, querying import hashes for ' . count($post_ids) . ' posts');

    $hashes_by_post = array();
    foreach ($post_id_chunks as $chunk) {
        if (empty($chunk)) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
        $query        = $wpdb->prepare(
            "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_import_hash' AND post_id IN ($placeholders)",
            $chunk
        );

        error_log('[PUNTWORK] [DB-DEBUG] Executing import hashes query for chunk of ' . count($chunk) . ' posts');

        $start_time   = microtime(true);
        $chunk_hashes = $wpdb->get_results($query, OBJECT_K);
        $query_time   = microtime(true) - $start_time;

        error_log('[PUNTWORK] [DB-DEBUG] Import hashes query returned ' . count($chunk_hashes) . ' results in ' . number_format($query_time, 4) . ' seconds');

        foreach ($chunk_hashes as $id => $obj) {
            $hashes_by_post[ $id ] = $obj->meta_value;
        }
    }

    error_log('[PUNTWORK] [DB-DEBUG] Total import hashes retrieved: ' . count($hashes_by_post));

    // Cache for 5 minutes during import processing
    \Puntwork\Utilities\CacheManager::set($cache_key, $hashes_by_post, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS, 5 * MINUTE_IN_SECONDS);
    return $hashes_by_post;
}
