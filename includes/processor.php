<?php
/**
 * Processor file for job import plugin.
 * Handles fetching and processing of job feeds.
 *
 * @package JobImport
 * @version 1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __FILE__ ) . '/helpers.php';
require_once dirname( __FILE__ ) . '/mappings.php';

/**
 * Main function to process all job feeds.
 * Queries 'job-feed' CPT, fetches each URL, parses XML, maps data, and imports to 'job' CPT.
 */
function job_import_process_feeds() {
    $feeds = job_import_get_job_feeds(); // Get all published job-feed posts

    if ( empty( $feeds ) ) {
        error_log( 'Job Import: No job-feed posts found to process.' );
        return;
    }

    foreach ( $feeds as $feed_post ) {
        $feed_url = get_field( 'feed_url', $feed_post->ID ); // ACF 'feed_url' field
        if ( empty( $feed_url ) ) {
            error_log( 'Job Import: Missing feed_url for job-feed post ID ' . $feed_post->ID );
            continue;
        }

        // Fetch feed with timeout and error handling
        $response = wp_remote_get( $feed_url, array( 'timeout' => 30 ) );
        if ( is_wp_error( $response ) ) {
            error_log( 'Job Import: Failed to fetch feed ' . esc_url( $feed_url ) . ' - ' . $response->get_error_message() );
            continue;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            error_log( 'Job Import: Empty response from ' . esc_url( $feed_url ) );
            continue;
        }

        // Parse XML
        $xml = simplexml_load_string( $body );
        if ( ! $xml || ! isset( $xml->channel ) ) {
            error_log( 'Job Import: Invalid XML from ' . esc_url( $feed_url ) );
            continue;
        }

        // Process each item in the feed
        foreach ( $xml->channel->item as $item ) {
            $job_data = job_import_map_feed_item( $item ); // Mapping function from mappings.php
            if ( empty( $job_data['title'] ) || empty( $job_data['guid'] ) ) {
                continue; // Skip invalid items
            }

            // Check for duplicates using GUID meta
            $existing_jobs = get_posts( array(
                'post_type' => JOB_POST_TYPE, // From constants.php
                'post_status' => 'any',
                'meta_query' => array(
                    array(
                        'key' => 'job_guid',
                        'value' => $job_data['guid'],
                        'compare' => '='
                    )
                ),
                'posts_per_page' => 1,
                'fields' => 'ids'
            ) );

            if ( ! empty( $existing_jobs ) ) {
                error_log( 'Job Import: Skipping duplicate job with GUID ' . $job_data['guid'] );
                continue;
            }

            // Insert new job post
            $post_data = array(
                'post_title' => sanitize_text_field( $job_data['title'] ),
                'post_content' => wp_kses_post( $job_data['description'] ),
                'post_type' => JOB_POST_TYPE,
                'post_status' => 'publish',
                'post_date' => current_time( 'mysql' )
            );

            $post_id = wp_insert_post( $post_data );
            if ( is_wp_error( $post_id ) ) {
                error_log( 'Job Import: Failed to insert job post - ' . $post_id->get_error_message() );
                continue;
            }

            // Update ACF fields
            foreach ( $acf_fields as $field_key ) { // From mappings.php
                if ( isset( $job_data[ $field_key ] ) ) {
                    update_field( $field_key, $job_data[ $field_key ], $post_id );
                }
            }

            // Update taxonomies
            foreach ( $taxonomies as $tax_key ) { // From mappings.php
                if ( isset( $job_data[ $tax_key ] ) && ! empty( $job_data[ $tax_key ] ) ) {
                    $terms = is_array( $job_data[ $tax_key ] ) ? $job_data[ $tax_key ] : array( $job_data[ $tax_key ] );
                    wp_set_object_terms( $post_id, $terms, $tax_key );
                }
            }

            // Store GUID for future dedup
            update_post_meta( $post_id, 'job_guid', sanitize_text_field( $job_data['guid'] ) );
            update_post_meta( $post_id, 'job_feed_source', $feed_post->ID ); // Track source feed

            error_log( 'Job Import: Successfully imported job ID ' . $post_id . ' from ' . esc_url( $feed_url ) );
        }
    }
}
