<?php
/**
 * Processor for Job Import Plugin
 * Handles downloading feeds, processing XML, cleaning/inferring data, and importing to 'job' CPT.
 * Refactored from old WPCode snippets 1.8, 1.9, 2.1-2.5.
 * Enhanced: Added detailed log_import_event for full flow (download size, item count, per-batch import).
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
    log_import_event( 'PROCESSOR: Batch import STARTED (force: ' . ($force ? 'yes' : 'no') . ')', 'info' );

    set_transient( 'job_import_running', true, 300 );
    set_transient( 'job_import_progress', 0 );

    $feeds = get_job_feed_urls();
    if ( empty( $feeds ) ) {
        log_import_event( 'PROCESSOR: No valid job-feed URLs found.', 'error' );
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
                log_import_event( "PROCESSOR: Skipping feed {$post_id} (already run today)", 'debug' );
                continue;
            }
        }

        log_import_event( "PROCESSOR: Processing feed {$post_id} ({$feed_url})", 'info' );
        $result = process_single_feed( $feed_url, $post_id );
        $stats['processed']++;
        $stats['imported'] += $result['imported'];
        $stats['errors'] += $result['errors'];

        $current++;
        set_transient( 'job_import_progress', round( ( $current / $total_feeds ) * 100 ) );

        update_feed_last_run( $post_id );
    }

    update_option( 'job_import_last_run', current_time( 'mysql' ) );
    log_import_event( 'PROCESSOR: Batch COMPLETE. Stats: ' . print_r( $stats, true ), 'info' );
    delete_transient( 'job_import_running' );
    set_transient( 'job_import_progress', 100 );

    // Trigger JSON export (from 2.2)
    require_once __DIR__ . '/exporter.php';
    export_all_jobs_to_json();

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
    log_import_event( "PROCESSOR: Single feed STARTED for {$post_id} ({$feed_url})", 'info' );

    $cache_key = 'job_feed_cache_' . md5( $feed_url );
    $cached_body = get_transient( $cache_key );

    if ( false === $cached_body ) {
        log_import_event( "PROCESSOR: Downloading fresh feed for {$post_id}", 'debug' );
        $response = wp_remote_get( $feed_url, array(
            'timeout' => 30,
            'user-agent' => 'Job-Import-Plugin/1.0',
        ) );

        if ( is_wp_error( $response ) ) {
            log_import_event( "PROCESSOR: Download ERROR for feed {$post_id}: " . $response->get_error_message(), 'error' );
            return array( 'imported' => 0, 'errors' => 1 );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( wp_remote_retrieve_response_code( $response ) !== 200 || empty( $body ) ) {
            log_import_event( "PROCESSOR: Invalid response for feed {$post_id} (code: " . wp_remote_retrieve_response_code( $response ) . ")", 'error' );
            return array( 'imported' => 0, 'errors' => 1 );
        }

        // Gzip handling and cache (enhanced from 2.1)
        if ( substr( $body, 0, 2 ) === "\x1f\x8b" ) {
            $body = gzdecode( $body );
        }
        set_transient( $cache_key, $body, JOB_IMPORT_CACHE_TTL );
        log_import_event( "PROCESSOR: Downloaded and cached feed {$post_id}, size: " . strlen( $body ) . " bytes", 'info' );
    } else {
        $body = $cached_body;
        log_import_event( "PROCESSOR: Using cached feed for {$post_id}, size: " . strlen( $body ) . " bytes", 'info' );
    }

    $xml = simplexml_load_string( $body );
    if ( ! $xml ) {
        log_import_event( "PROCESSOR: XML parse ERROR for feed {$post_id}", 'error' );
        return array( 'imported' => 0, 'errors' => 1 );
    }

    $jobs = $xml->xpath( '//job' ) ?: $xml->channel->item; // Flexible XPath for common XML structures
    $job_count = count( $jobs );
    log_import_event( "PROCESSOR: Parsed XML for feed {$post_id}, found {$job_count} jobs", 'info' );

    if ( $job_count === 0 ) {
        log_import_event( "PROCESSOR: No jobs in XML for feed {$post_id}", 'warn' );
        return array( 'imported' => 0, 'errors' => 0 );
    }

    global $job_import_batch_limit;
    $imported = 0;
    $errors = 0;
    $batches = ceil( $job_count / $job_import_batch_limit );

    for ( $i = 0; $i < $batches; $i++ ) {
        $batch_start = $i * $job_import_batch_limit;
        $batch_jobs = array_slice( (array) $jobs, $batch_start, $job_import_batch_limit );
        log_import_event( "PROCESSOR: Processing batch " . ($i + 1) . "/{$batches} for feed {$post_id} (jobs " . ($batch_start + 1) . "-" . min( $batch_start + $job_import_batch_limit, $job_count ) . ")", 'debug' );

        foreach ( $batch_jobs as $job_node ) {
            $raw_data = (array) $job_node;
            $clean_data = clean_item( $raw_data );
            $inferred_data = infer_item_details( $clean_data );

            $job_id = import_job_post( $inferred_data, $post_id );
            if ( $job_id ) {
                $imported++;
                log_import_event( "PROCESSOR: Imported job ID {$job_id} from feed {$post_id} (title: {$inferred_data['functiontitle']})", 'debug' );
            } else {
                $errors++;
                log_import_event( "PROCESSOR: Failed to import job from feed {$post_id} (title: {$inferred_data['functiontitle']})", 'error' );
            }
        }
    }

    log_import_event( "PROCESSOR: Single feed COMPLETE for {$post_id}: imported={$imported}, errors={$errors}", 'info' );

    return array( 'imported' => $imported, 'errors' => $errors );
}

// Placeholder for import_job_post (assume defined in mappings or similar; add if missing)
function import_job_post( $data, $feed_id ) {
    // Upsert logic: Check if exists by guid/external_id, update or insert as 'job' CPT
    $existing = get_posts( array(
        'post_type' => 'job',
        'meta_key' => '_external_guid',
        'meta_value' => $data['guid'] ?? '',
        'posts_per_page' => 1,
    ) );

    $post_data = array(
        'post_title' => $data['functiontitle'] ?? 'Untitled Job',
        'post_type' => 'job',
        'post_status' => 'publish',
    );

    if ( ! empty( $existing ) ) {
        $post_data['ID'] = $existing[0]->ID;
    }

    $post_id = wp_insert_post( $post_data );
    if ( is_wp_error( $post_id ) ) {
        return false;
    }

    // Save ACF/meta fields (e.g., salary, location, etc.)
    foreach ( $data as $key => $value ) {
        update_field( $key, $value, $post_id ); // ACF
    }
    update_post_meta( $post_id, '_feed_source', $feed_id );

    return $post_id;
}
?>
