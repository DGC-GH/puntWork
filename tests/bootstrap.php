<?php

/**
 * Bootstrap for PHPUnit tests
 */

// Define test mode constant
define( 'PUNTWORK_TESTING', true );
define( 'PHPUNIT_RUNNING', true );

// Define WordPress constants if not already defined
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// WordPress query constants
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

// Define PuntWork constants for testing
if ( ! defined( 'PUNTWORK_PATH' ) ) {
	define( 'PUNTWORK_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'PUNTWORK_URL' ) ) {
	define( 'PUNTWORK_URL', 'http://example.com/wp-content/plugins/puntwork/' );
}

if ( ! defined( 'PUNTWORK_LOGS' ) ) {
	define( 'PUNTWORK_LOGS', PUNTWORK_PATH . 'logs/import.log' );
}

if ( ! defined( 'PUNTWORK_VERSION' ) ) {
	define( 'PUNTWORK_VERSION', '0.0.6' );
}

// Load WordPress test functions
if ( file_exists( '/tmp/wordpress-tests-lib/includes/functions.php' ) ) {
	include_once '/tmp/wordpress-tests-lib/includes/functions.php';
} else {
	// Fallback for local testing
	include_once dirname( __DIR__ ) . '/vendor/autoload.php';
}

// Mock WordPress functions if not in full WP environment
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die() {
		die();
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		// For testing, return the project root + includes/
		// Assuming $file is something like includes/utilities/ImportAnalytics.php
		// We want to return the path to the plugin root
		$project_root = dirname( __DIR__ ); // Go up from tests/ to project root
		return $project_root . '/';
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook ) {
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook ) {
		return true;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) {
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		return true;
	}
}

if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules() {
		return true;
	}
}

if ( ! function_exists( 'wp_cache_flush' ) ) {
	function wp_cache_flush() {
		return true;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'timestamp' ) {
		return time();
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $function ) {
		return true;
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $function ) {
		return true;
	}
}

if ( ! function_exists( 'register_uninstall_hook' ) ) {
	function register_uninstall_hook( $file, $function ) {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {
		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		return true;
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $l10n ) {
		return true;
	}
}

// Global storage for mocked WordPress options
global $mock_wp_options;
$mock_wp_options = array();

// Global storage for mocked posts
global $mock_wp_posts;
$mock_wp_posts = array();

// Global storage for mocked network options
global $mock_network_options;
$mock_network_options = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = null ) {
		global $mock_wp_options;
		return $mock_wp_options[ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		global $mock_wp_options;
		$mock_wp_options[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		global $mock_wp_options;
		unset( $mock_wp_options[ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		global $mock_transients;
		return $mock_transients[ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		global $mock_transients;
		$mock_transients[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		global $mock_transients;
		unset( $mock_transients[ $key ] );
		return true;
	}
}

if ( ! function_exists( 'gethostname' ) ) {
	function gethostname() {
		return 'test-server';
	}
}

if ( ! function_exists( 'getmypid' ) ) {
	function getmypid() {
		return 12345;
	}
}

if ( ! function_exists( 'shell_exec' ) ) {
	function shell_exec( $cmd ) {
		return null;
	}
}

if ( ! function_exists( 'disk_free_space' ) ) {
	function disk_free_space( $directory ) {
		return 1000000000;
	} // 1GB
}

if ( ! function_exists( 'disk_total_space' ) ) {
	function disk_total_space( $directory ) {
		return 2000000000;
	} // 2GB
}

// Mock wpdb class for database operations
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {


		public $prefix      = 'wp_';
		public $base_prefix = 'wp_';

		public function get_results( $query, $output = ARRAY_A ) {
			if ( strpos( $query, 'DESCRIBE' ) !== false ) {
				// Mock table structure for DESCRIBE queries
				if ( strpos( $query, 'puntwork_network_jobs' ) !== false ) {
					return array(
						(object) array(
							'Field' => 'id',
							'Type'  => 'bigint(20)',
							'Null'  => 'NO',
							'Key'   => 'PRI',
						),
						(object) array(
							'Field' => 'job_id',
							'Type'  => 'varchar(100)',
							'Null'  => 'NO',
							'Key'   => '',
						),
						(object) array(
							'Field' => 'site_id',
							'Type'  => 'bigint(20)',
							'Null'  => 'NO',
							'Key'   => '',
						),
						(object) array(
							'Field' => 'status',
							'Type'  => 'varchar(20)',
							'Null'  => 'NO',
							'Key'   => '',
						),
						(object) array(
							'Field' => 'priority',
							'Type'  => 'int(11)',
							'Null'  => 'NO',
							'Key'   => '',
						),
						(object) array(
							'Field' => 'data',
							'Type'  => 'longtext',
							'Null'  => 'YES',
							'Key'   => '',
						),
						(object) array(
							'Field' => 'created_at',
							'Type'  => 'datetime',
							'Null'  => 'NO',
							'Key'   => '',
						),
						(object) array(
							'Field' => 'updated_at',
							'Type'  => 'datetime',
							'Null'  => 'NO',
							'Key'   => '',
						),
					);
				}
			}
			return array();
		}

		public function get_row( $query, $output = ARRAY_A, $y = 0 ) {
			return null;
		}

		public function query( $query, $output = ARRAY_A ) {
			return 0;
		}

		public function prepare( $query, ...$args ) {
			return $query; // Simplified for testing
		}

		public function replace( $table, $data, $format = null ) {
			return 1;
		}

		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			return 1;
		}

		public function get_charset_collate() {
			return 'utf8mb4_unicode_ci';
		}

		public function get_var( $query, $x = 0, $y = 0 ) {
			// Mock implementation - return a simple value for testing
			if ( strpos( $query, 'SHOW TABLES LIKE' ) !== false ) {
				// For table existence checks, return the table name
				if ( strpos( $query, 'wp_puntwork_network_jobs' ) !== false ) {
					return 'wp_puntwork_network_jobs';
				}
				if ( strpos( $query, 'wp_puntwork_load_balancer' ) !== false ) {
					return 'wp_puntwork_load_balancer';
				}
				if ( strpos( $query, 'wp_puntwork_instances' ) !== false ) {
					return 'wp_puntwork_instances';
				}
			}
			return 1;
		}

		public function get_col( $query, $x = 0 ) {
			return array( 1, 2, 3 );
		}
	}

	// Create global wpdb instance
	$GLOBALS['wpdb'] = new wpdb();
	global $wpdb;
	$wpdb = $GLOBALS['wpdb'];
}

// Define essential functions that may be needed by included files
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false; // Not in admin for API tests
	}
}

if ( ! function_exists( 'handle_get_import_status' ) ) {
	function handle_get_import_status( $request ) {
		// Mock implementation matching test expectations
		return new \WP_REST_Response(
			array(
				'success' => true,
				'status'  => 'idle',
				'data'    => array(),
			),
			200
		);
	}
}

// Mock WordPress plugin functions
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $function ) {
		// Mock - do nothing in test environment
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $function ) {
		// Mock - do nothing in test environment
	}
}

