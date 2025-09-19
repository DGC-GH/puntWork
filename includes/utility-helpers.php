<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

namespace Puntwork;

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
        $count = count(file($json_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        update_option('job_json_total_count', $count, false);
        return $count;
    }
}

if (!function_exists('load_json_batch')) {
    function load_json_batch($json_path, $start, $batch_size) {
        $file = new SplFileObject($json_path);
        $file->seek($start);
        $batch = [];
        for ($i = 0; $i < $batch_size && !$file->eof(); $i++) {
            $line = $file->fgets();
            if (trim($line)) {
                $item = json_decode($line, true);
                if ($item) $batch[] = $item;
            }
        }
        unset($file);
        return $batch;
    }
}
