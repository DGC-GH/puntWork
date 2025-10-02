<?php

/**
 * REST API endpoints for remote import triggering.
 *
 * @since      1.0.7
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * REST API handlers for remote import triggering
 */

/*
 * Register REST API routes
 */
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_import_api_routes' );
function register_import_api_routes() {
	// Existing endpoints
	register_rest_route(
		'puntwork/v1',
		'/trigger-import',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\handle_trigger_import',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key'   => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
				'force'     => array(
					'required'    => false,
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Force import even if one is already running',
				),
				'test_mode' => array(
					'required'    => false,
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Run in test mode (no actual posts created)',
				),
			),
		)
	);

	register_rest_route(
		'puntwork/v1',
		'/import-status',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\handle_get_import_status',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
			),
		)
	);

	// New expanded endpoints
	register_rest_route(
		'puntwork/v1',
		'/analytics',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\handle_get_analytics',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
				'period'  => array(
					'required'    => false,
					'type'        => 'string',
					'default'     => '30days',
					'enum'        => array( '7days', '30days', '90days' ),
					'description' => 'Time period for analytics data',
				),
			),
		)
	);

	register_rest_route(
		'puntwork/v1',
		'/feeds',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\handle_get_feeds',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
			),
		)
	);

	register_rest_route(
		'puntwork/v1',
		'/feeds/(?P<feed_key>[a-zA-Z0-9_-]+)',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\handle_get_feed_details',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key'  => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
				'feed_key' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'Feed key identifier',
				),
			),
		)
	);

	register_rest_route(
		'puntwork/v1',
		'/performance',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\handle_get_performance',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key'   => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
				'period'    => array(
					'required'    => false,
					'type'        => 'string',
					'default'     => '7days',
					'enum'        => array( '7days', '30days', '90days' ),
					'description' => 'Time period for performance data',
				),
				'operation' => array(
					'required'    => false,
					'type'        => 'string',
					'description' => 'Specific operation to filter by',
				),
			),
		)
	);

	register_rest_route(
		'puntwork/v1',
		'/jobs',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\handle_get_jobs',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key'  => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
				'page'     => array(
					'required'    => false,
					'type'        => 'integer',
					'default'     => 1,
					'minimum'     => 1,
					'description' => 'Page number for pagination',
				),
				'per_page' => array(
					'required'    => false,
					'type'        => 'integer',
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => 'Number of jobs per page',
				),
				'status'   => array(
					'required'    => false,
					'type'        => 'string',
					'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
					'description' => 'Filter by post status',
				),
				'search'   => array(
					'required'    => false,
					'type'        => 'string',
					'description' => 'Search term for job titles',
				),
			),
		)
	);

	register_rest_route(
		'puntwork/v1',
		'/jobs/(?P<id>\d+)',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\handle_get_job',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
				'id'      => array(
					'required'    => true,
					'type'        => 'integer',
					'description' => 'Job post ID',
				),
			),
		)
	);

	register_rest_route(
		'puntwork/v1',
		'/bulk-operations',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\handle_bulk_operations',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key'   => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
				'operation' => array(
					'required'    => true,
					'type'        => 'string',
					'enum'        => array( 'publish', 'unpublish', 'delete', 'update_status' ),
					'description' => 'Bulk operation type',
				),
				'job_ids'   => array(
					'required'    => true,
					'type'        => 'array',
					'items'       => array(
						'type' => 'integer',
					),
					'description' => 'Array of job post IDs',
				),
				'status'    => array(
					'required'    => false,
					'type'        => 'string',
					'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
					'description' => 'New status for update_status operation',
				),
			),
		)
	);

	register_rest_route(
		'puntwork/v1',
		'/health',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\handle_get_health_status',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
			),
		)
	);

	register_rest_route(
		'puntwork/v1',
		'/social/test-platform',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\handle_social_test_platform',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key'     => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
				'platform_id' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'Social media platform ID to test',
				),
			),
		)
	);

	register_rest_route(
		'puntwork/v1',
		'/social/save-config',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\handle_social_save_config',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key'     => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
				'platform_id' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'Social media platform ID',
				),
				'config'      => array(
					'required'    => true,
					'type'        => 'object',
					'description' => 'Platform configuration data',
				),
			),
		)
	);

	register_rest_route(
		'puntwork/v1',
		'/social/post',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\handle_social_post_now',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key'   => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
				'content'   => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'Post content',
				),
				'platforms' => array(
					'required'    => true,
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'description' => 'Array of platform IDs to post to',
				),
			),
		)
	);

	register_rest_route(
		'puntwork/v1',
		'/onboarding/complete',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\handle_onboarding_complete',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
			),
		)
	);

	register_rest_route(
		'puntwork/v1',
		'/cache/clear',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\handle_rest_clear_cache',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
			),
		)
	);
}

