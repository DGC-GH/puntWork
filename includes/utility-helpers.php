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
