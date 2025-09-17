<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function combine_jsonl_files($feeds, $output_dir, $total_items, &$logs) {
    $combined_json_path = $output_dir . 'combined-jobs.jsonl';
    $combined_gz_path = $combined_json_path . '.gz';
    $combined_handle = fopen($combined_json_path, 'w');
    if (!$combined_handle) throw new Exception('Can\'t open combined JSONL');
    foreach ($feeds as $feed_key => $url) {
        $feed_json_path = $output_dir . $feed_key . '.jsonl';
        if (file_exists($feed_json_path)) {
            $feed_handle = fopen($feed_json_path, 'r');
            stream_copy_to_stream($feed_handle, $combined_handle); // Efficient copy
            fclose($feed_handle);
            // Optional: Clean up individual feeds after combine
            // unlink($feed_json_path);
        }
    }
    fclose($combined_handle);
    @chmod($combined_json_path, 0644);
    $logs[] = '[' . date('d-m-Y H:i:s') . ' UTC] ' . "Combined JSONL ($total_items items)";
    error_log("Combined JSONL ($total_items items)");
    // Cache the total for import
    update_option('job_json_total_count', $total_items, false);
    gzip_file($combined_json_path, $combined_gz_path);
}
