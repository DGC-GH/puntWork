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
                error_log('[PUNTWORK] get_feeds() - Found feeds in options: ' . print_r($option_feeds, true));
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
    // Ensure output directory exists
    if (!wp_mkdir_p($output_dir) || !is_writable($output_dir)) {
        throw new \Exception('Output directory not writable');
    }
    
    $xml_path = $output_dir . $feed_key . '.xml';
    $json_filename = $feed_key . '.jsonl';
    $json_path = $output_dir . $json_filename;
    $gz_json_path = $json_path . '.gz';

    if (!download_feed($url, $xml_path, $output_dir, $logs)) {
        return 0;
    }

    $handle = fopen($json_path, 'w');
    if (!$handle) throw new \Exception("Can't open $json_path");
    $batch_size = 100;
    $total_items = 0;
    $count = process_xml_batch($xml_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
    fclose($handle);
    @chmod($json_path, 0644);

    gzip_file($json_path, $gz_json_path);
    return $count;
}

function download_feeds_in_parallel($feeds, $output_dir, $fallback_domain, &$logs) {
    $total_items = 0;
    $start_time = microtime(true);

    PuntWorkLogger::info('Starting parallel feed downloads', PuntWorkLogger::CONTEXT_FEED, [
        'feed_count' => count($feeds),
        'output_dir' => $output_dir
    ]);

    // Prepare download tasks
    $download_tasks = [];
    foreach ($feeds as $feed_key => $url) {
        $xml_path = $output_dir . $feed_key . '.xml';
        $download_tasks[$feed_key] = [
            'url' => $url,
            'xml_path' => $xml_path,
            'feed_key' => $feed_key
        ];
    }

    // Execute downloads in parallel using multi-curl
    $mh = curl_multi_init();
    $handles = [];
    $results = [];

    // Initialize all curl handles
    foreach ($download_tasks as $feed_key => $task) {
        $ch = curl_init($task['url']);
        curl_setopt($ch, CURLOPT_FILE, fopen($task['xml_path'], 'w'));
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes timeout per feed
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WordPress puntWork Importer');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // We're writing to file

        curl_multi_add_handle($mh, $ch);
        $handles[$feed_key] = $ch;
        $results[$feed_key] = [
            'handle' => $ch,
            'xml_path' => $task['xml_path'],
            'url' => $task['url'],
            'feed_key' => $feed_key,
            'start_time' => microtime(true)
        ];
    }

    // Execute all downloads in parallel
    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1.0); // Wait up to 1 second
        }
    } while ($active && $status == CURLM_OK);

    // Process results and close handles
    $successful_downloads = 0;
    foreach ($results as $feed_key => $result) {
        $ch = $result['handle'];
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $download_time = microtime(true) - $result['start_time'];

        if ($http_code === 200 && filesize($result['xml_path']) > 1000) {
            $successful_downloads++;
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Parallel download successful: ' . $feed_key . ' (' . filesize($result['xml_path']) . ' bytes in ' . round($download_time, 2) . 's)';

            PuntWorkLogger::info('Feed download completed successfully', PuntWorkLogger::CONTEXT_FEED, [
                'feed_key' => $feed_key,
                'url' => $result['url'],
                'file_size' => filesize($result['xml_path']),
                'download_time' => $download_time,
                'http_code' => $http_code
            ]);
        } else {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Parallel download failed: ' . $feed_key . ' (HTTP ' . $http_code . ', size: ' . filesize($result['xml_path']) . ')';

            PuntWorkLogger::error('Feed download failed', PuntWorkLogger::CONTEXT_FEED, [
                'feed_key' => $feed_key,
                'url' => $result['url'],
                'http_code' => $http_code,
                'file_size' => filesize($result['xml_path']),
                'download_time' => $download_time
            ]);
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);

    $parallel_download_time = microtime(true) - $start_time;
    PuntWorkLogger::info('Parallel feed downloads completed', PuntWorkLogger::CONTEXT_FEED, [
        'total_feeds' => count($feeds),
        'successful_downloads' => $successful_downloads,
        'total_download_time' => $parallel_download_time,
        'average_time_per_feed' => count($feeds) > 0 ? $parallel_download_time / count($feeds) : 0
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Parallel downloads completed: ' . $successful_downloads . '/' . count($feeds) . ' feeds in ' . round($parallel_download_time, 2) . 's';

    // Process downloaded feeds sequentially (XML processing can't be easily parallelized)
    foreach ($download_tasks as $feed_key => $task) {
        if (file_exists($task['xml_path']) && filesize($task['xml_path']) > 1000) {
            $count = process_feed_after_download($feed_key, $task['xml_path'], $output_dir, $fallback_domain, $logs);
            $total_items += $count;
        }
    }

    return $total_items;
}

function process_feed_after_download($feed_key, $xml_path, $output_dir, $fallback_domain, &$logs) {
    $json_filename = $feed_key . '.jsonl';
    $json_path = $output_dir . $json_filename;
    $gz_json_path = $json_path . '.gz';

    $handle = fopen($json_path, 'w');
    if (!$handle) throw new \Exception("Can't open $json_path");
    $batch_size = 100;
    $total_items = 0;
    $count = process_xml_batch($xml_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
    fclose($handle);
    @chmod($json_path, 0644);

    gzip_file($json_path, $gz_json_path);

    PuntWorkLogger::info('Feed processing completed', PuntWorkLogger::CONTEXT_FEED, [
        'feed_key' => $feed_key,
        'items_processed' => $count,
        'jsonl_path' => $json_path,
        'gz_path' => $gz_json_path
    ]);

    return $count;
}

function fetch_and_generate_combined_json() {
    global $import_logs;
    $import_logs = [];
    ini_set('memory_limit', '512M');
    set_time_limit(1800);
    $feeds = get_feeds();
    $output_dir = PUNTWORK_PATH . 'feeds/';
    if (!wp_mkdir_p($output_dir) || !is_writable($output_dir)) {
        error_log("Directory $output_dir not writable");
        throw new \Exception('Feeds directory not writable - check Hostinger permissions');
    }
    $fallback_domain = 'belgiumjobs.work';

    $total_items = 0;
    libxml_use_internal_errors(true);

    // Parallel feed downloads for improved performance
    $total_items = download_feeds_in_parallel($feeds, $output_dir, $fallback_domain, $import_logs);

    combine_jsonl_files($feeds, $output_dir, $total_items, $import_logs);
    return $import_logs;
}
