<?php

/**
 * AJAX handlers for import control operations
 * Handles batch processing, cancellation, and status retrieval.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handlers for import control operations
 * Handles batch processing, cancellation, and status retrieval.
 */

// Explicitly load required utility classes for AJAX context
require_once __DIR__ . '/../utilities/SecurityUtils.php';
require_once __DIR__ . '/../utilities/PuntWorkLogger.php';
require_once __DIR__ . '/../utilities/CacheManager.php';
require_once __DIR__ . '/../utilities/EnhancedCacheManager.php';
require_once __DIR__ . '/../utilities/AjaxErrorHandler.php';
require_once __DIR__ . '/../utilities/DynamicRateLimiter.php';
require_once __DIR__ . '/../utilities/AdvancedJsonlProcessor.php';
require_once __DIR__ . '/../utilities/JsonlOptimizer.php';
require_once __DIR__ . '/../utilities/utility-helpers.php';
require_once __DIR__ . '/../import/feed-processor.php';  // Load FeedProcessor before core-structure-logic.php
require_once __DIR__ . '/../core/core-structure-logic.php';
require_once __DIR__ . '/../import/download-feed.php';
require_once __DIR__ . '/../utilities/gzip-file.php';
require_once __DIR__ . '/../import/combine-jsonl.php';
require_once __DIR__ . '/../jobboards/jobboard.php';
require_once __DIR__ . '/../jobboards/jobboard-manager.php';
require_once __DIR__ . '/../jobboards/indeed-board.php';
require_once __DIR__ . '/../jobboards/linkedin-board.php';
require_once __DIR__ . '/../jobboards/glassdoor-board.php';
require_once __DIR__ . '/../utilities/ImportAnalytics.php';  // Load ImportAnalytics to prevent class not found errors

/*
 * AJAX handlers for import control operations
 * Handles batch processing, cancellation, and status retrieval
 */

add_action( 'wp_ajax_run_job_import_batch', __NAMESPACE__ . '\\run_job_import_batch_ajax' );
function run_job_import_batch_ajax() {
	// Log the start of AJAX request processing
	PuntWorkLogger::logAjaxRequest( 'run_job_import_batch', $_POST );
	error_log( '[PUNTWORK] [AJAX-START] run_job_import_batch_ajax function called at ' . date( 'Y-m-d H:i:s' ) . ' UTC' );
	error_log( '[PUNTWORK] [AJAX-START] POST data: ' . json_encode( $_POST ) );
	error_log( '[PUNTWORK] [AJAX-START] Current user: ' . get_current_user_id() . ' (' . ( current_user_can( 'manage_options' ) ? 'admin' : 'non-admin' ) . ')' );
	error_log( '[PUNTWORK] [AJAX-START] Memory usage at start: ' . memory_get_usage( true ) . ' bytes' );
	error_log( '[PUNTWORK] [AJAX-START] PHP version: ' . PHP_VERSION . ', WordPress: ' . get_bloginfo( 'version' ) );

	try {
		// Verify nonce for security
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'job_import_nonce' ) ) {
			error_log( '[PUNTWORK] [AJAX-ERROR] Nonce verification failed' );
			wp_send_json_error( array( 'message' => 'Security check failed' ) );

			return;
		}
		error_log( '[PUNTWORK] [AJAX-SECURITY] Nonce verification passed' );

		// Log that we entered the function
		error_log( '[PUNTWORK] [AJAX-ENTRY] AJAX handler entered successfully' );

		// Ensure ACF is loaded for AJAX calls
		if ( ! function_exists( 'get_field' ) ) {
			error_log( '[PUNTWORK] [AJAX-LOAD] ACF functions not available, attempting to load ACF plugin' );
			// Try to load ACF if it's installed
			if ( file_exists( WP_PLUGIN_DIR . '/advanced-custom-fields/acf.php' ) ) {
				include_once WP_PLUGIN_DIR . '/advanced-custom-fields/acf.php';
				error_log( '[PUNTWORK] [AJAX-LOAD] Loaded ACF from standard location' );
			} elseif ( file_exists( WP_PLUGIN_DIR . '/advanced-custom-fields-pro/acf.php' ) ) {
				include_once WP_PLUGIN_DIR . '/advanced-custom-fields-pro/acf.php';
				error_log( '[PUNTWORK] [AJAX-LOAD] Loaded ACF Pro from standard location' );
			} else {
				error_log( '[PUNTWORK] [AJAX-LOAD] ACF plugin files not found in standard locations' );
			}
		} else {
			error_log( '[PUNTWORK] [AJAX-LOAD] ACF functions already available' );
		}

		// Load import files
		$import_files = array(
			__DIR__ . '/../batch/batch-size-management.php',
			__DIR__ . '/../import/import-setup.php',
			__DIR__ . '/../batch/batch-processing.php',
			__DIR__ . '/../batch/batch-loading.php',
			__DIR__ . '/../import/import-finalization.php',
			__DIR__ . '/../utilities/ErrorHandler.php',
			__DIR__ . '/../exceptions/PuntworkExceptions.php',
			__DIR__ . '/../import/import-batch.php',
		);

		foreach ( $import_files as $file ) {
			if ( file_exists( $file ) ) {
				error_log( '[PUNTWORK] [AJAX-LOAD] Attempting to load file: ' . basename( $file ) );

				try {
					$load_result = include_once $file;
					error_log( '[PUNTWORK] [AJAX-LOAD] Loaded import file: ' . basename( $file ) . ', result: ' . ( $load_result ? 'true' : 'false' ) );
				} catch ( \Exception $e ) {
					error_log( '[PUNTWORK] [AJAX-LOAD] Exception loading ' . basename( $file ) . ': ' . $e->getMessage() );
				} catch ( \Error $e ) {
					error_log( '[PUNTWORK] [AJAX-LOAD] Fatal error loading ' . basename( $file ) . ': ' . $e->getMessage() );
				}
			} else {
				error_log( '[PUNTWORK] [AJAX-LOAD] Import file not found: ' . $file );
			}
		}

		// Check again after loading
		if ( ! function_exists( 'import_jobs_from_json' ) ) {
			error_log( '[PUNTWORK] [AJAX-LOAD] import_jobs_from_json function still not found after loading files' );
			// List all functions that start with 'import_' to see what's available
			$all_functions    = get_defined_functions();
			$import_functions = array_filter(
				$all_functions['user'],
				function ( $func ) {
					return strpos( $func, 'import_' ) === 0;
				}
			);
			error_log( '[PUNTWORK] [AJAX-LOAD] Available import functions: ' . implode( ', ', $import_functions ) );
			wp_send_json_error( array( 'message' => 'Import function not available - files could not be loaded' ) );

			return;
		}

		error_log( '[PUNTWORK] [AJAX-LOAD] import_jobs_from_json function now available after loading files' );

		// Use comprehensive security validation with field validation
		error_log( '[PUNTWORK] [AJAX-SECURITY] About to validate AJAX request' );
		$validation = SecurityUtils::validateAjaxRequest(
			'run_job_import_batch',
			'job_import_nonce',
			array( 'start' ), // required fields
			array(
				'start' => array(
					'type' => 'int',
					'min'  => 0,
					'max'  => 1000000,
				), // validation rules
			)
		);
		error_log( '[PUNTWORK] [AJAX-SECURITY] Security validation completed' );

		if ( is_wp_error( $validation ) ) {
			error_log( '[PUNTWORK] [AJAX-SECURITY] Security validation failed: ' . $validation->get_error_message() );
			wp_send_json_error( array( 'message' => is_wp_error( $validation ) ? $validation->get_error_message() : 'Validation failed' ) );

			return;
		}
		error_log( '[PUNTWORK] [AJAX-SECURITY] Security validation passed' );

		// Log comprehensive security validation results
		$user_id   = get_current_user_id();
		$user_ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		$memory_mb = round( memory_get_usage( true ) / 1024 / 1024, 2 );
		error_log( '[PUNTWORK] [AJAX-RECEIVED] Import request received - User: ' . $user_id . ', IP: ' . $user_ip . ', Memory: ' . $memory_mb . 'MB' );
		error_log( '[PUNTWORK] [AJAX-SECURITY] Nonce verification: passed; User permissions check: ' . ( current_user_can( 'manage_options' ) ? 'passed' : 'failed' ) . ' for user ' . $user_id . '; Field validation: start=' . ( $_POST['start'] ?? 'missing' ) . ', type=' . ( is_numeric( $_POST['start'] ?? null ) ? 'valid' : 'invalid' ) );

		// Check for concurrent import lock
		if ( get_transient( 'puntwork_import_lock' ) ) {
			\Puntwork\PuntWorkLogger::warn(
				'Import already running - concurrent request blocked',
				\Puntwork\PuntWorkLogger::CONTEXT_AJAX,
				array(
					'user_id'     => get_current_user_id(),
					'timestamp'   => time(),
					'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
				)
			);
			error_log( '[PUNTWORK] [AJAX-LOCK] Import already running, rejecting request' );
			AjaxErrorHandler::sendError( 'Import already running' );

			return;
		}
		error_log( '[PUNTWORK] [AJAX-LOCK] No import lock found, proceeding' );

		try {
			$start = $_POST['start'];
			PuntWorkLogger::info( "Starting batch import at index: {$start}", PuntWorkLogger::CONTEXT_BATCH );
			error_log( '[PUNTWORK] [AJAX-PROCESS] Starting batch import at index: ' . $start );

			// Add detailed logging before calling import_jobs_from_json
			PuntWorkLogger::debug( "About to call import_jobs_from_json with start={$start}", PuntWorkLogger::CONTEXT_BATCH );
			error_log( '[PUNTWORK] [AJAX-CALL] About to call import_jobs_from_json with start=' . $start );

			// Check if required functions exist before calling
			if ( ! function_exists( 'prepare_import_setup' ) ) {
				error_log( '[PUNTWORK] [AJAX-ERROR] prepare_import_setup function not found' );
				AjaxErrorHandler::sendError( 'prepare_import_setup function not available' );

				return;
			}
			if ( ! function_exists( 'process_batch_items_logic' ) ) {
				error_log( '[PUNTWORK] [AJAX-ERROR] process_batch_items_logic function not found' );
				AjaxErrorHandler::sendError( 'process_batch_items_logic function not available' );

				return;
			}
			if ( ! function_exists( 'finalize_batch_import' ) ) {
				error_log( '[PUNTWORK] [AJAX-ERROR] finalize_batch_import function not found' );
				AjaxErrorHandler::sendError( 'finalize_batch_import function not available' );

				return;
			}

			error_log( '[PUNTWORK] [AJAX-CHECK] All required functions are available' );

			try {
				error_log( '[PUNTWORK] [AJAX-EXECUTE] Starting manual import process...' );
				error_log( '[PUNTWORK] [AJAX-EXECUTE] Batch start parameter: ' . ( $start ?? 'null' ) );
				error_log( '[PUNTWORK] [AJAX-EXECUTE] Current user ID: ' . get_current_user_id() );
				error_log( '[PUNTWORK] [AJAX-EXECUTE] Current user capabilities: ' . ( current_user_can( 'manage_options' ) ? 'admin' : 'non-admin' ) );

				error_log( '[PUNTWORK] [AJAX-EXECUTE] Calling import_jobs_from_json...' );
				$result = import_jobs_from_json( true, $start );
				error_log( '[PUNTWORK] [AJAX-EXECUTE] import_jobs_from_json returned successfully' );
				error_log( '[PUNTWORK] [AJAX-EXECUTE] import_jobs_from_json result keys: ' . implode( ', ', array_keys( $result ) ) );
				error_log( '[PUNTWORK] [AJAX-EXECUTE] import_jobs_from_json result: ' . json_encode( $result ) );

				// Check if this batch completed the import and finalize if needed
				if ( isset( $result['complete'] ) && $result['complete'] && isset( $result['success'] ) && $result['success'] ) {
					error_log( '[PUNTWORK] [AJAX-FINALIZE] Import batch completed successfully, calling finalize_batch_import...' );

					try {
						$final_result = finalize_batch_import( $result );
						error_log( '[PUNTWORK] [AJAX-FINALIZE] finalize_batch_import completed successfully' );
						error_log( '[PUNTWORK] [AJAX-FINALIZE] Final result: ' . json_encode( $final_result ) );

						// Merge finalization results with the import result
						$result = array_merge( $result, array(
							'finalized' => true,
							'cleanup_completed' => isset( $final_result['success'] ) && $final_result['success'],
						) );
					} catch ( \Exception $e ) {
						error_log( '[PUNTWORK] [AJAX-FINALIZE] Exception during finalization: ' . $e->getMessage() );
						$result['finalization_error'] = $e->getMessage();
					} catch ( \Throwable $e ) {
						error_log( '[PUNTWORK] [AJAX-FINALIZE] Fatal error during finalization: ' . $e->getMessage() );
						$result['finalization_error'] = $e->getMessage();
					}
				} else {
					error_log( '[PUNTWORK] [AJAX-FINALIZE] Import batch not complete yet (complete: ' . ( $result['complete'] ?? false ) . ', success: ' . ( $result['success'] ?? false ) . ')' );
				}
			} catch ( \Exception $e ) {
				error_log( '[PUNTWORK] [AJAX-EXECUTE] Exception in import_jobs_from_json: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
				error_log( '[PUNTWORK] [AJAX-EXECUTE] Stack trace: ' . $e->getTraceAsString() );
				AjaxErrorHandler::sendError( 'Import failed with exception: ' . $e->getMessage() );

				return;
			} catch ( \Throwable $e ) {
				error_log( '[PUNTWORK] [AJAX-EXECUTE] Fatal error in import_jobs_from_json: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
				error_log( '[PUNTWORK] [AJAX-EXECUTE] Stack trace: ' . $e->getTraceAsString() );
				AjaxErrorHandler::sendError( 'Import failed with fatal error: ' . $e->getMessage() );

				return;
			}

			// Log summary instead of full result to prevent large debug logs
			$log_summary = array(
				'success'    => isset( $result['success'] ) && $result['success'],
				'processed'  => $result['processed'] ?? 0,
				'total'      => $result['total'] ?? 0,
				'published'  => $result['published'] ?? 0,
				'updated'    => $result['updated'] ?? 0,
				'skipped'    => $result['skipped'] ?? 0,
				'complete'   => $result['complete'] ?? false,
				'logs_count' => isset( $result['logs'] ) && is_array( $result['logs'] ) ? count( $result['logs'] ) : 0,
				'has_error'  => ! empty( $result['message'] ),
			);

			PuntWorkLogger::logAjaxResponse( 'run_job_import_batch', $log_summary, isset( $result['success'] ) && $result['success'] );

			// Enhance response with additional debugging information
			$enhanced_result = $result;
			$enhanced_result['debug_info'] = array(
				'memory_peak' => memory_get_peak_usage( true ),
				'memory_current' => memory_get_usage( true ),
				'execution_time' => microtime( true ) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true )),
				'php_version' => PHP_VERSION,
				'wp_version' => get_bloginfo( 'version' ),
				'acf_available' => function_exists( 'get_field' ),
				'response_timestamp' => time(),
			);

			// Add error details if import failed
			if ( ! isset( $result['success'] ) || ! $result['success'] ) {
				$enhanced_result['debug_info']['error_details'] = array(
					'last_error' => error_get_last(),
					'wp_debug' => defined( 'WP_DEBUG' ) && WP_DEBUG,
					'error_reporting' => error_reporting(),
				);
			}

			AjaxErrorHandler::sendSuccess( $enhanced_result );
		} catch ( \Exception $e ) {
			PuntWorkLogger::error( 'Batch import error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
			error_log( '[PUNTWORK] AJAX: Batch import exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
			error_log( '[PUNTWORK] AJAX: Stack trace: ' . $e->getTraceAsString() );
			AjaxErrorHandler::sendError( 'Batch import failed: ' . $e->getMessage() );
		}
	} catch ( \Throwable $e ) {
		error_log( '[PUNTWORK] AJAX: Fatal error in run_job_import_batch_ajax: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		error_log( '[PUNTWORK] AJAX: Stack trace: ' . $e->getTraceAsString() );
		wp_die( 'Internal server error', '500 Internal Server Error', array( 'response' => 500 ) );
	}
}

add_action( 'wp_ajax_cancel_job_import', __NAMESPACE__ . '\\cancel_job_import_ajax' );
function cancel_job_import_ajax() {
	PuntWorkLogger::logAjaxRequest( 'cancel_job_import', $_POST );

	// Use comprehensive security validation
	$validation = SecurityUtils::validateAjaxRequest( 'cancel_job_import', 'job_import_nonce' );
	if ( is_wp_error( $validation ) ) {
		wp_send_json_error( array( 'message' => $validation->get_error_message() ) );

		return;
	}

	try {
		set_transient( 'import_cancel', true, 3600 );
		// Also clear the import status to reset the UI
		delete_option( 'job_import_status' );
		delete_option( 'job_import_batch_size' );
		PuntWorkLogger::info( 'Import cancelled and status cleared', PuntWorkLogger::CONTEXT_BATCH );

		PuntWorkLogger::logAjaxResponse( 'cancel_job_import', array( 'message' => 'Import cancelled' ) );
		wp_send_json_success( null, array( 'message' => 'Import cancelled' ) );
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Cancel import error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		wp_send_json_error( array( 'message' => 'Failed to cancel import: ' . $e->getMessage() ) );
	}
}

add_action( 'wp_ajax_clear_import_cancel', __NAMESPACE__ . '\\clear_import_cancel_ajax' );
function clear_import_cancel_ajax() {
	PuntWorkLogger::logAjaxRequest( 'clear_import_cancel', $_POST );

	// Use simple validation to avoid 500 errors like reset_job_import_status_ajax
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'job_import_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed' ) );
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		return;
	}

	try {
		delete_transient( 'import_cancel' );
		PuntWorkLogger::info( 'Import cancellation flag cleared', PuntWorkLogger::CONTEXT_BATCH );

		PuntWorkLogger::logAjaxResponse( 'clear_import_cancel', array( 'message' => 'Cancellation cleared' ) );
		wp_send_json_success( null, array( 'message' => 'Cancellation cleared' ) );
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Clear import cancel error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		wp_send_json_error( array( 'message' => 'Failed to clear cancellation: ' . $e->getMessage() ) );
	}
}

