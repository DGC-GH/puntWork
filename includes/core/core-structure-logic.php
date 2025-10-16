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
    if (!chmod($json_path, 0644)) {
        PuntWorkLogger::warn('Failed to chmod JSONL file', PuntWorkLogger::CONTEXT_FEED, ['path' => $json_path]);
    }

    gzip_file($json_path, $gz_json_path);
    return $count;
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

    // Execute downloads in parallel using multi-curl
    $mh = curl_multi_init();
    $handles = [];
    $results = [];

    // Initialize all curl handles
    foreach ($download_tasks as $feed_key => $task) {
        $fp = fopen($task['xml_path'], 'w');
        if (!$fp) {
            PuntWorkLogger::error('Failed to open file for writing', PuntWorkLogger::CONTEXT_FEED, [
                'feed_key' => $feed_key,
                'xml_path' => $task['xml_path']
            ]);
            continue;
        }

    $ch = curl_init($task['url']);
    // Stream directly to file handle to avoid buffering entire response in memory
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes timeout per feed
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'WordPress puntWork Importer');
    curl_setopt($ch, CURLOPT_HEADER, false); // Don't include headers in response

        curl_multi_add_handle($mh, $ch);
        $handles[$feed_key] = $ch;
        $results[$feed_key] = [
            'handle' => $ch,
            'file_handle' => $fp,
            'xml_path' => $task['xml_path'],
            'url' => $task['url'],
            'feed_key' => $feed_key,
            'start_time' => microtime(true)
        ];
    }

    // Execute downloads in parallel using a robust curl_multi loop.
    // Map curl handle resource ids to feed keys so we can process completions as they arrive.
    $handle_map = [];
    foreach ($handles as $fk => $h) {
        $handle_map[(int) $h] = $fk;
    }

    $running = null;
    $mrc = curl_multi_exec($mh, $running);
    // Drive initial transfers
    while ($mrc == CURLM_CALL_MULTI_PERFORM) {
        $mrc = curl_multi_exec($mh, $running);
    }

    // Main loop: wait for activity and continue until no handles are running
    while ($running && $mrc == CURLM_OK) {
        $select = curl_multi_select($mh, 1.0);
        if ($select === -1) {
            // On some systems select returns -1; avoid busy loop
            usleep(100000); // 100ms
        }

        // Continue performing transfers
        do {
            $mrc = curl_multi_exec($mh, $running);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        // Read information about messages (completed handles)
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $key = $handle_map[(int) $ch] ?? null;
            if ($key === null) {
                // Unknown handle; ensure it's removed to avoid surprises
                @curl_multi_remove_handle($mh, $ch);
                @curl_close($ch);
                continue;
            }
            $result = &$results[$key];

            // Record transfer end time and capture info while handle is still valid
            $result['end_time'] = microtime(true);
            $result['curl_errno'] = $info['result'] ?? null;
            $result['curl_error'] = curl_error($ch);
            $result['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            // Remove the handle from the multi-handle before closing resources
            curl_multi_remove_handle($mh, $ch);

            // Close the file handle if still open
            if (!empty($result['file_handle'])) {
                @fclose($result['file_handle']);
                $result['file_handle'] = null;
            }

            // Close the curl handle and mark as closed so later processing doesn't touch it
            curl_close($ch);
            $result['handle'] = null;
            $result['completed'] = true;
        }
    }

    // Process results and close handles
    $successful_downloads = 0;
    foreach ($results as $feed_key => $result) {
        $ch = $result['handle'] ?? null;
        $http_code = $result['http_code'] ?? null;
        $curl_error = $result['curl_error'] ?? '';
        $download_time = (isset($result['end_time']) ? $result['end_time'] : microtime(true)) - $result['start_time'];

        // Ensure any remaining file handle is closed
        if (!empty($result['file_handle'])) {
            @fclose($result['file_handle']);
        }

        $file_size = file_exists($result['xml_path']) ? filesize($result['xml_path']) : 0;

        // If handle still exists, attempt to gather any missing info, but do not assume it's present
        if ($ch) {
            if ($http_code === null) $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (empty($curl_error)) $curl_error = curl_error($ch);
        }

        if ($http_code === 200 && $file_size > 1000 && empty($curl_error)) {
            $successful_downloads++;
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Parallel download successful: ' . $feed_key . ' (' . $file_size . ' bytes in ' . round($download_time, 2) . 's)';

            PuntWorkLogger::info('Feed download completed successfully', PuntWorkLogger::CONTEXT_FEED, [
                'feed_key' => $feed_key,
                'url' => $result['url'],
                'file_size' => $file_size,
                'download_time' => $download_time,
                'http_code' => $http_code
            ]);
        } else {
            // Read first 500 bytes of the file for debugging (if any)
            $response_preview = '';
            if (file_exists($result['xml_path'])) {
                $fp_preview = @fopen($result['xml_path'], 'r');
                if ($fp_preview) {
                    $response_preview = @fread($fp_preview, 500);
                    fclose($fp_preview);
                }
            }

            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Parallel download failed: ' . $feed_key . ' (HTTP ' . $http_code . ', size: ' . $file_size . ', curl_error: ' . $curl_error . ')';

            PuntWorkLogger::error('Feed download failed', PuntWorkLogger::CONTEXT_FEED, [
                'feed_key' => $feed_key,
                'url' => $result['url'],
                'http_code' => $http_code,
                'file_size' => $file_size,
                'download_time' => $download_time,
                'curl_error' => $curl_error,
                'response_length' => $file_size,
                'response_preview' => $response_preview
            ]);
        }

        // Only remove/close if the curl handle still exists (it may have been closed earlier)
        if ($ch) {
            @curl_multi_remove_handle($mh, $ch);
            @curl_close($ch);
        }
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

            // Update progress after processing each feed
            $processed_feeds++;
            $feed_status['processed'] = $processed_feeds;
            $feed_status['time_elapsed'] = microtime(true) - $start_time;
            set_import_status($feed_status);

            PuntWorkLogger::debug('Feed processing progress update', PuntWorkLogger::CONTEXT_FEED, [
                'feed_key' => $feed_key,
                'processed_feeds' => $processed_feeds,
                'total_feeds' => $total_feeds,
                'items_in_feed' => $count,
                'total_items_so_far' => $total_items
            ]);
        } else {
            // Count failed feeds as processed but log the failure
            $processed_feeds++;
            $feed_status['processed'] = $processed_feeds;
            $feed_status['time_elapsed'] = microtime(true) - $start_time;
            set_import_status($feed_status);

            PuntWorkLogger::warn('Feed processing skipped - download failed', PuntWorkLogger::CONTEXT_FEED, [
                'feed_key' => $feed_key,
                'processed_feeds' => $processed_feeds,
                'total_feeds' => $total_feeds
            ]);
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
    if (!chmod($json_path, 0644)) {
        PuntWorkLogger::warn('Failed to chmod JSONL file', PuntWorkLogger::CONTEXT_FEED, ['path' => $json_path]);
    }

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
