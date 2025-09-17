<?php
/**
 * Helper functions for Job Import Plugin
 * Based on old WPCode snippets utilities.
 */

// Existing helpers (if any) go here... e.g., clean_item(), infer_salary(), etc. from snippets 1.6/1.7

/**
 * Get all published job-feed URLs from ACF CPT.
 *
 * @return array Array of feed URLs, keyed by post ID for logging.
 */
function get_job_feed_urls() {
    if ( ! class_exists( 'ACF' ) ) {
        error_log( 'Job Import: ACF not active, skipping feed fetch.' );
        return array();
    }

    $feeds = array();
    $query = new WP_Query( array(
        'post_type'      => 'job-feed',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ) );

    if ( $query->have_posts() ) {
        foreach ( $query->posts as $post_id ) {
            $feed_url = get_field( 'feed_url', $post_id ); // ACF field
            if ( ! empty( $feed_url ) && filter_var( $feed_url, FILTER_VALIDATE_URL ) ) {
                $feeds[ $post_id ] = $feed_url;
            } else {
                error_log( "Job Import: Invalid or missing feed_url for job-feed post ID {$post_id}" );
            }
        }
    }

    wp_reset_postdata();
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
    return update_post_meta( $post_id, '_feed_last_run', current_time( 'mysql' ) );
}
