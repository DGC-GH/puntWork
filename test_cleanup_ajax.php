<?php

/**
 * Simple test script to debug the cleanup AJAX functionality
 */

// Define minimal WordPress constants
define('ABSPATH', '/tmp/');
define('WP_DEBUG', true);
define('WP_MEMORY_LIMIT', '256M');

// Mock essential WordPress functions
function wp_verify_nonce($nonce, $action) {
    // For testing, accept any nonce that matches the action
    return $nonce === $action . '_test_nonce' ? 1 : false;
}

function current_user_can($capability) {
    return true; // Mock admin user
}

function get_current_user_id() {
    return 1;
}

function wp_send_json_error($data) {
    echo "ERROR: " . json_encode($data) . "\n";
    exit;
}

function wp_send_json_success($data) {
    echo "SUCCESS: " . json_encode($data) . "\n";
    exit;
}

function wp_die($message = '') {
    echo "WP_DIE: $message\n";
    exit;
}

function get_option($key, $default = null) {
    static $options = [];
    return $options[$key] ?? $default;
}

function update_option($key, $value) {
    static $options = [];
    $options[$key] = $value;
    return true;
}

function delete_option($key) {
    static $options = [];
    unset($options[$key]);
    return true;
}

function get_transient($key) {
    static $transients = [];
    return $transients[$key] ?? false;
}

function set_transient($key, $value, $expiration = 0) {
    static $transients = [];
    $transients[$key] = $value;
    return true;
}

function delete_transient($key) {
    static $transients = [];
    unset($transients[$key]);
    return true;
}

function wp_defer_term_counting($defer = null) {
    return true;
}

function wp_defer_comment_counting($defer = null) {
    return true;
}

function wp_delete_post($post_id, $force = false) {
    echo "MOCK: Would delete post $post_id (force: " . ($force ? 'true' : 'false') . ")\n";
    return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    echo "MOCK: add_action('$hook', callback, $priority, $accepted_args)\n";
    return true;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) {
    echo "MOCK: wp_schedule_event($timestamp, '$recurrence', '$hook')\n";
    return true;
}

function wp_next_scheduled($hook, $args = array()) {
    return false; // Mock as not scheduled
}

function is_wp_error($thing) {
    return $thing instanceof WP_Error;
}

function current_time($type = 'mysql', $gmt = false) {
    return date('Y-m-d H:i:s');
}

function apply_filters($tag, $value) {
    return $value; // Mock filter application
}

// Mock WP_Error class
class WP_Error {
    private $code;
    private $message;

    public function __construct($code, $message) {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_code() {
        return $this->code;
    }
}

// Define WordPress constants
define('ARRAY_A', 'ARRAY_A');

// Mock wpdb class
class wpdb {
    public $ready = true;
    public $posts = 'wp_posts'; // Add the posts table property

    public function get_var($query) {
        echo "MOCK DB QUERY: $query\n";
        // Return 5 for job count queries
        if (strpos($query, "COUNT(*) FROM") !== false && strpos($query, "post_type = 'job'") !== false) {
            return 5;
        }
        return 0;
    }

    public function get_results($query, $output = ARRAY_A) {
        echo "MOCK DB QUERY: $query\n";
        // Return mock job posts
        if (strpos($query, "FROM") !== false && strpos($query, "post_type = 'job'") !== false) {
            return [
                (object)['ID' => 1001, 'post_status' => 'draft', 'post_title' => 'Test Draft Job 1'],
                (object)['ID' => 1002, 'post_status' => 'trash', 'post_title' => 'Test Trash Job 1'],
            ];
        }
        return [];
    }

    public function prepare($query, ...$args) {
        return $query; // Simplified
    }
}

global $wpdb;
$wpdb = new wpdb();

// Mock logger class
class PuntWorkLogger {
    const CONTEXT_AJAX = 'ajax';
    const CONTEXT_PURGE = 'purge';

    public static function logAjaxRequest($action, $data) {
        echo "LOG: AJAX Request - $action\n";
    }

    public static function logAjaxResponse($action, $data, $success = true) {
        echo "LOG: AJAX Response - $action - " . ($success ? 'SUCCESS' : 'FAILED') . "\n";
    }

    public static function info($message, $context, $data = []) {
        echo "LOG INFO [$context]: $message\n";
    }

    public static function error($message, $context, $data = []) {
        echo "LOG ERROR [$context]: $message\n";
    }

    public static function warn($message, $context, $data = []) {
        echo "LOG WARN [$context]: $message\n";
    }
}

// Mock error handler
class AjaxErrorHandler {
    public static function sendError($message) {
        echo "AJAX ERROR: $message\n";
        exit;
    }

    public static function sendSuccess($data) {
        echo "AJAX SUCCESS: " . json_encode($data) . "\n";
        exit;
    }
}

// Mock security utils
class SecurityUtils {
    public static function validateAjaxRequest($action, $nonce_action, $required_fields = [], $validation_rules = []) {
        echo "VALIDATING: action=$action, nonce_action=$nonce_action\n";

        // Check nonce
        $nonce = $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, $nonce_action)) {
            echo "NONCE VALIDATION FAILED: got '$nonce', expected for '$nonce_action'\n";
            return new WP_Error('security', 'Security check failed: invalid nonce');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            return new WP_Error('permissions', 'Insufficient permissions');
        }

        echo "VALIDATION PASSED\n";
        return true;
    }
}

// Load the actual AJAX purge file
require_once __DIR__ . '/includes/api/ajax-purge.php';

// Simulate the AJAX request
echo "=== TESTING CLEANUP AJAX FUNCTIONALITY ===\n";

// Test 1: Valid nonce
echo "\n--- Test 1: Valid nonce ---\n";
$_POST = [
    'action' => 'job_import_cleanup_duplicates',
    'nonce' => 'job_import_nonce_test_nonce'  // This should pass wp_verify_nonce
];

try {
    \Puntwork\job_import_cleanup_duplicates_ajax();
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

// Test 2: Invalid nonce
echo "\n--- Test 2: Invalid nonce ---\n";
$_POST = [
    'action' => 'job_import_cleanup_duplicates',
    'nonce' => 'invalid_nonce'
];

try {
    \Puntwork\job_import_cleanup_duplicates_ajax();
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

echo "\n=== TEST COMPLETE ===\n";