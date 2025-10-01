<?php

/**
 * Plugin Name: puntWork
 * Description: Advanced job import plugin with multi-format feed support,
 *     real-time analytics, health monitoring, AI-powered features, CRM integrations,
 *     multi-site support, horizontal scaling, GraphQL API, webhooks, and mobile app.
 * Version: 0.0.4
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

// Additional check: prevent loading if already loaded this request
$load_token = 'puntwork_loaded_' . getmypid() . '_' . microtime( true );
if ( isset( $GLOBALS['puntwork_load_tokens'] ) && in_array( $load_token, $GLOBALS['puntwork_load_tokens'] ) ) {
	return;
}
if ( ! isset( $GLOBALS['puntwork_load_tokens'] ) ) {
	$GLOBALS['puntwork_load_tokens'] = array();
}
$GLOBALS['puntwork_load_tokens'][] = $load_token;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PUNTWORK_VERSION', '0.0.4' );
define( 'PUNTWORK_PATH', plugin_dir_path( __FILE__ ) );
define( 'PUNTWORK_URL', plugin_dir_url( __FILE__ ) );
define( 'PUNTWORK_LOGS', PUNTWORK_PATH . 'logs/import.log' );

// Load Composer autoloader if available
if ( file_exists( PUNTWORK_PATH . 'vendor/autoload.php' ) ) {
	include_once PUNTWORK_PATH . 'vendor/autoload.php';
}

// =====================================================================================
// PLUGIN INITIALIZATION - RUNS ONCE WHEN PLUGIN LOADS
// =====================================================================================

// Increase memory limit to prevent exhaustion
ini_set( 'memory_limit', '1024M' );

$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

if ( $debug_mode ) {
	error_log( '[PUNTWORK] [PLUGIN-LOAD] ===== PLUGIN INITIALIZATION START =====' );
}

// =====================================================================================
// END PLUGIN INITIALIZATION
// =====================================================================================

// Activation hook
register_activation_hook( __FILE__, __NAMESPACE__ . '\\job_import_activate' );
function job_import_activate() {
	// Schedule cron
	if ( ! wp_next_scheduled( 'job_import_cron' ) ) {
		wp_schedule_event( current_time( 'timestamp' ), 'daily', 'job_import_cron' );
	}

	// Schedule social media cron
	if ( ! wp_next_scheduled( 'puntwork_social_cron' ) ) {
		wp_schedule_event( current_time( 'timestamp' ), 'puntwork_hourly', 'puntwork_social_cron' );
	}

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

	// Create API key for SSE and REST API authentication
	if ( function_exists( __NAMESPACE__ . '\\get_or_create_api_key' ) ) {
		call_user_func( __NAMESPACE__ . '\\get_or_create_api_key' );
	}
}

// Deactivation hook
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\job_import_deactivate' );
function job_import_deactivate() {
	wp_clear_scheduled_hook( 'job_import_cron' );
	wp_clear_scheduled_hook( 'puntwork_social_cron' );
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

// Add social media cron handler
add_action( 'puntwork_social_cron', __NAMESPACE__ . '\\process_social_media_posts' );
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
}

if ( ! function_exists( __NAMESPACE__ . '\\load_puntwork_includes' ) ) {
	function load_puntwork_includes() {
		// Prevent multiple include loading with static flag (more reliable than global)
		static $includes_loaded = false;
		if ( $includes_loaded ) {
			return;
		}
		$includes_loaded = true;

		// Prevent multiple include loading with a global flag
		if ( isset( $GLOBALS['puntwork_includes_loaded'] ) && $GLOBALS['puntwork_includes_loaded'] ) {
			return;
		}

		$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [INIT-DEBUG] Loading includes conditionally...' );
		}

		// Determine context for conditional loading
		$is_admin    = is_admin();
		$is_ajax     = defined( 'DOING_AJAX' ) && DOING_AJAX;
		$is_rest     = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$is_cron     = defined( 'DOING_CRON' ) && DOING_CRON;
		$is_frontend = ! $is_admin && ! $is_ajax && ! $is_rest && ! $is_cron;

		// Always load core functionality
		$includes = array(
			// Core functionality (always needed)
			'core/core-structure-logic.php',
			'core/enqueue-scripts-js.php',
			'utilities/CacheManager.php',
			'utilities/PuntWorkLogger.php',
			'utilities/SecurityUtils.php',
			'utilities/utility-helpers.php',
			'utilities/database-optimization.php',
			'utilities/performance-functions.php',
		);

		// Social Media includes (load on admin or cron) - moved before admin includes
		if ( $is_admin || $is_cron ) {
			$includes = array_merge(
				$includes,
				array(
					'socialmedia/social-media-platform.php',
					'socialmedia/twitter-platform.php',
					'socialmedia/twitter-ads-manager.php',
					'socialmedia/facebook-platform.php',
					'socialmedia/facebook-ads-manager.php',
					'socialmedia/tiktok-platform.php',
					'socialmedia/tiktok-ads-manager.php',
					'socialmedia/social-media-manager.php',
					'database/social-media-db.php',
				)
			);
		}

		// Admin-only includes
		if ( $is_admin ) {
			$includes = array_merge(
				$includes,
				array(
					'admin/admin-menu.php',
					'admin/admin-page-html.php',
					'admin/admin-ui-debug.php',
					'admin/admin-ui-main.php',
					'admin/admin-ui-scheduling.php',
					'admin/admin-api-settings.php',
					'admin/admin-ui-feed-health.php',
					'admin/admin-ui-analytics.php',
					'admin/admin-ui-performance.php',
					'admin/admin-ui-monitoring.php',
					'admin/admin-ajax-monitoring.php',
					'admin/admin-feed-config.php',
					'admin/admin-modern-styles.php',
					'admin/onboarding-wizard.php',
					'admin/social-media-admin.php',
					'admin/social-media-test.php',
					'admin/crm-admin.php',
				)
			);
		}

		// CRM includes (load on admin)
		if ( $is_admin ) {
			$includes = array_merge(
				$includes,
				array(
					'crm/crm-integration.php',
					'crm/hubspot-integration.php',
					'crm/salesforce-integration.php',
					'crm/zoho-integration.php',
					'crm/pipedrive-integration.php',
					'crm/crm-manager.php',
					'database/crm-db.php',
				)
			);
		}

		// API/AJAX includes (load on AJAX, REST, or admin)
		if ( $is_ajax || $is_rest || $is_admin ) {
			$includes = array_merge(
				$includes,
				array(
					'api/ajax-feed-processing.php',
					'api/ajax-handlers.php',
					'api/ajax-import-control.php',
					'api/ajax-purge.php',
					'api/ajax-db-optimization.php',
					'api/ajax-feed-health.php',
					'api/rest-api.php',
				)
			);
		}

		// SSE endpoint (always load since REST_REQUEST may not be set during init)
		$includes = array_merge(
			$includes,
			array(
				'api/sse-import-progress.php',
			)
		);

		// Batch/Import includes (load on AJAX, cron, or when explicitly needed)
		if ( $is_ajax || $is_cron || isset( $_REQUEST['puntwork_import'] ) || ( isset( $_REQUEST['action'] ) && strpos( $_REQUEST['action'], 'puntwork' ) === 0 ) ) {
			$includes = array_merge(
				$includes,
				array(
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
				)
			);
		}

		// Queue includes (load on AJAX or cron)
		if ( $is_ajax || $is_cron ) {
			$includes = array_merge(
				$includes,
				array(
					'queue/queue-manager.php',
					'queue/queue-ajax.php',
				)
			);
		}

		// Mapping includes (load on admin or import operations)
		if ( $is_admin || $is_ajax || isset( $_REQUEST['puntwork_import'] ) ) {
			$includes = array_merge(
				$includes,
				array(
					'mappings/mappings-constants.php',
					'mappings/mappings-fields.php',
					'mappings/mappings-geographic.php',
					'mappings/mappings-icons.php',
					'mappings/mappings-salary.php',
					'mappings/mappings-schema.php',
				)
			);
		}

		// Scheduling includes (load on admin or cron)
		if ( $is_admin || $is_cron ) {
			$includes = array_merge(
				$includes,
				array(
					'scheduling/scheduling-ajax.php',
					'scheduling/scheduling-core.php',
					'scheduling/scheduling-history.php',
					'scheduling/scheduling-triggers.php',
					'scheduling/test-scheduling.php',
				)
			);
		}

		$loaded_count = 0;
		$failed_count = 0;
		foreach ( $includes as $include ) {
			$file = PUNTWORK_PATH . 'includes/' . $include;
			if ( file_exists( $file ) ) {
				include_once $file;
				++$loaded_count;
				if ( $debug_mode && $loaded_count % 10 == 0 ) {
					error_log( '[PUNTWORK] [INIT-DEBUG] Loaded ' . $loaded_count . ' includes so far...' );
				}
			} else {
				++$failed_count;
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [INIT-WARN] Include file not found: ' . $file );
				}
			}
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [INIT-DEBUG] Conditional include loading complete: ' . $loaded_count . ' loaded, ' . $failed_count . ' failed' );
			error_log( '[PUNTWORK] [INIT-DEBUG] Context: admin=' . ( $is_admin ? '1' : '0' ) . ', ajax=' . ( $is_ajax ? '1' : '0' ) . ', rest=' . ( $is_rest ? '1' : '0' ) . ', cron=' . ( $is_cron ? '1' : '0' ) );
		}

		$GLOBALS['puntwork_includes_loaded'] = true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\setup_job_import' ) ) {
	function setup_job_import() {
		// Prevent multiple initialization with static flag
		static $setup_completed = false;
		if ( $setup_completed ) {
			return;
		}

		// Skip initialization on AJAX requests to prevent duplicate loading
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [INIT-SKIP] Skipping initialization on AJAX request' );
			}

			return;
		}

		// Prevent multiple initialization across requests using WordPress option
		$init_option_key = 'puntwork_setup_done';
		$setup_done      = get_option( $init_option_key, false );
		error_log( '[PUNTWORK] [OPTION-DEBUG] Key: ' . $init_option_key . ', Value: ' . ( $setup_done ? 'true' : 'false' ) );
		if ( $setup_done ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [INIT-SKIP] Setup already completed globally, skipping...' );
			}

			return;
		}
		$setup_completed = true;
		update_option( $init_option_key, true );
		error_log( '[PUNTWORK] [OPTION-DEBUG] Set option for key: ' . $init_option_key );

		// Increase memory limit to prevent exhaustion
		ini_set( 'memory_limit', '1024M' );

		$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [INIT-START] ===== SETUP_JOB_IMPORT START =====' );
			error_log( '[PUNTWORK] [INIT-DEBUG] WordPress version: ' . get_bloginfo( 'version' ) );
			error_log( '[PUNTWORK] [INIT-DEBUG] PHP version: ' . PHP_VERSION );
			error_log( '[PUNTWORK] [INIT-DEBUG] Memory limit: ' . ini_get( 'memory_limit' ) );
			error_log( '[PUNTWORK] [INIT-DEBUG] Max execution time: ' . ini_get( 'max_execution_time' ) );
			error_log( '[PUNTWORK] [INIT-DEBUG] ABSPATH: ' . ABSPATH );
			error_log( '[PUNTWORK] [INIT-DEBUG] Plugin path: ' . PUNTWORK_PATH );
		}

		// Test database connection
		global $wpdb;
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [INIT-DEBUG] Testing database connection...' );
		}

		// Use comprehensive database connection test
		if ( function_exists( __NAMESPACE__ . '\\test_database_connection' ) ) {
			$db_test_results = call_user_func( __NAMESPACE__ . '\\test_database_connection' );
			if ( ! $db_test_results['connected'] ) {
				error_log( '[PUNTWORK] [INIT-ERROR] Database connection test FAILED' );
				error_log( '[PUNTWORK] [INIT-ERROR] Connection details: ' . json_encode( $db_test_results ) );
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [INIT-ERROR] Database connection issues detected - this will cause AJAX failures' );
				}
			} elseif ( $debug_mode ) {
					error_log( '[PUNTWORK] [INIT-DEBUG] Database connection test PASSED' );
			}
		} else {
			// Fallback to simple test
			try {
				$test_query = $wpdb->get_var( 'SELECT 1' );
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [INIT-DEBUG] Database connection test successful' );
				}
			} catch ( \Exception $e ) {
				error_log( '[PUNTWORK] [INIT-ERROR] Database connection test failed: ' . $e->getMessage() );
				if ( $debug_mode ) {
					error_log(
						'[PUNTWORK] [INIT-ERROR] Database error details: ' . json_encode(
							array(
								'host'  => DB_HOST,
								'name'  => DB_NAME,
								'user'  => DB_USER,
								'error' => $e->getMessage(),
							)
						)
					);
				}
			}
		}

		// Global batch limit (from old 1)
		global $job_import_batch_limit;
		$job_import_batch_limit = 500;

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [INIT-DEBUG] Loading text domain...' );
		}

		// Load text domain for internationalization
		load_plugin_textdomain( 'puntwork', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [INIT-DEBUG] Initializing scheduling...' );
		}
		// Initialize scheduling
		if ( function_exists( __NAMESPACE__ . '\\init_scheduling' ) ) {
			call_user_func( __NAMESPACE__ . '\\init_scheduling' );
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [INIT-DEBUG] Initializing async processing...' );
		}
		// Initialize async processing
		if ( function_exists( __NAMESPACE__ . '\\init_async_processing' ) ) {
			call_user_func( __NAMESPACE__ . '\\init_async_processing' );
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [INIT-DEBUG] Initializing feed health monitoring...' );
		}
		// Initialize feed health monitoring
		if ( class_exists( __NAMESPACE__ . '\\FeedHealthMonitor' ) ) {
			call_user_func( array( __NAMESPACE__ . '\\FeedHealthMonitor', 'init' ) );
		}

		// Ensure database indexes exist (call during setup, not just activation)
		if ( function_exists( __NAMESPACE__ . '\\create_database_indexes' ) ) {
			call_user_func( __NAMESPACE__ . '\\create_database_indexes' );
		}

		// Ensure API key exists for SSE and REST API authentication
		if ( function_exists( __NAMESPACE__ . '\\get_or_create_api_key' ) ) {
			call_user_func( __NAMESPACE__ . '\\get_or_create_api_key' );
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [INIT-DEBUG] Initializing import analytics...' );
		}
		// Initialize import analytics
		if ( class_exists( __NAMESPACE__ . '\\ImportAnalytics' ) ) {
			call_user_func( array( __NAMESPACE__ . '\\ImportAnalytics', 'init' ) );
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [INIT-DEBUG] Initializing social media functionality...' );
		}
		// Initialize social media functionality
		if ( class_exists( __NAMESPACE__ . '\\PuntworkSocialMediaAdmin' ) ) {
			// Admin interface is initialized in the class constructor
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [INIT-DEBUG] Initializing GraphQL API...' );
		}
		// Initialize GraphQL API
		if ( class_exists( __NAMESPACE__ . '\\API\\GraphQLAPI' ) ) {
			call_user_func( array( __NAMESPACE__ . '\\API\\GraphQLAPI', 'init' ) );
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [INIT-DEBUG] Initializing Webhook Manager...' );
		}
		// Initialize Webhook Manager
		if ( class_exists( __NAMESPACE__ . '\\API\\WebhookManager' ) ) {
			call_user_func( array( __NAMESPACE__ . '\\API\\WebhookManager', 'init' ) );
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [INIT-DEBUG] Initializing Feed Optimizer...' );
		}
		// Initialize Feed Optimizer
		if ( class_exists( __NAMESPACE__ . '\\AI\\FeedOptimizer' ) ) {
			call_user_func( array( __NAMESPACE__ . '\\AI\\FeedOptimizer', 'init' ) );
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [INIT-END] ===== SETUP_JOB_IMPORT COMPLETED =====' );
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
	// Clear cron
	wp_clear_scheduled_hook( 'job_import_cron' );
}
