<?php
/**
 * Utility helper functions
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
    /**
     * Get the total count of items in JSONL file.
     *
     * @param string $json_path Path to JSONL file.
     * @return int Total item count.
     */
    function get_json_item_count($json_path) {
        $count = 0;
        if (($handle = fopen($json_path, "r")) !== false) {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (!empty($line)) {
                    $item = json_decode($line, true);
                    if ($item !== null) {
                        $count++;
                    }
                }
            }
            fclose($handle);
        }
        return $count;
    }
}