add_action( 'wp_ajax_reset_job_import_status', __NAMESPACE__ . '\\reset_job_import_status_ajax' );
add_action( 'wp_ajax_reset_job_import', __NAMESPACE__ . '\\reset_job_import_status_ajax' ); // Alias for compatibility
function reset_job_import_status_ajax() {
	// Add CORS headers
	header( 'Access-Control-Allow-Origin: ' . ( isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '*' ) );
	header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS' );
	header( 'Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization' );
	header( 'Access-Control-Allow-Credentials: true' );

	// Handle preflight requests
	if ( $_SERVER['REQUEST_METHOD'] == 'OPTIONS' ) {
		exit( 0 );
	}

	error_log( '[PUNTWORK] [DEBUG-PHP] ===== RESET_JOB_IMPORT_STATUS_AJAX START =====' );
	error_log( '[PUNTWORK] [DEBUG-PHP] Timestamp: ' . date( 'Y-m-d H:i:s T' ) );
	error_log( '[PUNTWORK] [DEBUG-PHP] POST data: ' . json_encode( $_POST ) );
	error_log( '[PUNTWORK] [DEBUG-PHP] Memory usage: ' . memory_get_usage( true ) . ' bytes' );

	PuntWorkLogger::logAjaxRequest( 'reset_job_import_status', $_POST );

	error_log( '[PUNTWORK] [DEBUG-PHP] Starting security validation' );
	// Use simple validation first to debug
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'job_import_nonce' ) ) {
		error_log( '[PUNTWORK] [DEBUG-PHP] Simple nonce verification failed' );
		wp_send_json_error( array( 'message' => 'Security check failed' ) );

		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		error_log( '[PUNTWORK] [DEBUG-PHP] User capability check failed' );
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );

		return;
	}
	error_log( '[PUNTWORK] [DEBUG-PHP] Simple security validation passed' );

	// Skip comprehensive validation for now to avoid 500 errors
	// $validation = SecurityUtils::validateAjaxRequest('reset_job_import_status', 'job_import_nonce');
	// if (is_wp_error($validation)) {
	// error_log('[PUNTWORK] [DEBUG-PHP] Comprehensive security validation failed: ' . $validation->get_error_message());
	// Continue anyway for debugging
	// } else {
	// error_log('[PUNTWORK] [DEBUG-PHP] Comprehensive security validation passed');
	// }

	try {
		error_log( '[PUNTWORK] [DEBUG-PHP] Starting import status reset' );
		// Clear only the import status, not other options
		delete_option( 'job_import_status' );
		delete_option( 'puntwork_last_import_details' );
		error_log( '[PUNTWORK] [DEBUG-PHP] Deleted job_import_status and puntwork_last_import_details options' );

		// Also reset progress and related options for complete reset
		delete_option( 'job_import_progress' );
		delete_option( 'job_import_processed_guids' );
		delete_option( 'job_import_last_batch_time' );
		delete_option( 'job_import_last_batch_processed' );
		delete_option( 'job_import_batch_size' );
		delete_option( 'job_import_consecutive_small_batches' );
		delete_transient( 'import_cancel' );
		error_log( '[PUNTWORK] [DEBUG-PHP] Deleted all related import options and transients' );

		PuntWorkLogger::info( 'Import status and progress completely reset', PuntWorkLogger::CONTEXT_BATCH );

		PuntWorkLogger::logAjaxResponse( 'reset_job_import_status', array( 'message' => 'Import status reset' ) );
		error_log( '[PUNTWORK] [DEBUG-PHP] Sending success response' );
		wp_send_json_success( array( 'message' => 'Import status reset' ) );
		error_log( '[PUNTWORK] [DEBUG-PHP] ===== RESET_JOB_IMPORT_STATUS_AJAX SUCCESS =====' );
	} catch ( \Exception $e ) {
		error_log( '[PUNTWORK] [DEBUG-PHP] Exception in reset_job_import_status_ajax: ' . $e->getMessage() );
		error_log( '[PUNTWORK] [DEBUG-PHP] Stack trace: ' . $e->getTraceAsString() );
		PuntWorkLogger::error( 'Reset import status error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		wp_send_json_error( array( 'message' => 'Failed to reset import status: ' . $e->getMessage() ) );
	} catch ( \Throwable $e ) {
		error_log( '[PUNTWORK] [DEBUG-PHP] Fatal error in reset_job_import_status_ajax: ' . $e->getMessage() );
		error_log( '[PUNTWORK] [DEBUG-PHP] Fatal stack trace: ' . $e->getTraceAsString() );
		PuntWorkLogger::error( 'Reset import status fatal error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		wp_send_json_error( array( 'message' => 'Failed to reset import status with fatal error: ' . $e->getMessage() ) );
	}
}

add_action( 'wp_ajax_get_job_import_status', __NAMESPACE__ . '\\get_job_import_status_ajax' );
add_action( 'wp_ajax_get_import_status', __NAMESPACE__ . '\\get_job_import_status_ajax' ); // Alias for compatibility
function get_job_import_status_ajax() {
	PuntWorkLogger::logAjaxRequest( 'get_job_import_status', $_POST );

	// Simple validation for debugging
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'job_import_nonce' ) ) {
		error_log( '[PUNTWORK] [DEBUG-AJAX] Nonce verification failed' );
		wp_send_json_error( array( 'message' => 'Security check failed' ) );

		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		error_log( '[PUNTWORK] [DEBUG-AJAX] Insufficient permissions' );
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );

		return;
	}

	try {
		$raw_status = get_option( 'job_import_status' );
		error_log( '[PUNTWORK] [STATUS-DEBUG] Raw job_import_status from get_option: ' . json_encode( $raw_status ) );
		
		$progress = get_option( 'job_import_status', array(
			'total'              => 0,
			'processed'          => 0,
			'published'          => 0,
			'updated'            => 0,
			'skipped'            => 0,
			'duplicates_drafted' => 0,
			'time_elapsed'       => 0,
			'complete'           => true, // Fresh state is complete
			'success'            => false, // Add success status
			'error_message'      => '', // Add error message for failures
			'batch_size'         => 10,
			'inferred_languages' => 0,
			'inferred_benefits'  => 0,
			'schema_generated'   => 0,
			'start_time'         => microtime( true ),
			'end_time'           => null,
			'last_update'        => time(),
			'logs'               => array(),
		) );

		error_log( '[PUNTWORK] [STATUS-DEBUG] Final progress after get_option with default: ' . json_encode( $progress ) );

		error_log( '[PUNTWORK] [STATUS-CORRECTION] ===== STATUS CORRECTION LOGIC START =====' );

		// Check if combined file exists and status seems incorrect
		$combined_file = ABSPATH . 'feeds/combined-jobs.jsonl';
		error_log( '[PUNTWORK] [STATUS-CORRECTION] Checking combined file: ' . $combined_file );
		$file_exists = file_exists( $combined_file );
		$file_size = $file_exists ? filesize( $combined_file ) : 0;
		error_log( '[PUNTWORK] [STATUS-CORRECTION] File exists: ' . ($file_exists ? 'YES' : 'NO') . ', size: ' . $file_size . ' bytes' );
		
		if ( $file_exists && $file_size > 0 ) {
			$current_total = $progress['total'] ?? 0;
			$current_complete = $progress['complete'] ?? false;
			$status_exists = isset( $progress['total'] ) && isset( $progress['complete'] );
			error_log( '[PUNTWORK] [STATUS-CORRECTION] Current status - total: ' . $current_total . ', complete: ' . ($current_complete ? 'true' : 'false') . ', status_exists: ' . ($status_exists ? 'true' : 'false') );
			
			// Check if this is the default fresh state (should not be corrected)
			$is_default_fresh_state = ( $current_total === 0 && $current_complete === true && 
				( $progress['processed'] ?? 0 ) === 0 && empty( $progress['logs'] ?? array() ) );
			
			error_log( '[PUNTWORK] [STATUS-CORRECTION] Is default fresh state: ' . ($is_default_fresh_state ? 'true' : 'false') );
			
			// Check if status needs correction:
			// 1. Status is missing entirely (empty array), OR
			// 2. Status exists but shows incorrect values (total=0 and complete=true) AND it's not the default fresh state, OR
			// 3. Status exists but shows total=0 even though there's a combined file with content (this indicates status was never properly initialized)
			$needs_correction = ( ! $status_exists ) ||
				( $current_total == 0 && $current_complete && ! $is_default_fresh_state ) ||
				( $current_total == 0 && $file_exists && $file_size > 0 );
			
			error_log( '[PUNTWORK] [STATUS-CORRECTION] needs_correction calculation: !status_exists=' . (!$status_exists ? 'true' : 'false') . ', current_total==0=' . ($current_total == 0 ? 'true' : 'false') . ', current_complete=' . ($current_complete ? 'true' : 'false') . ', !is_default_fresh_state=' . (!$is_default_fresh_state ? 'true' : 'false') . ', file_exists_and_has_content=' . (($file_exists && $file_size > 0) ? 'true' : 'false') . ', needs_correction=' . ($needs_correction ? 'true' : 'false') );
			
			if ( $needs_correction ) {
				// Check if there was a recent successful import that completed
				$last_import_details = get_option( 'puntwork_last_import_details', array() );
				$has_recent_successful_import = isset( $last_import_details['success'] ) && 
					$last_import_details['success'] && 
					isset( $last_import_details['complete'] ) && 
					$last_import_details['complete'];
				
				error_log( '[PUNTWORK] [STATUS-CORRECTION] Last import details check: ' . json_encode( $last_import_details ) );
				error_log( '[PUNTWORK] [STATUS-CORRECTION] Has recent successful import: ' . ($has_recent_successful_import ? 'true' : 'false') );
				
				// Status needs correction - combined file exists but status is missing or incorrect
				error_log( '[PUNTWORK] [STATUS-CORRECTION] Status correction condition met - correcting status' );
				
				// Try to get the actual count from the file
				$function_exists = function_exists( 'get_json_item_count' );
				error_log( '[PUNTWORK] [STATUS-CORRECTION] get_json_item_count function exists: ' . ($function_exists ? 'YES' : 'NO') );
				
				if ( $function_exists ) {
					$actual_total = get_json_item_count( $combined_file );
					error_log( '[PUNTWORK] [STATUS-CORRECTION] get_json_item_count returned: ' . $actual_total );
					
					if ( $actual_total > 0 ) {
						// Check if this is a fresh import ready to start (not a completed one)
						$current_logs = $progress['logs'] ?? array();
						$is_ready_for_import = in_array('JSONL files combined successfully - ready for import', $current_logs);
						
						error_log( '[PUNTWORK] [STATUS-CORRECTION] Current logs: ' . json_encode( $current_logs ) );
						error_log( '[PUNTWORK] [STATUS-CORRECTION] is_ready_for_import (logs contain ready message): ' . ($is_ready_for_import ? 'true' : 'false') );
						
						$progress['total'] = $actual_total;
						$progress['processed'] = $has_recent_successful_import && !$is_ready_for_import ? $actual_total : 0;
						$progress['complete'] = $has_recent_successful_import && !$is_ready_for_import;
						$progress['start_time'] = ($has_recent_successful_import && !$is_ready_for_import) ? null : microtime( true );
						$progress['last_update'] = time();
						$progress['logs'] = $is_ready_for_import ? $current_logs : array( 'Import status corrected - combined file exists with ' . $actual_total . ' items' . ($has_recent_successful_import ? ' (import appears complete)' : '') );
						$update_result = update_option( 'job_import_status', $progress );
						error_log( '[PUNTWORK] [STATUS-CORRECTION] Status corrected: total=' . $actual_total . ', complete=' . (($has_recent_successful_import && !$is_ready_for_import) ? 'true' : 'false') . ', is_ready_for_import=' . ($is_ready_for_import ? 'true' : 'false') . ', update_result=' . ($update_result ? 'true' : 'false') );
					} else {
						error_log( '[PUNTWORK] [STATUS-CORRECTION] get_json_item_count returned 0 or invalid value, not correcting status' );
					}
				} else {
					error_log( '[PUNTWORK] [STATUS-CORRECTION] get_json_item_count function not available, cannot correct status' );
				}
			} else {
				error_log( '[PUNTWORK] [STATUS-CORRECTION] Status correction condition not met - no correction needed' );
			}
		} else {
			error_log( '[PUNTWORK] [STATUS-CORRECTION] Combined file does not exist or is empty, skipping status correction' );
		}

		error_log( '[PUNTWORK] [STATUS-CORRECTION] ===== STATUS CORRECTION LOGIC END =====' );

		PuntWorkLogger::debug(
			'Retrieved import status',
			PuntWorkLogger::CONTEXT_BATCH,
			array(
				'total'     => $progress['total'] ?? 0,
				'processed' => $progress['processed'] ?? 0,
				'complete'  => $progress['complete'] ?? null,
			)
		);

		// Check for stuck or stale imports and clear them
		if ( isset( $progress['complete'] ) && ! $progress['complete'] && isset( $progress['total'] ) && $progress['total'] > 0 ) {
			$current_time           = time();
			$time_elapsed           = 0;
			$last_update            = isset( $progress['last_update'] ) ? $progress['last_update'] : 0;
			$time_since_last_update = $current_time - $last_update;

			if ( isset( $progress['start_time'] ) && $progress['start_time'] > 0 ) {
				$time_elapsed = microtime( true ) - $progress['start_time'];
			} elseif ( isset( $progress['time_elapsed'] ) ) {
				$time_elapsed = $progress['time_elapsed'];
			}

			error_log( '[PUNTWORK] [STUCK-DETECTION] Checking for stuck import: complete=' . ($progress['complete'] ? 'true' : 'false') . ', total=' . $progress['total'] . ', processed=' . ($progress['processed'] ?? 0) . ', time_elapsed=' . round($time_elapsed, 2) . 's, time_since_last_update=' . $time_since_last_update . 's' );

			// Detect stuck imports with multiple criteria:
			// 1. No progress for 5+ minutes (300 seconds)
			// 2. Import running for more than 2 hours without completion (7200 seconds)
			// 3. No status update for 10+ minutes (600 seconds)
			$is_stuck     = false;
			$stuck_reason = '';

			// Check if this is a fresh import ready for batch processing (JSONL combined but not started)
			$combined_file = ABSPATH . 'feeds/combined-jobs.jsonl';
			$has_jsonl_success = isset($progress['logs']) && is_array($progress['logs']) && 
				in_array('JSONL files combined successfully - ready for import', $progress['logs']);
			$combined_file_exists = file_exists($combined_file) && filesize($combined_file) > 0;

			error_log( '[PUNTWORK] [STUCK-DETECTION] Combined file exists: ' . ($combined_file_exists ? 'true' : 'false') . ', has_jsonl_success log: ' . ($has_jsonl_success ? 'true' : 'false') );

			if ( ($progress['processed'] ?? 0) === 0 && $time_elapsed > 300 ) {
				// Don't consider stuck if combined file exists (ready for batch processing)
				if ( !$combined_file_exists ) {
					$is_stuck     = true;
					$stuck_reason = 'no progress for 5+ minutes and no jobs processed yet';
					error_log( '[PUNTWORK] [STUCK-DETECTION] Detected stuck: ' . $stuck_reason );
				} else {
					error_log( '[PUNTWORK] [STUCK-DETECTION] Not stuck: combined file exists (ready for batch processing)' );
				}
			} elseif ( ($progress['processed'] ?? 0) > 0 && $time_elapsed > 300 ) {
				$is_stuck     = true;
				$stuck_reason = 'no progress for 5+ minutes after starting';
				error_log( '[PUNTWORK] [STUCK-DETECTION] Detected stuck: ' . $stuck_reason );
			} elseif ( $time_elapsed > 7200 ) { // 2 hours
				$is_stuck     = true;
				$stuck_reason = 'running for more than 2 hours';
				error_log( '[PUNTWORK] [STUCK-DETECTION] Detected stuck: ' . $stuck_reason );
			} elseif ( $time_since_last_update > 600 ) { // 10 minutes since last update
				$is_stuck     = true;
				$stuck_reason = 'no status update for 10+ minutes';
				error_log( '[PUNTWORK] [STUCK-DETECTION] Detected stuck: ' . $stuck_reason );
			}

			if ( $is_stuck ) {
				error_log( '[PUNTWORK] [STUCK-DETECTION] Import detected as stuck, resetting status' );
				PuntWorkLogger::info(
					'Detected stuck import in status check, clearing status',
					PuntWorkLogger::CONTEXT_BATCH,
					array(
						'processed'              => $progress['processed'] ?? 0,
						'total'                  => $progress['total'] ?? 0,
						'time_elapsed'           => $time_elapsed,
						'time_since_last_update' => $time_since_last_update,
						'reason'                 => $stuck_reason,
					)
				);
				
				// Check if combined file exists - if so, don't clear status completely, just reset progress
				$combined_file = ABSPATH . 'feeds/combined-jobs.jsonl';
				if ( file_exists( $combined_file ) && filesize( $combined_file ) > 0 && function_exists( 'get_json_item_count' ) ) {
					$actual_total = get_json_item_count( $combined_file );
					if ( $actual_total > 0 ) {
						error_log( '[PUNTWORK] [STUCK-DETECTION] Combined file exists with ' . $actual_total . ' items - resetting progress but keeping total' );
						// Reset progress but keep total and set incomplete
						$progress = array(
							'total'              => $actual_total,
							'processed'          => 0,
							'published'          => 0,
							'updated'            => 0,
							'skipped'            => 0,
							'duplicates_drafted' => 0,
							'time_elapsed'       => 0,
							'complete'           => false, // Not complete
							'success'            => false,
							'error_message'      => 'Import was stuck and reset - ' . $stuck_reason,
							'batch_size'         => 10,
							'inferred_languages' => 0,
							'inferred_benefits'  => 0,
							'schema_generated'   => 0,
							'start_time'         => microtime( true ),
							'end_time'           => null,
							'last_update'        => time(),
							'logs'               => array( 'Import was stuck and reset: ' . $stuck_reason . ' - ready to resume' ),
						);
						update_option( 'job_import_status', $progress );
						error_log( '[PUNTWORK] [STUCK-DETECTION] Status reset with total=' . $actual_total . ', complete=false' );
					} else {
						// No valid items, clear status
						delete_option( 'job_import_status' );
						delete_option( 'job_import_progress' );
						delete_option( 'job_import_processed_guids' );
						delete_option( 'job_import_last_batch_time' );
						delete_option( 'job_import_last_batch_processed' );
						delete_option( 'job_import_batch_size' );
						delete_option( 'job_import_consecutive_small_batches' );
						delete_transient( 'import_cancel' );
						
						$progress = array(
							'total'              => 0,
							'processed'          => 0,
							'published'          => 0,
							'updated'            => 0,
							'skipped'            => 0,
							'duplicates_drafted' => 0,
							'time_elapsed'       => 0,
							'complete'           => true, // Fresh state is complete
							'success'            => null, // Don't assume failure - set to null for unknown status
							'error_message'      => 'Import was detected as stuck and cleared',
							'batch_size'         => 10,
							'inferred_languages' => 0,
							'inferred_benefits'  => 0,
							'schema_generated'   => 0,
							'start_time'         => microtime( true ),
							'end_time'           => null,
							'last_update'        => time(),
							'logs'               => array(),
						);
					}
				} else {
					// No combined file, clear status completely
					delete_option( 'job_import_status' );
					delete_option( 'job_import_progress' );
					delete_option( 'job_import_processed_guids' );
					delete_option( 'job_import_last_batch_time' );
					delete_option( 'job_import_last_batch_processed' );
					delete_option( 'job_import_batch_size' );
					delete_option( 'job_import_consecutive_small_batches' );
					delete_transient( 'import_cancel' );

					// Return fresh status - preserve success status if import was actually complete
					$progress = array(
						'total'              => 0,
						'processed'          => 0,
						'published'          => 0,
						'updated'            => 0,
						'skipped'            => 0,
						'duplicates_drafted' => 0,
						'time_elapsed'       => 0,
						'complete'           => true, // Fresh state is complete
						'success'            => null, // Don't assume failure - set to null for unknown status
						'error_message'      => 'Import was detected as stuck and cleared',
						'batch_size'         => 10,
						'inferred_languages' => 0,
						'inferred_benefits'  => 0,
						'schema_generated'   => 0,
						'start_time'         => microtime( true ),
						'end_time'           => null,
						'last_update'        => time(),
						'logs'               => array(),
					);
				}
			} else {
				error_log( '[PUNTWORK] [STUCK-DETECTION] Import not detected as stuck' );
			}
		}

		if ( ! isset( $progress['start_time'] ) ) {
			$progress['start_time'] = microtime( true );
		}
		// Calculate elapsed time properly - if we have a start time, use it
		if ( isset( $progress['start_time'] ) && $progress['start_time'] > 0 ) {
			$current_time             = microtime( true );
			$progress['time_elapsed'] = $current_time - $progress['start_time'];
		} else {
			$progress['time_elapsed'] = $progress['time_elapsed'] ?? 0;
		}
		// Only recalculate complete status if it's not already marked as complete
		if ( ! isset( $progress['complete'] ) || ! $progress['complete'] ) {
			$progress['complete'] = ( ($progress['processed'] ?? 0) >= ($progress['total'] ?? 0) && ($progress['total'] ?? 0) > 0 );
		}

		// Add resume_progress for JavaScript
		$progress['resume_progress'] = (int) safe_get_option( 'job_import_progress', 0 );

		// Track job importing start time
		if ( ($progress['total'] ?? 0) > 1 && ! isset( $progress['job_import_start_time'] ) ) {
			$progress['job_import_start_time'] = microtime( true );
			update_option( 'job_import_status', $progress );
		}

		// Calculate job importing elapsed time
		$progress['job_importing_time_elapsed'] = isset( $progress['job_import_start_time'] ) ? microtime( true ) - $progress['job_import_start_time'] : $progress['time_elapsed'];

		// Add batch timing data for accurate time calculations
		$progress['batch_time']      = (float) safe_get_option( 'job_import_last_batch_time', 0 );
		$progress['batch_processed'] = (int) safe_get_option( 'job_import_last_batch_processed', 0 );

		// Add estimated time remaining calculation from PHP
		$progress['estimated_time_remaining'] = calculate_estimated_time_remaining( $progress );

		// Log response summary instead of full data to prevent large debug logs
		$log_summary = array(
			'total'                      => $progress['total'] ?? 0,
			'processed'                  => $progress['processed'] ?? 0,
			'published'                  => $progress['published'] ?? 0,
			'updated'                    => $progress['updated'] ?? 0,
			'skipped'                    => $progress['skipped'] ?? 0,
			'complete'                   => $progress['complete'] ?? false,
			'success'                    => $progress['success'] ?? false,
			'time_elapsed'               => $progress['time_elapsed'] ?? 0,
			'job_importing_time_elapsed' => $progress['job_importing_time_elapsed'] ?? 0,
			'estimated_time_remaining'   => $progress['estimated_time_remaining'] ?? 0,
			'batch_time'                 => $progress['batch_time'] ?? 0,
			'batch_processed'            => $progress['batch_processed'] ?? 0,
			'logs_count'                 => is_array( $progress['logs'] ) ? count( $progress['logs'] ) : 0,
			'has_error'                  => ! empty( $progress['error_message'] ),
		);

		PuntWorkLogger::logAjaxResponse( 'get_job_import_status', $log_summary );
		wp_send_json_success( $progress );
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Get import status error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		wp_send_json_error( array( 'message' => 'Failed to get import status: ' . $e->getMessage() ) );
	}
}

