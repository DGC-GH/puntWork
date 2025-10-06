<?php

/**
 * Plugin Name: puntWork
 * Description: Advanced job import plugin with multi-format feed support, time analytics, health monitoring, AI-powered features, CRM integrations, multi-site support, horizontal scaling, GraphQL API, webhooks, and mobile app.
 * Version: 0.0.7
 * Author: DGC-GH
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 8.1
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: puntwork
 * Domain Path: /languages.
 */

namespace Puntwork;

// Prevent multiple plugin loading (critical performance fix)
if ( isset( $GLOBALS['puntwork_plugin_loaded'] ) && $GLOBALS['puntwork_plugin_loaded'] ) {
	return;
}
$GLOBALS['puntwork_plugin_loaded'] = true;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PUNTWORK_VERSION', '0.0.7' );
define( 'PUNTWORK_PATH', plugin_dir_path( __FILE__ ) );
define( 'PUNTWORK_URL', plugin_dir_url( __FILE__ ) );
define( 'PUNTWORK_LOGS', PUNTWORK_PATH . 'logs/import.log' );

// Load Composer autoloader if available
if ( file_exists( PUNTWORK_PATH . 'vendor/autoload.php' ) ) {
	include_once PUNTWORK_PATH . 'vendor/autoload.php';
}

// Initialize Action Scheduler after WordPress is loaded
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init_action_scheduler', 0 );
function init_action_scheduler() {
	// Check if Action Scheduler is already available (from WooCommerce or another plugin)
	if ( function_exists( 'as_schedule_single_action' ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// error_log( '[PUNTWORK] [ACTION-SCHEDULER] Action Scheduler already available from another source' );
		}
		return;
	}

	// Load bundled Action Scheduler since it's not available from external sources
	$action_scheduler_path = PUNTWORK_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
	if ( file_exists( $action_scheduler_path ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// error_log( '[PUNTWORK] [ACTION-SCHEDULER] Loading bundled Action Scheduler from: ' . $action_scheduler_path );
		}
		require_once $action_scheduler_path;
	} else {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// error_log( '[PUNTWORK] [ACTION-SCHEDULER] ERROR: Bundled Action Scheduler not found at: ' . $action_scheduler_path );
		}
	}
}

// =====================================================================================
// PLUGIN INITIALIZATION - RUNS ONCE WHEN PLUGIN LOADS
// =====================================================================================

// Increase memory limit to prevent exhaustion (increased for large imports)
ini_set( 'memory_limit', '1536M' );

$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

// Temporarily disabled to reduce log clutter
// if ( $debug_mode ) {
// 	error_log( '[PUNTWORK] [PLUGIN-LOAD] ===== PLUGIN INITIALIZATION START =====' );
// }

// =====================================================================================
// END PLUGIN INITIALIZATION
// =====================================================================================

// Activation hook
register_activation_hook( __FILE__, __NAMESPACE__ . '\\job_import_activate' );
function job_import_activate() {
	// Schedule cron - DISABLED: Background processing disabled
	// if ( ! wp_next_scheduled( 'job_import_cron' ) ) {
	// 	wp_schedule_event( current_time( 'timestamp' ), 'daily', 'job_import_cron' );
	// }

	// Schedule social media cron - DISABLED: Background processing disabled
	// if ( ! wp_next_scheduled( 'puntwork_social_cron' ) ) {
	// 	wp_schedule_event( current_time( 'timestamp' ), 'puntwork_hourly', 'puntwork_social_cron' );
	// }

	// Create logs dir if needed
	$logs_dir = dirname( PUNTWORK_LOGS );
	if ( ! file_exists( $logs_dir ) ) {
		wp_mkdir_p( $logs_dir );
	}
	// Flush rewrite rules if CPTs involved (though ACF handles)
	flush_rewrite_rules();

	// Clear any cached admin menu data to ensure icon updates
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}

	// Create database indexes for performance optimization
	if ( function_exists( __NAMESPACE__ . '\\create_database_indexes' ) ) {
		call_user_func( __NAMESPACE__ . '\\create_database_indexes' );
	}

	// Create performance logs table for storing import metrics
	if ( function_exists( __NAMESPACE__ . '\\create_performance_logs_table' ) ) {
		call_user_func( __NAMESPACE__ . '\\create_performance_logs_table' );
	}

	// Create API key for SSE and REST API authentication
	if ( function_exists( __NAMESPACE__ . '\\get_or_create_api_key' ) ) {
		call_user_func( __NAMESPACE__ . '\\get_or_create_api_key' );
	}

	// Initialize safe purge options (disabled by default for safety)
	add_option( 'puntwork_auto_purge_old_jobs', false ); // Disabled by default
	add_option( 'puntwork_purge_age_threshold_days', 30 ); // Only purge jobs older than 30 days
	add_option( 'puntwork_purge_min_jobs_threshold', 10 ); // Require at least 10 processed GUIDs
	add_option( 'puntwork_cleanup_batch_size', 100 ); // Process max 100 draft/trash jobs per cleanup
}