if ( ! function_exists( 'register_uninstall_hook' ) ) {
	function register_uninstall_hook( $file, $function ) {
		// Mock - do nothing in test environment
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $function, $priority = 10, $accepted_args = 1 ) {
		// Mock - do nothing in test environment
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $function, $priority = 10, $accepted_args = 1 ) {
		// Mock - do nothing in test environment
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $tag ) {
		return 0; // Mock - action hasn't been fired
	}
}

if ( ! function_exists( 'has_action' ) ) {
	function has_action( $tag, $function_to_check = false ) {
		return false; // Mock - no actions registered
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $tag, $function_to_check = false ) {
		return false; // Mock - no filters registered
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {
		// Mock - do nothing in test environment
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		return $value; // Mock - return value unchanged
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
		// Mock - do nothing in test environment
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		return false; // Mock - no events scheduled
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array() ) {
		// Mock - do nothing in test environment
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
		// Mock - do nothing in test environment
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		return time(); // Mock - return current timestamp
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) {
		return mkdir( $dir, 0755, true ); // Use PHP's mkdir
	}
}

if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( $hard = true ) {
		// Mock - do nothing in test environment
	}
}

if ( ! function_exists( 'wp_cache_flush' ) ) {
	function wp_cache_flush() {
		// Mock - do nothing in test environment
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
		global $mock_options;
		if ( ! isset( $mock_options[ $option ] ) ) {
			$mock_options[ $option ] = $value;
		}
		return true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		global $mock_options;
		$mock_options[ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		global $mock_options;
		return $mock_options[ $option ] ?? $default;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		global $mock_options;
		unset( $mock_options[ $option ] );
		return true;
	}
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
		// Mock - do nothing in test environment
		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text; // Mock - return text unchanged
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( $file ); // Mock - return basename
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return dirname( $file ) . '/'; // Mock - return directory path
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/'; // Mock URL
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $string, $allowed_html = array() ) {
		// Simple mock - just return the string for testing
		return $string;
	}
}

