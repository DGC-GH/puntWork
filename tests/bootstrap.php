<?php

/**
 * Bootstrap for PHPUnit tests
 */

// Define test mode constant
define('PUNTWORK_TESTING', true);

// Define WordPress constants if not already defined
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
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
    function wp_die()
    {
        die();
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file)
    {
        // For testing, return the project root + includes/
        // Assuming $file is something like includes/utilities/ImportAnalytics.php
        // We want to return the path to the plugin root
        $project_root = dirname(__DIR__); // Go up from tests/ to project root
        return $project_root . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file)
    {
        return 'http://example.com/wp-content/plugins/' . basename(dirname($file)) . '/';
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook)
    {
        return false;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook)
    {
        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook)
    {
        return true;
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir)
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return true;
    }
}

if (!function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules()
    {
        return true;
    }
}

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush()
    {
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time($type = 'timestamp')
    {
        return time();
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $function)
    {
        return true;
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $function)
    {
        return true;
    }
}

if (!function_exists('register_uninstall_hook')) {
    function register_uninstall_hook($file, $function)
    {
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($tag, $function_to_add, $priority = 10, $accepted_args = 1)
    {
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action($tag, $function_to_add, $priority = 10, $accepted_args = 1)
    {
        return true;
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback)
    {
        return true;
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false)
    {
        return true;
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all')
    {
        return true;
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $l10n)
    {
        return true;
    }
}

// Global storage for mocked WordPress options
global $mock_wp_options;
$mock_wp_options = [];

// Global storage for mocked posts
global $mock_wp_posts;
$mock_wp_posts = [];

// Global storage for mocked transients
global $mock_transients;
$mock_transients = [];

if (!function_exists('get_option')) {
    function get_option($key, $default = null)
    {
        global $mock_wp_options;
        return $mock_wp_options[$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($key, $value)
    {
        global $mock_wp_options;
        $mock_wp_options[$key] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($key)
    {
        global $mock_wp_options;
        unset($mock_wp_options[$key]);
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($key)
    {
        global $mock_transients;
        return $mock_transients[$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0)
    {
        global $mock_transients;
        $mock_transients[$key] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($key)
    {
        global $mock_transients;
        unset($mock_transients[$key]);
        return true;
    }
}

if (!function_exists('gethostname')) {
    function gethostname()
    {
        return 'test-server';
    }
}

if (!function_exists('getmypid')) {
    function getmypid()
    {
        return 12345;
    }
}

if (!function_exists('shell_exec')) {
    function shell_exec($cmd)
    {
        return null;
    }
}

if (!function_exists('disk_free_space')) {
    function disk_free_space($directory)
    {
        return 1000000000;
    } // 1GB
}

if (!function_exists('disk_total_space')) {
    function disk_total_space($directory)
    {
        return 2000000000;
    } // 2GB
}

// Mock wpdb class for database operations
if (!class_exists('wpdb')) {
    class wpdb
    {
        public $prefix = 'wp_';

        public function get_results($query, $output = ARRAY_A)
        {
            return [];
        }

        public function get_row($query, $output = ARRAY_A, $y = 0)
        {
            return null;
        }

        public function query($query, $output = ARRAY_A)
        {
            return 0;
        }

        public function prepare($query, ...$args)
        {
            return $query; // Simplified for testing
        }

        public function replace($table, $data, $format = null)
        {
            return 1;
        }

        public function update($table, $data, $where, $format = null, $where_format = null)
        {
            return 1;
        }

        public function get_charset_collate()
        {
            return 'utf8mb4_unicode_ci';
        }

        public function check_connection()
        {
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

    // Import functionality - testing
    'import/combine-jsonl.php',
    'import/download-feed.php',
    'import/import-batch.php',
    'import/import-finalization.php',
    'import/import-setup.php',
    'import/process-batch-items.php',
    'import/reset-import.php',
    'import/process-xml-batch.php',

    // Utilities - only load essential ones
    'utilities/PuntWorkLogger.php',
    'utilities/handle-duplicates.php',
    'utilities/performance-functions.php',
    'utilities/PuntworkHorizontalScalingManager.php',
    'utilities/PuntworkLoadBalancer.php',
    'utilities/SecurityUtils.php',
    'utilities/async-processing.php',

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
foreach ($includes as $include) {
    $file = dirname(__DIR__) . '/includes/' . $include;
    if (file_exists($file)) {
        require_once $file;
    }
}

// Additional mock functions for testing
if (!function_exists('wp_kses')) {
    function wp_kses($string, $allowed_html = [])
    {
        // Simple mock - just return the string for testing
        return $string;
    }
}

if (!function_exists('wp_kses_allowed_html')) {
    function wp_kses_allowed_html($context = 'post')
    {
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
    function wp_kses_post($content)
    {
        return wp_kses($content, wp_kses_allowed_html('post'));
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post($postarr, $wp_error = false)
    {
        global $mock_wp_posts;
        // Mock implementation - return a fake post ID
        static $post_id = 1000;
        $id = $post_id++;

        $post = (object) [
            'ID' => $id,
            'post_title' => $postarr['post_title'] ?? 'Mock Job',
            'post_content' => $postarr['post_content'] ?? 'Mock content',
            'post_status' => $postarr['post_status'] ?? 'publish',
            'post_type' => $postarr['post_type'] ?? 'post',
            'post_modified' => '2024-01-01 12:00:00',
            'post_date' => '2024-01-01 10:00:00',
            'post_excerpt' => $postarr['post_excerpt'] ?? '',
            'guid' => 'mock-guid-' . $id
        ];

        $mock_wp_posts[$id] = $post;
        return $id;
    }
}

if (!function_exists('get_post')) {
    function get_post($post_id)
    {
        global $mock_wp_posts;
        return $mock_wp_posts[$post_id] ?? null;
    }
}

if (!function_exists('wp_delete_post')) {
    function wp_delete_post($post_id, $force = false)
    {
        return true;
    }
}

if (!function_exists('post_type_exists')) {
    function post_type_exists($post_type)
    {
        return in_array($post_type, ['job', 'post', 'page']);
    }
}

if (!function_exists('get_posts')) {
    function get_posts($args = [])
    {
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
    function wp_update_post($postarr)
    {
        global $mock_wp_posts;
        $id = $postarr['ID'];
        if (isset($mock_wp_posts[$id])) {
            foreach ($postarr as $key => $value) {
                if ($key !== 'ID') {
                    $mock_wp_posts[$id]->$key = $value;
                }
            }
        }
        return $id;
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false)
    {
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
    function get_post_meta($post_id, $key, $single = false)
    {
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
    function is_admin()
    {
        return false; // Not in admin for API tests
    }
}

// Mock WordPress REST API classes
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private $method;
        private $route;
        private $params = [];

        public function __construct($method = 'GET', $route = '')
        {
            $this->method = $method;
            $this->route = $route;
        }

        public function set_param($key, $value)
        {
            $this->params[$key] = $value;
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }

        public function get_params()
        {
            return $this->params;
        }

        public function get_method()
        {
            return $this->method;
        }

        public function get_route()
        {
            return $this->route;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        private $data;
        private $status;

        public function __construct($data = null, $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function set_data($data)
        {
            $this->data = $data;
        }

        public function get_status()
        {
            return $this->status;
        }

        public function set_status($status)
        {
            $this->status = $status;
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private $code;
        private $message;

        public function __construct($code, $message)
        {
            $this->code = $code;
            $this->message = $message;
        }

        public function get_error_code()
        {
            return $this->code;
        }

        public function get_error_message()
        {
            return $this->message;
        }
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink($post_id)
    {
        return 'http://example.com/?p=' . $post_id;
    }
}

if (!function_exists('wp_publish_post')) {
    function wp_publish_post($post_id)
    {
        wp_update_post([
            'ID' => $post_id,
            'post_status' => 'publish'
        ]);
    }
}

if (!function_exists('handle_get_import_status')) {
    function handle_get_import_status($request)
    {
        // Mock implementation
        return new \WP_REST_Response([
            'success' => true,
            'status' => 'idle',
            'data' => []
        ], 200);
    }
}

// Mock WP_Query class
if (!class_exists('WP_Query')) {
    class WP_Query
    {
        public $posts = [];
        public $found_posts = 0;
        public $max_num_pages = 0;

        public function __construct($args = [])
        {
            global $mock_wp_posts;
            // Filter posts by args
            $posts = array_filter($mock_wp_posts, function ($post) use ($args) {
                if (isset($args['post_type']) && $post->post_type !== $args['post_type']) {
                    return false;
                }
                if (isset($args['post_status']) && $args['post_status'] !== 'any' && $post->post_status !== $args['post_status']) {
                    return false;
                }
                return true;
            });
            $this->posts = array_values($posts);
            $this->found_posts = count($this->posts);
            $this->max_num_pages = 1;
        }
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        return 1; // Mock admin user
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '')
    {
        $info = [
            'version' => '6.0',
            'name' => 'Test Site',
            'url' => 'http://example.com'
        ];
        return $info[$show] ?? 'Test';
    }
}

if (!function_exists('run_scheduled_import')) {
    function run_scheduled_import($test_mode = false, $trigger = 'scheduled')
    {
        // Mock implementation - return success array
        return [
            'success' => true,
            'processed' => 10,
            'total' => 10,
            'message' => 'Mock import completed'
        ];
    }
}

if (!function_exists('get_next_scheduled_time')) {
    function get_next_scheduled_time()
    {
        // Mock next scheduled time
        return time() + 3600; // 1 hour from now
    }
}

if (!function_exists('size_format')) {
    function size_format($bytes, $decimals = 0)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $decimals) . ' ' . $units[$pow];
    }
}
