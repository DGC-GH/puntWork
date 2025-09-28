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
if (! defined('ABSPATH') ) {
    exit;
}

use Puntwork\Utilities\CacheManager;

/**
 * Get all configured feeds with caching
 *
 * @return array Array of feed URLs keyed by feed slug
 */
function get_feeds(): array
{
    $cache_key = 'puntwork_feeds';
    $feeds     = CacheManager::get($cache_key, CacheManager::GROUP_MAPPINGS);

    if ($feeds === false ) {
        error_log('[PUNTWORK] [DEBUG] get_feeds: Cache miss, building feeds array');
        $feeds = array();

        // First, check if CPT is registered
        if (! post_type_exists('job-feed') ) {
            error_log('[PUNTWORK] [DEBUG] get_feeds: job-feed post type not registered, checking options');
            // Try alternative: check if feeds are stored as options
            $option_feeds = get_option('job_feed_url');
            error_log('[PUNTWORK] [DEBUG] get_feeds: job_feed_url option value: ' . print_r($option_feeds, true));
            if (! empty($option_feeds) ) {
                if (is_array($option_feeds) ) {
                    $feeds = $option_feeds;
                    error_log('[PUNTWORK] [DEBUG] get_feeds: Using array from option: ' . json_encode($feeds));
                } elseif (is_string($option_feeds) ) {
                    // Try to parse as JSON
                    $parsed = json_decode($option_feeds, true);
                    if ($parsed && is_array($parsed) ) {
                        $feeds = $parsed;
                        error_log('[PUNTWORK] [DEBUG] get_feeds: Parsed JSON from option: ' . json_encode($feeds));
                    } else {
                        error_log('[PUNTWORK] [DEBUG] get_feeds: Failed to parse option as JSON');
                    }
                }
            } else {
                error_log('[PUNTWORK] [DEBUG] get_feeds: No feeds in options');
            }

            // Cache for 1 hour
            CacheManager::set($cache_key, $feeds, CacheManager::GROUP_MAPPINGS, HOUR_IN_SECONDS);
            error_log('[PUNTWORK] [DEBUG] get_feeds: Returning feeds (no CPT): ' . json_encode($feeds));
            return $feeds;
        }

        error_log('[PUNTWORK] [DEBUG] get_feeds: job-feed post type exists, querying posts');
        $query = new \WP_Query(
            array(
            'post_type'      => 'job-feed',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            )
        );

        error_log('[PUNTWORK] [DEBUG] get_feeds: Query found ' . $query->found_posts . ' job-feed posts');
        if ($query->have_posts() ) {
            foreach ( $query->posts as $post_id ) {
                error_log('[PUNTWORK] [DEBUG] get_feeds: Processing post ID ' . $post_id);
                $feed_url  = get_post_meta($post_id, 'feed_url', true);
                $feed_type = get_post_meta($post_id, 'feed_type', true) ?: 'traditional';
                $post      = get_post($post_id);

                // Also check for ACF field if regular meta is empty
                if (empty($feed_url) && function_exists('get_field') ) {
                    $feed_url = get_field('feed_url', $post_id);
                    error_log('[PUNTWORK] [DEBUG] get_feeds: Got feed_url from ACF: ' . $feed_url);
                }

                if (! empty($feed_url) ) {
                    $feed_url = esc_url_raw($feed_url);
                    if (! filter_var($feed_url, FILTER_VALIDATE_URL) ) {
                           error_log('[PUNTWORK] [DEBUG] get_feeds: Invalid URL for post ' . $post_id . ': ' . $feed_url);
                           continue; // skip invalid URLs
                    }
                    $feeds[ $post->post_name ] = $feed_url; // Use slug as key
                    error_log('[PUNTWORK] [DEBUG] get_feeds: Added feed ' . $post->post_name . ' -> ' . $feed_url);
                } elseif ($feed_type === 'job_board' ) {
                    error_log('[PUNTWORK] [DEBUG] get_feeds: Handling job board feed for post ' . $post_id);
                    // Handle job board feeds
                    $board_id     = get_post_meta($post_id, 'job_board_id', true);
                    $board_params = get_post_meta($post_id, 'job_board_params', true) ?: array();

                    if (! empty($board_id) ) {
                        // Create job board URL: job_board://board_id?param1=value1&...
                        $job_board_url = 'job_board://' . $board_id;
                        if (! empty($board_params) ) {
                            $job_board_url .= '?' . http_build_query($board_params);
                        }
                        $feeds[ $post->post_name ] = $job_board_url;
                        error_log('[PUNTWORK] [DEBUG] get_feeds: Added job board feed ' . $post->post_name . ' -> ' . $job_board_url);
                    } else {
                        error_log('[PUNTWORK] [DEBUG] get_feeds: No board_id for job board post ' . $post_id);
                    }
                } else {
                    error_log('[PUNTWORK] [DEBUG] get_feeds: No feed_url for post ' . $post_id . ', feed_type: ' . $feed_type);
                }
            }
        } else {
            error_log('[PUNTWORK] [DEBUG] get_feeds: No published job-feed posts found');
        }

        // Add configured job boards as additional feeds
        error_log('[PUNTWORK] [DEBUG] get_feeds: Adding job board feeds');
        $job_board_feeds = get_job_board_feeds();
        error_log('[PUNTWORK] [DEBUG] get_feeds: Job board feeds: ' . json_encode($job_board_feeds));
        $feeds = array_merge($feeds, $job_board_feeds);

        // Cache for 1 hour
        CacheManager::set($cache_key, $feeds, CacheManager::GROUP_MAPPINGS, HOUR_IN_SECONDS);
    } else {
        error_log('[PUNTWORK] [DEBUG] get_feeds: Using cached feeds: ' . json_encode($feeds));
    }

    error_log('[PUNTWORK] [DEBUG] get_feeds: Final feeds array: ' . json_encode($feeds));
    return $feeds;
}

