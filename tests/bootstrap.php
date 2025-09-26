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
        return dirname($file) . '/';
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

// Load the plugin
require_once dirname(__DIR__) . '/puntwork.php';

// Manually load includes for testing (since add_action is mocked)
$includes = array(
    // Core functionality
    'core/core-structure-logic.php',
    'core/enqueue-scripts-js.php',
    
    // Admin interface
    'admin/admin-menu.php',
    'admin/admin-page-html.php',
    'admin/admin-ui-debug.php',
    'admin/admin-ui-main.php',
    'admin/admin-ui-scheduling.php',
    'admin/admin-api-settings.php',
    
    // API handlers
    'api/ajax-feed-processing.php',
    'api/ajax-handlers.php',
    'api/ajax-import-control.php',
    'api/ajax-purge.php',
    'api/rest-api.php',
    
    // Batch processing
    'batch/batch-core.php',
    'batch/batch-data.php',
    'batch/batch-processing.php',
    'batch/batch-size-management.php',
    'batch/batch-utils.php',
    
    // Import functionality
    'import/combine-jsonl.php',
    'import/download-feed.php',
    'import/import-batch.php',
    'import/import-finalization.php',
    'import/import-setup.php',
    'import/process-batch-items.php',
    'import/process-xml-batch.php',
    'import/reset-import.php',
    
    // Utilities
    'utilities/puntwork-logger.php',
    'utilities/gzip-file.php',
    'utilities/handle-duplicates.php',
    'utilities/heartbeat-control.php',
    'utilities/item-cleaning.php',
    'utilities/item-inference.php',
    'utilities/shortcode.php',
    'utilities/utility-helpers.php',
    
    // Mappings
    'mappings/mappings-constants.php',
    'mappings/mappings-fields.php',
    'mappings/mappings-geographic.php',
    'mappings/mappings-icons.php',
    'mappings/mappings-salary.php',
    'mappings/mappings-schema.php',
    
    // Scheduling
    'scheduling/scheduling-ajax.php',
    'scheduling/scheduling-core.php',
    'scheduling/scheduling-history.php',
    'scheduling/scheduling-triggers.php',
    'scheduling/test-scheduling.php',
);
foreach ( $includes as $include ) {
    $file = dirname(__DIR__) . '/includes/' . $include;
    if ( file_exists( $file ) ) {
        require_once $file;
    }
}