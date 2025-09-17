<?php
/**
 * Processor for Job Import Plugin
 * Handles downloading feeds, processing XML, cleaning/inferring data, and importing to 'job' CPT.
 * Refactored from old WPCode snippets 1.8, 1.9, 2.3-2.5.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/helpers.php'; // Ensure helpers are loaded

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
            // Skip if run today (adjust interval as needed, e.g., via option)
            if ( date( 'Y-m-d', strtotime( $last_run ) ) === date( 'Y-m-d' ) ) {
                continue;
            }
        }

        $result = process_single_feed( $feed_url, $post_id );
        $stats['processed']++;
        $stats['imported'] += $result['imported'];
        $stats['errors'] += $result['errors'];

        update_feed_last_run( $post_id ); // Update after processing
    }

    update_option( 'job_import_last_run', current_time( 'mysql' ) ); // Global last run for shortcode
    error_log( 'Job Import: Batch complete. Stats: ' . print_r( $stats, true ) );

    return $stats;
}

/**
 * Process a single feed URL: Download, parse XML, import items.
 *
 * @param string $feed_url XML feed URL.
 * @param int $post_id Job-feed post ID for logging.
 * @return array Local stats.
 */
function process_single_feed( $feed_url, $post_id ) {
    // Download feed (from old 1.8 snippet)
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

    // Handle gzip if needed (from old snippets)
    if ( substr( $body, 0, 2 ) === "\x1f\x8b" ) {
        $body = gzdecode( $body );
    }

    // Parse XML (from old 1.9 snippet)
    $xml = simplexml_load_string( $body );
    if ( ! $xml ) {
        error_log( "Job Import: XML parse error for feed {$post_id}" );
        return array( 'imported' => 0, 'errors' => 1 );
    }

    // Assume XML structure like <jobs><job>...</job></jobs> – adjust xpath as per your feeds/mappings.php
    $items = $xml->xpath( '//job' ); // Example; use mappings for real paths
    $batch_size = 50; // Define in constants.php or here

    $local_stats = array( 'imported' => 0, 'errors' => 0 );
    $batches = array_chunk( $items, $batch_size );

    foreach ( $batches as $batch ) {
        import_batch_items( $batch, $post_id );
    }

    return $local_stats;
}

/**
 * Import a batch of XML items to 'job' CPT (from old 2.3/2.5 snippets).
 *
 * @param array $items SimpleXML items.
 * @param int $feed_post_id For meta/logging.
 */
function import_batch_items( $items, $feed_post_id ) {
    global $wpdb;

    foreach ( $items as $item ) {
        // Clean item (call helpers from 1.6)
        $clean_data = clean_item( (array) $item );

        // Infer data (from 1.7: salary, benefits, etc.)
        $inferred = infer_item_data( $clean_data );

        // Check duplicates (from 2.4: e.g., by title + company)
        if ( is_duplicate_job( $inferred['title'], $inferred['company'] ) ) {
            continue;
        }

        // Create/update 'job' post
        $job_id = wp_insert_post( array(
            'post_type'   => 'job',
            'post_title'  => $inferred['title'],
            'post_status' => 'publish',
            'meta_input'  => $inferred, // All fields as meta
        ) );

        if ( is_wp_error( $job_id ) ) {
            error_log( "Job Import: Insert error for item in feed {$feed_post_id}" );
            continue;
        }

        // Link to feed
        update_post_meta( $job_id, '_source_feed', $feed_post_id );

        // Increment stats
        // (Update local_stats in caller)
    }
}

/**
 * Placeholder for duplicate check (implement from old 2.4).
 *
 * @param string $title
 * @param string $company
 * @return bool
 */
function is_duplicate_job( $title, $company ) {
    // Query 'job' posts with similar title/company meta
    $exists = get_posts( array(
        'post_type' => 'job',
        'meta_query' => array(
            'relation' => 'AND',
            array( 'key' => 'title', 'value' => $title, 'compare' => '=' ),
            array( 'key' => 'company', 'value' => $company, 'compare' => '=' ),
        ),
        'posts_per_page' => 1,
    ) );
    return ! empty( $exists );
}

// Placeholder helpers (port from snippets if not in helpers.php)
function clean_item( $data ) {
    // Sanitize, trim, etc. from 1.6
    return array_map( 'sanitize_text_field', $data );
}

function infer_item_data( $data ) {
    // Salary inference, benefits regex from 1.7
    $inferred = $data;
    $inferred['salary'] = infer_salary( $data['description'] ?? '' );
    $inferred['benefits'] = detect_benefits( $data['description'] ?? '' );
    return $inferred;
}

function infer_salary( $text ) {
    // Regex example from snippet: match €50k-€70k, fallback averages
    preg_match( '/€?(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)(?:\s*-\s*€?(\d{1,3}(?:,\d{3})*(?:\.\d{2})?))?/i', $text, $matches );
    // ... calculate average, etc.
    return $matches ? ( (float) $matches[1] + (float) ($matches[2] ?? $matches[1]) ) / 2 : 0;
}

function detect_benefits( $text ) {
    $benefits = array();
    if ( preg_match( '/(remote|home office)/i', $text ) ) $benefits[] = 'remote';
    if ( preg_match( '/(company car|leasing)/i', $text ) ) $benefits[] = 'company_car';
    // Add more from snippet regex
    return implode( ', ', $benefits );
}