/**
 * Verify API key for authentication with enhanced security.
 */
function verify_api_key( $request ) {
	$api_key = $request->get_param( 'api_key' );

	if ( empty( $api_key ) ) {
		SecurityUtils::logSecurityEvent(
			'api_key_missing',
			array(
				'endpoint' => $request->get_route(),
				'method'   => $request->get_method(),
				'ip'       => SecurityUtils::getClientIp(),
			)
		);

		return new \WP_Error( 'missing_api_key', 'API key is required', array( 'status' => 401 ) );
	}

	// Rate limiting for API key attempts
	$rate_limit_key = 'api_key_attempts_' . SecurityUtils::getClientIp();
	$attempts       = get_transient( $rate_limit_key ) ?: 0;

	if ( $attempts >= 6 ) {
		SecurityUtils::logSecurityEvent(
			'api_rate_limit_exceeded',
			array(
				'endpoint' => $request->get_route(),
				'ip'       => SecurityUtils::getClientIp(),
			)
		);

		return new \WP_Error( 'rate_limit_exceeded', 'Too many API key attempts', array( 'status' => 429 ) );
	}

	$stored_key = get_option( 'puntwork_api_key' );

	if ( empty( $stored_key ) ) {
		SecurityUtils::logSecurityEvent(
			'api_not_configured',
			array(
				'endpoint' => $request->get_route(),
				'ip'       => SecurityUtils::getClientIp(),
			)
		);

		return new \WP_Error( 'api_not_configured', 'API key not configured', array( 'status' => 403 ) );
	}

	if ( ! hash_equals( $stored_key, $api_key ) ) {
		set_transient( $rate_limit_key, $attempts + 1, 300 ); // 5 minutes
		SecurityUtils::logSecurityEvent(
			'api_key_invalid',
			array(
				'endpoint' => $request->get_route(),
				'ip'       => SecurityUtils::getClientIp(),
			)
		);

		return new \WP_Error( 'invalid_api_key', 'Invalid API key', array( 'status' => 401 ) );
	}

	// Clear rate limit on successful authentication
	delete_transient( $rate_limit_key );

	return true;
}

/**
 * Handle trigger import request.
 */
