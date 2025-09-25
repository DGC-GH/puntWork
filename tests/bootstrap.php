<?php
/**
 * Bootstrap for PHPUnit tests
 */

// Define WordPress constants if not already defined
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

// Load WordPress test functions
if (file_exists('/tmp/wordpress-tests-lib/includes/functions.php')) {
    require_once '/tmp/wordpress-tests-lib/includes/functions.php';
} else {
    // Fallback for local testing
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// Load the plugin
require_once dirname(__DIR__) . '/puntwork.php';

// Mock WordPress functions if not in full WP environment
if (!function_exists('wp_die')) {
    function wp_die() { die(); }
}

if (!function_exists('get_option')) {
    function get_option($key, $default = null) { return $default; }
}

if (!function_exists('update_option')) {
    function update_option($key, $value) { return true; }
}

if (!function_exists('get_transient')) {
    function get_transient($key) { return false; }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0) { return true; }
}

if (!function_exists('delete_transient')) {
    function delete_transient($key) { return true; }
}