if ( ! function_exists( 'wp_kses_allowed_html' ) ) {
	function wp_kses_allowed_html( $context = 'post' ) {
		// Return basic allowed HTML tags for testing
		return array(
			'a'      => array(
				'href'  => array(),
				'title' => array(),
			),
			'br'     => array(),
			'em'     => array(),
			'strong' => array(),
			'p'      => array(),
		);
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ) {
		return wp_kses( $content, wp_kses_allowed_html( 'post' ) );
	}
}

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

	// API handlers - testing one by one
	// 'api/ajax-feed-processing.php',
	// 'api/rest-api.php',

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
	'import/process-xml-batch.php',

	// CRM functionality - testing
	'crm/crm-integration.php',
	'crm/crm-manager.php',
	'crm/hubspot-integration.php',
	'crm/pipedrive-integration.php',
	'crm/salesforce-integration.php',
	'crm/zoho-integration.php',

	// Utilities - only load essential ones
	'utilities/PuntWorkLogger.php',
	'utilities/feeds-path-utils.php',
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
foreach ( $includes as $include ) {
	$file = dirname( __DIR__ ) . '/includes/' . $include;
	if ( file_exists( $file ) ) {
		include_once $file;
	}
}

// Define additional functions that may be needed by namespaced includes
if ( ! function_exists( 'get_next_scheduled_time' ) ) {
	function get_next_scheduled_time() {
		// Mock next scheduled time
		return time() + 3600; // 1 hour from now
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 1; // Mock admin user
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) {
		$units  = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$bytes  = max( $bytes, 0 );
		$pow    = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow    = min( $pow, count( $units ) - 1 );
		$bytes /= ( 1 << ( 10 * $pow ) );
		return round( $bytes, $decimals ) . ' ' . $units[ $pow ];
	}
}

if ( ! function_exists( 'wp_count_posts' ) ) {
	function wp_count_posts( $type = 'post', $perm = '' ) {
		return (object) array(
			'publish' => 10,
			'draft'   => 2,
			'pending' => 1,
			'private' => 0,
			'future'  => 0,
		);
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return true; // Mock admin permissions for testing
	}
}

// Additional mock functions for testing
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $string, $allowed_html = array() ) {
		// Simple mock - just return the string for testing
		return $string;
	}
}

if ( ! function_exists( 'wp_kses_allowed_html' ) ) {
	function wp_kses_allowed_html( $context = 'post' ) {
		// Return basic allowed HTML tags for testing
		return array(
			'a'      => array(
				'href'  => array(),
				'title' => array(),
			),
			'br'     => array(),
			'em'     => array(),
			'strong' => array(),
			'p'      => array(),
		);
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ) {
		return wp_kses( $content, wp_kses_allowed_html( 'post' ) );
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $postarr, $wp_error = false ) {
		global $mock_wp_posts;
		// Mock implementation - return a fake post ID
		static $post_id = 1000;
		$id             = $post_id++;

		$post = (object) array(
			'ID'            => $id,
			'post_title'    => $postarr['post_title'] ?? 'Mock Job',
			'post_content'  => $postarr['post_content'] ?? 'Mock content',
			'post_status'   => $postarr['post_status'] ?? 'publish',
			'post_type'     => $postarr['post_type'] ?? 'post',
			'post_modified' => '2024-01-01 12:00:00',
			'post_date'     => '2024-01-01 10:00:00',
			'post_excerpt'  => $postarr['post_excerpt'] ?? '',
			'guid'          => 'mock-guid-' . $id,
		);

		$mock_wp_posts[ $id ] = $post;
		return $id;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		global $mock_wp_posts;
		return $mock_wp_posts[ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $post_id, $force = false ) {
		return true;
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $post_type ) {
		return in_array( $post_type, array( 'job', 'post', 'page' ) );
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		// Return mock posts
		return array(
			(object) array(
				'ID'          => 1001,
				'post_title'  => 'Mock Job 1',
				'post_status' => 'publish',
			),
		);
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $postarr ) {
		global $mock_wp_posts;
		$id = $postarr['ID'];
		if ( isset( $mock_wp_posts[ $id ] ) ) {
			foreach ( $postarr as $key => $value ) {
				if ( $key !== 'ID' ) {
					$mock_wp_posts[ $id ]->$key = $value;
				}
			}
		}
		return $id;
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		if ( $special_chars ) {
			$chars .= '!@#$%^&*()';
		}
		if ( $extra_special_chars ) {
			$chars .= '-_ []{}<>~`+=,.;:/?|';
		}
		$password = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$password .= $chars[ rand( 0, strlen( $chars ) - 1 ) ];
		}
		return $password;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		// Mock post meta
		$mock_meta = array(
			'guid'     => 'mock-guid-' . $post_id,
			'company'  => 'Mock Company',
			'location' => 'Mock City, Mock State',
			'salary'   => '$50,000 - $70,000',
		);
		return $mock_meta[ $key ] ?? '';
	}
}

