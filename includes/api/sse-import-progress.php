<?php

/**
 * Server-Sent Events (SSE) for real-time import progress updates.
 *
 * @since      1.0.16
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// error_log( '[PUNTWORK] SSE: sse-import-progress.php file loaded successfully' );

// Explicitly load required utility classes for SSE context
require_once __DIR__ . '/../utilities/async-processing.php';
require_once __DIR__ . '/../scheduling/scheduling-core.php';
require_once __DIR__ . '/../utilities/utility-helpers.php';

/**
 * Deep sanitize data for JSON serialization
 * Recursively removes non-serializable objects, resources, and invalid values.
 *
 * @param  mixed $data Data to sanitize
 * @return mixed Sanitized data
 */
function deep_sanitize_for_json( $data ) {
	if ( is_object( $data ) || is_resource( $data ) ) {
		error_log( '[PUNTWORK] SSE: Removed object/resource from data' );

		return null;
	}

	if ( is_float( $data ) && ( is_infinite( $data ) || is_nan( $data ) ) ) {
		error_log( '[PUNTWORK] SSE: Removed infinite/NaN float from data' );

		return null;
	}

	if ( is_array( $data ) ) {
		$sanitized = array();
		foreach ( $data as $key => $value ) {
			// Skip keys that are objects or resources
			if ( is_object( $key ) || is_resource( $key ) ) {
				error_log( '[PUNTWORK] SSE: Skipped object/resource key in array' );

				continue;
			}

			// Convert object/resource keys to strings
			if ( ! is_string( $key ) && ! is_int( $key ) ) {
				$key = (string) $key;
			}

			// Special handling for "undefined" values
			if ( $value === 'undefined' || ( is_string( $value ) && strpos( $value, 'undefined' ) !== false ) ) {
				error_log( '[PUNTWORK] SSE: Found and removing "undefined" value for key: ' . $key );
				$value = ''; // Convert to empty string
			}

			$sanitized[ $key ] = deep_sanitize_for_json( $value );
		}

		return $sanitized;
	}

	// For scalars and other types, return as-is
	return $data;
}

/*
 * Server-Sent Events handlers for real-time import progress
 */