function handle_trigger_import( $request ) {
	$force     = $request->get_param( 'force' );
	$test_mode = $request->get_param( 'test_mode' );

	PuntWorkLogger::info(
		'Remote import trigger requested',
		PuntWorkLogger::CONTEXT_API,
		array(
			'force'     => $force,
			'test_mode' => $test_mode,
			'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
		)
	);

	// Check if import is already running
	$import_status = get_option( 'job_import_status', array() );
	$is_running    = isset( $import_status['complete'] ) && ! $import_status['complete'] && ! isset( $import_status['paused'] );

	if ( $is_running && ! $force ) {
		PuntWorkLogger::info( 'Import already running, skipping trigger', PuntWorkLogger::CONTEXT_API );

		return new \WP_REST_Response(
			array(
				'success'          => false,
				'message'          => 'Import already running. Use force=true to override.',
				'status'           => 'running',
				'current_progress' => $import_status,
			),
			409
		);
	}

	// If forcing, cancel current import
	if ( $is_running && $force ) {
		set_transient( 'import_cancel', true, 3600 );
		delete_option( 'job_import_status' );
		delete_option( 'job_import_progress' );
		delete_option( 'job_import_processed_guids' );
		delete_option( 'job_import_last_batch_time' );
		delete_option( 'job_import_last_batch_processed' );
		delete_option( 'job_import_batch_size' );
		delete_option( 'job_import_consecutive_small_batches' );
		PuntWorkLogger::info( 'Cancelled existing import for forced trigger', PuntWorkLogger::CONTEXT_API );
		sleep( 2 ); // Brief pause to allow cleanup
	}

	try {
		// Set test mode if requested
		if ( $test_mode ) {
			update_option( 'puntwork_test_mode', true );
		}

		// Get total items count for proper status initialization
		$json_path   = ABSPATH . 'feeds/combined-jobs.jsonl';
		$total_items = 0;
		if ( file_exists( $json_path ) ) {
			$total_items = get_json_item_count( $json_path );
		}

		// Initialize import status for immediate API response
		$initial_status = array(
			'total'              => $total_items, // Set correct total from the start
			'processed'          => 0,
			'published'          => 0,
			'updated'            => 0,
			'skipped'            => 0,
			'duplicates_drafted' => 0,
			'time_elapsed'       => 0,
			'success'            => false,
			'error_message'      => '',
			'batch_size'         => get_option( 'job_import_batch_size' ) ?: 1,
			'inferred_languages' => 0,
			'inferred_benefits'  => 0,
			'schema_generated'   => 0,
			'start_time'         => microtime( true ),
			'end_time'           => null,
			'last_update'        => time(),
			'logs'               => array( 'API import started - preparing feeds...' ),
			'trigger_type'       => 'api',
			'test_mode'          => $test_mode,
		);
		update_option( 'job_import_status', $initial_status, false );

		// Reset import progress for fresh API-triggered import
		update_option( 'job_import_progress', 0, false );
		update_option( 'job_import_processed_guids', array(), false );
		delete_option( 'job_import_last_batch_time' );
		delete_option( 'job_import_last_batch_processed' );
		delete_option( 'job_import_batch_size' );
		delete_option( 'job_import_consecutive_small_batches' );

		// Clear any previous cancellation before starting
		delete_transient( 'import_cancel' );

		// Determine execution mode based on import size and settings
		$use_async = false;

		// Check if async processing is enabled and available
		if ( is_async_processing_enabled() ) {
			// Get estimated item count to determine if async is beneficial
			$jsonl_path = ABSPATH . 'feeds/combined-jobs.jsonl';
			if ( file_exists( $jsonl_path ) ) {
				$estimated_count = get_json_item_count( $jsonl_path );
				// Use async for imports larger than 500 items or when explicitly requested
				$use_async = ( $estimated_count > 500 ) || isset( $_GET['async'] );
			}
		}

		if ( $use_async ) {
			// Use new async batch processing system
			if ( ! defined( 'PUNTWORK_TESTING' ) || ! PUNTWORK_TESTING ) {
				error_log( '[PUNTWORK] API: Using async batch processing' );
			}

			$result = trigger_async_import( $test_mode, 'api' );

			if ( $result['success'] ) {
				PuntWorkLogger::info( 'Remote import trigger initiated asynchronously', PuntWorkLogger::CONTEXT_API );

				return new \WP_REST_Response(
					array(
						'success'           => true,
						'message'           => 'Async import started successfully',
						'async'             => true,
						'job_ids'           => $result['job_ids'] ?? array(),
						'estimated_batches' => $result['batch_count'] ?? 0,
					),
					200
				);
			} else {
				// Fallback to sync if async fails
				if ( ! defined( 'PUNTWORK_TESTING' ) || ! PUNTWORK_TESTING ) {
					error_log( '[PUNTWORK] API: Async failed, falling back to sync: ' . $result['message'] );
				}
				$use_async = false;
			}
		}

		if ( ! $use_async ) {
			// Force synchronous execution
			if ( ! defined( 'PUNTWORK_TESTING' ) || ! PUNTWORK_TESTING ) {
				error_log( '[PUNTWORK] API: Using synchronous execution' );
			}
			if ( ! function_exists( 'run_scheduled_import' ) ) {
				if ( ! defined( 'PUNTWORK_TESTING' ) || ! PUNTWORK_TESTING ) {
					error_log( '[PUNTWORK] API: run_scheduled_import function not found' );
				}

				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Import function not found',
						'async'   => false,
					),
					500
				);
			}

			$result = run_scheduled_import( $test_mode, 'api' );
			if ( ! defined( 'PUNTWORK_TESTING' ) || ! PUNTWORK_TESTING ) {
				error_log( '[PUNTWORK] API: run_scheduled_import returned: ' . json_encode( $result ) );
			}

			// Clear test mode
			if ( $test_mode ) {
				delete_option( 'puntwork_test_mode' );
			}

			// Add debug information
			$debug_info = array(
				'jsonl_path'     => ABSPATH . 'feeds/combined-jobs.jsonl',
				'jsonl_exists'   => file_exists( ABSPATH . 'feeds/combined-jobs.jsonl' ),
				'jsonl_size'     => file_exists( ABSPATH . 'feeds/combined-jobs.jsonl' ) ? filesize( ABSPATH . 'feeds/combined-jobs.jsonl' ) : 0,
				'jsonl_readable' => is_readable( ABSPATH . 'feeds/combined-jobs.jsonl' ),
				'feeds_count'    => count( get_feeds() ),
				'wp_abspath'     => ABSPATH,
			);

			if ( $result['success'] ) {
				if ( ! defined( 'PUNTWORK_TESTING' ) || ! PUNTWORK_TESTING ) {
					error_log( '[PUNTWORK] API: About to return sync success response' );
				}
				PuntWorkLogger::info(
					'Remote import trigger completed successfully (sync)',
					PuntWorkLogger::CONTEXT_API,
					array(
						'processed' => $result['processed'] ?? 0,
						'total'     => $result['total'] ?? 0,
					)
				);

				return new \WP_REST_Response(
					array(
						'success' => true,
						'message' => 'Import completed successfully - FIXED VERSION',
						'data'    => $result,
						'debug'   => $debug_info,
						'async'   => false,
					),
					200
				);
			} else {
				$error_msg = $result['message'] ?? 'Unknown error occurred';
				if ( ! defined( 'PUNTWORK_TESTING' ) || ! PUNTWORK_TESTING ) {
					error_log( '[PUNTWORK] API: About to return sync error response: ' . $error_msg );
				}
				PuntWorkLogger::error(
					'Remote import trigger failed (sync)',
					PuntWorkLogger::CONTEXT_API,
					array(
						'error' => $error_msg,
					)
				);

				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => $error_msg,
						'data'    => $result,
						'debug'   => $debug_info,
						'async'   => false,
					),
					500
				);
			}
		}
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Remote import trigger exception',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Import failed: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Handle get import status request.
 */