/**
 * Get configured job board feeds
 *
 * @return array Array of job board feed URLs
 */
function get_job_board_feeds(): array
{
    $job_board_feeds = array();

    // Include the JobBoardManager
    include_once plugin_dir_path(__FILE__) . '../jobboards/jobboard-manager.php';

    $board_manager     = new \Puntwork\JobBoards\JobBoardManager();
    $configured_boards = $board_manager->getConfiguredBoards();

    foreach ( $configured_boards as $board_id ) {
        // Create job board URL for each configured board
        $job_board_url                               = 'job_board://' . $board_id;
        $job_board_feeds[ 'job_board_' . $board_id ] = $job_board_url;
    }

    return $job_board_feeds;
}

// Clear feeds cache when job-feed post is updated
add_action(
    'save_post',
    function ( $post_id, $post, $update ) {
        if ($post->post_type === 'job-feed' && $post->post_status === 'publish' ) {
            CacheManager::delete('puntwork_feeds', CacheManager::GROUP_MAPPINGS);
        }
    },
    10,
    3
);

/**
 * Process a single feed and return the number of items processed
 *
 * @param  string $feed_key        Unique identifier for the feed
 * @param  string $url             Feed URL to process
 * @param  string $output_dir      Directory to store processed files
 * @param  string $fallback_domain Fallback domain for job URLs
 * @param  array  &$logs           Reference to logs array for recording processing details
 * @return int Number of items processed from this feed
 * @throws \Exception If feed processing fails
 */
