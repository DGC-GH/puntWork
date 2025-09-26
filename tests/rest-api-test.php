<?php
/**
 * Simple test script for REST API functionality
 * This tests basic loading and function existence without requiring WordPress
 */

namespace Puntwork;

// Mock WordPress functions that the REST API depends on
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        if ($special_chars) {
            $chars .= '!@#$%^&*()';
        }
        if ($extra_special_chars) {
            $chars .= '-_ []{}<>~`+=,.;:/?|';
        }
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= substr($chars, rand(0, strlen($chars) - 1), 1);
        }
        return $password;
    }
}

if (!function_exists('get_option')) {
    function get_option($key, $default = null) {
        static $options = [];
        return $options[$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($key, $value) {
        static $options = [];
        $options[$key] = $value;
        return true;
    }
}

if (!function_exists('wp_timezone')) {
    function wp_timezone() {
        return new DateTimeZone('Europe/Brussels');
    }
}

if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null) {
        $timestamp = $timestamp ?? time();
        $dt = new DateTime('@' . $timestamp);
        $dt->setTimezone(wp_timezone());
        return $dt->format($format);
    }
}

if (!function_exists('get_site_url')) {
    function get_site_url() {
        return 'https://example.com';
    }
}

if (!function_exists('check_admin_referer')) {
    function check_admin_referer($action, $query_arg = '_wpnonce') {
        return true;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return true;
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null) {
        echo json_encode(['error' => true, 'data' => $data]);
        exit;
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null) {
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message = '') {
        echo "WordPress died: $message";
        exit;
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

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook) {
        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook) {
        return false;
    }
}

if (!function_exists('microtime')) {
    function microtime($get_as_float = false) {
        return $get_as_float ? microtime(true) : '0.123456 1234567890';
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) {
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        // Mock action registration
        return true;
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = []) {
        // Mock REST route registration
        return true;
    }
}

if (!function_exists('rest_api_init')) {
    function rest_api_init() {
        // Mock REST API init
        return true;
    }
}

if (!function_exists('hash_equals')) {
    function hash_equals($known_string, $user_string) {
        return $known_string === $user_string;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) {
        return true;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) {
        return 'mock_nonce';
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return $str;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($str) {
        return $str;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) {
        return ['body' => 'mock response', 'response' => ['code' => 200]];
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return $response['body'] ?? '';
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return $response['response']['code'] ?? 200;
    }
}

// Mock the import functions that the REST API calls
function run_scheduled_import() {
    return [
        'success' => true,
        'message' => 'Mock import completed',
        'processed' => 10,
        'total' => 10
    ];
}

function get_next_scheduled_time() {
    return [
        'timestamp' => time() + 3600,
        'formatted' => '2025-09-26 15:00',
        'relative' => 'in 1 hour'
    ];
}

function calculate_estimated_time_remaining($progress) {
    return 300; // 5 minutes
}

// Mock classes
class WP_REST_Response {
    public function __construct($data, $status = 200) {
        $this->data = $data;
        $this->status = $status;
    }
}

class WP_Error {
    public function __construct($code, $message, $data = []) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
}

// Define constants
define('ABSPATH', '/tmp/wordpress/');
define('WP_DEBUG', true);

// Load the logger first
require_once __DIR__ . '/../includes/utilities/puntwork-logger.php';

// Load the REST API file
require_once __DIR__ . '/../includes/api/rest-api.php';

// Test basic function existence
echo "Testing REST API functions...\n";

$functions_to_test = [
    'register_import_api_routes',
    'verify_api_key',
    'handle_trigger_import',
    'handle_get_import_status',
    'generate_api_key',
    'get_or_create_api_key',
    'regenerate_api_key'
];

foreach ($functions_to_test as $function) {
    $full_function = 'Puntwork\\' . $function;
    if (function_exists($full_function)) {
        echo "✓ Function $full_function exists\n";
    } else {
        echo "✗ Function $full_function does not exist\n";
    }
}

// Test API key generation
echo "\nTesting API key generation...\n";
$key1 = generate_api_key();
$key2 = generate_api_key();

echo "Generated key 1: " . substr($key1, 0, 10) . "...\n";
echo "Generated key 2: " . substr($key2, 0, 10) . "...\n";

if ($key1 !== $key2 && strlen($key1) === 32 && strlen($key2) === 32) {
    echo "✓ API key generation works correctly\n";
} else {
    echo "✗ API key generation failed\n";
}

// Test API key storage/retrieval
echo "\nTesting API key storage...\n";
$stored_key = get_or_create_api_key();
$stored_key2 = get_or_create_api_key();

if ($stored_key === $stored_key2 && strlen($stored_key) === 32) {
    echo "✓ API key storage and retrieval works\n";
} else {
    echo "✗ API key storage failed\n";
}

// Test API key regeneration
echo "\nTesting API key regeneration...\n";
$old_key = get_or_create_api_key();
$new_key = regenerate_api_key();
$current_key = get_or_create_api_key();

if ($new_key === $current_key && $old_key !== $new_key) {
    echo "✓ API key regeneration works\n";
} else {
    echo "✗ API key regeneration failed\n";
}

echo "\nAll basic tests completed!\n";