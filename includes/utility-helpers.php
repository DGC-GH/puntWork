<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if (!function_exists('get_memory_limit_bytes')) {
    function get_memory_limit_bytes() {
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit == '-1') return PHP_INT_MAX;
        $number = (int) preg_replace('/[^0-9]/', '', $memory_limit);
        $suffix = preg_replace('/[0-9]/', '', $memory_limit);
        switch (strtoupper($suffix)) {
            case 'G': return $number * 1024 * 1024 * 1024;
            case 'M': return $number * 1024 * 1024;
            case 'K': return $number * 1024;
            default: return $number;
        }
    }
}

if (!function_exists('get_json_item_count')) {
    function get_json_item_count($json_path) {
        if (false !== ($cached_count = get_option('job_json_total_count'))) {
            return $cached_count;
        }
        if (!file_exists($json_path)) {
            error_log('get_json_item_count: File not found - ' . $json_path);
            return 0;
        }
        $lines = file($json_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            error_log('get_json_item_count: Failed to read lines - ' . $json_path);
            return 0;
        }
        $count = count($lines);
        update_option('job_json_total_count', $count, false);
        return $count;
    }
}

if (!function_exists('load_json_batch')) {
    function load_json_batch($json_path, $start, $batch_size) {
        if (!file_exists($json_path)) {
            error_log('load_json_batch: File not found - ' . $json_path);
            return [];
        }
        $file = new SplFileObject($json_path);
        $file->seek($start);
        $batch = [];
        $bad_lines = 0;
        for ($i = 0; $i < $batch_size && !$file->eof(); $i++) {
            $line = $file->fgets();
            if (trim($line)) {
                $item = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($item)) {
                    $batch[] = $item;
                } else {
                    $bad_lines++;
                    error_log('load_json_batch: JSON decode error on line ~' . ($start + $i + 1) . ': ' . json_last_error_msg() . ' - ' . substr($line, 0, 100));
                }
            }
        }
        if ($bad_lines > 0) {
            error_log('load_json_batch: Skipped ' . $bad_lines . ' invalid lines in batch starting at ' . $start);
        }
        unset($file);
        return $batch;
    }
}
