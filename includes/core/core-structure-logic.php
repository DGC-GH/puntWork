<?php

/**
 * Core structure and logic for job import plugin.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Puntwork\Utilities\CacheManager;

/**
 * Get all configured feeds with caching.
 *
 * @return array Array of feed URLs keyed by feed slug
 */
function get_feeds(): array
{
    $cache_key = 'puntwork_feeds';
    $feeds = CacheManager::get($cache_key, CacheManager::GROUP_MAPPINGS);

    if ($feeds === false) {
        if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
            error_log('[PUNTWORK] [DEBUG] get_feeds: Cache miss, building feeds array');
        }
        $feeds = [];

        // First, check if CPT is registered
        if (!post_type_exists('job-feed')) {
            if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                error_log('[PUNTWORK] [DEBUG] get_feeds: job-feed post type not registered, checking options');
            }
            // Try alternative: check if feeds are stored as options
            $option_feeds = get_option('job_feed_url');
            if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                error_log('[PUNTWORK] [DEBUG] get_feeds: job_feed_url option value: ' . print_r($option_feeds, true));
            }
            if (!empty($option_feeds)) {
                if (is_array($option_feeds)) {
                    $feeds = $option_feeds;
                    if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                        error_log('[PUNTWORK] [DEBUG] get_feeds: Using array from option: ' . json_encode($feeds));
                    }
                } elseif (is_string($option_feeds)) {
                    // Try to parse as JSON
                    $parsed = json_decode($option_feeds, true);
                    if ($parsed && is_array($parsed)) {
                        $feeds = $parsed;
                        if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                            error_log('[PUNTWORK] [DEBUG] get_feeds: Parsed JSON from option: ' . json_encode($feeds));
                        }
                    } elseif (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                        error_log('[PUNTWORK] [DEBUG] get_feeds: Failed to parse option as JSON');
                    }
                }
            } elseif (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                error_log('[PUNTWORK] [DEBUG] get_feeds: No feeds in options');
            }

            // Cache for 1 hour
            CacheManager::set($cache_key, $feeds, CacheManager::GROUP_MAPPINGS, HOUR_IN_SECONDS);
            if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                error_log('[PUNTWORK] [DEBUG] get_feeds: Returning feeds (no CPT): ' . json_encode($feeds));
            }

            return $feeds;
        }

        if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
            error_log('[PUNTWORK] [DEBUG] get_feeds: job-feed post type exists, querying posts');
        }
        $query = new \WP_Query(
            [
                'post_type' => 'job-feed',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
            ]
        );

        if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
            error_log('[PUNTWORK] [DEBUG] get_feeds: Query found ' . $query->found_posts . ' job-feed posts');
        }
        if ($query->have_posts()) {
            foreach ($query->posts as $post_id) {
                if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                    error_log('[PUNTWORK] [DEBUG] get_feeds: Processing post ID ' . $post_id);
                }
                $feed_url = get_post_meta($post_id, 'feed_url', true);
                $feed_type = get_post_meta($post_id, 'feed_type', true) ?: 'traditional';
                $post = get_post($post_id);

                // Also check for ACF field if regular meta is empty
                if (empty($feed_url) && function_exists('get_field')) {
                    $feed_url = get_field('feed_url', $post_id);
                    if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                        error_log('[PUNTWORK] [DEBUG] get_feeds: Got feed_url from ACF: ' . $feed_url);
                    }
                }

                if (!empty($feed_url)) {
                    $feed_url = esc_url_raw($feed_url);
                    if (!filter_var($feed_url, FILTER_VALIDATE_URL)) {
                        if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                            error_log('[PUNTWORK] [DEBUG] get_feeds: Invalid URL for post ' . $post_id . ': ' . $feed_url);
                        }

                        continue; // skip invalid URLs
                    }
                    $feeds[$post->post_name] = $feed_url; // Use slug as key
                    if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                        error_log('[PUNTWORK] [DEBUG] get_feeds: Added feed ' . $post->post_name . ' -> ' . $feed_url);
                    }
                } elseif ($feed_type == 'job_board') {
                    if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                        error_log('[PUNTWORK] [DEBUG] get_feeds: Handling job board feed for post ' . $post_id);
                    }
                    // Handle job board feeds
                    $board_id = get_post_meta($post_id, 'job_board_id', true);
                    $board_params = get_post_meta($post_id, 'job_board_params', true) ?: [];

                    if (!empty($board_id)) {
                        // Create job board URL: job_board://board_id?param1=value1&...
                        $job_board_url = 'job_board://' . $board_id;
                        if (!empty($board_params)) {
                            $job_board_url .= '?' . http_build_query($board_params);
                        }
                        $feeds[$post->post_name] = $job_board_url;
                        if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                            error_log('[PUNTWORK] [DEBUG] get_feeds: Added job board feed ' . $post->post_name . ' -> ' . $job_board_url);
                        }
                    } elseif (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                        error_log('[PUNTWORK] [DEBUG] get_feeds: No board_id for job board post ' . $post_id);
                    }
                } elseif (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
                    error_log('[PUNTWORK] [DEBUG] get_feeds: No feed_url for post ' . $post_id . ', feed_type: ' . $feed_type);
                }
            }
        } elseif (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
            error_log('[PUNTWORK] [DEBUG] get_feeds: No published job-feed posts found');
        }

        // Add configured job boards as additional feeds
        if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
            error_log('[PUNTWORK] [DEBUG] get_feeds: Adding job board feeds');
        }
        $job_board_feeds = get_job_board_feeds();
        if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
            error_log('[PUNTWORK] [DEBUG] get_feeds: Job board feeds: ' . json_encode($job_board_feeds));
        }
        $feeds = array_merge($feeds, $job_board_feeds);

        // Cache for 1 hour
        CacheManager::set($cache_key, $feeds, CacheManager::GROUP_MAPPINGS, HOUR_IN_SECONDS);
    } elseif (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
        error_log('[PUNTWORK] [DEBUG] get_feeds: Using cached feeds: ' . json_encode($feeds));
    }

    if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
        error_log('[PUNTWORK] [DEBUG] get_feeds: Final feeds array: ' . json_encode($feeds));
    }

    return $feeds;
}