function process_one_feed( string $feed_key, string $url, string $output_dir, string $fallback_domain, array &$logs ): int
{
    error_log('[PUNTWORK] ===== process_one_feed START =====');
    error_log('[PUNTWORK] Feed key: ' . $feed_key);
    error_log('[PUNTWORK] URL: ' . $url);
    error_log('[PUNTWORK] Output dir: ' . $output_dir);
    error_log('[PUNTWORK] Fallback domain: ' . $fallback_domain);

    // Determine file extension based on URL
    $extension = \Puntwork\FeedProcessor::detectFormat($url);
    error_log('[PUNTWORK] Detected extension: ' . $extension);

    $feed_path     = $output_dir . $feed_key . '.' . $extension;
    $json_filename = $feed_key . '.jsonl';
    $json_path     = $output_dir . $json_filename;
    $gz_json_path  = $json_path . '.gz';

    error_log('[PUNTWORK] Feed path: ' . $feed_path);
    error_log('[PUNTWORK] JSON path: ' . $json_path);
    error_log('[PUNTWORK] GZ JSON path: ' . $gz_json_path);

    // Handle job board feeds differently - no download needed
    if ($extension === \Puntwork\FeedProcessor::FORMAT_JOB_BOARD ) {
        error_log('[PUNTWORK] Handling as job board feed');
        $feed_path       = $url; // Use the job board URL directly
        $detected_format = \Puntwork\FeedProcessor::FORMAT_JOB_BOARD;
    } else {
        error_log('[PUNTWORK] Handling as regular feed, downloading...');
        // Download feed and detect format
        $detected_format = null;
        if (! download_feed($url, $feed_path, $output_dir, $logs, $detected_format) ) {
            error_log('[PUNTWORK] download_feed failed');
            return 0;
        }
        error_log('[PUNTWORK] download_feed succeeded, detected format: ' . $detected_format);
    }

    // Use detected format, fallback to URL-based detection
    $format = $detected_format ?: \Puntwork\FeedProcessor::detectFormat($url);
    error_log('[PUNTWORK] Final format: ' . $format);

    $handle = fopen($json_path, 'w');
    if (! $handle ) {
        error_log('[PUNTWORK] Failed to open JSON file: ' . $json_path);
        throw new \Exception("Can't open $json_path");
    }
    error_log('[PUNTWORK] JSON file opened successfully');

    $batch_size  = 100;
    $total_items = 0;

    error_log('[PUNTWORK] About to call FeedProcessor::processFeed');
    // Process feed using FeedProcessor
    $count = \Puntwork\FeedProcessor::processFeed($feed_path, $format, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
    error_log('[PUNTWORK] FeedProcessor::processFeed returned count: ' . $count);
    error_log('[PUNTWORK] Total items processed: ' . $total_items);

    fclose($handle);
    @chmod($json_path, 0644);

    error_log('[PUNTWORK] About to gzip file');
    gzip_file($json_path, $gz_json_path);
    error_log('[PUNTWORK] Gzip completed');

    error_log('[PUNTWORK] ===== process_one_feed END =====');
    return $count;
}

/**
 * Fetch and process all configured feeds, generating combined JSONL output
 *
 * @global array $import_logs Global logs array for recording import details
 * @return array Import logs containing processing details and any errors
 * @throws \Exception If feed processing setup fails
 */
function fetch_and_generate_combined_json(): array
{
    global $import_logs;
    $import_logs = array();
    ini_set('memory_limit', '512M');
    set_time_limit(1800);
    $feeds      = get_feeds();
    $output_dir = ABSPATH . 'feeds/';
    if (! wp_mkdir_p($output_dir) || ! is_writable($output_dir) ) {
        error_log("Directory $output_dir not writable");
        throw new \Exception('Feeds directory not writable - check Hostinger permissions');
    }
    $fallback_domain = 'belgiumjobs.work';

    $total_items = 0;
    libxml_use_internal_errors(true);

    foreach ( $feeds as $feed_key => $url ) {
        $count        = process_one_feed($feed_key, $url, $output_dir, $fallback_domain, $import_logs);
        $total_items += $count;
    }

    combine_jsonl_files($feeds, $output_dir, $total_items, $import_logs);
    return $import_logs;
}