// Deactivation hook
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\job_import_deactivate' );
function job_import_deactivate() {
	// Clear cron - DISABLED: Background processing disabled
	// wp_clear_scheduled_hook( 'job_import_cron' );
	// wp_clear_scheduled_hook( 'puntwork_social_cron' );
	
	// Clear plugin loading flags to allow fresh load on reactivation
	delete_option( 'puntwork_includes_loaded' );
	delete_option( 'puntwork_setup_done' );
}

// Register custom cron schedules
add_filter( 'cron_schedules', __NAMESPACE__ . '\\register_custom_cron_schedules' );
function register_custom_cron_schedules( $schedules ) {
	$schedules['puntwork_hourly'] = array(
		'interval' => HOUR_IN_SECONDS,
		'display'  => __( 'Hourly', 'puntwork' ),
	);

	$schedules['puntwork_3hours'] = array(
		'interval' => 3 * HOUR_IN_SECONDS,
		'display'  => __( 'Every 3 hours', 'puntwork' ),
	);

	$schedules['puntwork_6hours'] = array(
		'interval' => 6 * HOUR_IN_SECONDS,
		'display'  => __( 'Every 6 hours', 'puntwork' ),
	);

	$schedules['puntwork_12hours'] = array(
		'interval' => 12 * HOUR_IN_SECONDS,
		'display'  => __( 'Every 12 hours', 'puntwork' ),
	);

	// Add common custom intervals
	for ( $hours = 2; $hours <= 24; $hours++ ) {
		if ( $hours != 3 && $hours != 6 && $hours != 12 ) { // Skip already defined ones
			$schedules[ 'puntwork_' . $hours . 'hours' ] = array(
				'interval' => $hours * HOUR_IN_SECONDS,
				'display'  => sprintf( __( 'Every %d hours', 'puntwork' ), $hours ),
			);
		}
	}

	return $schedules;
}

// Add social media cron handler - DISABLED: Background processing disabled
// add_action( 'puntwork_social_cron', __NAMESPACE__ . '\\process_social_media_posts' );
function process_social_media_posts() {
	if ( class_exists( __NAMESPACE__ . '\\SocialMedia\\SocialMediaManager' ) ) {
		$social_manager = new \Puntwork\SocialMedia\SocialMediaManager();
		$social_manager->processScheduledPosts();
	}
}