/**
 * Get configured job board feeds.
 *
 * @return array Array of job board feed URLs
 */
function get_job_board_feeds(): array
{
    $job_board_feeds = [];

    // Include the JobBoardManager
    include_once plugin_dir_path(__FILE__) . '../jobboards/jobboard-manager.php';

    $board_manager = new \Puntwork\JobBoards\JobBoardManager();
    $configured_boards = $board_manager->getConfiguredBoards();

    foreach ($configured_boards as $board_id) {
        // Create job board URL for each configured board
        $job_board_url = 'job_board://' . $board_id;
        $job_board_feeds['job_board_' . $board_id] = $job_board_url;
    }

    return $job_board_feeds;
}

// Clear feeds cache when job-feed post is updated
add_action(
    'save_post',
    function ($post_id, $post, $update) {
        if ($post->post_type == 'job-feed' && $post->post_status == 'publish') {
            CacheManager::delete('puntwork_feeds', CacheManager::GROUP_MAPPINGS);
        }
    },
    10,
    3
);

/**
 * Process a single feed and return the number of items processed.
 *
 * @param  string $feed_key        Unique identifier for the feed
 * @param  string $url             Feed URL to process
 * @param  string $output_dir      Directory to store processed files
 * @param  string $fallback_domain Fallback domain for job URLs
 * @param  array  &$logs           Reference to logs array for recording processing details
 * @return int Number of items processed from this feed
 * @throws \Exception If feed processing fails
 */