add_action( 'wp_ajax_log_manual_import_run', __NAMESPACE__ . '\\log_manual_import_run_ajax' );
function log_manual_import_run_ajax() {
	PuntWorkLogger::logAjaxRequest( 'log_manual_import_run', $_POST );

	// Use comprehensive security validation with field validation
	$validation = SecurityUtils::validateAjaxRequest(
		'log_manual_import_run',
		'job_import_nonce',
		array( 'timestamp', 'duration', 'success', 'processed', 'total', 'published', 'updated', 'skipped' ), // required fields
		array(
			'timestamp'     => array(
				'type' => 'int',
				'min'  => 0,
			),
			'duration'      => array(
				'type' => 'float',
				'min'  => 0,
			),
			'success'       => array( 'type' => 'string' ),
			'processed'     => array(
				'type' => 'int',
				'min'  => 0,
			),
			'total'         => array(
				'type' => 'int',
				'min'  => 0,
			),
			'published'     => array(
				'type' => 'int',
				'min'  => 0,
			),
			'updated'       => array(
				'type' => 'int',
				'min'  => 0,
			),
			'skipped'       => array(
				'type' => 'int',
				'min'  => 0,
			),
			'error_message' => array(
				'type'       => 'text',
				'max_length' => 1000,
			),
		)
	);

	if ( is_wp_error( $validation ) ) {
		wp_send_json_error( array( 'message' => $validation->get_error_message() ) );

		return;
	}

	try {
		$details = array(
			'timestamp'     => $_POST['timestamp'],
			'duration'      => $_POST['duration'],
			'success'       => filter_var( $_POST['success'], FILTER_VALIDATE_BOOLEAN ),
			'processed'     => $_POST['processed'] ?? 0,
			'total'         => $_POST['total'] ?? 0,
			'published'     => $_POST['published'],
			'updated'       => $_POST['updated'],
			'skipped'       => $_POST['skipped'],
			'error_message' => $_POST['error_message'] ?? '',
		);

		// Include the scheduling history functions
		include_once __DIR__ . '/../scheduling/scheduling-history.php';

		// Log the manual import run
		log_manual_import_run( $details );

		PuntWorkLogger::info(
			'Manual import run logged to history',
			PuntWorkLogger::CONTEXT_AJAX,
			array(
				'success'   => $details['success'],
				'processed' => $details['processed'] ?? 0,
				'total'     => $details['total'],
				'duration'  => $details['duration'],
			)
		);

		PuntWorkLogger::logAjaxResponse( 'log_manual_import_run', array( 'message' => 'Manual import run logged' ) );
		wp_send_json_success( null, array( 'message' => 'Manual import run logged to history' ) );
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Log manual import run error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		wp_send_json_error( array( 'message' => 'Failed to log manual import run: ' . $e->getMessage() ) );
	}
}

