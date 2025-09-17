<?php
/**
 * Helper functions for Job Import Plugin
 * Based on old WPCode snippets utilities (1.2, 1.6, 1.7).
 * Enhanced: Added log_import_event() definition (file append to import.log with levels).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/mappings.php'; // For maps

// Define log function if not exists (fallback)
if ( ! function_exists( 'log_import_event' ) ) {
    function log_import_event( $message, $level = 'info' ) {
        $log_file = JOB_IMPORT_LOGS;
        $timestamp = current_time( 'mysql' );
        $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        error_log( $log_entry, 3, $log_file ); // Append to file
        // Also console if WP_DEBUG
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( $log_entry );
        }
    }
}

/**
 * Get all published job-feed URLs from ACF CPT.
 *
 * @return array Array of feed URLs, keyed by post ID for logging.
 */
function get_job_feed_urls() {
    log_import_event( 'HELPER: Fetching job-feed URLs', 'debug' );

    if ( ! class_exists( 'ACF' ) ) {
        log_import_event( 'HELPER: ACF not active, skipping feed fetch.', 'error' );
        return array();
    }

    $feeds = array();
    $query = new WP_Query( array(
        'post_type'         => 'job-feed',
        'post_status'       => 'publish',
        'posts_per_page'    => -1,
        'fields'            => 'ids',
    ) );

    if ( $query->have_posts() ) {
        foreach ( $query->posts as $post_id ) {
            $feed_url = get_field( 'feed_url', $post_id ); // ACF field
            if ( ! empty( $feed_url ) && filter_var( $feed_url, FILTER_VALIDATE_URL ) ) {
                $feeds[ $post_id ] = $feed_url;
            } else {
                log_import_event( "HELPER: Invalid or missing feed_url for job-feed post ID {$post_id}", 'warn' );
            }
        }
    }

    wp_reset_postdata();
    log_import_event( 'HELPER: Fetched ' . count( $feeds ) . ' valid feed URLs', 'info' );
    return $feeds;
}

/**
 * Get last run timestamp for a specific feed (stored as post meta).
 *
 * @param int $post_id Job-feed post ID.
 * @return string Timestamp or empty.
 */
function get_feed_last_run( $post_id ) {
    return get_post_meta( $post_id, '_feed_last_run', true );
}

/**
 * Update last run timestamp for a feed.
 *
 * @param int $post_id Job-feed post ID.
 * @return bool True on success.
 */
function update_feed_last_run( $post_id ) {
    $success = update_post_meta( $post_id, '_feed_last_run', current_time( 'mysql' ) );
    if ( $success ) {
        log_import_event( "HELPER: Updated last_run for feed {$post_id}", 'debug' );
    }
    return $success;
}

/**
 * Clean item data (ported/adapted from old 1.6 - Item Cleaning.php).
 * Sanitizes and trims fields, handles common XML quirks.
 *
 * @param array $data Raw item data from XML.
 * @return array Cleaned data.
 */
function clean_item( $data ) {
    $clean = array();
    foreach ( $data as $key => $value ) {
        if ( is_array( $value ) ) {
            $clean[ $key ] = clean_item( $value ); // Recursive for nested
        } else {
            $clean[ $key ] = trim( sanitize_text_field( (string) $value ) );
        }
    }
    // Remove empty fields
    return array_filter( $clean, function( $v ) { return ! empty( $v ); } );
}

/**
 * Infer additional details for item (ported from old 1.7 - Item Inference.php).
 * Handles salary estimates, benefits regex, skills, enhanced title, etc.
 *
 * @param array $item Cleaned item data.
 * @param string $fallback_domain Default domain (e.g., 'be').
 * @param string $lang Language (e.g., 'nl', 'fr', 'en').
 * @return array Inferred data merged with original.
 */
function infer_item_details( $item, $fallback_domain = 'be', $lang = 'en' ) {
    log_import_event( 'INFER: Starting inference for item (title: ' . ($item['functiontitle'] ?? 'unknown') . ')', 'debug' );

    $inferred = $item;

    $province = strtolower( trim( $item['province'] ?? '' ) );
    $norm_province = get_province_map()[ $province ] ?? $fallback_domain;

    $title = $item['functiontitle'] ?? '';
    $enhanced_title = $title;
    if ( isset( $item['city'] ) ) {
        $enhanced_title .= ' in ' . $item['city'];
    }
    if ( isset( $item['province'] ) ) {
        $enhanced_title .= ', ' . $item['province'];
    }
    $enhanced_title = trim( $enhanced_title );
    $slug = sanitize_title( $enhanced_title . '-' . ( $item['guid'] ?? '' ) );
    $inferred['job_link'] = 'https://' . $norm_province . '/job/' . $slug;

    $fg = strtolower( trim( $item['functiongroup'] ?? '' ) );
    $salary_estimates = get_salary_estimates();
    foreach ( $salary_estimates as $key => $estimate ) {
        if ( strpos( $fg, strtolower( $key ) ) !== false ) {
            $inferred['salary_estimate'] = $estimate;
            log_import_event( 'INFER: Added salary estimate ' . $estimate . ' for functiongroup: ' . $fg, 'debug' );
            break;
        }
    }

    // Additional inferences: benefits regex, skills extraction, etc. (expand as needed)
    if ( isset( $item['description'] ) ) {
        // Example: Extract skills/benefits via regex
        preg_match_all( '/(benefit|perk):?\s*([^\.]+)/i', $item['description'], $matches );
        if ( ! empty( $matches[2] ) ) {
            $inferred['benefits'] = implode( '; ', $matches[2] );
        }
    }

    log_import_event( 'INFER: Completed for item (added ' . count( array_diff_key( $inferred, $item ) ) . ' fields)', 'debug' );
    return $inferred;
}
?>