// Mock WordPress REST API classes
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {


		private $method;
		private $route;
		private $params = array();

		public function __construct( $method = 'GET', $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
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

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {


		private $data;
		private $status;

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function set_data( $data ) {
			$this->data = $data;
		}

		public function get_status() {
			return $this->status;
		}

		public function set_status( $status ) {
			$this->status = $status;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {


		private $code;
		private $message;

		public function __construct( $code, $message ) {
			$this->code    = $code;
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

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post_id ) {
		return 'http://example.com/?p=' . $post_id;
	}
}

if ( ! function_exists( 'wp_publish_post' ) ) {
	function wp_publish_post( $post_id ) {
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
	}
}

// Mock WP_Query class
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {


		public $posts         = array();
		public $found_posts   = 0;
		public $max_num_pages = 0;

		public function __construct( $args = array() ) {
			global $mock_wp_posts;
			// Filter posts by args
			$posts               = array_filter(
				$mock_wp_posts,
				function ( $post ) use ( $args ) {
					if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) {
						return false;
					}
					if ( isset( $args['post_status'] ) && $args['post_status'] !== 'any' && $post->post_status !== $args['post_status'] ) {
						return false;
					}
						return true;
				}
			);
			$this->posts         = array_values( $posts );
			$this->found_posts   = count( $this->posts );
			$this->max_num_pages = 1;
		}
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return true; // Mock admin permissions for testing
	}
}

if ( ! function_exists( 'wp_count_posts' ) ) {
	function wp_count_posts( $type = 'post', $perm = '' ) {
		return (object) array(
			'publish' => 10,
			'draft'   => 2,
			'pending' => 1,
			'private' => 0,
			'future'  => 0,
		);
	}
}

if ( ! function_exists( 'has_action' ) ) {
	function has_action( $tag, $function_to_check = false ) {
		return false; // Mock - no actions registered in test environment
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $tag, $function_to_check = false ) {
		return false; // Mock - no filters registered in test environment
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		$info = array(
			'version' => '6.0',
			'name'    => 'Test Site',
			'url'     => 'http://example.com',
		);
		return $info[ $show ] ?? 'Test';
	}
}

if ( ! function_exists( 'run_scheduled_import' ) ) {
	function run_scheduled_import( $test_mode = false, $trigger = 'scheduled' ) {
		// Mock implementation - return success array
		return array(
			'success'   => true,
			'processed' => 10,
			'total'     => 10,
			'message'   => 'Mock import completed',
		);
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) {
		$units  = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$bytes  = max( $bytes, 0 );
		$pow    = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow    = min( $pow, count( $units ) - 1 );
		$bytes /= ( 1 << ( 10 * $pow ) );
		return round( $bytes, $decimals ) . ' ' . $units[ $pow ];
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return true; // Enable multisite for testing
	}
}

if ( ! function_exists( 'get_sites' ) ) {
	function get_sites( $args = array() ) {
		// Mock sites for multisite testing
		return array(
			(object) array(
				'blog_id'  => 1,
				'site_id'  => 1,
				'domain'   => 'example.com',
				'path'     => '/',
				'public'   => 1,
				'archived' => 0,
				'mature'   => 0,
				'spam'     => 0,
				'deleted'  => 0,
				'lang_id'  => 0,
			),
		);
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $blog_id ) {
		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() {
		return true;
	}
}

if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	function is_plugin_active_for_network( $plugin ) {
		return true; // Assume plugin is active for network testing
	}
}

if ( ! function_exists( 'network_admin_url' ) ) {
	function network_admin_url( $path = '' ) {
		return 'http://example.com/wp-admin/network/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'get_network_option' ) ) {
	function get_network_option( $network_id, $option, $default = null ) {
		global $mock_network_options;
		return $mock_network_options[ $network_id ][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_network_option' ) ) {
	function update_network_option( $network_id, $option, $value ) {
		global $mock_network_options;
		$mock_network_options[ $network_id ][ $option ] = $value;
		return true;
	}
}

// Load the plugin AFTER all mocks are defined
require_once dirname( __DIR__ ) . '/puntwork.php';