function handle_get_import_status( $request ) {
	// Return mock response for testing
	if ( defined( 'PUNTWORK_TESTING' ) && PUNTWORK_TESTING ) {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'status'  => 'idle',
				'data'    => array(),
			),
			200
		);
	}

	try {
		$import_status   = get_option( 'job_import_status', array() );
		$import_progress = get_option( 'job_import_progress', 0 );
		$last_run        = get_option( 'puntwork_last_import_run' );
		$next_scheduled  = get_next_scheduled_time();

		// Calculate time elapsed if import is running
		if ( isset( $import_status['start_time'] ) && ! $import_status['complete'] ) {
			$import_status['time_elapsed'] = microtime( true ) - $import_status['start_time'];
		}

		// Calculate if import is currently running
		$complete   = isset( $import_status['complete'] ) ? $import_status['complete'] : true;
		$paused     = isset( $import_status['paused'] ) ? $import_status['paused'] : false;
		$is_running = ! $complete && ! $paused;

		// Add additional status information
		$status_data = array(
			'current_status' => $import_status,
			'progress'       => $import_progress,
			'last_run'       => $last_run,
			'next_scheduled' => $next_scheduled,
			'is_running'     => $is_running,
			'timestamp'      => current_time( 'timestamp' ),
		);
		PuntWorkLogger::debug( 'Import status requested via API', PuntWorkLogger::CONTEXT_API );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $status_data,
			),
			200
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Import status API error',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error' => $e->getMessage(),
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Failed to retrieve import status: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Handle get analytics request.
 */
