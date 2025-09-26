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

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// WordPress query constants
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

// Load WordPress test functions
if (file_exists('/tmp/wordpress-tests-lib/includes/functions.php')) {
    require_once '/tmp/wordpress-tests-lib/includes/functions.php';
} else {
    // Fallback for local testing
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// Mock WordPress functions if not in full WP environment
if (!function_exists('wp_die')) {
    function wp_die() { die(); }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        // For testing, return the project root + includes/
        // Assuming $file is something like includes/utilities/import-analytics.php
        // We want to return the path to the plugin root
        $project_root = dirname(__DIR__); // Go up from tests/ to project root
        return $project_root . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) {
        return 'http://example.com/wp-content/plugins/' . basename(dirname($file)) . '/';
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook) { return false; }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook) { return true; }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook) { return true; }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return true;
    }
}

if (!function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules() { return true; }
}

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush() { return true; }
}

if (!function_exists('current_time')) {
    function current_time($type = 'timestamp') {
        return time();
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $function) { return true; }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $function) { return true; }
}

if (!function_exists('register_uninstall_hook')) {
    function register_uninstall_hook($file, $function) { return true; }
}

if (!function_exists('add_filter')) {
    function add_filter($tag, $function_to_add, $priority = 10, $accepted_args = 1) { return true; }
}

if (!function_exists('add_action')) {
    function add_action($tag, $function_to_add, $priority = 10, $accepted_args = 1) { return true; }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) { return true; }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false) { return true; }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all') { return true; }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $l10n) { return true; }
}

// Global storage for mocked WordPress options
global $mock_wp_options;
$mock_wp_options = [];