// Init setup - add hook only once to prevent multiple loading
if ( ! isset( $GLOBALS['puntwork_init_hook_added'] ) ) {
	$GLOBALS['puntwork_init_hook_added'] = true;
	add_action( 'init', __NAMESPACE__ . '\\load_puntwork_includes', 5 );
	add_action( 'init', __NAMESPACE__ . '\\setup_job_import', 10 );

	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
	if ( $debug_mode ) {
		// error_log( '[PUNTWORK] [INIT-DEBUG] Init hooks added: load_puntwork_includes and setup_job_import' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\load_puntwork_includes' ) ) {
	function load_puntwork_includes() {
		// Prevent multiple include loading
		static $includes_loaded = false;
		if ( $includes_loaded ) {
			return;
		}
		$includes_loaded = true;

		// Determine context for conditional loading
		$is_admin = is_admin();
		$is_ajax  = defined( 'DOING_AJAX' ) && DOING_AJAX;
		$is_cron  = defined( 'DOING_CRON' ) && DOING_CRON;

		// Always load core functionality
		$includes = array(
			'core/core-structure-logic.php',
			'core/enqueue-scripts-js.php',
			'utilities/CacheManager.php',
			'utilities/PuntWorkLogger.php',
			'utilities/SecurityUtils.php',
			'utilities/utility-helpers.php',
			'utilities/database-optimization.php',
			'utilities/performance-functions.php',
			'utilities/feeds-path-utils.php',
		);

		// Admin-only includes
		if ( $is_admin ) {
			$includes = array_merge( $includes, array(
				'admin/admin-menu.php',
				'admin/admin-page-html.php',
				'admin/admin-ui-debug.php',
				'admin/admin-ui-main.php',
				'admin/admin-ui-scheduling.php',
				'admin/admin-api-settings.php',
				'admin/admin-ajax-monitoring.php',
				'admin/admin-feed-config.php',
				'admin/admin-modern-styles.php',
				'mappings/mappings-constants.php',
				'mappings/mappings-fields.php',
				'mappings/mappings-geographic.php',
				'mappings/mappings-icons.php',
				'mappings/mappings-salary.php',
				'mappings/mappings-schema.php',
				'scheduling/scheduling-core.php',
				'scheduling/scheduling-history.php',
				'scheduling/scheduling-triggers.php',
				'scheduling/test-scheduling.php',
			) );
		}

		// AJAX/REST includes
		if ( $is_ajax ) {
			$includes = array_merge( $includes, array(
				'api/ajax-feed-processing.php',
				'api/ajax-handlers.php',
				'api/ajax-purge.php',
				'api/ajax-db-optimization.php',
				'api/ajax-feed-health.php',
				'api/rest-api.php',
				'api/sse-import-progress.php',
				'import/feed-processor.php',
				'scheduling/scheduling-core.php',
				'scheduling/scheduling-history.php',
			) );
		}

		// Import/batch processing includes (load on AJAX, cron, or explicit import requests)
		$current_action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
		$is_import_request = isset( $_REQUEST['puntwork_import'] ) ||
		                    ( strpos( $current_action, 'puntwork' ) === 0 );

		if ( $is_ajax && $is_import_request || $is_cron ) {
			$includes = array_merge( $includes, array(
				'utilities/ErrorHandler.php',
				'batch/batch-core.php',
				'batch/batch-data.php',
				'batch/batch-loading.php',
				'batch/batch-processing.php',
				'batch/batch-duplicates.php',
				'batch/batch-metadata.php',
				'batch/batch-size-management.php',
				'batch/batch-utils.php',
				'utilities/async-processing.php',
				'batch/batch-processing-core.php',
				'import/combine-jsonl.php',
				'import/download-feed.php',
				'import/parallel-feed-downloader.php',
				'import/import-batch.php',
				'import/import-finalization.php',
				'import/import-setup.php',
				'import/process-batch-items.php',
				'import/process-xml-batch.php',
				'utilities/JobDeduplicator.php',
				'utilities/EnhancedCacheManager.php',
				'utilities/AdaptiveResourceManager.php',
				'utilities/BatchPrioritizer.php',
				'utilities/AdvancedJsonlProcessor.php',
				'utilities/IterativeLearner.php',
				'utilities/MemoryManager.php',
				'utilities/item-cleaning.php',
				'utilities/gzip-file.php',
				'utilities/ImportAnalytics.php',
				'utilities/FeedHealthMonitor.php',
				'utilities/heartbeat-control.php',
				'utilities/PuntworkTracing.php',
				'utilities/AjaxErrorHandler.php',
				'utilities/item-inference.php',
				'utilities/handle-duplicates.php',
			) );
		}

		// Load includes
		foreach ( $includes as $include ) {
			$file = PUNTWORK_PATH . 'includes/' . $include;
			if ( file_exists( $file ) ) {
				include_once $file;
			}
		}
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\setup_job_import' ) ) {
	function setup_job_import() {
		// Prevent multiple initialization
		static $setup_completed = false;
		if ( $setup_completed ) {
			return;
		}

		// Skip initialization on AJAX/REST requests
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ||
		     ( strpos( $request_uri, '/wp-json/' ) !== false ) ) {
			return;
		}

		// Prevent multiple initialization across requests
		$init_option_key = 'puntwork_setup_done';
		if ( get_option( $init_option_key, false ) ) {
			return;
		}

		$setup_completed = true;
		update_option( $init_option_key, true );

		// Load text domain
		load_plugin_textdomain( 'puntwork', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Initialize core systems
		if ( function_exists( __NAMESPACE__ . '\\init_scheduling' ) ) {
			call_user_func( __NAMESPACE__ . '\\init_scheduling' );
		}

		if ( function_exists( __NAMESPACE__ . '\\init_async_processing' ) ) {
			call_user_func( __NAMESPACE__ . '\\init_async_processing' );
		}

		if ( class_exists( __NAMESPACE__ . '\\FeedHealthMonitor' ) ) {
			call_user_func( array( __NAMESPACE__ . '\\FeedHealthMonitor', 'init' ) );
		}

		if ( function_exists( __NAMESPACE__ . '\\create_database_indexes' ) ) {
			call_user_func( __NAMESPACE__ . '\\create_database_indexes' );
		}

		if ( function_exists( __NAMESPACE__ . '\\create_performance_logs_table' ) ) {
			call_user_func( __NAMESPACE__ . '\\create_performance_logs_table' );
		}

		if ( function_exists( __NAMESPACE__ . '\\get_or_create_api_key' ) ) {
			call_user_func( __NAMESPACE__ . '\\get_or_create_api_key' );
		}

		if ( class_exists( __NAMESPACE__ . '\\ImportAnalytics' ) ) {
			call_user_func( array( __NAMESPACE__ . '\\ImportAnalytics', 'init' ) );
		}
	}
}

// Add custom favicon
add_action( 'wp_head', __NAMESPACE__ . '\\add_custom_favicon' );
function add_custom_favicon() {
	$favicon_url = PUNTWORK_URL . 'assets/images/icon.svg?v=' . PUNTWORK_VERSION;
	echo '<link rel="icon" type="image/svg+xml" href="' . esc_url( $favicon_url ) . '">' . "\n";
}

// Add security headers
add_action( 'wp_head', __NAMESPACE__ . '\\add_security_headers' );
function add_security_headers() {
	if ( is_admin() ) {
		// Content Security Policy for admin pages
		$csp  = "default-src 'self'; ";
		$csp .= "script-src 'self' 'unsafe-inline' 'unsafe-eval' ";
		$csp .= 'https://code.jquery.com https://cdn.jsdelivr.net ';
		$csp .= 'https://cdnjs.cloudflare.com; ';
		$csp .= "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com ";
		$csp .= 'https://cdn.jsdelivr.net; ';
		$csp .= "font-src 'self' https://fonts.gstatic.com; ";
		$csp .= "img-src 'self' data: https:; ";
		$csp .= "connect-src 'self'; ";
		$csp .= "frame-ancestors 'none';";

		header( 'Content-Security-Policy: ' . $csp );

		// Other security headers
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-XSS-Protection: 1; mode=block' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );

		// HSTS for HTTPS sites
		if ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
		}
	}
}

// Add REST API security headers
add_action( 'rest_api_init', __NAMESPACE__ . '\\add_rest_api_security_headers' );
function add_rest_api_security_headers() {
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: DENY' );
	header( 'X-XSS-Protection: 1; mode=block' );
	header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS' );
	header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key' );
	header( 'Access-Control-Max-Age: 86400' );
}

// Handle preflight OPTIONS requests for CORS
add_action( 'init', __NAMESPACE__ . '\\handle_cors_preflight' );
function handle_cors_preflight() {
	if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
		header( 'Access-Control-Allow-Origin: ' . get_site_url() );
		header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key' );
		header( 'Access-Control-Max-Age: 86400' );
		exit( 0 );
	}
}

// Add CORS headers for admin-ajax requests
add_action( 'admin_init', __NAMESPACE__ . '\\add_admin_ajax_cors_headers' );
function add_admin_ajax_cors_headers() {
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		header( 'Access-Control-Allow-Origin: ' . get_site_url() );
		header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization, X-WP-Nonce' );
		header( 'Access-Control-Allow-Credentials: true' );
	}
}

// Add analytics async processing hook
add_action( 'puntwork_update_analytics_async', 'process_async_analytics_update_global' );

// Uninstall hook (cleanup)
register_uninstall_hook( __FILE__, __NAMESPACE__ . '\\job_import_uninstall' );
function job_import_uninstall() {
	// Delete options, transients; optional: delete job-feed posts
	delete_option( 'job_import_last_run' );
	// Clear cron - DISABLED: Background processing disabled
	// wp_clear_scheduled_hook( 'job_import_cron' );
}