function handle_get_analytics( $request ) {
	$period = $request->get_param( 'period' );

	try {
		$analytics_data = ImportAnalytics::getAnalyticsData( $period );

		PuntWorkLogger::debug( 'Analytics data requested via API', PuntWorkLogger::CONTEXT_API );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $analytics_data,
				'period'  => $period,
			),
			200
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Analytics API error',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error' => $e->getMessage(),
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Failed to retrieve analytics data: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Handle get feeds request.
 */
function handle_get_feeds( $request ) {
	try {
		$feeds       = get_feeds();
		$feed_health = FeedHealthMonitor::getFeedHealthStatus();

		$feeds_data = array();
		foreach ( $feeds as $key => $url ) {
			$health       = $feed_health[ $key ] ?? null;
			$feeds_data[] = array(
				'key'           => $key,
				'url'           => $url,
				'health_status' => $health ? $health['status'] : 'unknown',
				'last_check'    => $health ? $health['check_time'] : null,
				'response_time' => $health ? $health['response_time'] : null,
				'item_count'    => $health ? $health['item_count'] : null,
			);
		}

		PuntWorkLogger::debug( 'Feeds data requested via API', PuntWorkLogger::CONTEXT_API );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $feeds_data,
				'total'   => count( $feeds_data ),
			),
			200
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Feeds API error',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error' => $e->getMessage(),
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Failed to retrieve feeds data: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Handle get feed details request.
 */
function handle_get_feed_details( $request ) {
	$feed_key = $request->get_param( 'feed_key' );

	try {
		$feeds = get_feeds();

		if ( ! isset( $feeds[ $feed_key ] ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Feed not found',
				),
				404
			);
		}

		$feed_health = FeedHealthMonitor::getFeedHealthStatus();
		$health      = $feed_health[ $feed_key ] ?? null;
		$history     = FeedHealthMonitor::getFeedHealthHistory( $feed_key, 7 );

		$feed_data = array(
			'key'            => $feed_key,
			'url'            => $feeds[ $feed_key ],
			'health_status'  => $health ? $health['status'] : 'unknown',
			'last_check'     => $health ? $health['check_time'] : null,
			'response_time'  => $health ? $health['response_time'] : null,
			'http_code'      => $health ? $health['http_code'] : null,
			'item_count'     => $health ? $health['item_count'] : null,
			'error_message'  => $health ? $health['error_message'] : null,
			'health_history' => array_slice( $history, 0, 50 ), // Last 50 checks
		);

		PuntWorkLogger::debug(
			'Feed details requested via API',
			PuntWorkLogger::CONTEXT_API,
			array(
				'feed_key' => $feed_key,
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $feed_data,
			),
			200
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Feed details API error',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error'    => $e->getMessage(),
				'feed_key' => $feed_key,
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Failed to retrieve feed details: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Handle get performance request.
 */
function handle_get_performance( $request ) {
	$period    = $request->get_param( 'period' );
	$operation = $request->get_param( 'operation' );

	try {
		$performance_data = get_performance_statistics( $operation, $period == '7days' ? 7 : ( $period == '30days' ? 30 : 90 ) );
		$current_snapshot = get_performance_snapshot();

		PuntWorkLogger::debug(
			'Performance data requested via API',
			PuntWorkLogger::CONTEXT_API,
			array(
				'period'    => $period,
				'operation' => $operation,
			)
		);

		return new \WP_REST_Response(
			array(
				'success'          => true,
				'data'             => $performance_data,
				'current_snapshot' => $current_snapshot,
				'period'           => $period,
				'operation'        => $operation,
			),
			200
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Performance API error',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error' => $e->getMessage(),
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Failed to retrieve performance data: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Handle get jobs request.
 */
function handle_get_jobs( $request ) {
	$page     = $request->get_param( 'page' );
	$per_page = $request->get_param( 'per_page' );
	$status   = $request->get_param( 'status' );
	$search   = $request->get_param( 'search' );

	try {
		$args = array(
			'post_type'      => 'job',
			'post_status'    => $status ?: 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( $search ) {
			$args['s'] = $search;
		}

		$query = new \WP_Query( $args );

		$jobs = array();
		foreach ( $query->posts as $post ) {
			$jobs[] = array(
				'id'            => $post->ID,
				'title'         => $post->post_title,
				'status'        => $post->post_status,
				'date_created'  => $post->post_date,
				'date_modified' => $post->post_modified,
				'guid'          => get_post_meta( $post->ID, 'guid', true ),
				'permalink'     => get_permalink( $post->ID ),
				'excerpt'       => $post->post_excerpt,
			);
		}

		PuntWorkLogger::debug(
			'Jobs data requested via API',
			PuntWorkLogger::CONTEXT_API,
			array(
				'page'     => $page,
				'per_page' => $per_page,
				'total'    => $query->found_posts,
			)
		);

		return new \WP_REST_Response(
			array(
				'success'    => true,
				'data'       => $jobs,
				'pagination' => array(
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => $query->found_posts,
					'total_pages' => $query->max_num_pages,
				),
			),
			200
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Jobs API error',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error' => $e->getMessage(),
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Failed to retrieve jobs data: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Handle get job request.
 */
function handle_get_job( $request ) {
	$job_id = $request->get_param( 'id' );

	try {
		$post = get_post( $job_id );

		if ( ! $post || $post->post_type !== 'job' ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Job not found',
				),
				404
			);
		}

		// Get ACF fields if available
		$acf_fields = get_acf_fields();
		$job_data   = array(
			'id'            => $post->ID,
			'title'         => $post->post_title,
			'content'       => $post->post_content,
			'excerpt'       => $post->post_excerpt,
			'status'        => $post->post_status,
			'date_created'  => $post->post_date,
			'date_modified' => $post->post_modified,
			'permalink'     => get_permalink( $post->ID ),
			'guid'          => get_post_meta( $post->ID, 'guid', true ),
		);

		// Add ACF field data
		foreach ( $acf_fields as $field ) {
			$value = get_post_meta( $post->ID, $field, true );
			if ( ! empty( $value ) ) {
				$job_data[ $field ] = $value;
			}
		}

		PuntWorkLogger::debug(
			'Job details requested via API',
			PuntWorkLogger::CONTEXT_API,
			array(
				'job_id' => $job_id,
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $job_data,
			),
			200
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Job details API error',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error'  => $e->getMessage(),
				'job_id' => $job_id,
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Failed to retrieve job details: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Handle bulk operations request.
 */
function handle_bulk_operations( $request ) {
	$operation = $request->get_param( 'operation' );
	$job_ids   = $request->get_param( 'job_ids' );
	$status    = $request->get_param( 'status' );

	try {
		if ( empty( $job_ids ) || ! is_array( $job_ids ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Job IDs array is required',
				),
				400
			);
		}

		$results       = array();
		$success_count = 0;
		$error_count   = 0;

		foreach ( $job_ids as $job_id ) {
			try {
				$post = get_post( $job_id );
				if ( ! $post || $post->post_type !== 'job' ) {
					$results[] = array(
						'id'      => $job_id,
						'success' => false,
						'message' => 'Job not found',
					);
					++$error_count;

					continue;
				}

				switch ( $operation ) {
					case 'publish':
						wp_publish_post( $job_id );
						$results[] = array(
							'id'      => $job_id,
							'success' => true,
							'message' => 'Job published',
						);
						++$success_count;

						break;

					case 'unpublish':
						wp_update_post(
							array(
								'ID'          => $job_id,
								'post_status' => 'draft',
							)
						);
						$results[] = array(
							'id'      => $job_id,
							'success' => true,
							'message' => 'Job unpublished',
						);
						++$success_count;

						break;

					case 'delete':
						wp_delete_post( $job_id, true );
						$results[] = array(
							'id'      => $job_id,
							'success' => true,
							'message' => 'Job deleted',
						);
						++$success_count;

						break;

					case 'update_status':
						if ( ! $status ) {
							$results[] = array(
								'id'      => $job_id,
								'success' => false,
								'message' => 'Status parameter required for update_status operation',
							);
							++$error_count;

							break; // Changed from continue to break
						}

						wp_update_post(
							array(
								'ID'          => $job_id,
								'post_status' => $status,
							)
						);
						$results[] = array(
							'id'      => $job_id,
							'success' => true,
							'message' => 'Job status updated to ' . $status,
						);
						++$success_count;

						break;

					default:
						$results[] = array(
							'id'      => $job_id,
							'success' => false,
							'message' => 'Unknown operation: ' . $operation,
						);
						++$error_count;
				}
			} catch ( \Exception $e ) {
				$results[] = array(
					'id'      => $job_id,
					'success' => false,
					'message' => 'Error: ' . $e->getMessage(),
				);
				++$error_count;
			}
		}

		PuntWorkLogger::info(
			'Bulk operation completed via API',
			PuntWorkLogger::CONTEXT_API,
			array(
				'operation'     => $operation,
				'job_count'     => count( $job_ids ),
				'success_count' => $success_count,
				'error_count'   => $error_count,
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'operation'  => $operation,
					'total_jobs' => count( $job_ids ),
					'successful' => $success_count,
					'failed'     => $error_count,
					'results'    => $results,
				),
			),
			200
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Bulk operations API error',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error'     => $e->getMessage(),
				'operation' => $operation,
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Bulk operation failed: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Convert memory limit string to bytes.
 */
function convert_memory_limit_to_bytes( $memory_limit ) {
	if ( is_numeric( $memory_limit ) ) {
		return (int) $memory_limit;
	}

	$unit  = strtolower( substr( $memory_limit, -1 ) );
	$value = (int) substr( $memory_limit, 0, -1 );

	switch ( $unit ) {
		case 'g':
			return $value * 1024 * 1024 * 1024;
		case 'm':
			return $value * 1024 * 1024;
		case 'k':
			return $value * 1024;
		default:
			return (int) $memory_limit;
	}
}

/**
 * Handle get health status request.
 */
function handle_get_health_status( $request ) {
	try {
		$feed_health   = FeedHealthMonitor::getFeedHealthStatus();
		$import_status = get_option( 'job_import_status', array() );
		$system_health = get_performance_snapshot();

		$health_summary = array(
			'feeds'  => array(
				'total'    => count( $feed_health ),
				'healthy'  => count( array_filter( $feed_health, fn ( $f ) => ( $f['status'] ?? '' ) == 'healthy' ) ),
				'warning'  => count( array_filter( $feed_health, fn ( $f ) => ( $f['status'] ?? '' ) == 'warning' ) ),
				'critical' => count( array_filter( $feed_health, fn ( $f ) => ( $f['status'] ?? '' ) == 'critical' ) ),
				'down'     => count( array_filter( $feed_health, fn ( $f ) => ( $f['status'] ?? '' ) == 'down' ) ),
			),
			'import' => array(
				'status'         => isset( $import_status['complete'] ) && $import_status['complete'] ? 'idle' : 'running',
				'last_run'       => get_option( 'puntwork_last_import_run' ),
				'next_scheduled' => get_next_scheduled_time(),
			),
			'system' => array(
				'memory_usage'      => size_format( $system_health['memory_current'] ),
				'memory_limit'      => size_format( convert_memory_limit_to_bytes( $system_health['memory_limit'] ) ),
				'php_version'       => $system_health['php_version'],
				'wordpress_version' => $system_health['wordpress_version'],
				'load_average'      => $system_health['load_average'],
			),
		);

		$overall_status = 'healthy';
		if ( $health_summary['feeds']['critical'] > 0 || $health_summary['feeds']['down'] > 0 ) {
			$overall_status = 'critical';
		} elseif ( $health_summary['feeds']['warning'] > 0 ) {
			$overall_status = 'warning';
		}

		PuntWorkLogger::debug( 'Health status requested via API', PuntWorkLogger::CONTEXT_API );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'overall_status' => $overall_status,
					'summary'        => $health_summary,
					'feeds'          => $feed_health,
					'timestamp'      => current_time( 'timestamp' ),
				),
			),
			200
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Health status API error',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error' => $e->getMessage(),
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Failed to retrieve health status: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Handle social media platform test request.
 */
function handle_social_test_platform( $request ) {
	$platform_id = $request->get_param( 'platform_id' );

	try {
		if ( empty( $platform_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Platform ID required',
				),
				400
			);
		}

		// Check if SocialMediaManager class exists
		if ( ! class_exists( '\Puntwork\SocialMedia\SocialMediaManager' ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Social media manager not available',
				),
				500
			);
		}

		$social_manager = new \Puntwork\SocialMedia\SocialMediaManager();
		$result         = $social_manager->testPlatform( $platform_id );

		if ( $result['success'] ) {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'data'    => $result,
				),
				200
			);
		} else {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'data'    => $result,
				),
				400
			);
		}
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Social media test platform API error',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error'       => $e->getMessage(),
				'platform_id' => $platform_id,
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Failed to test platform: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Handle social media platform configuration save request.
 */
function handle_social_save_config( $request ) {
	$platform_id = $request->get_param( 'platform_id' );
	$config      = $request->get_param( 'config' );

	try {
		if ( empty( $platform_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Platform ID required',
				),
				400
			);
		}

		// Check if SocialMediaManager class exists
		if ( ! class_exists( '\Puntwork\SocialMedia\SocialMediaManager' ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Social media manager not available',
				),
				500
			);
		}

		// Sanitize config data
		$sanitized_config = array();
		if ( is_array( $config ) ) {
			foreach ( $config as $key => $value ) {
				$sanitized_config[ $key ] = sanitize_text_field( $value );
			}
		}

		$success = \Puntwork\SocialMedia\SocialMediaManager::configurePlatform( $platform_id, $sanitized_config );

		if ( $success ) {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => 'Configuration saved successfully',
				),
				200
			);
		} else {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Failed to save configuration',
				),
				500
			);
		}
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Social media save config API error',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error'       => $e->getMessage(),
				'platform_id' => $platform_id,
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Failed to save configuration: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Handle social media post request.
 */
function handle_social_post_now( $request ) {
	$content   = $request->get_param( 'content' );
	$platforms = $request->get_param( 'platforms' );

	try {
		if ( empty( $content ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Content is required',
				),
				400
			);
		}

		if ( empty( $platforms ) || ! is_array( $platforms ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'At least one platform must be selected',
				),
				400
			);
		}

		// Check if SocialMediaManager class exists
		if ( ! class_exists( '\Puntwork\SocialMedia\SocialMediaManager' ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Social media manager not available',
				),
				500
			);
		}

		$social_manager = new \Puntwork\SocialMedia\SocialMediaManager();

		// Sanitize platforms
		$sanitized_platforms = array_map( 'sanitize_text_field', $platforms );

		$results = $social_manager->postToPlatforms(
			array( 'text' => sanitize_textarea_field( $content ) ),
			$sanitized_platforms
		);

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Post sent to platforms',
				'data'    => array(
					'results' => $results,
				),
			),
			200
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Social media post API error',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error' => $e->getMessage(),
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Failed to post to platforms: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Handle onboarding completion request.
 */
function handle_onboarding_complete( $request ) {
	try {
		// Mark onboarding as completed
		update_option( 'puntwork_onboarding_completed', true );

		// Log the completion
		PuntWorkLogger::info( 'Onboarding completed via API', PuntWorkLogger::CONTEXT_API );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Onboarding completed successfully',
			),
			200
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Onboarding complete API error',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error' => $e->getMessage(),
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Failed to complete onboarding: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Handle cache clearing request.
 */
function handle_rest_clear_cache( $request ) {
	try {
		// Clear various caches
		wp_cache_flush();
		
		// Clear any plugin-specific caches
		if ( function_exists( 'wp_cache_supercache_dir' ) ) {
			// WP Super Cache
			global $cache_path;
			if ( function_exists( 'prune_super_cache' ) ) {
				prune_super_cache( $cache_path, true );
			}
		}

		// Clear object cache if using persistent cache
		if ( wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}

		// Clear transients
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'" );

		PuntWorkLogger::info( 'Cache cleared via API', PuntWorkLogger::CONTEXT_API );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Cache cleared successfully',
			),
			200
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error(
			'Clear cache API error',
			PuntWorkLogger::CONTEXT_API,
			array(
				'error' => $e->getMessage(),
			)
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'Failed to clear cache: ' . $e->getMessage(),
			),
			500
		);
	}
}

/**
 * Generate a new API key.
 */
function generate_api_key() {
	return wp_generate_password( 32, false );
}

/**
 * Get or create API key.
 */
function get_or_create_api_key() {
	$existing_key = get_option( 'puntwork_api_key' );

	if ( ! $existing_key ) {
		$new_key = generate_api_key();
		update_option( 'puntwork_api_key', $new_key );

		return $new_key;
	}

	return $existing_key;
}

/**
 * Regenerate API key.
 */
function regenerate_api_key() {
	$new_key = generate_api_key();
	update_option( 'puntwork_api_key', $new_key );

	return $new_key;
}
