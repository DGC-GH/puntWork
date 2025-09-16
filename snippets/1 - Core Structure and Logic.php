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
