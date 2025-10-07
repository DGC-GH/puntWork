<?php
/**
 * Test script to run the import process directly
 */

// Define WordPress constants for testing
define('ABSPATH', __DIR__ . '/');
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);

// Include required files
require_once 'includes/core/core-structure-logic.php';
require_once 'includes/utilities/async-processing.php';
require_once 'includes/scheduling/scheduling-history.php';
require_once 'includes/import/import-batch.php';

// Mock WordPress functions if not available
if (!function_exists('get_option')) {
    function get_option($key, $default = null) {
        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($key, $value) {
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($key) {
        return false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0) {
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($key) {
        return true;
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($path) {
        return mkdir($path, 0755, true);
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return false;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = array()) {
        return true;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return 1;
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($key) {
        return 'Test Site';
    }
}

if (!function_exists('microtime')) {
    function microtime($as_float = false) {
        return $as_float ? microtime(true) : '0.123456 1234567890';
    }
}

if (!function_exists('get_site_url')) {
    function get_site_url() {
        return 'http://localhost';
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

if (!function_exists('post_type_exists')) {
    function post_type_exists($post_type) {
        return false; // Assume no CPT for testing
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        return null;
    }
}

if (!function_exists('get_post')) {
    function get_post($post_id) {
        return null;
    }
}

if (!function_exists('get_posts')) {
    function get_posts($args) {
        return array();
    }
}

if (!function_exists('new WP_Query')) {
    class WP_Query {
        public $found_posts = 0;
        public $posts = array();
        public function have_posts() { return false; }
    }
}

if (!function_exists('function_exists')) {
    function function_exists($function) {
        return false;
    }
}

if (!function_exists('class_exists')) {
    function class_exists($class) {
        return false;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action($tag) {
        return;
    }
}

if (!function_exists('add_action')) {
    function add_action($tag, $function) {
        return;
    }
}

if (!function_exists('wp_defer_term_counting')) {
    function wp_defer_term_counting($defer) {
        return;
    }
}

if (!function_exists('wp_defer_comment_counting')) {
    function wp_defer_comment_counting($defer) {
        return;
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post($postarr) {
        return 1;
    }
}

if (!function_exists('wp_delete_post')) {
    function wp_delete_post($post_id) {
        return true;
    }
}

// Set memory and time limits
ini_set('memory_limit', '1024M');
set_time_limit(600);

echo "Starting import test...\n";

// Test the process_feeds_to_jsonl function
try {
    echo "Calling process_feeds_to_jsonl...\n";
    $result = process_feeds_to_jsonl();
    echo "Result: " . json_encode($result) . "\n";
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

echo "Test completed.\n";