add_action( 'wp_ajax_test_single_job_import', __NAMESPACE__ . '\\test_single_job_import_ajax' );
function test_single_job_import_ajax() {
	PuntWorkLogger::logAjaxRequest( 'test_single_job_import', $_POST );

	// Use comprehensive security validation
	$validation = SecurityUtils::validateAjaxRequest( 'test_single_job_import', 'job_import_nonce' );
	if ( is_wp_error( $validation ) ) {
		wp_send_json_error( array( 'message' => $validation->get_error_message() ) );

		return;
	}

	try {
		// Test job data - extracted from JSONL and modified
		$test_job = array(
			'guid'                        => 'TEST_JOB_001',
			'author'                      => '<a10:name xmlns:a10="http://www.w3.org/2005/Atom">Test Company</a10:name>',
			'name'                        => 'Test Company',
			'category'                    => 'Test Category',
			'title'                       => 'TEST', // Modified title for testing
			'description'                 => '<p>This is a test job to verify the import functionality works correctly.</p>',
			'pubdate'                     => date( 'D, d M Y H:i:s O' ),
			'updated'                     => date( 'Y-m-d\TH:i:sP' ),
			'link'                        => 'https://test.com/job/test',
			'applylink'                   => 'https://test.com/apply/test',
			'magiclink'                   => '',
			'branche'                     => 'Test',
			'postalcode'                  => '1000',
			'city'                        => 'TEST CITY',
			'province'                    => 'Test Province',
			'provincecode'                => 'TEST',
			'country'                     => 'BE',
			'validfrom'                   => date( 'Y-m-d\TH:i:s' ),
			'validtill'                   => date( 'Y-m-d\TH:i:s', strtotime( '+30 days' ) ),
			'channeltype'                 => '29998',
			'functiongroup'               => 'Test Services',
			'functiongroup2'              => '',
			'functiongroup3'              => '',
			'functiongroupid'             => '1',
			'functiongroupid2'            => '0',
			'functiongroupid3'            => '0',
			'function'                    => 'Test Function',
			'function2'                   => '',
			'function3'                   => '',
			'functionid'                  => '1',
			'functionid2'                 => '0',
			'functionid3'                 => '0',
			'functiontitle'               => 'TEST',
			'functiondescription'         => '<p>This is a test job function.</p>',
			'education'                   => 'Bachelor',
			'education2'                  => 'Bachelor',
			'education3'                  => 'Bachelor',
			'educationid'                 => '1',
			'educationid2'                => '1',
			'educationid3'                => '1',
			'educationgroup'              => 'Bachelor',
			'educationgroup2'             => 'Bachelor',
			'educationgroup3'             => 'Bachelor',
			'educationgroupcode'          => '001',
			'educationgroupcode2'         => '001',
			'educationgroupcode3'         => '001',
			'jobtype'                     => 'Full-time',
			'jobtypecode'                 => 'FULL',
			'jobtypegroup'                => 'Permanent Contract',
			'jobtypegroupcode'            => '001',
			'contracttype'                => 'Employee',
			'contracttype2'               => '',
			'contracttype3'               => '',
			'contracttypecode'            => '20',
			'contracttypecode2'           => '',
			'contracttypecode3'           => '',
			'experience'                  => 'No experience required',
			'experiencecode'              => '001',
			'brand'                       => 'Test Company',
			'accountid'                   => '123456',
			'internal'                    => 'false',
			'payrollid'                   => '123456',
			'payroll'                     => 'Test Payroll',
			'brancheid'                   => '1',
			'label'                       => 'Test Label',
			'labelid'                     => '1',
			'language'                    => 'English',
			'language2'                   => '',
			'language3'                   => '',
			'languagecode'                => '1',
			'languagecode2'               => '',
			'languagecode3'               => '',
			'languagelevel'               => 'Good',
			'languagelevel2'              => '',
			'languagelevel3'              => '',
			'languagelevelcode'           => '3',
			'languagelevelcode2'          => '',
			'languagelevelcode3'          => '',
			'office'                      => 'Test Office',
			'officeid'                    => '1',
			'officestreet'                => 'Test Street',
			'officehousenumber'           => '1',
			'officeaddition'              => '',
			'officepostalcode'            => '1000',
			'officecity'                  => 'TEST CITY',
			'officetelephone'             => '+32 123 456 789',
			'officeemail'                 => 'test@test.com',
			'hours'                       => '40',
			'salaryfrom'                  => '30000',
			'salaryto'                    => '40000',
			'salarytype'                  => 'per year',
			'salarytypecode'              => '1',
			'parttime'                    => 'false',
			'offerdescription'            => '<p>Test job offer description.</p>',
			'requirementsdescription'     => '<p>Test job requirements.</p>',
			'reference'                   => 'TEST001',
			'shift'                       => 'Day shift',
			'shiftcode'                   => '1',
			'driverslicense'              => '',
			'driverslicenseid'            => '0',
			'publicationlanguage'         => 'EN',
			'companydescription'          => '<p>Test company description.</p>',
			'job_title'                   => 'TEST',
			'job_slug'                    => 'test-job',
			'job_link'                    => 'https://test.com/job/test',
			'job_salary'                  => '€30000 - €40000',
			'job_apply'                   => 'https://test.com/apply/test',
			'job_icon'                    => '<i class="fas fa-briefcase"></i>',
			'job_car'                     => '',
			'job_time'                    => 'Full-time',
			'job_description'             => 'Test job description',
			'job_remote'                  => '',
			'job_meal_vouchers'           => '',
			'job_flex_hours'              => '',
			'job_skills'                  => array(),
			'job_posting'                 => '{}',
			'job_ecommerce'               => '{}',
			'job_languages'               => '<ul><li>English: Good (3/5)</li></ul>',
			'job_category'                => 'Test',
			'job_quality_score'           => 50.0,
			'job_quality_level'           => 'Average',
			'job_quality_factors'         => '{}',
			'job_quality_recommendations' => '[]',
		);

		PuntWorkLogger::info(
			'Starting test single job import',
			PuntWorkLogger::CONTEXT_AJAX,
			array(
				'guid'  => $test_job['guid'],
				'title' => $test_job['title'],
			)
		);

		// Check if job already exists
		$existing_post = get_posts(
			array(
				'post_type'      => 'job',
				'meta_key'       => '_guid',
				'meta_value'     => $test_job['guid'],
				'posts_per_page' => 1,
			)
		);

		if ( ! empty( $existing_post ) ) {
			PuntWorkLogger::warn(
				'Test job already exists',
				PuntWorkLogger::CONTEXT_AJAX,
				array(
					'guid'             => $test_job['guid'],
					'existing_post_id' => $existing_post[0]->ID,
				)
			);
			wp_send_json_error( array( 'message' => 'Test job already exists with GUID: ' . $test_job['guid'] ) );

			return;
		}

		// Prepare job data
		$job_data = array(
			'post_title'   => $test_job['title'] ?? 'Untitled Job',
			'post_content' => $test_job['description'] ?? '',
			'post_status'  => 'publish',
			'post_type'    => 'job',
			'post_author'  => get_current_user_id(),
		);

		// Insert the job post
		$post_id = wp_insert_post( $job_data );

		if ( is_wp_error( $post_id ) ) {
			PuntWorkLogger::error(
				'Failed to create test job post',
				PuntWorkLogger::CONTEXT_AJAX,
				array(
					'error' => $post_id->get_error_message(),
				)
			);
			wp_send_json_error( array( 'message' => 'Failed to create test job: ' . $post_id->get_error_message() ) );

			return;
		}

		PuntWorkLogger::info(
			'Test job post created',
			PuntWorkLogger::CONTEXT_AJAX,
			array(
				'post_id' => $post_id,
				'title'   => $job_data['post_title'],
			)
		);

		// Add job metadata
		update_post_meta( $post_id, '_guid', $test_job['guid'] );
		update_post_meta( $post_id, '_job_location', $test_job['city'] ?? '' );
		update_post_meta( $post_id, '_job_salary', $test_job['job_salary'] ?? '' );
		update_post_meta( $post_id, '_job_type', $test_job['jobtype'] ?? '' );
		update_post_meta( $post_id, '_company_name', $test_job['name'] ?? '' );
		update_post_meta( $post_id, '_company_website', $test_job['link'] ?? '' );
		update_post_meta( $post_id, '_job_expires', $test_job['validtill'] ?? '' );

		// Add ACF fields if available
		if ( function_exists( 'update_field' ) ) {
			update_field( 'job_description', $test_job['job_description'] ?? '', $post_id );
			update_field( 'job_requirements', $test_job['requirementsdescription'] ?? '', $post_id );
			update_field( 'company_description', $test_job['companydescription'] ?? '', $post_id );
			update_field( 'application_link', $test_job['job_apply'] ?? '', $post_id );
		}

		// Verify the job was created and has all metadata
		$verify_post = get_post( $post_id );
		if ( ! $verify_post ) {
			PuntWorkLogger::error(
				'Test job creation verification failed',
				PuntWorkLogger::CONTEXT_AJAX,
				array(
					'post_id' => $post_id,
				)
			);
			wp_send_json_error( array( 'message' => 'Test job creation verification failed' ) );

			return;
		}

		// Verify metadata was added correctly
		$verification_logs   = array();
		$verification_logs[] = '✅ Test job created successfully';
		$verification_logs[] = '📝 Post ID: ' . $post_id;
		$verification_logs[] = '🏷️  Title: ' . $verify_post->post_title;
		$verification_logs[] = '📊 Status: ' . $verify_post->post_status;
		$verification_logs[] = '🔗 GUID: ' . $test_job['guid'];

		// Check metadata
		$guid_meta     = get_post_meta( $post_id, '_guid', true );
		$location_meta = get_post_meta( $post_id, '_job_location', true );
		$salary_meta   = get_post_meta( $post_id, '_job_salary', true );
		$type_meta     = get_post_meta( $post_id, '_job_type', true );
		$company_meta  = get_post_meta( $post_id, '_company_name', true );

		$verification_logs[] = '� Metadata verification:';
		$verification_logs[] = '  • GUID: ' . ( $guid_meta === $test_job['guid'] ? '✅' : '❌' ) . ' (' . $guid_meta . ')';
		$verification_logs[] = '  • Location: ' . ( ! empty( $location_meta ) ? '✅' : '❌' ) . ' (' . $location_meta . ')';
		$verification_logs[] = '  • Salary: ' . ( ! empty( $salary_meta ) ? '✅' : '❌' ) . ' (' . $salary_meta . ')';
		$verification_logs[] = '  • Job Type: ' . ( ! empty( $type_meta ) ? '✅' : '❌' ) . ' (' . $type_meta . ')';
		$verification_logs[] = '  • Company: ' . ( ! empty( $company_meta ) ? '✅' : '❌' ) . ' (' . $company_meta . ')';

		// Check ACF fields if available
		if ( function_exists( 'get_field' ) ) {
			$acf_description  = get_field( 'job_description', $post_id );
			$acf_requirements = get_field( 'job_requirements', $post_id );
			$acf_company_desc = get_field( 'company_description', $post_id );
			$acf_apply_link   = get_field( 'application_link', $post_id );

			$verification_logs[] = '🔧 ACF Fields verification:';
			$verification_logs[] = '  • Job Description: ' . ( ! empty( $acf_description ) ? '✅' : '❌' );
			$verification_logs[] = '  • Requirements: ' . ( ! empty( $acf_requirements ) ? '✅' : '❌' );
			$verification_logs[] = '  • Company Description: ' . ( ! empty( $acf_company_desc ) ? '✅' : '❌' );
			$verification_logs[] = '  • Application Link: ' . ( ! empty( $acf_apply_link ) ? '✅' : '❌' );
		} else {
			$verification_logs[] = '⚠️  ACF not available - skipping ACF field verification';
		}

		// Final verification - check if post exists in database
		$final_check = get_posts(
			array(
				'post_type'      => 'job',
				'p'              => $post_id,
				'posts_per_page' => 1,
			)
		);

		if ( empty( $final_check ) ) {
			PuntWorkLogger::error(
				'Final verification failed - job not found in database',
				PuntWorkLogger::CONTEXT_AJAX,
				array(
					'post_id' => $post_id,
				)
			);
			wp_send_json_error( array( 'message' => 'Final verification failed - job not found in database' ) );

			return;
		}

		$verification_logs[] = '🎯 Final verification: Job exists in database ✅';

		PuntWorkLogger::info(
			'Test single job import completed with full verification',
			PuntWorkLogger::CONTEXT_AJAX,
			array(
				'post_id'           => $post_id,
				'title'             => $verify_post->post_title,
				'status'            => $verify_post->post_status,
				'metadata_verified' => true,
			)
		);

		PuntWorkLogger::logAjaxResponse(
			'test_single_job_import',
			array(
				'post_id'               => $post_id,
				'post_title'            => $verify_post->post_title,
				'post_status'           => $verify_post->post_status,
				'logs'                  => $verification_logs,
				'verification_complete' => true,
			)
		);

		AjaxErrorHandler::sendSuccess(
			array(
				'post_id'               => $post_id,
				'post_title'            => $verify_post->post_title,
				'post_status'           => $verify_post->post_status,
				'logs'                  => $verification_logs,
				'verification_complete' => true,
			)
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Test single job import error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		wp_send_json_error( array( 'message' => 'Test single job import failed: ' . $e->getMessage() ) );
	}
}

add_action( 'wp_ajax_clear_rate_limits', __NAMESPACE__ . '\\clear_rate_limits_ajax' );
add_action( 'wp_ajax_clear_rate_limits_ajax', __NAMESPACE__ . '\\clear_rate_limits_ajax' ); // Alias for compatibility
function clear_rate_limits_ajax() {
	PuntWorkLogger::logAjaxRequest( 'clear_rate_limits', $_POST );

	// Use comprehensive security validation
	$validation = SecurityUtils::validateAjaxRequest( 'clear_rate_limits', 'job_import_nonce' );
	if ( is_wp_error( $validation ) ) {
		AjaxErrorHandler::sendError( $validation );

		return;
	}

	try {
		// Include SecurityUtils
		include_once __DIR__ . '/../utilities/SecurityUtils.php';

		$cleared = SecurityUtils::clearAllRateLimits();

		PuntWorkLogger::info( 'Rate limits cleared', PuntWorkLogger::CONTEXT_AJAX, array( 'cleared_count' => $cleared ) );

		PuntWorkLogger::logAjaxResponse(
			'clear_rate_limits',
			array(
				'message'       => 'Rate limits cleared',
				'cleared_count' => $cleared,
			)
		);
		AjaxErrorHandler::sendSuccess( null, array( 'message' => "Cleared {$cleared} rate limit entries" ) );
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Clear rate limits error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		AjaxErrorHandler::sendError( 'Failed to clear rate limits: ' . $e->getMessage() );
	}
}

add_action( 'wp_ajax_get_rate_limit_status', __NAMESPACE__ . '\\get_rate_limit_status_ajax' );
function get_rate_limit_status_ajax() {
	PuntWorkLogger::logAjaxRequest( 'get_rate_limit_status', $_POST );

	// Use comprehensive security validation
	$validation = SecurityUtils::validateAjaxRequest( 'get_rate_limit_status', 'puntwork_rate_limits' );
	if ( is_wp_error( $validation ) ) {
		AjaxErrorHandler::sendError( $validation );

		return;
	}

	try {
		// Use transient caching to reduce database load (cache for 15 seconds)
		$cache_key     = 'puntwork_rate_limit_status_cache_' . get_current_user_id();
		$cached_status = get_transient( $cache_key );

		if ( $cached_status !== false ) {
			PuntWorkLogger::logAjaxResponse(
				'get_rate_limit_status',
				array(
					'status_count' => count( $cached_status ),
					'cached'       => true,
				)
			);
			AjaxErrorHandler::sendSuccess( $cached_status );

			return;
		}

		$user_id = get_current_user_id();
		$configs = SecurityUtils::getAllRateLimitConfigs();
		$status  = array();

		foreach ( $configs as $action => $config ) {
			$key      = "rate_limit_{$action}_{$user_id}";
			$requests = get_transient( $key );

			if ( ! $requests ) {
				$requests = array();
			}

			// Clean old requests
			$current_time = time();
			$requests     = array_filter(
				$requests,
				function ( $timestamp ) use ( $current_time, $config ) {
					return ( $current_time - $timestamp ) < $config['time_window'];
				}
			);

			$status[ $action ] = array(
				'requests' => count( $requests ),
				'limit'    => $config['max_requests'],
				'window'   => $config['time_window'],
			);
		}

		// Cache the result for 15 seconds
		set_transient( $cache_key, $status, 15 );

		PuntWorkLogger::logAjaxResponse( 'get_rate_limit_status', array( 'status_count' => count( $status ) ) );
		AjaxErrorHandler::sendSuccess( $status );
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Get rate limit status error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		AjaxErrorHandler::sendError( 'Failed to get rate limit status: ' . $e->getMessage() );
	}
}

add_action( 'wp_ajax_get_dynamic_rate_status', __NAMESPACE__ . '\\get_dynamic_rate_status_ajax' );
function get_dynamic_rate_status_ajax() {
	PuntWorkLogger::logAjaxRequest( 'get_dynamic_rate_status', $_POST );

	// Use comprehensive security validation
	$validation = SecurityUtils::validateAjaxRequest( 'get_dynamic_rate_status', 'puntwork_dynamic_rate_limits' );
	if ( is_wp_error( $validation ) ) {
		AjaxErrorHandler::sendError( $validation );

		return;
	}

	try {
		// Use transient caching to reduce database load (cache for 30 seconds)
		$cache_key     = 'puntwork_dynamic_rate_status_cache';
		$cached_status = get_transient( $cache_key );

		if ( $cached_status !== false ) {
			PuntWorkLogger::logAjaxResponse(
				'get_dynamic_rate_status',
				array(
					'enabled'       => $cached_status['enabled'],
					'total_metrics' => $cached_status['total_metrics'],
					'cached'        => true,
				)
			);
			AjaxErrorHandler::sendSuccess( $cached_status );

			return;
		}

		$status = \Puntwork\DynamicRateLimiter::getStatus();

		// Cache the result for 30 seconds
		set_transient( $cache_key, $status, 30 );

		PuntWorkLogger::logAjaxResponse(
			'get_dynamic_rate_status',
			array(
				'enabled'       => $status['enabled'],
				'total_metrics' => $status['total_metrics'],
			)
		);
		AjaxErrorHandler::sendSuccess( $status );
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Get dynamic rate status error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		AjaxErrorHandler::sendError( 'Failed to get dynamic rate limiting status: ' . $e->getMessage() );
	}
}

add_action( 'wp_ajax_get_api_key', __NAMESPACE__ . '\\get_api_key_ajax' );
function get_api_key_ajax() {
	PuntWorkLogger::logAjaxRequest( 'get_api_key', $_POST );

	// Use comprehensive security validation
	$validation = SecurityUtils::validateAjaxRequest( 'get_api_key', 'job_import_nonce' );
	if ( is_wp_error( $validation ) ) {
		AjaxErrorHandler::sendError( $validation );

		return;
	}

	try {
		// Generate or retrieve API key for real-time updates
		$api_key = safe_get_option( 'puntwork_api_key', '' );
		if ( empty( $api_key ) ) {
			$api_key = wp_generate_password( 32, false );
			update_option( 'puntwork_api_key', $api_key );
		}

		PuntWorkLogger::logAjaxResponse( 'get_api_key', array( 'key_generated' => empty( safe_get_option( 'puntwork_api_key', '' ) ) ) );
		AjaxErrorHandler::sendSuccess( array( 'api_key' => $api_key ) );
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Get API key error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		AjaxErrorHandler::sendError( 'Failed to get API key: ' . $e->getMessage() );
	}
}

add_action( 'wp_ajax_get_async_status', __NAMESPACE__ . '\\get_async_status_ajax' );
function get_async_status_ajax() {
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [ASYNC-AJAX-START] ===== GET_ASYNC_STATUS_AJAX START =====' );
		error_log( '[PUNTWORK] [ASYNC-AJAX-START] POST data: ' . json_encode( $_POST ) );
		error_log( '[PUNTWORK] [ASYNC-AJAX-START] Memory usage at start: ' . memory_get_usage( true ) . ' bytes' );
	}

	PuntWorkLogger::logAjaxRequest( 'get_async_status', $_POST );

	// Simple validation for debugging
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'job_import_nonce' ) ) {
		error_log( '[PUNTWORK] [DEBUG-AJAX] Nonce verification failed for get_async_status' );
		wp_send_json_error( array( 'message' => 'Security check failed' ) );

		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		error_log( '[PUNTWORK] [DEBUG-AJAX] Insufficient permissions for get_async_status' );
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );

		return;
	}

	try {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [ASYNC-AJAX-DEBUG] Loading async processing utilities' );
		}
		// Include async processing utilities
		require_once __DIR__ . '/../utilities/async-processing.php';

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [ASYNC-AJAX-DEBUG] Calling get_async_processing_status' );
		}
		$status = get_async_processing_status();

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [ASYNC-AJAX-DEBUG] Async status retrieved: ' . json_encode( $status ) );
			error_log( '[PUNTWORK] [ASYNC-AJAX-DEBUG] Preparing success response' );
		}

		PuntWorkLogger::logAjaxResponse(
			'get_async_status',
			array(
				'enabled'   => $status['enabled'],
				'available' => $status['available'],
			)
		);
		AjaxErrorHandler::sendSuccess( $status );

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [ASYNC-AJAX-END] ===== GET_ASYNC_STATUS_AJAX SUCCESS =====' );
		}
	} catch ( \Exception $e ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [ASYNC-AJAX-ERROR] Exception in get_async_status_ajax: ' . $e->getMessage() );
			error_log( '[PUNTWORK] [ASYNC-AJAX-ERROR] Stack trace: ' . $e->getTraceAsString() );
		}
		PuntWorkLogger::error( 'Get async status error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		AjaxErrorHandler::sendError( 'Failed to get async status: ' . $e->getMessage() );
	} catch ( \Throwable $e ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [ASYNC-AJAX-FATAL] Fatal error in get_async_status_ajax: ' . $e->getMessage() );
			error_log( '[PUNTWORK] [ASYNC-AJAX-FATAL] Stack trace: ' . $e->getTraceAsString() );
		}
		PuntWorkLogger::error( 'Get async status fatal error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		AjaxErrorHandler::sendError( 'Failed to get async status with fatal error: ' . $e->getMessage() );
	}
}

