<?php
/**
 * Core structure and logic for job import plugin
 *
 * @package    Puntwork
 * @subpackage Core
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function get_feeds() {
    // Clear cache for debugging
    delete_transient('puntwork_feeds');
    
    $feeds = get_transient('puntwork_feeds');
    if (false === $feeds) {
        $feeds = [];
        
        // First, check if CPT is registered
        if (!post_type_exists('job-feed')) {
            error_log('[PUNTWORK] get_feeds() - ERROR: job-feed post type is not registered!');
        
        // Try alternative: check if feeds are stored as options
        $option_feeds = get_option('job_feed_url');
        if (!empty($option_feeds)) {
            if (WP_DEBUG) error_log('[PUNTWORK] get_feeds() - Found feeds in options: ' . print_r($option_feeds, true));
            if (is_array($option_feeds)) {
                $feeds = $option_feeds;
            } elseif (is_string($option_feeds)) {
                // Try to parse as JSON
                $parsed = json_decode($option_feeds, true);
                if ($parsed && is_array($parsed)) {
                    $feeds = $parsed;
                }
            }
        }
        
        return $feeds;
            
            return $feeds;
        }
        
        $query = new \WP_Query([
            'post_type' => 'job-feed',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        error_log('[PUNTWORK] get_feeds() - Query found ' . $query->found_posts . ' job-feed posts');

        if ($query->have_posts()) {
            foreach ($query->posts as $post_id) {
                $feed_url = get_post_meta($post_id, 'feed_url', true);
                $post = get_post($post_id);

                error_log('[PUNTWORK] get_feeds() - Post ID ' . $post_id . ': title="' . $post->post_title . '", status="' . $post->post_status . '", feed_url="' . $feed_url . '"');

                // Also check for ACF field if regular meta is empty
                if (empty($feed_url) && function_exists('get_field')) {
                    $feed_url = get_field('feed_url', $post_id);
                    error_log('[PUNTWORK] get_feeds() - ACF feed_url for post ' . $post_id . ': ' . $feed_url);
                }

                if (!empty($feed_url)) {
                    $feeds[$post->post_name] = $feed_url; // Use slug as key
                    error_log('[PUNTWORK] get_feeds() - Added feed: ' . $post->post_name . ' -> ' . $feed_url);
                } else {
                    error_log('[PUNTWORK] get_feeds() - Skipping post ID ' . $post_id . ' - empty feed_url');
                }
            }
        } else {
            error_log('[PUNTWORK] get_feeds() - No job-feed posts found');
            
            // Check if there are any job-feed posts with different status
            $all_query = new \WP_Query([
                'post_type' => 'job-feed',
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
            ]);
            error_log('[PUNTWORK] get_feeds() - Found ' . $all_query->found_posts . ' job-feed posts with any status');
            
            if ($all_query->have_posts()) {
                error_log('[PUNTWORK] get_feeds() - Post IDs with any status: ' . implode(', ', $all_query->posts));
            }
        }

        set_transient('puntwork_feeds', $feeds, 3600); // Cache for 1 hour
        error_log('[PUNTWORK] get_feeds() - Returning ' . count($feeds) . ' feeds: ' . implode(', ', array_keys($feeds)));
    } else {
        error_log('[PUNTWORK] get_feeds() - Using cached feeds: ' . count($feeds) . ' feeds');
    }
    return $feeds;
}

// Clear feeds cache when job-feed post is updated
add_action('save_post', function($post_id, $post, $update) {
    if ($post->post_type === 'job-feed' && $post->post_status === 'publish') {
        delete_transient('puntwork_feeds');
    }
}, 10, 3);

function process_one_feed($feed_key, $url, $output_dir, $fallback_domain, &$logs) {
    // Determine file extension based on URL
    $extension = FeedProcessor::detect_format($url);
    $feed_path = $output_dir . $feed_key . '.' . $extension;
    $json_filename = $feed_key . '.jsonl';
    $json_path = $output_dir . $json_filename;
    $gz_json_path = $json_path . '.gz';

    // Download feed and detect format
    $detected_format = null;
    if (!download_feed($url, $feed_path, $output_dir, $logs, $detected_format)) {
        return 0;
    }

    // Use detected format, fallback to URL-based detection
    $format = $detected_format ?: FeedProcessor::detect_format($url);

    $handle = fopen($json_path, 'w');
    if (!$handle) throw new \Exception("Can't open $json_path");
    $batch_size = 100;
    $total_items = 0;

    // Process feed using FeedProcessor
    $count = FeedProcessor::process_feed($feed_path, $format, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);

    fclose($handle);
    @chmod($json_path, 0644);

    gzip_file($json_path, $gz_json_path);
    return $count;
}

function fetch_and_generate_combined_json() {
    global $import_logs;
    $import_logs = [];
    ini_set('memory_limit', '512M');
    set_time_limit(1800);
    $feeds = get_feeds();
    $output_dir = ABSPATH . 'feeds/';
    if (!wp_mkdir_p($output_dir) || !is_writable($output_dir)) {
        error_log("Directory $output_dir not writable");
        throw new \Exception('Feeds directory not writable - check Hostinger permissions');
    }
    $fallback_domain = 'belgiumjobs.work';

    $total_items = 0;
    libxml_use_internal_errors(true);

    foreach ($feeds as $feed_key => $url) {
        $count = process_one_feed($feed_key, $url, $output_dir, $fallback_domain, $import_logs);
        $total_items += $count;
    }

    combine_jsonl_files($feeds, $output_dir, $total_items, $import_logs);
    return $import_logs;
}
