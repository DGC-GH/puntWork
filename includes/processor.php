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

/**
 * Main import process: Fetch and process all feeds dynamically.
 *
 * @param bool $force Skip last-run check (for manual/AJAX).
 * @return array Stats: processed feeds, imported jobs, errors.
 */
function process_all_imports( $force = false ) {
    $feeds = get_job_feed_urls();
    if ( empty( $feeds ) ) {
        error_log( 'Job Import: No valid job-feed URLs found.' );
        return array( 'processed' => 0, 'imported' => 0, 'errors' => 1 );
    }

    $stats = array( 'processed' => 0, 'imported' => 0, 'errors' => 0 );

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

        update_feed_last_run( $post_id );
    }

    update_option( 'job_import_last_run', current_time( 'mysql' ) );
    error_log( 'Job Import: Batch complete. Stats: ' . print_r( $stats, true ) );

    return $stats;
}

/**
 * Process a single feed URL: Download, parse XML, import items in batches.
 *
 * @param string $feed_url XML feed URL.
 * @param int $post_id Job-feed post ID for logging.
 * @return array Local stats.
 */
function process_single_feed( $feed_url, $post_id ) {
    $response = wp_remote_get( $feed_url, array(
        'timeout' => 30,
        'user-agent' => 'Job-Import-Plugin/1.0',
    ) );

    if ( is_wp_error( $response ) ) {
        error_log( "Job Import: Download error for feed {$post_id}: " . $response->get_error_message() );
        return array( 'imported' => 0, 'errors' => 1 );
    }

    $body = wp_remote_retrieve_body( $response );
    if ( wp_remote_retrieve_response_code( $response ) !== 200 || empty( $body ) ) {
        error_log( "Job Import: Invalid response for feed {$post_id}" );
        return array( 'imported' => 0, 'errors' => 1 );
    }

    // Gzip handling (from old 2.1)
    if ( substr( $body, 0, 2 ) === "\x1f\x8b" ) {
        $body = gzdecode( $body );
    }

    $xml = simplexml_load_string( $body );
    if ( ! $xml ) {
        error_log( "Job Import: XML parse error for feed {$post_id}" );
        return array( 'imported' => 0, 'errors' => 1 );
    }

    $items = $xml->xpath( '//job' ); // Adjust per mappings.php
    $batch_size = 50;
    $batches = array_chunk( $items, $batch_size );

    $local_stats = array( 'imported' => 0, 'errors' => 0 );
    foreach ( $batches as $index => $batch ) {
        $batch_result = import_batch_items( $batch, $post_id );
        $local_stats['imported'] += $batch_result['imported'];
        $local_stats['errors'] += $batch_result['errors'];
        error_log( "Job Import: Processed batch {$index} for feed {$post_id}: {$batch_result['imported']} imported." );
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
        $clean_data = clean_item( $raw_data ); // From 1.6 port
        $inferred = infer_item_details( $clean_data, 'be', 'en' ); // From 1.7 port

        if ( is_duplicate_job( $inferred['functiontitle'] ?? '', $inferred['company'] ?? '' ) ) { // From 2.4
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
            error_log( "Job Import: Insert error for item in feed {$feed_post_id}" );
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
        ),
    );

    // Fuzzy title via LIKE
    global $wpdb;
    $args['meta_query'][] = array(
        'key' => 'functiontitle',
        'value' => $title,
        'compare' => 'LIKE',
    );

    $exists = get_posts( $args );
    return ! empty( $exists );
}
