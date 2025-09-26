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

/**
 * Get all configured feeds with caching
 *
 * @return array Array of feed URLs keyed by feed slug
 */
function get_feeds(): array {
    $cache_key = 'puntwork_feeds';
    $feeds = CacheManager::get($cache_key, CacheManager::GROUP_MAPPINGS);

    if ($feeds === false) {
        $feeds = [];

        // First, check if CPT is registered
        if (!post_type_exists('job-feed')) {
            // Try alternative: check if feeds are stored as options
            $option_feeds = get_option('job_feed_url');
            if (!empty($option_feeds)) {
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

            // Cache for 1 hour
            CacheManager::set($cache_key, $feeds, CacheManager::GROUP_MAPPINGS, HOUR_IN_SECONDS);
            return $feeds;
        }

        $query = new \WP_Query([
            'post_type' => 'job-feed',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        if ($query->have_posts()) {
            foreach ($query->posts as $post_id) {
                $feed_url = get_post_meta($post_id, 'feed_url', true);
                $post = get_post($post_id);

                // Also check for ACF field if regular meta is empty
                if (empty($feed_url) && function_exists('get_field')) {
                    $feed_url = get_field('feed_url', $post_id);
                }

                if (!empty($feed_url)) {
                    $feed_url = esc_url_raw($feed_url);
                    if (!filter_var($feed_url, FILTER_VALIDATE_URL)) {
                        continue; // skip invalid URLs
                    }
                    $feeds[$post->post_name] = $feed_url; // Use slug as key
                }
            }
        }

        // Cache for 1 hour
        CacheManager::set($cache_key, $feeds, CacheManager::GROUP_MAPPINGS, HOUR_IN_SECONDS);
    }

    return $feeds;
}

// Clear feeds cache when job-feed post is updated
add_action('save_post', function($post_id, $post, $update) {
    if ($post->post_type === 'job-feed' && $post->post_status === 'publish') {
        CacheManager::delete('puntwork_feeds', CacheManager::GROUP_MAPPINGS);
    }
}, 10, 3);

/**
 * Process a single feed and return the number of items processed
 *
 * @param string $feed_key Unique identifier for the feed
 * @param string $url Feed URL to process
 * @param string $output_dir Directory to store processed files
 * @param string $fallback_domain Fallback domain for job URLs
 * @param array &$logs Reference to logs array for recording processing details
 * @return int Number of items processed from this feed
 * @throws \Exception If feed processing fails
 */
function process_one_feed(string $feed_key, string $url, string $output_dir, string $fallback_domain, array &$logs): int {
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

/**
 * Fetch and process all configured feeds, generating combined JSONL output
 *
 * @global array $import_logs Global logs array for recording import details
 * @return array Import logs containing processing details and any errors
 * @throws \Exception If feed processing setup fails
 */
function fetch_and_generate_combined_json(): array {
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
