<?php
/**
 * Helpers file for job import plugin.
 * Utility functions for feed querying and other tasks.
 *
 * @package JobImport
 * @version 1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Retrieve all published job-feed CPT posts.
 *
 * @return array|WP_Post[] Array of job-feed posts.
 */
function job_import_get_job_feeds() {
    $feeds = get_posts( array(
        'post_type' => 'job-feed',
        'post_status' => 'publish',
        'posts_per_page' => -1, // Fetch all
        'orderby' => 'modified',
        'order' => 'DESC',
        'fields' => 'all'
    ) );

    return $feeds;
}

/**
 * Sanitize and validate a feed URL.
 *
 * @param string $url The URL to validate.
 * @return string|false Valid URL or false.
 */
function job_import_validate_feed_url( $url ) {
    $url = filter_var( $url, FILTER_SANITIZE_URL );
    if ( filter_var( $url, FILTER_VALIDATE_URL ) && strpos( $url, 'xml' ) !== false ) { // Basic RSS/XML check
        return $url;
    }
    return false;
}

// Additional existing helpers can be added here if present in current version
// e.g., function job_import_log_error( $message ) { error_log( '[Job Import] ' . $message ); }
