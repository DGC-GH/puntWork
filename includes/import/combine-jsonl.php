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
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function combine_jsonl_files($feeds, $output_dir, $total_items, &$logs) {
    $combined_json_path = $output_dir . 'combined-jobs.jsonl';
    $combined_handle = fopen($combined_json_path, 'w');
    if (!$combined_handle) throw new \Exception('Cant open combined JSONL');
    // Initialize combine progress in import status
    try {
        $status = get_import_status();
        $status['phase'] = 'jsonl-combining';
        $status['total'] = $total_items;
        $status['processed'] = 0;
        $status['last_update'] = time();
        $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Starting JSONL combination';
        set_import_status_atomic($status);
    } catch (\Exception $e) {}
    foreach ($feeds as $feed_key => $url) {
        $feed_json_path = $output_dir . $feed_key . '.jsonl';
        if (file_exists($feed_json_path)) {
            $feed_handle = fopen($feed_json_path, 'r');
            stream_copy_to_stream($feed_handle, $combined_handle); // Efficient copy
            fclose($feed_handle);
            // Update combine progress per feed
            try {
                $status = get_import_status();
                $status['processed'] = ($status['processed'] ?? 0) + 1;
                $status['last_update'] = time();
                $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Added ' . $feed_key . ' to combined JSONL';
                set_import_status_atomic($status);
            } catch (\Exception $e) {}
        }
    }
    fclose($combined_handle);
    @chmod($combined_json_path, 0644);
    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Combined JSONL ($total_items items)";
    error_log("Combined JSONL ($total_items items)");
}