if (!function_exists('get_option')) {
    function get_option($key, $default = null) {
        global $mock_wp_options;
        return $mock_wp_options[$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($key, $value) {
        global $mock_wp_options;
        $mock_wp_options[$key] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($key) {
        global $mock_wp_options;
        unset($mock_wp_options[$key]);
        return true;
    }
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

if (!function_exists('gethostname')) {
    function gethostname() { return 'test-server'; }
}

if (!function_exists('getmypid')) {
    function getmypid() { return 12345; }
}

if (!function_exists('shell_exec')) {
    function shell_exec($cmd) { return null; }
}

if (!function_exists('disk_free_space')) {
    function disk_free_space($directory) { return 1000000000; } // 1GB
}

if (!function_exists('disk_total_space')) {
    function disk_total_space($directory) { return 2000000000; } // 2GB
}

// Mock wpdb class for database operations
if (!class_exists('wpdb')) {
    class wpdb {
        public $prefix = 'wp_';
        
        public function get_results($query, $output = ARRAY_A) {
            return [];
        }
        
        public function get_row($query, $output = ARRAY_A, $y = 0) {
            return null;
        }
        
        public function query($query, $output = ARRAY_A) {
            return 0;
        }
        
        public function prepare($query, ...$args) {
            return $query; // Simplified for testing
        }
        
        public function replace($table, $data, $format = null) {
            return 1;
        }
        
        public function update($table, $data, $where, $format = null, $where_format = null) {
            return 1;
        }
        
        public function get_charset_collate() {
            return 'utf8mb4_unicode_ci';
        }
        
        public function check_connection() {
            return true;
        }
    }
    
    // Create global wpdb instance
    $GLOBALS['wpdb'] = new wpdb();
}

// Load the plugin
require_once dirname(__DIR__) . '/puntwork.php';

// Manually load includes for testing (since add_action is mocked)
$includes = array(
    // Core functionality - testing
    'core/core-structure-logic.php',
    'core/enqueue-scripts-js.php',
    
    // Admin interface - testing
    'admin/admin-menu.php',
    'admin/admin-page-html.php',
    'admin/admin-ui-debug.php',
    'admin/admin-ui-main.php',
    'admin/admin-ui-scheduling.php',
    'admin/admin-api-settings.php',
    'admin/admin-ui-feed-health.php',
    'admin/admin-ui-analytics.php',
    'admin/onboarding-wizard.php',
    
    // API handlers - testing one by one
    'api/ajax-feed-processing.php',
    'api/rest-api.php',
    
    // Batch processing - testing individual files
    'batch/batch-core.php',
    'batch/batch-data.php',
    'batch/batch-processing.php',
    'batch/batch-size-management.php',
    'batch/batch-utils.php',
    
    // Import functionality - commented out
    /*
    'import/combine-jsonl.php',
    'import/download-feed.php',
    'import/import-batch.php',
    'import/import-finalization.php',
    'import/import-setup.php',
    'import/process-batch-items.php',
    'import/process-xml-batch.php',
    'import/reset-import.php',
    */
    
    // Utilities - only load essential ones
    'utilities/puntwork-logger.php',
    'utilities/handle-duplicates.php',
    'utilities/performance-monitor.php',
    'utilities/horizontal-scaling.php',
    'utilities/load-balancer.php',
    
    // Queue management - commented out
    /*
    'queue/queue-manager.php',
    */
    
    // Mappings
    'mappings/mappings-constants.php',
    'mappings/mappings-fields.php',
    'mappings/mappings-geographic.php',
    'mappings/mappings-icons.php',
    'mappings/mappings-salary.php',
    'mappings/mappings-schema.php',
    
    // Scheduling - commented out
    /*
    'scheduling/scheduling-ajax.php',
    'scheduling/scheduling-core.php',
    'scheduling/scheduling-history.php',
    'scheduling/scheduling-triggers.php',
    'scheduling/test-scheduling.php',
    */
);
foreach ( $includes as $include ) {
    $file = dirname(__DIR__) . '/includes/' . $include;
    if ( file_exists( $file ) ) {
        require_once $file;
    }
}

// Additional mock functions for testing
if (!function_exists('wp_kses')) {
    function wp_kses($string, $allowed_html = []) {
        // Simple mock - just return the string for testing
        return $string;
    }
}

if (!function_exists('wp_kses_allowed_html')) {
    function wp_kses_allowed_html($context = 'post') {
        // Return basic allowed HTML tags for testing
        return [
            'a' => ['href' => [], 'title' => []],
            'br' => [],
            'em' => [],
            'strong' => [],
            'p' => [],
        ];
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($content) {
        return wp_kses($content, wp_kses_allowed_html('post'));
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post($postarr, $wp_error = false) {
        // Mock implementation - return a fake post ID
        static $post_id = 1000;
        return $post_id++;
    }
}

if (!function_exists('get_post')) {
    function get_post($post_id) {
        // Mock post object with all required properties
        return (object) [
            'ID' => $post_id,
            'post_title' => 'Mock Job',
            'post_content' => 'Mock content',
            'post_status' => 'publish',
            'post_type' => 'job',
            'post_modified' => '2024-01-01 12:00:00',
            'post_date' => '2024-01-01 10:00:00',
            'guid' => 'mock-guid-' . $post_id
        ];
    }
}

if (!function_exists('wp_delete_post')) {
    function wp_delete_post($post_id, $force = false) {
        return true;
    }
}

if (!function_exists('post_type_exists')) {
    function post_type_exists($post_type) {
        return in_array($post_type, ['job', 'post', 'page']);
    }
}

if (!function_exists('get_posts')) {
    function get_posts($args = []) {
        // Return mock posts
        return [
            (object) [
                'ID' => 1001,
                'post_title' => 'Mock Job 1',
                'post_status' => 'publish'
            ]
        ];
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post($postarr) {
        return $postarr['ID'] ?? 1001;
    }
}

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
            $password .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $password;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        // Mock post meta
        $mock_meta = [
            'guid' => 'mock-guid-' . $post_id,
            'company' => 'Mock Company',
            'location' => 'Mock City, Mock State',
            'salary' => '$50,000 - $70,000'
        ];
        return $mock_meta[$key] ?? '';
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return false; // Not in admin for API tests
    }
}

// Mock WordPress REST API classes
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $method;
        private $route;
        private $params = [];
        
        public function __construct($method = 'GET', $route = '') {
            $this->method = $method;
            $this->route = $route;
        }
        
        public function set_param($key, $value) {
            $this->params[$key] = $value;
        }
        
        public function get_param($key) {
            return $this->params[$key] ?? null;
        }
        
        public function get_params() {
            return $this->params;
        }
        
        public function get_method() {
            return $this->method;
        }
        
        public function get_route() {
            return $this->route;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        private $data;
        private $status;
        
        public function __construct($data = null, $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }
        
        public function get_data() {
            return $this->data;
        }
        
        public function set_data($data) {
            $this->data = $data;
        }
        
        public function get_status() {
            return $this->status;
        }
        
        public function set_status($status) {
            $this->status = $status;
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;
        
        public function __construct($code, $message) {
            $this->code = $code;
            $this->message = $message;
        }
        
        public function get_error_code() {
            return $this->code;
        }
        
        public function get_error_message() {
            return $this->message;
        }
    }
}