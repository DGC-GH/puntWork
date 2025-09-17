<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function get_feeds() {
    $feeds = [];
    $args = [
        'post_type' => 'job-feed',
        'post_status' => 'publish',
        'posts_per_page' => -1,
    ];
    $query = new WP_Query($args);
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $feed_key = get_post_field('post_name', get_the_ID());
            $url = get_field('feed-url', get_the_ID());
            $url = trim($url);
            if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                $feeds[$feed_key] = $url;
            }
        }
        wp_reset_postdata();
    }
    return $feeds;
}

function process_one_feed($feed_key, $url, $output_dir, $fallback_domain, &$logs) {
    $xml_path = $output_dir . $feed_key . '.xml';
    $json_filename = $feed_key . '.json';
    $json_path = $output_dir . $json_filename;
    $gz_json_path = $json_path . '.gz';

    if (!download_feed($url, $xml_path, $output_dir, $logs)) {
        return 0;
    }

    $handle = fopen($json_path, 'w');
    if (!$handle) throw new Exception("Can't open $json_path");
    $batch_size = 100;
    $total_items = 0;
    $count = process_xml_batch($xml_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
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
        throw new Exception('Feeds directory not writable - check Hosting permissions');
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

function download_feed($url, $xml_path, $output_dir, &$logs) {
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $fp = fopen($xml_path, 'w');
            if (!$fp) throw new Exception("Can't open $xml_path for write");
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'WordPress Job Importer');
            $success = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);
            if (!$success || $http_code !== 200 || filesize($xml_path) < 1000) {
                throw new Exception("cURL download failed (HTTP $http_code, size: " . filesize($xml_path) . " bytes)");
            }
        } else {
            $response = wp_remote_get($url, ['timeout' => 300]);
            if (is_wp_error($response)) throw new Exception($response->get_error_message());
            $body = wp_remote_retrieve_body($response);
            if (empty($body) || strlen($body) < 1000) throw new Exception('Empty or small response');
            file_put_contents($xml_path, $body);
        }
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Downloaded XML: " . filesize($xml_path) . " bytes";
        error_log("Downloaded XML: " . filesize($xml_path) . " bytes");
        @chmod($xml_path, 0644);
    } catch (Exception $e) {
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Download error: " . $e->getMessage();
        return false;
    }
    return true;
}

function combine_jsonl_files($feeds, $output_dir, $total_items, &$logs) {
    $combined_json_path = $output_dir . 'combined-jobs.json';
    $combined_gz_path = $combined_json_path . '.gz';
    $combined_handle = fopen($combined_json_path, 'w');
    if (!$combined_handle) throw new Exception('Cant open combined JSONL');
    foreach ($feeds as $feed_key => $url) {
        $feed_json_path = $output_dir . $feed_key . '.json';
        if (file_exists($feed_json_path)) {
            $feed_handle = fopen($feed_json_path, 'r');
            stream_copy_to_stream($feed_handle, $combined_handle); // Efficient copy
            fclose($feed_handle);
        }
    }
    fclose($combined_handle);
    @chmod($combined_json_path, 0644);
    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Combined JSONL ($total_items items)";
    error_log("Combined JSONL ($total_items items)");
    gzip_file($combined_json_path, $combined_gz_path);
}