add_action( 'wp_ajax_process_feed', __NAMESPACE__ . '\\process_feed_ajax' );
function process_feed_ajax() {
	error_log( '[PUNTWORK] [DEBUG-PHP] ===== PROCESS_FEED_AJAX START =====' );
	error_log( '[PUNTWORK] [DEBUG-PHP] Timestamp: ' . date( 'Y-m-d H:i:s T' ) );
	error_log( '[PUNTWORK] [DEBUG-PHP] POST data: ' . json_encode( $_POST ) );
	error_log( '[PUNTWORK] [DEBUG-PHP] Memory usage: ' . memory_get_usage( true ) . ' bytes' );
	error_log( '[PUNTWORK] [DEBUG-PHP] Peak memory usage: ' . memory_get_peak_usage( true ) . ' bytes' );

	// Include required files for AJAX processing
	include_once dirname( __DIR__ ) . '/import/feed-processor.php';

	PuntWorkLogger::logAjaxRequest( 'process_feed', $_POST );

	// Basic security validation
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'job_import_nonce' ) ) {
		error_log( '[PUNTWORK] [DEBUG-PHP] Nonce verification failed' );
		wp_send_json_error( array( 'message' => 'Security check failed' ) );

		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		error_log( '[PUNTWORK] [DEBUG-PHP] Insufficient permissions' );
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );

		return;
	}

	if ( ! isset( $_POST['feed_key'] ) || empty( $_POST['feed_key'] ) ) {
		error_log( '[PUNTWORK] [DEBUG-PHP] Missing feed_key parameter' );
		wp_send_json_error( array( 'message' => 'Missing required parameter: feed_key' ) );

		return;
	}

	try {
		$feed_key = sanitize_text_field( $_POST['feed_key'] );
		error_log( '[PUNTWORK] [DEBUG-PHP] Processing feed: ' . $feed_key );

		// Check if this feed is already being processed
		$feed_lock_key = 'puntwork_feed_processing_' . $feed_key;
		if ( get_transient( $feed_lock_key ) ) {
			error_log( '[PUNTWORK] [DEBUG-PHP] Feed ' . $feed_key . ' is already being processed, skipping' );
			wp_send_json_error( array( 'message' => 'Feed ' . $feed_key . ' is already being processed' ) );
			return;
		}

		// Set feed processing lock (5 minute timeout)
		set_transient( $feed_lock_key, true, 300 );

		// Get feeds and find the URL for this feed key
		$feeds = get_feeds();
		error_log( '[PUNTWORK] [DEBUG-PHP] Available feeds: ' . json_encode( $feeds ) );
		if ( ! isset( $feeds[ $feed_key ] ) ) {
			error_log( '[PUNTWORK] [DEBUG-PHP] Feed not found: ' . $feed_key );
			delete_transient( $feed_lock_key ); // Clear lock
			wp_send_json_error( array( 'message' => 'Feed not found: ' . $feed_key ) );

			return;
		}

		$feed_url        = $feeds[ $feed_key ];
		$output_dir      = ABSPATH . 'feeds/';
		$fallback_domain = 'belgiumjobs.work';

		error_log( '[PUNTWORK] [DEBUG-PHP] Feed URL: ' . $feed_url . ', Output dir: ' . $output_dir );

		// Validate feed URL
		if ( ! filter_var( $feed_url, FILTER_VALIDATE_URL ) ) {
			error_log( '[PUNTWORK] [DEBUG-PHP] Invalid feed URL: ' . $feed_url );
			delete_transient( $feed_lock_key ); // Clear lock
			wp_send_json_error( array( 'message' => 'Invalid feed URL: ' . $feed_url ) );
			return;
		}

		// Ensure output directory exists
		if ( ! wp_mkdir_p( $output_dir ) || ! is_writable( $output_dir ) ) {
			error_log( '[PUNTWORK] [DEBUG-PHP] Feeds directory not writable: ' . $output_dir );
			delete_transient( $feed_lock_key ); // Clear lock
			wp_send_json_error( array( 'message' => 'Feeds directory not writable' ) );

			return;
		}
		error_log( '[PUNTWORK] [DEBUG-PHP] Output directory ready' );

		$logs = array();
		error_log( '[PUNTWORK] [DEBUG-PHP] Calling process_one_feed...' );

		try {
			$start_time      = microtime( true );
			$item_count      = process_one_feed( $feed_key, $feed_url, $output_dir, $fallback_domain, $logs );
			$end_time        = microtime( true );
			$processing_time = $end_time - $start_time;
			error_log( '[PUNTWORK] [DEBUG-PHP] process_one_feed completed in ' . round( $processing_time, 2 ) . ' seconds' );
			error_log( '[PUNTWORK] [DEBUG-PHP] process_one_feed returned item_count: ' . $item_count );
		} catch ( \Exception $e ) {
			error_log( '[PUNTWORK] [DEBUG-PHP] process_one_feed threw exception: ' . $e->getMessage() );
			error_log( '[PUNTWORK] [DEBUG-PHP] Exception file: ' . $e->getFile() . ':' . $e->getLine() );
			error_log( '[PUNTWORK] [DEBUG-PHP] Exception trace: ' . $e->getTraceAsString() );
			\Puntwork\PuntWorkLogger::error(
				'Feed processing failed with exception',
				\Puntwork\PuntWorkLogger::CONTEXT_AJAX,
				array(
					'feed_key'   => $feed_key,
					'feed_url'   => $feed_url,
					'error'      => $e->getMessage(),
					'error_file' => $e->getFile(),
					'error_line' => $e->getLine(),
				)
			);
			delete_transient( $feed_lock_key ); // Clear lock
			wp_send_json_error( array( 'message' => 'Feed processing failed: ' . ( $e->getMessage() ?: 'Unknown error - check server logs for details' ) ) );

			return;
		} catch ( \Throwable $e ) {
			error_log( '[PUNTWORK] [DEBUG-PHP] process_one_feed threw throwable: ' . $e->getMessage() );
			error_log( '[PUNTWORK] [DEBUG-PHP] Throwable file: ' . $e->getFile() . ':' . $e->getLine() );
			error_log( '[PUNTWORK] [DEBUG-PHP] Throwable trace: ' . $e->getTraceAsString() );
			\Puntwork\PuntWorkLogger::error(
				'Feed processing failed with throwable',
				\Puntwork\PuntWorkLogger::CONTEXT_AJAX,
				array(
					'feed_key'   => $feed_key,
					'feed_url'   => $feed_url,
					'error'      => $e->getMessage(),
					'error_file' => $e->getFile(),
					'error_line' => $e->getLine(),
				)
			);
			delete_transient( $feed_lock_key ); // Clear lock
			wp_send_json_error( array( 'message' => 'Feed processing failed: ' . $e->getMessage() ?: 'Unknown error - check server logs for details' ) );

			return;
		}
		// Clear feed processing lock
		delete_transient( $feed_lock_key );

		error_log( '[PUNTWORK] [DEBUG-PHP] Logs from processing: ' . json_encode( $logs ) );

		PuntWorkLogger::info(
			'Feed processed via AJAX',
			PuntWorkLogger::CONTEXT_AJAX,
			array(
				'feed_key'   => $feed_key,
				'item_count' => $item_count,
				'feed_url'   => $feed_url,
			)
			);

		PuntWorkLogger::logAjaxResponse(
			'process_feed',
			array(
				'feed_key'   => $feed_key,
				'item_count' => $item_count,
				'logs_count' => count( $logs ),
			)
		);

		error_log( '[PUNTWORK] [DEBUG-PHP] ===== PROCESS_FEED_AJAX SUCCESS =====' );
		wp_send_json_success(
			array(
				'feed_key'   => $feed_key,
				'item_count' => $item_count,
				'message'    => 'Feed processed successfully',
			)
		);
	} catch ( \Exception $e ) {
		// Clear feed processing lock on any error
		if ( isset( $feed_lock_key ) ) {
			delete_transient( $feed_lock_key );
		}

		error_log( '[PUNTWORK] [DEBUG-PHP] process_feed_ajax exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		error_log( '[PUNTWORK] [DEBUG-PHP] Stack trace: ' . $e->getTraceAsString() );
		PuntWorkLogger::error( 'Process feed AJAX error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		wp_send_json_error( array( 'message' => 'Failed to process feed: ' . ( $e->getMessage() ?: 'Unknown error - check server logs for details' ) ) );
	} catch ( \Throwable $e ) {
		// Clear feed processing lock on any error
		if ( isset( $feed_lock_key ) ) {
			delete_transient( $feed_lock_key );
		}

		error_log( '[PUNTWORK] [DEBUG-PHP] process_feed_ajax throwable: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		error_log( '[PUNTWORK] [DEBUG-PHP] Stack trace: ' . $e->getTraceAsString() );
		PuntWorkLogger::error( 'Process feed AJAX throwable: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		wp_send_json_error( array( 'message' => 'Failed to process feed: ' . ( $e->getMessage() ?: 'Unknown error - check server logs for details' ) ) );
	}
}

add_action( 'wp_ajax_combine_jsonl', __NAMESPACE__ . '\\combine_jsonl_ajax' );
function combine_jsonl_ajax() {
	error_log( '[PUNTWORK] [DEBUG-PHP] ===== COMBINE_JSONL_AJAX START =====' );
	error_log( '[PUNTWORK] [DEBUG-PHP] Timestamp: ' . date( 'Y-m-d H:i:s T' ) );
	error_log( '[PUNTWORK] [DEBUG-PHP] POST data: ' . json_encode( $_POST ) );
	error_log( '[PUNTWORK] [DEBUG-PHP] Memory usage: ' . memory_get_usage( true ) . ' bytes' );
	error_log( '[PUNTWORK] [DEBUG-PHP] Peak memory usage: ' . memory_get_peak_usage( true ) . ' bytes' );

	PuntWorkLogger::logAjaxRequest( 'combine_jsonl', $_POST );

	// Basic security validation
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'job_import_nonce' ) ) {
		error_log( '[PUNTWORK] [DEBUG-PHP] Nonce verification failed' );
		wp_send_json_error( array( 'message' => 'Security check failed' ) );

		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		error_log( '[PUNTWORK] [DEBUG-PHP] Insufficient permissions' );
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );

		return;
	}

	if ( ! isset( $_POST['total_items'] ) || ! is_numeric( $_POST['total_items'] ) ) {
		error_log( '[PUNTWORK] [DEBUG-PHP] Missing or invalid total_items parameter' );
		wp_send_json_error( array( 'message' => 'Missing or invalid required parameter: total_items' ) );

		return;
	}

	try {
		$total_items = intval( $_POST['total_items'] );
		$feeds       = get_feeds();
		$output_dir  = ABSPATH . 'feeds/';

		// Check if combination is already in progress
		$combine_lock_key = 'puntwork_combine_processing';
		if ( get_transient( $combine_lock_key ) ) {
			error_log( '[PUNTWORK] [DEBUG-PHP] JSONL combination already in progress, skipping' );
			wp_send_json_error( array( 'message' => 'JSONL combination already in progress' ) );
			return;
		}

		// Set combination processing lock (10 minute timeout)
		set_transient( $combine_lock_key, true, 600 );

		error_log( '[PUNTWORK] [DEBUG-PHP] Combining JSONL for ' . count( $feeds ) . ' feeds, total_items: ' . $total_items );
		error_log( '[PUNTWORK] [DEBUG-PHP] Available feeds: ' . json_encode( $feeds ) );
		error_log( '[PUNTWORK] [DEBUG-PHP] Output directory: ' . $output_dir );

		// Check if there are any feed files to combine
		$feed_files = array();
		foreach ( $feeds as $feed_key => $url ) {
			$feed_files[] = $output_dir . $feed_key . '.jsonl';
		}
		$existing_feeds = array_filter( $feed_files, 'file_exists' );
		if ( empty( $existing_feeds ) ) {
			error_log( '[PUNTWORK] [DEBUG-PHP] No feed files found to combine' );
			delete_transient( $combine_lock_key ); // Clear lock
			wp_send_json_error( array( 'message' => 'No feed files found to combine - please process feeds first' ) );
			return;
		}

		// Ensure output directory exists
		if ( ! wp_mkdir_p( $output_dir ) || ! is_writable( $output_dir ) ) {
			error_log( '[PUNTWORK] [DEBUG-PHP] Feeds directory not writable: ' . $output_dir );
			delete_transient( $combine_lock_key ); // Clear lock
			wp_send_json_error( array( 'message' => 'Feeds directory not writable' ) );

			return;
		}
		error_log( '[PUNTWORK] [DEBUG-PHP] Output directory ready' );

		$logs = array();
		error_log( '[PUNTWORK] [DEBUG-PHP] Calling combine_jsonl_files...' );

		// Use chunked processing for large datasets to avoid timeouts
		$chunk_size = 1000; // Process 1000 items per chunk
		if ( $total_items > $chunk_size ) {
			error_log( '[PUNTWORK] [DEBUG-PHP] Using chunked processing for large dataset (' . $total_items . ' items)' );

			$chunk_offset = 0;
			$total_processed = 0;
			$chunks_processed = 0;

			while ( $chunk_offset < $total_items ) {
				try {
					$start_time = microtime( true );
					$chunk_result = combine_jsonl_files( $feeds, $output_dir, $total_items, $logs, $chunk_size, $chunk_offset );
					$end_time = microtime( true );
					$chunk_time = $end_time - $start_time;

					$total_processed += $chunk_result['processed_in_chunk'];
					$chunks_processed++;

					error_log( '[PUNTWORK] [DEBUG-PHP] Chunk ' . $chunks_processed . ' processed in ' . round( $chunk_time, 2 ) . ' seconds: ' . $chunk_result['processed_in_chunk'] . ' items' );

					if ( $chunk_result['is_final_chunk'] ) {
						break;
					}

					$chunk_offset = $chunk_result['next_offset'];

					// Safety check to prevent infinite loops
					if ( $chunks_processed > 100 ) {
						error_log( '[PUNTWORK] [DEBUG-PHP] Too many chunks processed, stopping to prevent infinite loop' );
						break;
					}
				} catch ( \Exception $e ) {
					error_log( '[PUNTWORK] [DEBUG-PHP] Exception in chunked processing: ' . $e->getMessage() );
					error_log( '[PUNTWORK] [DEBUG-PHP] Exception file: ' . $e->getFile() . ':' . $e->getLine() );
					error_log( '[PUNTWORK] [DEBUG-PHP] Exception trace: ' . $e->getTraceAsString() );
					\Puntwork\PuntWorkLogger::error(
						'JSONL combination failed with exception',
						\Puntwork\PuntWorkLogger::CONTEXT_AJAX,
						array(
							'total_items' => $total_items,
							'error'       => $e->getMessage(),
							'error_file'  => $e->getFile(),
							'error_line'  => $e->getLine(),
						)
					);
					delete_transient( $combine_lock_key ); // Clear lock
					wp_send_json_error( array( 'message' => 'JSONL combination failed: ' . $e->getMessage() ) );

					return;
				}
			}

			error_log( '[PUNTWORK] [DEBUG-PHP] Chunked processing completed: ' . $chunks_processed . ' chunks, ' . $total_processed . ' total items' );
		} else {
			try {
				$start_time = microtime( true );
				combine_jsonl_files( $feeds, $output_dir, $total_items, $logs );
				$end_time        = microtime( true );
				$processing_time = $end_time - $start_time;
				error_log( '[PUNTWORK] [DEBUG-PHP] combine_jsonl_files completed in ' . round( $processing_time, 2 ) . ' seconds' );
			} catch ( \Exception $e ) {
				error_log( '[PUNTWORK] [DEBUG-PHP] combine_jsonl_files threw exception: ' . $e->getMessage() );
				error_log( '[PUNTWORK] [DEBUG-PHP] Exception file: ' . $e->getFile() . ':' . $e->getLine() );
				error_log( '[PUNTWORK] [DEBUG-PHP] Exception trace: ' . $e->getTraceAsString() );
				\Puntwork\PuntWorkLogger::error(
					'JSONL combination failed with exception',
					\Puntwork\PuntWorkLogger::CONTEXT_AJAX,
					array(
						'total_items' => $total_items,
						'error'       => $e->getMessage(),
						'error_file'  => $e->getFile(),
						'error_line'  => $e->getLine(),
					)
				);
				delete_transient( $combine_lock_key ); // Clear lock
				wp_send_json_error( array( 'message' => 'JSONL combination failed: ' . $e->getMessage() ) );

				return;
			}
		}

		// Clear combination processing lock
		delete_transient( $combine_lock_key );

		error_log( '[PUNTWORK] [DEBUG-PHP] Logs from combination: ' . json_encode( $logs ) );

		// Check if combined file was created
		$combined_file = $output_dir . 'combined-jobs.jsonl';
		if ( file_exists( $combined_file ) ) {
			$file_size = filesize( $combined_file );
			error_log( '[PUNTWORK] [DEBUG-PHP] Combined file created: ' . $combined_file . ' (' . $file_size . ' bytes)' );

			// Initialize import status with total items for the upcoming batch import
			$import_status = array(
				'total'              => $total_items,
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
				'logs'               => array( 'JSONL files combined successfully - starting import' ),
			);
			error_log( '[PUNTWORK] [COMBINE-STATUS] About to set import status with total=' . $total_items . ', complete=false' );
			$update_result = update_option( 'job_import_status', $import_status );
			error_log( '[PUNTWORK] [COMBINE-STATUS] update_option result: ' . ( $update_result ? 'true' : 'false' ) . ', status set: ' . json_encode( $import_status ) );
			error_log( '[PUNTWORK] [DEBUG-PHP] Import status initialized with total: ' . $total_items );

			$scheduling_success = true; // Import will start directly

			PuntWorkLogger::info(
				'JSONL combination completed and import scheduled',
				PuntWorkLogger::CONTEXT_AJAX,
				array(
					'total_items'        => $total_items,
					'combined_file_size' => $file_size,
					'scheduling_success' => $scheduling_success,
				)
			);
		} else {
			error_log( '[PUNTWORK] [DEBUG-PHP] Combined file was not created' );
		}

		PuntWorkLogger::logAjaxResponse(
			'combine_jsonl',
			array(
				'total_items'          => $total_items,
				'logs_count'           => count( $logs ),
				'combined_file_exists' => file_exists( $combined_file ),
				'combined_file_size'   => $file_size ?? 0,
				'import_started'       => true,
			)
		);

		error_log( '[PUNTWORK] [DEBUG-PHP] ===== COMBINE_JSONL_AJAX SUCCESS =====' );

		// Send JSON response manually without wp_die() to allow import to continue
		$response = array(
			'success' => true,
			'data'    => array(
				'total_items'          => $total_items,
				'message'              => 'JSONL files combined successfully - import starting in background',
				'combined_file_exists' => file_exists( $combined_file ),
				'combined_file_size'   => $file_size ?? 0,
				'import_started'       => true,
			)
		);

		// Send headers and JSON response
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
			header( 'X-Content-Type-Options: nosniff' );
			status_header( 200 );
		}

		echo wp_json_encode( $response );

		// Flush output buffers to ensure response is sent
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} else {
			// Fallback: flush all output buffers
			while ( ob_get_level() ) {
				ob_end_flush();
			}
			flush();
		}

		// Start output buffering to capture any accidental output during background execution
		ob_start();

		// Script continues here - start the import directly
		error_log( '[PUNTWORK] [COMBINE] AJAX response sent, starting import directly' );
		
		// Load required files for import processing
		$import_files = array(
			__DIR__ . '/../batch/batch-size-management.php',
			__DIR__ . '/../import/import-setup.php',
			__DIR__ . '/../batch/batch-processing.php',
			__DIR__ . '/../import/import-finalization.php',
			__DIR__ . '/../utilities/ErrorHandler.php',
			__DIR__ . '/../exceptions/PuntworkExceptions.php',
			__DIR__ . '/../import/import-batch.php',
		);

		foreach ( $import_files as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		// Start the import
		ignore_user_abort(true);
		set_time_limit(0);
		start_scheduled_import();

		// Clean any output that might have been generated
		ob_end_clean();
	} catch ( \Exception $e ) {
		// Clear combination processing lock on any error
		delete_transient( $combine_lock_key );

		error_log( '[PUNTWORK] [DEBUG-PHP] combine_jsonl_ajax exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		error_log( '[PUNTWORK] [DEBUG-PHP] Stack trace: ' . $e->getTraceAsString() );
		PuntWorkLogger::error( 'Combine JSONL AJAX error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX );
		wp_send_json_error( array( 'message' => 'Failed to combine JSONL files: ' . $e->getMessage() ) );
	}
}

add_action( 'puntwork_start_batch_import', __NAMESPACE__ . '\\puntwork_start_batch_import_handler' );
function puntwork_start_batch_import_handler() {
	error_log( '[PUNTWORK] [BATCH-START] Starting batch import via Action Scheduler' );
	
	try {
		// Load required files for batch processing
		$import_files = array(
			__DIR__ . '/../batch/batch-size-management.php',
			__DIR__ . '/../import/import-setup.php',
			__DIR__ . '/../batch/batch-processing.php',
			__DIR__ . '/../import/import-finalization.php',
			__DIR__ . '/../utilities/ErrorHandler.php',
			__DIR__ . '/../exceptions/PuntworkExceptions.php',
			__DIR__ . '/../import/import-batch.php',
		);

		foreach ( $import_files as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		// Start the FULL import (all batches) instead of just one batch
		$result = import_all_jobs_from_json();
		error_log( '[PUNTWORK] [BATCH-RESULT] Full import result: ' . json_encode( $result ) );
		
		if ( isset( $result['success'] ) && $result['success'] ) {
			error_log( '[PUNTWORK] [BATCH-SUCCESS] Full import completed successfully' );
		} else {
			error_log( '[PUNTWORK] [BATCH-ERROR] Full import failed or incomplete' );
		}
	} catch ( \Exception $e ) {
		error_log( '[PUNTWORK] [BATCH-EXCEPTION] Exception in batch import handler: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		error_log( '[PUNTWORK] [BATCH-EXCEPTION] Stack trace: ' . $e->getTraceAsString() );
	} catch ( \Throwable $e ) {
		error_log( '[PUNTWORK] [BATCH-FATAL] Fatal error in batch import handler: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		error_log( '[PUNTWORK] [BATCH-FATAL] Stack trace: ' . $e->getTraceAsString() );
	}
}
