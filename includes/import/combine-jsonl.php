<?php

/**
 * JSONL file combination utilities
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

function combine_jsonl_files($feeds, $output_dir, $total_items, &$logs)
{
    // Ensure output directory exists
    if (! wp_mkdir_p($output_dir) || ! is_writable($output_dir)) {
        throw new \Exception('Feeds directory not writable');
    }

    $combined_json_path = $output_dir . 'combined-jobs.jsonl';
    $combined_gz_path   = $combined_json_path . '.gz';
    $combined_handle    = fopen($combined_json_path, 'w');
    if (! $combined_handle) {
        throw new \Exception('Cant open combined JSONL');
    }

    $seen_guids      = array();
    $duplicate_count = 0;
    $unique_count    = 0;

    foreach ($feeds as $feed_key => $url) {
        $feed_json_path = $output_dir . $feed_key . '.jsonl';
        if (file_exists($feed_json_path)) {
            $feed_handle = fopen($feed_json_path, 'r');
            if ($feed_handle) {
                while (( $line = fgets($feed_handle) ) !== false) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    // Parse JSON to check GUID
                    $job_data = json_decode($line, true);
                    if ($job_data === null) {
                        // Invalid JSON, skip
                        continue;
                    }

                    $guid = isset($job_data['guid']) ? trim($job_data['guid']) : '';
                    if (empty($guid)) {
                        // No GUID, include but log
                        fwrite($combined_handle, $line . "\n");
                        ++$unique_count;
                        continue;
                    }

                    // Check for duplicates
                    if (isset($seen_guids[ $guid ])) {
                        ++$duplicate_count;
                        continue; // Skip duplicate
                    }

                    // New unique job
                    $seen_guids[ $guid ] = true;
                    fwrite($combined_handle, $line . "\n");
                    ++$unique_count;
                }
                fclose($feed_handle);
            }
        }
    }

    fclose($combined_handle);
    @chmod($combined_json_path, 0644);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Combined JSONL ($unique_count unique items, $duplicate_count duplicates removed)";
    error_log("Combined JSONL ($unique_count unique items, $duplicate_count duplicates removed)");

    gzip_file($combined_json_path, $combined_gz_path);
}
