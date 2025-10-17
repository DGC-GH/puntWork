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

require_once __DIR__ . '/../import/process-xml-batch.php';
require_once __DIR__ . '/../utilities/item-cleaning.php';
require_once __DIR__ . '/../utilities/item-inference.php';

function get_feeds() {
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

function process_one_feed($feed_key, $url, $output_dir, $fallback_domain, &$logs, $force_use_wp_remote = false) {
    // Ensure output directory exists
    if (!wp_mkdir_p($output_dir) || !is_writable($output_dir)) {
        throw new \Exception('Output directory not writable');
    }
    
    $xml_path = $output_dir . $feed_key . '.xml';
    $json_filename = $feed_key . '.jsonl';
    $json_path = $output_dir . $json_filename;
    $gz_json_path = $json_path . '.gz';

    // Update import status: feed download starting
    try {
        $status = get_import_status();
        $status['phase'] = 'feed-downloading';
        $status['current_feed'] = $feed_key;
        $status['last_update'] = time();
        $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Starting download for ' . $feed_key;
        set_import_status_atomic($status);
    } catch (\Exception $e) {
        // Non-fatal; continue
    }

    if (!download_feed($url, $xml_path, $output_dir, $logs, $force_use_wp_remote)) {
        // Report failed download in import status
        try {
            $status = get_import_status();
            $status['phase'] = 'feed-downloading';
            $status['last_update'] = time();
            $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Download failed for ' . $feed_key;
            set_import_status_atomic($status);
        } catch (\Exception $e) {}
        return 0;
    }

    // Download succeeded; now process XML to JSONL
    $total_feed_items = 0;
    $batch_size = 1000;

    try {
        $status = get_import_status();
        $status['phase'] = 'feed-processing';
        $status['current_feed'] = $feed_key;
        $status['last_update'] = time();
        $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Download complete, starting XML processing for ' . $feed_key;
        set_import_status_atomic($status);
    } catch (\Exception $e) {}

    // Open JSONL file for writing
    $json_handle = fopen($json_path, 'w');
    if (!$json_handle) {
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Error: Could not open JSONL file for writing: ' . $json_path;
        return 0;
    }

    // Process XML feed in batches to JSONL
    process_xml_batch($xml_path, $json_handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_feed_items, $logs);

    // Close JSONL file
    fclose($json_handle);
    @chmod($json_path, 0644);

    // Update import status with processed count
    try {
        $status = get_import_status();
        $status['last_update'] = time();
        $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Processed ' . $total_feed_items . ' items for ' . $feed_key;
        set_import_status_atomic($status);
    } catch (\Exception $e) {}

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] XML processing complete for ' . $feed_key . ' (' . $total_feed_items . ' items)';
    return $total_feed_items;
}

function download_feeds_in_parallel($feeds, $output_dir, $fallback_domain, &$logs) {
    $total_items = 0;
    $start_time = microtime(true);
    $total_feeds = count($feeds);
    $processed_feeds = 0;

    PuntWorkLogger::info('Starting parallel feed downloads', PuntWorkLogger::CONTEXT_FEED, [
        'feed_count' => $total_feeds,
        'output_dir' => $output_dir
    ]);

    // Initialize import status for feed processing phase
    $feed_status = [
        'phase' => 'feed-processing',
        'total' => $total_feeds,
        'processed' => 0,
        'published' => 0,
        'updated' => 0,
        'skipped' => 0,
        'duplicates_drafted' => 0,
        'time_elapsed' => 0,
        'start_time' => $start_time,
        'success' => null,
        'error_message' => '',
        'logs' => []
    ];
    set_import_status($feed_status);

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

    // For this deployment we intentionally use sequential downloads for reliability.
    PuntWorkLogger::info('Starting feed downloads (sequential wp_remote_get)', PuntWorkLogger::CONTEXT_FEED, [
        'feed_count' => count($download_tasks),
        'output_dir' => $output_dir,
        'method' => 'sequential_wp_remote_get'
    ]);

    $successful_downloads = 0;
    foreach ($download_tasks as $feed_key => $task) {
        try {
            // Force the use of wp_remote_get inside process_one_feed
            $count = process_one_feed($feed_key, $task['url'], $output_dir, $fallback_domain, $logs, true);
            if ($count > 0) $successful_downloads++;
            $total_items += $count;
        } catch (\Exception $e) {
            PuntWorkLogger::error('Sequential feed download failed', PuntWorkLogger::CONTEXT_FEED, [
                'feed_key' => $feed_key,
                'url' => $task['url'],
                'error' => $e->getMessage()
            ]);
        }

        // Update progress after processing each feed
        $processed_feeds++;
        $feed_status['processed'] = $processed_feeds;
        $feed_status['time_elapsed'] = microtime(true) - $start_time;
        set_import_status($feed_status);
    }

    $parallel_download_time = microtime(true) - $start_time;
    PuntWorkLogger::info('Sequential feed downloads completed', PuntWorkLogger::CONTEXT_FEED, [
        'total_feeds' => count($feeds),
        'successful_downloads' => $successful_downloads,
        'total_download_time' => $parallel_download_time,
        'average_time_per_feed' => count($feeds) > 0 ? $parallel_download_time / count($feeds) : 0
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Sequential downloads completed: ' . $successful_downloads . '/' . count($feeds) . ' feeds in ' . round($parallel_download_time, 2) . 's';

    return $total_items;
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

    // Get start_time from existing import status
    $current_status = get_import_status();
    $start_time = $current_status['start_time'] ?? microtime(true);

    // Update status for JSONL combination phase
    $jsonl_status = [
        'phase' => 'jsonl-combining',
        'total' => 1, // This phase processes 1 item (the combination)
        'processed' => 0,
        'published' => 0,
        'updated' => 0,
        'skipped' => 0,
        'duplicates_drafted' => 0,
        'time_elapsed' => microtime(true) - $start_time,
        'start_time' => $start_time,
        'success' => null,
        'error_message' => '',
        'logs' => []
    ];
    set_import_status($jsonl_status);

    PuntWorkLogger::info('Starting JSONL combination phase', PuntWorkLogger::CONTEXT_FEED, [
        'total_items' => $total_items
    ]);

    combine_jsonl_files($feeds, $output_dir, $total_items, $import_logs);

    // Mark JSONL combination as complete
    $jsonl_status['processed'] = 1;
    $jsonl_status['time_elapsed'] = microtime(true) - $start_time;
    set_import_status($jsonl_status);

    PuntWorkLogger::info('JSONL combination phase completed', PuntWorkLogger::CONTEXT_FEED, [
        'total_items' => $total_items
    ]);

    return $import_logs;
}
