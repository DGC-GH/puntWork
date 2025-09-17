<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function get_feeds() {
    return [
        'startpeople' => 'https://feeds.startpeople.be/api/vacancies',
        'internationalrecruitment' => 'https://feeds.internationalrecruitment.be/api/vacancies',
        'unique' => 'https://feeds.unique.be/api/vacancies',
        'expressmedical' => 'https://feeds.expressmedical.be/api/vacancies',
    ];
}

function process_one_feed($feed_key, $url, $output_dir, $fallback_domain, &$logs) {
    $xml_path = $output_dir . $feed_key . '.xml';
    $json_filename = $feed_key . '.jsonl';
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
        throw new Exception('Feeds directory not writable - check Hostinger permissions');
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