/*
 * Register SSE endpoint for import progress
 */
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_sse_import_progress_route' );
function register_sse_import_progress_route() {
	// error_log( '[PUNTWORK] SSE: register_sse_import_progress_route called' );
	
	// Check if verify_api_key function exists
	if ( ! function_exists( __NAMESPACE__ . '\\verify_api_key' ) ) {
		error_log( '[PUNTWORK] SSE: ERROR - verify_api_key function not found' );
		return;
	}
	
	register_rest_route(
		'puntwork/v1',
		'/import-progress',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\handle_import_progress_sse',
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
	// error_log( '[PUNTWORK] SSE: SSE route registered successfully' );
}

/*
 * Register SSE endpoint for monitoring dashboard
 */
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_sse_monitoring_route' );
function register_sse_monitoring_route() {
	// error_log( '[PUNTWORK] SSE: register_sse_monitoring_route called' );
	
	// Check if verify_api_key function exists
	if ( ! function_exists( __NAMESPACE__ . '\\verify_api_key' ) ) {
		error_log( '[PUNTWORK] SSE: ERROR - verify_api_key function not found' );
		return;
	}
	
	register_rest_route(
		'puntwork/v1',
		'/monitoring',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\handle_monitoring_sse',
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
	// error_log( '[PUNTWORK] SSE: Monitoring SSE route registered successfully' );
}

/**
 * Handle Server-Sent Events for import progress.
 */
function handle_import_progress_sse( $request ) {
	try {
		// error_log( '[PUNTWORK] SSE: handle_import_progress_sse called at ' . date( 'Y-m-d H:i:s' ) );

		$api_key = $request->get_param( 'api_key' );
		// error_log( '[PUNTWORK] SSE: API key from request: ' . ( empty( $api_key ) ? 'empty' : 'provided' ) );

		// Verify API key
		if ( empty( $api_key ) ) {
			error_log( '[PUNTWORK] SSE: Missing API key' );
			// Send error event instead of returning WP_Error
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-cache' );
			header( 'Connection: keep-alive' );
			header( 'Access-Control-Allow-Origin: ' . get_site_url() );
			header( 'Access-Control-Allow-Headers: Cache-Control' );
			if ( ob_get_level() ) {
				ob_end_clean();
			}
			echo "event: error\n";
			echo 'data: ' . json_encode(
				array(
					'timestamp' => time(),
					'error'     => 'API key is required',
					'code'      => 'missing_api_key',
				)
			) . "\n\n";
			flush();
			exit();
		}

		$stored_key = get_option( 'puntwork_api_key' );
		error_log( '[PUNTWORK] SSE: Stored API key exists: ' . ( ! empty( $stored_key ) ? 'yes' : 'no' ) );

		if ( empty( $stored_key ) || ! hash_equals( $stored_key, $api_key ) ) {
			error_log( '[PUNTWORK] SSE: Invalid API key provided' );
			// Send error event instead of returning WP_Error
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-cache' );
			header( 'Connection: keep-alive' );
			header( 'Access-Control-Allow-Origin: ' . get_site_url() );
			header( 'Access-Control-Allow-Headers: Cache-Control' );
			if ( ob_get_level() ) {
				ob_end_clean();
			}
			echo "event: error\n";
			echo 'data: ' . json_encode(
				array(
					'timestamp' => time(),
					'error'     => 'Invalid API key',
					'code'      => 'invalid_api_key',
				)
			) . "\n\n";
			flush();
			exit();
		}

		// error_log( '[PUNTWORK] SSE: API key verified, starting SSE connection' );

		// Set headers for Server-Sent Events
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'Access-Control-Allow-Origin: ' . get_site_url() );
		header( 'Access-Control-Allow-Headers: Cache-Control' );

		// Disable output buffering
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// Send initial connection event
		echo "event: connected\n";
		echo 'data: ' . json_encode(
			array(
				'status'    => 'connected',
				'timestamp' => time(),
			)
		) . "\n\n";
		flush();

		// error_log( '[PUNTWORK] SSE: Initial connection event sent' );

		$last_status         = null;
		$last_update         = 0;
		$client_disconnected = false;

		// Set up connection handling
		ignore_user_abort( false );
		set_time_limit( 0 );

		// Handle client disconnect
		register_shutdown_function(
			function () use ( &$client_disconnected ) {
				$client_disconnected = true;
			}
		);

		// Main SSE loop
		while ( ! $client_disconnected && ! connection_aborted() ) {
			// Check if client is still connected
			if ( connection_status() !== CONNECTION_NORMAL ) {
				break;
			}

			try {
				// Get current import status - bypass object caching by using direct database query
				global $wpdb;
				$status_json = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", 'job_import_status' ) );
				$current_status = $status_json ? json_decode( $status_json, true ) : array();
				error_log( '[PUNTWORK] SSE: Raw current_status from direct DB query: ' . json_encode( $current_status ) );
				
				// DEBUG: Check if we're reaching the file checking code
				error_log( '[PUNTWORK] SSE: About to check file existence - ABSPATH defined: ' . (defined('ABSPATH') ? 'YES' : 'NO') . ', DOCUMENT_ROOT set: ' . (isset($_SERVER['DOCUMENT_ROOT']) ? 'YES' : 'NO'));
				
				// Check if combined file exists and status seems incorrect
				// FIRST TRY: Use DOCUMENT_ROOT if available (more reliable in REST context)
				$combined_file = null;
				$file_exists = false;
				$file_size = 0;
				
				if (isset($_SERVER['DOCUMENT_ROOT'])) {
					$combined_file = $_SERVER['DOCUMENT_ROOT'] . '/feeds/combined-jobs.jsonl';
					error_log( '[PUNTWORK] SSE: Trying DOCUMENT_ROOT path: ' . $combined_file );
					$file_exists = file_exists( $combined_file );
					$file_size = $file_exists ? filesize( $combined_file ) : 0;
					error_log( '[PUNTWORK] SSE: DOCUMENT_ROOT file exists: ' . ($file_exists ? 'YES' : 'NO') . ', Size: ' . $file_size . ' bytes');
				}
				
				// FALLBACK: Try ABSPATH if DOCUMENT_ROOT didn't work
				if (!$file_exists && defined('ABSPATH')) {
					$combined_file = puntwork_get_combined_jsonl_path();
					error_log( '[PUNTWORK] SSE: Trying ABSPATH fallback: ' . $combined_file );
					$file_exists = file_exists( $combined_file );
					$file_size = $file_exists ? filesize( $combined_file ) : 0;
					error_log( '[PUNTWORK] SSE: ABSPATH file exists: ' . ($file_exists ? 'YES' : 'NO') . ', Size: ' . $file_size . ' bytes');
				}
				
				// ADDITIONAL FALLBACK: Try domain root feeds directory (file uploaded via FTP to /feeds/)
				if (!$file_exists) {
					$domain_root = dirname($_SERVER['DOCUMENT_ROOT'] ?? ABSPATH);
					$combined_file = $domain_root . '/feeds/combined-jobs.jsonl';
					error_log( '[PUNTWORK] SSE: Trying domain root path: ' . $combined_file );
					$file_exists = file_exists( $combined_file );
					$file_size = $file_exists ? filesize( $combined_file ) : 0;
					error_log( '[PUNTWORK] SSE: Domain root file exists: ' . ($file_exists ? 'YES' : 'NO') . ', Size: ' . $file_size . ' bytes');
				}
				
				if ( file_exists( $combined_file ) && filesize( $combined_file ) > 0 ) {
					$current_total = $current_status['total'] ?? 0;
					$current_complete = $current_status['complete'] ?? false;
					$status_exists = isset( $current_status['total'] ) && isset( $current_status['complete'] );
					
					error_log( '[PUNTWORK] SSE: Status analysis - total: ' . $current_total . ', complete: ' . ($current_complete ? 'true' : 'false') . ', status_exists: ' . ($status_exists ? 'true' : 'false'));
					
					// Check if status needs correction:
					// 1. Status is missing entirely (empty array), OR
					// 2. Status exists but shows incorrect values (total=0 and complete=true)
					$needs_correction = ( ! $status_exists ) || ( $current_total == 0 && $current_complete );
					
					error_log( '[PUNTWORK] SSE: Needs correction: ' . ($needs_correction ? 'YES' : 'NO'));
					
					if ( $needs_correction ) {
						// Status needs correction - combined file exists but status is missing or incorrect
						error_log( '[PUNTWORK] SSE: STATUS-CORRECTION: Combined file exists but status is missing or shows total=0, complete=true - correcting status' );
						
						// Try to get the actual count from the file
						if ( function_exists( 'get_json_item_count' ) ) {
							error_log( '[PUNTWORK] SSE: get_json_item_count function is available' );
							$actual_total = get_json_item_count( $combined_file );
							error_log( '[PUNTWORK] SSE: Actual total from file: ' . $actual_total );
							if ( $actual_total > 0 ) {
								$current_status = array(
									'total'              => $actual_total,
									'processed'          => 0,
									'published'          => 0,
									'updated'            => 0,
									'skipped'            => 0,
									'duplicates_drafted' => 0,
									'time_elapsed'       => 0,
									'complete'           => false, // Set to false so import can start
									'success'            => false,
									'error_message'      => '',
									'batch_size'         => 10,
									'inferred_languages' => 0,
									'inferred_benefits'  => 0,
									'schema_generated'   => 0,
									'start_time'         => microtime( true ),
									'end_time'           => null,
									'last_update'        => time(),
									//'logs'               => array( 'Import status corrected - combined file exists with ' . $actual_total . ' items' ),
								);
								update_option( 'job_import_status', $current_status );
								error_log( '[PUNTWORK] SSE: STATUS-CORRECTION: Status corrected: total=' . $actual_total . ', complete=false' );
							} else {
								error_log( '[PUNTWORK] SSE: STATUS-CORRECTION: get_json_item_count returned 0 or invalid value' );
							}
						} else {
							error_log( '[PUNTWORK] SSE: STATUS-CORRECTION: get_json_item_count function is NOT available' );
						}
					} else {
						error_log( '[PUNTWORK] SSE: STATUS-CORRECTION: No correction needed' );
					}
				} else {
					error_log( '[PUNTWORK] SSE: Combined file does not exist or is empty, skipping status correction' );
				}

				// Ensure current_status is an array and sanitize it
				if ( ! is_array( $current_status ) ) {
					error_log( '[PUNTWORK] SSE: current_status is not an array, resetting to empty array' );
					$current_status = array();
				}

				// Deep sanitize the status to ensure it's JSON serializable
				$current_status = deep_sanitize_for_json( $current_status );
				error_log( '[PUNTWORK] SSE: After deep sanitization: ' . json_encode( $current_status ) );

				// Check for "undefined" values in the status
				$undefined_found = false;
				array_walk_recursive(
					$current_status,
					function ( $value, $key ) use ( &$undefined_found ) {
						if ( $value === 'undefined' || ( is_string( $value ) && strpos( $value, 'undefined' ) !== false ) ) {
							error_log( '[PUNTWORK] SSE: Found "undefined" in status[' . $key . ']: ' . var_export( $value, true ) );
							$undefined_found = true;
						}
					}
				);
				if ( $undefined_found ) {
					error_log( '[PUNTWORK] SSE: WARNING - "undefined" values found in current_status before JSON encoding' );
					error_log( '[PUNTWORK] SSE: Full current_status: ' . print_r( $current_status, true ) );
				}

				// Check for async import status if applicable
				$async_status = check_async_import_status();
				if ( $async_status['active'] ) {
					$async_progress = $async_status['progress'] ?? array();
					// Deep sanitize async progress data
					$async_progress = deep_sanitize_for_json( $async_progress );

					$current_status                 = array_merge( $current_status, $async_progress );
					$current_status['async_active'] = true;
					$current_status['async_status'] = $async_status['status'];
				} else {
					$current_status['async_active'] = false;
				}

				error_log( '[PUNTWORK] SSE: Final current_status: ' . json_encode( $current_status ) );

				// Calculate elapsed time
				if ( isset( $current_status['start_time'] ) && $current_status['start_time'] > 0 ) {
					$current_time                   = microtime( true );
					$current_status['time_elapsed'] = $current_time - $current_status['start_time'];
				}

				// Calculate completion status
				if ( ! isset( $current_status['complete'] ) || ! $current_status['complete'] ) {
					$current_status['complete'] = ( ($current_status['processed'] ?? 0) >= ($current_status['total'] ?? 0) && ($current_status['total'] ?? 0) > 0 );
				}

				// Add additional status info
				$current_status['is_running'] = ! $current_status['complete'];
				$current_status['last_run']   = get_option( 'puntwork_last_import_run' );

				// Get next scheduled time and ensure it's serializable
				$next_scheduled                   = get_next_scheduled_time();
				$current_status['next_scheduled'] = is_array( $next_scheduled ) ?
					( $next_scheduled['formatted'] ?? null ) : $next_scheduled;

				// Only send update if status has changed or it's been more than 30 seconds
				$current_time   = time();
				$status_changed = $last_status == null ||
								json_encode( $current_status ) !== json_encode( $last_status );
				$should_update  = $status_changed || ( $current_time - $last_update ) > 30;

				if ( $should_update ) {
					$event_data = array(
						'timestamp' => $current_time,
						'status'    => $current_status,
					);

					error_log( '[PUNTWORK] SSE: About to send progress update' );
					error_log( '[PUNTWORK] SSE: event_data status keys: ' . implode( ', ', array_keys( $current_status ) ) );
					error_log( '[PUNTWORK] SSE: event_data status processed: ' . ( $current_status['processed'] ?? 'not set' ) );
					error_log( '[PUNTWORK] SSE: event_data status total: ' . ( $current_status['total'] ?? 'not set' ) );
					error_log( '[PUNTWORK] SSE: event_data status complete: ' . ( $current_status['complete'] ?? 'not set' ) );
					$json_data = json_encode( $event_data );

					if ( $json_data == false ) {
						error_log( '[PUNTWORK] SSE: JSON encoding failed: ' . json_last_error_msg() );
						error_log( '[PUNTWORK] SSE: Event data that failed: ' . print_r( $event_data, true ) );
						// Send error event instead with sanitized data
						$error_event_data = array(
							'timestamp'      => $current_time,
							'error'          => 'Failed to encode status data: ' . json_last_error_msg(),
							'status_summary' => array(
								'processed' => $current_status['processed'] ?? 0,
								'total'     => $current_status['total'] ?? 0,
								'complete'  => $current_status['complete'] ?? false,
							),
						);
						echo "event: error\n";
						echo 'data: ' . json_encode( $error_event_data ) . "\n\n";
						flush();

						continue;
					}

					error_log( '[PUNTWORK] SSE: JSON encoded successfully, length: ' . strlen( $json_data ) );
					error_log( '[PUNTWORK] SSE: JSON data starts with: ' . substr( $json_data, 0, 50 ) );
					if ( strpos( $json_data, 'undefined' ) !== false ) {
						error_log( '[PUNTWORK] SSE: WARNING - JSON contains "undefined" string!' );
						error_log( '[PUNTWORK] SSE: Full JSON: ' . $json_data );
					}
					echo "event: progress\n";
					echo 'data: ' . $json_data . "\n\n";
					flush();

					$last_status = $current_status;
					$last_update = $current_time;

					error_log( '[PUNTWORK] SSE: Progress update sent - processed: ' . ( $current_status['processed'] ?? 0 ) . '/' . ( $current_status['total'] ?? 0 ) );
				}

				// If import is complete, send final update and close connection
				if ( isset( $current_status['complete'] ) && $current_status['complete'] ) {
					echo "event: complete\n";
					echo 'data: ' . json_encode(
						array(
							'timestamp' => time(),
							'status'    => $current_status,
							'message'   => 'Import completed',
						)
					) . "\n\n";
					flush();

					error_log( '[PUNTWORK] SSE: Import completed, closing connection' );

					break;
				}
			} catch ( \Exception $e ) {
				error_log( '[PUNTWORK] SSE: Error in main loop: ' . $e->getMessage() );
				echo "event: error\n";
				echo 'data: ' . json_encode(
					array(
						'timestamp' => time(),
						'error'     => $e->getMessage(),
					)
				) . "\n\n";
				flush();

				break;
			}

			// Wait before next check (balance between real-time updates and server load)
			sleep( 1 );
		}

		error_log( '[PUNTWORK] SSE: Connection closed' );
	} catch ( \Throwable $e ) {
		error_log( '[PUNTWORK] SSE: Fatal error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		error_log( '[PUNTWORK] SSE: Stack trace: ' . $e->getTraceAsString() );
		echo "event: error\n";
		echo 'data: ' . json_encode(
			array(
				'timestamp' => time(),
				'error'     => 'SSE initialization failed: ' . $e->getMessage(),
			)
		) . "\n\n";
		flush();
	}

	exit();
}

/**
 * Handle Server-Sent Events for monitoring dashboard.
 */
function handle_monitoring_sse( $request ) {
	try {
		error_log( '[PUNTWORK] SSE: handle_monitoring_sse called at ' . date( 'Y-m-d H:i:s' ) );

		$api_key = $request->get_param( 'api_key' );
		error_log( '[PUNTWORK] SSE: API key from request: ' . ( empty( $api_key ) ? 'empty' : 'provided' ) );

		// Verify API key
		if ( empty( $api_key ) ) {
			error_log( '[PUNTWORK] SSE: Missing API key' );
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-cache' );
			header( 'Connection: keep-alive' );
			header( 'Access-Control-Allow-Origin: ' . get_site_url() );
			header( 'Access-Control-Allow-Headers: Cache-Control' );
			if ( ob_get_level() ) {
				ob_end_clean();
			}
			echo "event: error\n";
			echo 'data: ' . json_encode(
				array(
					'timestamp' => time(),
					'error'     => 'API key is required',
					'code'      => 'missing_api_key',
				)
			) . "\n\n";
			flush();
			exit();
		}

		$stored_key = get_option( 'puntwork_api_key' );
		error_log( '[PUNTWORK] SSE: Stored API key exists: ' . ( ! empty( $stored_key ) ? 'yes' : 'no' ) );

		if ( empty( $stored_key ) || ! hash_equals( $stored_key, $api_key ) ) {
			error_log( '[PUNTWORK] SSE: Invalid API key provided' );
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-cache' );
			header( 'Connection: keep-alive' );
			header( 'Access-Control-Allow-Origin: ' . get_site_url() );
			header( 'Access-Control-Allow-Headers: Cache-Control' );
			if ( ob_get_level() ) {
				ob_end_clean();
			}
			echo "event: error\n";
			echo 'data: ' . json_encode(
				array(
					'timestamp' => time(),
					'error'     => 'Invalid API key',
					'code'      => 'invalid_api_key',
				)
			) . "\n\n";
			flush();
			exit();
		}

		error_log( '[PUNTWORK] SSE: API key verified, starting monitoring SSE connection' );

		// Set headers for Server-Sent Events
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'Access-Control-Allow-Origin: ' . get_site_url() );
		header( 'Access-Control-Allow-Headers: Cache-Control' );

		// Disable output buffering
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// Send initial connection event with data
		echo "event: connected\n";
		echo 'data: ' . json_encode(
			array(
				'status'    => 'connected',
				'timestamp' => time(),
				'initial_data' => array(
					'system_metrics' => get_system_metrics_for_sse(),
					'performance_metrics' => get_performance_metrics_for_sse(),
					'rate_limit_status' => get_rate_limit_status_for_sse(),
					'dynamic_rate_status' => get_dynamic_rate_status_for_sse(),
					'feed_health_status' => get_feed_health_status_for_sse(),
					'queue_stats' => get_queue_stats_for_sse(),
					'analytics_data' => get_analytics_data_for_sse('30days'),
				)
			)
		) . "\n\n";
		flush();

		error_log( '[PUNTWORK] SSE: Monitoring SSE initial connection event sent with data' );

		$last_data           = null;
		$last_update         = 0;
		$client_disconnected = false;

		// Set up connection handling
		ignore_user_abort( false );
		set_time_limit( 0 );

		// Handle client disconnect
		register_shutdown_function(
			function () use ( &$client_disconnected ) {
				$client_disconnected = true;
			}
		);

		// Main SSE loop for monitoring data
		while ( ! $client_disconnected && ! connection_aborted() ) {
			// Check if client is still connected
			if ( connection_status() !== CONNECTION_NORMAL ) {
				break;
			}

			try {
				$current_time = time();

				// Collect monitoring data
				$monitoring_data = array(
					'timestamp' => $current_time,
					'system_metrics' => get_system_metrics_for_sse(),
					'performance_metrics' => get_performance_metrics_for_sse(),
					'rate_limit_status' => get_rate_limit_status_for_sse(),
					'dynamic_rate_status' => get_dynamic_rate_status_for_sse(),
					'feed_health_status' => get_feed_health_status_for_sse(),
					'queue_stats' => get_queue_stats_for_sse(),
				);

				// Deep sanitize the monitoring data
				$monitoring_data = deep_sanitize_for_json( $monitoring_data );

				// Only send update if data has changed or it's been more than 30 seconds
				$data_changed = $last_data == null ||
							   json_encode( $monitoring_data ) !== json_encode( $last_data );
				$should_update = $data_changed || ( $current_time - $last_update ) > 30;

				if ( $should_update ) {
					$event_data = array(
						'timestamp' => $current_time,
						'data'      => $monitoring_data,
					);

					$json_data = json_encode( $event_data );

					if ( $json_data === false ) {
						error_log( '[PUNTWORK] SSE: JSON encoding failed for monitoring data: ' . json_last_error_msg() );
						continue;
					}

					echo "event: monitoring\n";
					echo 'data: ' . $json_data . "\n\n";
					flush();

					$last_data = $monitoring_data;
					$last_update = $current_time;

					error_log( '[PUNTWORK] SSE: Monitoring update sent at ' . date( 'Y-m-d H:i:s', $current_time ) );
				}

			} catch ( \Exception $e ) {
				error_log( '[PUNTWORK] SSE: Error in monitoring loop: ' . $e->getMessage() );
				echo "event: error\n";
				echo 'data: ' . json_encode(
					array(
						'timestamp' => time(),
						'error'     => $e->getMessage(),
					)
				) . "\n\n";
				flush();
				break;
			}

			// Wait before next check (balance between real-time updates and server load)
			sleep( 2 );
		}

		error_log( '[PUNTWORK] SSE: Monitoring SSE connection closed' );
	} catch ( \Throwable $e ) {
		error_log( '[PUNTWORK] SSE: Fatal error in monitoring SSE: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		echo "event: error\n";
		echo 'data: ' . json_encode(
			array(
				'timestamp' => time(),
				'error'     => 'Monitoring SSE initialization failed: ' . $e->getMessage(),
			)
		) . "\n\n";
		flush();
	}

	exit();
}

/**
 * Get system metrics for SSE
 */
function get_system_metrics_for_sse() {
	return array(
		'timestamp'            => current_time( 'timestamp' ),
		'memory_usage'         => function_exists( __NAMESPACE__ . '\\puntwork_get_memory_usage' ) ? call_user_func( __NAMESPACE__ . '\\puntwork_get_memory_usage' ) : array(),
		'cpu_usage'            => function_exists( __NAMESPACE__ . '\\puntwork_get_cpu_usage' ) ? call_user_func( __NAMESPACE__ . '\\puntwork_get_cpu_usage' ) : array(),
		'disk_usage'           => function_exists( __NAMESPACE__ . '\\puntwork_get_disk_usage' ) ? call_user_func( __NAMESPACE__ . '\\puntwork_get_disk_usage' ) : array(),
		'database_connections' => function_exists( __NAMESPACE__ . '\\puntwork_get_db_connections' ) ? call_user_func( __NAMESPACE__ . '\\puntwork_get_db_connections' ) : array(),
		'active_users'         => function_exists( __NAMESPACE__ . '\\puntwork_get_active_users' ) ? call_user_func( __NAMESPACE__ . '\\puntwork_get_active_users' ) : 0,
		'queue_status'         => function_exists( __NAMESPACE__ . '\\puntwork_get_queue_status' ) ? call_user_func( __NAMESPACE__ . '\\puntwork_get_queue_status' ) : array(),
		'error_rate'           => function_exists( __NAMESPACE__ . '\\puntwork_get_error_rate' ) ? call_user_func( __NAMESPACE__ . '\\puntwork_get_error_rate' ) : array(),
		'response_time'        => function_exists( __NAMESPACE__ . '\\puntwork_get_response_time' ) ? call_user_func( __NAMESPACE__ . '\\puntwork_get_response_time' ) : array(),
	);
}

/**
 * Get performance metrics for SSE
 */
function get_performance_metrics_for_sse() {
	return array(
		'timestamp'            => current_time( 'timestamp' ),
		'time_range'           => '1h',
		'page_load_times'      => function_exists( __NAMESPACE__ . '\\puntwork_get_page_load_times' ) ? call_user_func( __NAMESPACE__ . '\\puntwork_get_page_load_times', '1h' ) : array(),
		'api_response_times'   => function_exists( __NAMESPACE__ . '\\puntwork_get_api_response_times' ) ? call_user_func( __NAMESPACE__ . '\\puntwork_get_api_response_times', '1h' ) : array(),
		'database_query_times' => function_exists( __NAMESPACE__ . '\\puntwork_get_db_query_times' ) ? call_user_func( __NAMESPACE__ . '\\puntwork_get_db_query_times', '1h' ) : array(),
		'cache_hit_rate'       => function_exists( __NAMESPACE__ . '\\puntwork_get_cache_hit_rate' ) ? call_user_func( __NAMESPACE__ . '\\puntwork_get_cache_hit_rate', '1h' ) : array(),
		'throughput'           => function_exists( __NAMESPACE__ . '\\puntwork_get_throughput' ) ? call_user_func( __NAMESPACE__ . '\\puntwork_get_throughput', '1h' ) : array(),
	);
}

/**
 * Get rate limit status for SSE
 */
function get_rate_limit_status_for_sse() {
	// This would need to be implemented to return current rate limit status
	// For now, return a basic structure
	return array(
		'enabled' => true,
		'limits'  => array(
			'get_job_import_status' => array('requests' => 5, 'limit' => 100),
			'run_job_import_batch' => array('requests' => 2, 'limit' => 50),
			'process_feed' => array('requests' => 10, 'limit' => 200),
		),
		'usage'   => array(
			'total_requests' => 45,
			'time_window' => 3600, // 1 hour
		),
	);
}

/**
 * Get dynamic rate status for SSE
 */
function get_dynamic_rate_status_for_sse() {
	// This would need to be implemented to return dynamic rate limiting status
	return array(
		'enabled' => true,
		'metrics' => array(
			'total_metrics'   => 0,
			'recent_metrics'  => 0,
			'current_load'    => 0,
			'current_memory'  => 0,
			'current_cpu'     => 0,
		),
	);
}

/**
 * Get feed health status for SSE
 */
function get_feed_health_status_for_sse() {
	// This would need to be implemented to return feed health data
	return array(
		'feeds' => array(),
		'overall_health' => 'healthy',
	);
}

/**
 * Get queue stats for SSE
 */
function get_queue_stats_for_sse() {
	return array(
		'pending'    => 0,
		'processing' => 0,
		'completed'  => 0,
		'failed'     => 0,
		'recent_jobs' => array(),
	);
}

/**
 * Get analytics data for SSE
 */
function get_analytics_data_for_sse( $period = '30days' ) {
	try {
		// Use ReportingEngine if available, otherwise return basic structure
		if ( class_exists( '\\Puntwork\\Reporting\\ReportingEngine' ) ) {
			$date_range = 30; // Default to 30 days
			switch ( $period ) {
				case '7days':
					$date_range = 7;
					break;
				case '90days':
					$date_range = 90;
					break;
			}
			
			$report_data = \Puntwork\Reporting\ReportingEngine::generatePerformanceReport( array( 'date_range' => $date_range ) );
			
			// Transform to match expected analytics structure
			return array(
				'overview' => array(
					'total_imports' => $report_data['summary']['total_imports'] ?? 0,
					'total_processed' => $report_data['summary']['total_jobs'] ?? 0,
					'avg_success_rate' => $report_data['summary']['avg_success_rate'] ?? 0,
					'avg_duration' => $report_data['summary']['avg_response_time'] ?? 0,
					'total_published' => 0, // Not available in performance report
					'total_updated' => 0,
					'total_duplicates' => 0,
				),
				'performance' => array(), // Would need more detailed data
				'trends' => array(
					'daily' => $report_data['trends'] ?? array(),
					'hourly' => array(),
				),
				'feed_stats' => array(
					'avg_feeds_processed' => 0,
					'avg_feeds_successful' => 0,
					'avg_feeds_failed' => 0,
					'avg_response_time' => $report_data['summary']['avg_response_time'] ?? 0,
				),
				'errors' => array(
					'total_errors' => 0,
					'error_messages' => '',
				),
			);
		}
		
		// Fallback: return basic analytics structure
		return array(
			'overview' => array(
				'total_imports' => 0,
				'total_processed' => 0,
				'avg_success_rate' => 0,
				'avg_duration' => 0,
				'total_published' => 0,
				'total_updated' => 0,
				'total_duplicates' => 0,
			),
			'performance' => array(),
			'trends' => array(
				'daily' => array(),
				'hourly' => array(),
			),
			'feed_stats' => array(
				'avg_feeds_processed' => 0,
				'avg_feeds_successful' => 0,
				'avg_feeds_failed' => 0,
				'avg_response_time' => 0,
			),
			'errors' => array(
				'total_errors' => 0,
				'error_messages' => '',
			),
		);
	} catch ( \Exception $e ) {
		error_log( '[PUNTWORK] SSE: Error getting analytics data: ' . $e->getMessage() );
		return array(
			'error' => 'Failed to load analytics data',
			'overview' => array(
				'total_imports' => 0,
				'total_processed' => 0,
				'avg_success_rate' => 0,
				'avg_duration' => 0,
				'total_published' => 0,
				'total_updated' => 0,
				'total_duplicates' => 0,
			),
		);
	}
}

/**
 * Get activity logs for SSE
 */
function get_activity_logs_for_sse( $limit = 20 ) {
	try {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'puntwork_logs';
		
		// Check if table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
			return array();
		}
		
		$logs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		
		if ( ! $logs ) {
			return array();
		}
		
		// Format logs for SSE
		$formatted_logs = array();
		foreach ( $logs as $log ) {
			$formatted_logs[] = array(
				'level' => $log['level'] ?? 'info',
				'message' => $log['message'] ?? 'Unknown event',
				'timestamp' => $log['timestamp'] ?? time(),
			);
		}
		
		return $formatted_logs;
	} catch ( \Exception $e ) {
		error_log( '[PUNTWORK] SSE: Error getting activity logs: ' . $e->getMessage() );
		return array();
	}
}

/*
 * Register REST API endpoints for monitoring actions
 */
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_monitoring_api_routes' );
function register_monitoring_api_routes() {
	// error_log( '[PUNTWORK] SSE: register_monitoring_api_routes called' );
	
	// Activity logs endpoint
	register_rest_route(
		'puntwork/v1',
		'/monitoring/activity-logs',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\handle_get_activity_logs',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
				'limit' => array(
					'required'    => false,
					'type'        => 'integer',
					'default'     => 20,
					'description' => 'Number of logs to retrieve',
				),
			),
		)
	);
	
	// Clear logs endpoint
	register_rest_route(
		'puntwork/v1',
		'/monitoring/clear-logs',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\handle_clear_old_logs',
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
	
	// Save alert settings endpoint
	register_rest_route(
		'puntwork/v1',
		'/monitoring/alert-settings',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\handle_save_alert_settings',
			'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
			'args'                => array(
				'api_key' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => 'API key for authentication',
				),
				'email_enabled' => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => true,
				),
				'email_recipients' => array(
					'required' => false,
					'type'     => 'string',
					'default'  => '',
				),
				'alert_types' => array(
					'required' => false,
					'type'     => 'object',
					'default'  => array(),
				),
			),
		)
	);
	
	// error_log( '[PUNTWORK] SSE: Monitoring API routes registered successfully' );
}
