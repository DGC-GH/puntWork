<?php

// Define basic WordPress constants for PHPStan
define('ABSPATH', '/var/www/html/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');

// Mock some WordPress functions if needed
if (!function_exists('wp_die')) {
    function wp_die() {}
}
if (!function_exists('wp_send_json')) {
    function wp_send_json($data) {}
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data) {}
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data, $status = 400) {}
}
if (!function_exists('current_time')) {
    function current_time($type = 'timestamp') {
        return time();
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return 1;
    }
}
if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        return (object) ['ID' => 1, 'user_login' => 'admin'];
    }
}
if (!function_exists('wp_update_post')) {
    function wp_update_post($postarr) {
        return 1;
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        return strtolower(str_replace(' ', '-', $title));
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value) {
        return true;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $meta_key, $single = false) {
        return '';
    }
}
if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        return true;
    }
}
if (!function_exists('get_transient')) {
    function get_transient($transient) {
        return false;
    }
}
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        return true;
    }
}
if (!function_exists('gethostname')) {
    function gethostname() {
        return 'localhost';
    }
}
if (!function_exists('disk_free_space')) {
    function disk_free_space($directory) {
        return 1000000000;
    }
}
if (!function_exists('disk_total_space')) {
    function disk_total_space($directory) {
        return 2000000000;
    }
}
if (!function_exists('memory_get_peak_usage')) {
    function memory_get_peak_usage($real_usage = false) {
        return 1000000;
    }
}
if (!function_exists('ini_get')) {
    function ini_get($varname) {
        return '128M';
    }
}
if (!function_exists('get_memory_limit_bytes')) {
    function get_memory_limit_bytes() {
        return 134217728;
    }
}

// Mock global $wpdb
global $wpdb;
$wpdb = new stdClass();
$wpdb->postmeta = 'wp_postmeta';
$wpdb->posts = 'wp_posts';
$wpdb->options = 'wp_options';
$wpdb->prefix = 'wp_';
$wpdb->num_queries = 0;
$wpdb->prepare = function($query, ...$args) { return $query; };
$wpdb->query = function($query) { return true; };
$wpdb->get_results = function($query, $output = OBJECT) { return []; };
$wpdb->get_row = function($query, $output = OBJECT, $y = 0) { return null; };
$wpdb->get_col = function($query) { return []; };
$wpdb->get_var = function($query) { return null; };
$wpdb->insert = function($table, $data, $format = null) { return 1; };
$wpdb->update = function($table, $data, $where, $format = null, $where_format = null) { return 1; };
$wpdb->replace = function($table, $data, $format = null) { return 1; };
$wpdb->delete = function($table, $where, $where_format = null) { return 1; };

// Mock classes
class WP_Error {
    public function __construct($code = '', $message = '', $data = '') {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
    public function add($code, $message, $data = '') {}
    public function has_errors() { return false; }
}
class WP_Post {}

// Define constants
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);