function process_one_feed(string $feed_key, string $url, string $output_dir, string $fallback_domain, array &$logs): int
{
    error_log('[PUNTWORK] ==== process_one_feed START ===');
    error_log('[PUNTWORK] Feed key: ' . $feed_key);
    error_log('[PUNTWORK] Feed URL: ' . $url);
    error_log('[PUNTWORK] Output dir: ' . $output_dir);

    $json_filename = $feed_key . '.jsonl';
    $json_path = $output_dir . $json_filename;
    $gz_json_path = $json_path . '.gz';

    error_log('[PUNTWORK] JSON path: ' . $json_path);
    error_log('[PUNTWORK] GZ JSON path: ' . $gz_json_path);

    // Download the feed
    $feed_file_path = $output_dir . $feed_key . '.xml'; // Temporary file for downloaded feed
    error_log('[PUNTWORK] Feed file path: ' . $feed_file_path);
    if (!download_feed($url, $feed_file_path, $output_dir, $logs)) {
        error_log('[PUNTWORK] Feed download failed for ' . $feed_key);

        return 0;
    }

    // Detect format from file content
    $content = file_get_contents($feed_file_path);
    if ($content === false) {
        error_log('[PUNTWORK] Failed to read downloaded feed file: ' . $feed_file_path);

        return 0;
    }

    $format = \Puntwork\FeedProcessor::detectFormat($url, $content);
    error_log('[PUNTWORK] Detected format: ' . $format);

    $handle = fopen($json_path, 'w');
    if (!$handle) {
        error_log('[PUNTWORK] Failed to open JSON file: ' . $json_path);

        throw new \Exception("Can't open $json_path");
    }
    error_log('[PUNTWORK] JSON file opened successfully');

    $batch_size = 500;
    $total_items = 0;

    error_log('[PUNTWORK] About to call FeedProcessor::processFeed');

    try {
        // Process feed using FeedProcessor
        $count = \Puntwork\FeedProcessor::processFeed($feed_file_path, $format, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
        error_log('[PUNTWORK] FeedProcessor::processFeed returned count: ' . $count);
        error_log('[PUNTWORK] Total items processed: ' . $total_items);
    } catch (\Exception $e) {
        error_log('[PUNTWORK] ERROR in FeedProcessor::processFeed: ' . $e->getMessage());
        error_log('[PUNTWORK] ERROR class: ' . get_class($e));
        error_log('[PUNTWORK] ERROR file: ' . $e->getFile() . ':' . $e->getLine());
        error_log('[PUNTWORK] ERROR trace: ' . $e->getTraceAsString());
        fclose($handle);

        throw $e; // Re-throw to maintain existing behavior
    }

    fclose($handle);
    @chmod($json_path, 0644);

    error_log('[PUNTWORK] About to gzip file');
    gzip_file($json_path, $gz_json_path);
    error_log('[PUNTWORK] Gzip completed');

    error_log('[PUNTWORK] ==== process_one_feed END ===');

    return $count;
}
/**
 * Process a feed that has already been downloaded.
 *
 * @param  string $feed_key        Unique identifier for the feed
 * @param  string $feed_path       Path to the downloaded feed file
 * @param  string $output_dir      Directory to store processed files
 * @param  string $fallback_domain Fallback domain for job URLs
 * @param  array  &$logs           Reference to logs array for recording processing details
 * @return int Number of items processed from this feed
 * @throws \Exception If feed processing fails
 */
function process_downloaded_feed(string $feed_key, string $feed_path, string $output_dir, string $fallback_domain, array &$logs): int
{
    error_log('[PUNTWORK] ==== process_downloaded_feed START ===');
    error_log('[PUNTWORK] Feed key: ' . $feed_key);
    error_log('[PUNTWORK] Feed path: ' . $feed_path);
    error_log('[PUNTWORK] Output dir: ' . $output_dir);

    if (!file_exists($feed_path)) {
        error_log('[PUNTWORK] Feed file does not exist: ' . $feed_path);

        return 0;
    }

    $json_filename = $feed_key . '.jsonl';
    $json_path = $output_dir . $json_filename;
    $gz_json_path = $json_path . '.gz';

    error_log('[PUNTWORK] JSON path: ' . $json_path);
    error_log('[PUNTWORK] GZ JSON path: ' . $gz_json_path);

    // Detect format from file content
    $content = file_get_contents($feed_path);
    if ($content === false) {
        error_log('[PUNTWORK] Failed to read feed file: ' . $feed_path);

        return 0;
    }

    $format = \Puntwork\FeedProcessor::detectFormat('', $content);
    error_log('[PUNTWORK] Detected format: ' . $format);

    $handle = fopen($json_path, 'w');
    if (!$handle) {
        error_log('[PUNTWORK] Failed to open JSON file: ' . $json_path);

        throw new \Exception("Can't open $json_path");
    }
    error_log('[PUNTWORK] JSON file opened successfully');

    $batch_size = 500;
    $total_items = 0;

    error_log('[PUNTWORK] About to call FeedProcessor::processFeed');

    try {
        // Process feed using FeedProcessor
        $count = \Puntwork\FeedProcessor::processFeed($feed_path, $format, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
        error_log('[PUNTWORK] FeedProcessor::processFeed returned count: ' . $count);
        error_log('[PUNTWORK] Total items processed: ' . $total_items);
    } catch (\Exception $e) {
        error_log('[PUNTWORK] ERROR in FeedProcessor::processFeed: ' . $e->getMessage());
        error_log('[PUNTWORK] ERROR class: ' . get_class($e));
        error_log('[PUNTWORK] ERROR file: ' . $e->getFile() . ':' . $e->getLine());
        error_log('[PUNTWORK] ERROR trace: ' . $e->getTraceAsString());
        fclose($handle);

        throw $e; // Re-throw to maintain existing behavior
    }

    fclose($handle);
    @chmod($json_path, 0644);

    error_log('[PUNTWORK] About to gzip file');
    gzip_file($json_path, $gz_json_path);
    error_log('[PUNTWORK] Gzip completed');

    error_log('[PUNTWORK] ==== process_downloaded_feed END ===');

    return $count;
}

/**
 * Fetch and process all configured feeds, generating combined JSONL output.
 *
 * @global array $import_logs Global logs array for recording import details
 * @return array Import logs containing processing details and any errors
 * @throws \Exception If feed processing setup fails
 */
function fetch_and_generate_combined_json(): array
{
    global $import_logs;

    // Check for concurrent import lock
    if (get_transient('puntwork_import_running')) {
        error_log('[PUNTWORK] [CONCURRENT] Import already running, aborting');

        throw new \Exception('Import already running - please wait for current import to complete');
    }

    // Set import lock
    set_transient('puntwork_import_running', true, 3600); // 1 hour timeout

    try {
        $import_logs = [];
        ini_set('memory_limit', '1024M'); // Increased from 512M to handle large datasets
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

        // Add memory usage logging
        error_log('[PUNTWORK] [MEMORY] Initial memory usage: ' . memory_get_usage(true) / 1024 / 1024 . ' MB');
        error_log('[PUNTWORK] [MEMORY] Peak memory usage so far: ' . memory_get_peak_usage(true) / 1024 / 1024 . ' MB');

        // Use parallel downloading for better performance
        if (function_exists('\Puntwork\download_feeds_parallel')) {
            error_log('[PUNTWORK] Using parallel feed downloads');
            $download_results = \Puntwork\download_feeds_parallel($feeds, $output_dir, $import_logs, 5); // Max 5 concurrent

            // Process each downloaded feed
            foreach ($download_results as $feed_key => $result) {
                if ($result['success']) {
                    // Process the downloaded feed
                    $count = process_downloaded_feed($feed_key, $result['path'], $output_dir, $fallback_domain, $import_logs);
                    $total_items += $count;

                    // Log memory usage after each feed
                    error_log('[PUNTWORK] [MEMORY] After processing ' . $feed_key . ': ' . memory_get_usage(true) / 1024 / 1024 . ' MB (peak: ' . memory_get_peak_usage(true) / 1024 / 1024 . ' MB)');
                } else {
                    error_log("[PUNTWORK] Failed to download feed {$feed_key}: " . ($result['error'] ?? 'Unknown error'));
                }
            }
        } else {
            // Fallback to sequential processing
            error_log('[PUNTWORK] Falling back to sequential feed processing');
            foreach ($feeds as $feed_key => $url) {
                $count = process_one_feed($feed_key, $url, $output_dir, $fallback_domain, $import_logs);
                $total_items += $count;

                // Log memory usage after each feed
                error_log('[PUNTWORK] [MEMORY] After processing ' . $feed_key . ': ' . memory_get_usage(true) / 1024 / 1024 . ' MB (peak: ' . memory_get_peak_usage(true) / 1024 / 1024 . ' MB)');
            }
        }

        combine_jsonl_files($feeds, $output_dir, $total_items, $import_logs);

        // Final memory logging
        error_log('[PUNTWORK] [MEMORY] Final memory usage: ' . memory_get_usage(true) / 1024 / 1024 . ' MB');
        error_log('[PUNTWORK] [MEMORY] Peak memory usage: ' . memory_get_peak_usage(true) / 1024 / 1024 . ' MB');

        return $import_logs;
    } finally {
        // Always clear the import lock
        delete_transient('puntwork_import_running');
    }
}
