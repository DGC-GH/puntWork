<?php
/**
 * Processor for Job Import Plugin
 * Handles downloading feeds, processing XML, cleaning/inferring data, and importing to 'job' CPT.
 * Refactored from old WPCode snippets 1.8, 1.9, 2.1-2.5.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/helpers.php'; // Ensure helpers loaded
require_once __DIR__ . '/mappings.php';

/**
 * Main import process: Fetch and process all feeds dynamically.
 *
 * @param bool $force Skip last-run check (for manual/AJAX).
 * @return array Stats: processed feeds, imported jobs, errors.
 */
function process_all_imports( $force = false ) {
    set_transient( 'job_import_running', true, 300 );
    set_transient( 'job_import_progress', 0 );

    $feeds = get_job_feed_urls();
    if ( empty( $feeds ) ) {
        log_import_event( 'No valid job-feed URLs found.', 'error' );
        delete_transient( 'job_import_running' );
        return array( 'processed' => 0, 'imported' => 0, 'errors' => 1 );
    }

    $stats = array( 'processed' => 0, 'imported' => 0, 'errors' => 0 );
    $total_feeds = count( $feeds );
    $current = 0;

    foreach ( $feeds as $post_id => $feed_url ) {
        $last_run = get_feed_last_run( $post_id );
        if ( ! $force && ! empty( $last_run ) ) {
            if ( date( 'Y-m-d', strtotime( $last_run ) ) === date( 'Y-m-d' ) ) {
                continue;
            }
        }

        $result = process_single_feed( $feed_url, $post_id );
        $stats['processed']++;
        $stats['imported'] += $result['imported'];
        $stats['errors'] += $result['errors'];

        $current++;
        set_transient( 'job_import_progress', round( ( $current / $total_feeds ) * 100 ) );

        update_feed_last_run( $post_id );
    }

    update_option( 'job_import_last_run', current_time( 'mysql' ) );
    log_import_event( 'Batch complete. Stats: ' . print_r( $stats, true ) );
    delete_transient( 'job_import_running' );
    set_transient( 'job_import_progress', 100 );

    // Trigger JSONL export (from 2.2)
    require_once __DIR__ . '/exporter.php';
    export_all_jobs_to_jsonl();

    return $stats;
}

/**
 * Process a single feed URL: Download/cache, parse XML, import items in batches.
 *
 * @param string $feed_url XML feed URL.
 * @param int $post_id Job-feed post ID for logging.
 * @return array Local stats.
 */
function process_single_feed( $feed_url, $post_id ) {
    $cache_key = 'job_feed_cache_' . md5( $feed_url );
    $cached_body = get_transient( $cache_key );

    if ( false === $cached_body ) {
        $response = wp_remote_get( $feed_url, array(
            'timeout' => 30,
            'user-agent' => 'Job-Import-Plugin/1.0',
        ) );

        if ( is_wp_error( $response ) ) {
            log_import_event( "Download error for feed {$post_id}: " . $response->get_error_message(), 'error' );
            return array( 'imported' => 0, 'errors' => 1 );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( wp_remote_retrieve_response_code( $response ) !== 200 || empty( $body ) ) {
            log_import_event( "Invalid response for feed {$post_id}", 'error' );
            return array( 'imported' => 0, 'errors' => 1 );
        }

        // Gzip handling and cache (enhanced from 2.1)
        if ( substr( $body, 0, 2 ) === "\x1f\x8b" ) {
            $body = gzdecode( $body );
        }
        set_transient( $cache_key, $body, JOB_IMPORT_CACHE_TTL );
    } else {
        $body = $cached_body;
        log_import_event( "Using cached feed for {$post_id}", 'info' );
    }

    $xml = simplexml_load_string( $body );
    if ( ! $xml ) {
        log_import_event( "XML parse error for feed {$post_id}", 'error' );
        return array( 'imported' => 0, 'errors' => 1 );
    }

    $items = $xml->xpath( '//job' ); // Adjust per mappings
    $batches = array_chunk( $items, JOB_IMPORT_BATCH_SIZE );

    $local_stats = array( 'imported' => 0, 'errors' => 0 );
    foreach ( $batches as $index => $batch ) {
        $batch_result = import_batch_items( $batch, $post_id );
        $local_stats['imported'] += $batch_result['imported'];
        $local_stats['errors'] += $batch_result['errors'];
        log_import_event( "Processed batch {$index} for feed {$post_id}: {$batch_result['imported']} imported.", 'info' );
    }

    return $local_stats;
}

/**
 * Import a batch of XML items to 'job' CPT (from old 2.3/2.5).
 *
 * @param array $items SimpleXML items.
 * @param int $feed_post_id For meta/logging.
 * @return array Batch stats.
 */
function import_batch_items( $items, $feed_post_id ) {
    $batch_stats = array( 'imported' => 0, 'errors' => 0 );

    foreach ( $items as $item ) {
        $raw_data = (array) $item;
        $clean_data = clean_item( $raw_data );
        $inferred = infer_item_details( $clean_data, 'be', 'en' );

        if ( is_duplicate_job( $inferred['functiontitle'] ?? '', $inferred['company'] ?? '' ) ) {
            continue;
        }

        $job_id = wp_insert_post( array(
            'post_type'   => 'job',
            'post_title'  => $inferred['functiontitle'] ?? 'Untitled Job',
            'post_status' => 'publish',
            'post_content' => $inferred['job_desc'] ?? '',
            'meta_input'  => $inferred,
        ) );

        if ( is_wp_error( $job_id ) ) {
            log_import_event( "Insert error for item in feed {$feed_post_id}", 'error' );
            $batch_stats['errors']++;
            continue;
        }

        update_post_meta( $job_id, '_source_feed', $feed_post_id );
        $batch_stats['imported']++;
    }

    return $batch_stats;
}

/**
 * Check for duplicate job (enhanced from old 2.4 - fuzzy title match).
 *
 * @param string $title
 * @param string $company
 * @return bool
 */
function is_duplicate_job( $title, $company ) {
    $args = array(
        'post_type' => 'job',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => 'company',
                'value' => $company,
                'compare' => 'LIKE',
            ),
            array(
                'key' => 'functiontitle',
                'value' => $title,
                'compare' => 'LIKE',
            ),
        ),
    );

    $exists = get_posts( $args );
    return ! empty( $exists );
}
