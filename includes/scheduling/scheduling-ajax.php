<?php

/**
 * AJAX handlers for scheduling operations.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * AJAX handlers for scheduling operations
 * Handles schedule management, history, and execution
 */

// Explicitly load required utility classes for AJAX context
require_once __DIR__ . '/../utilities/SecurityUtils.php';
require_once __DIR__ . '/../utilities/AjaxErrorHandler.php';
require_once __DIR__ . '/../utilities/PuntWorkLogger.php';

add_action(
	'wp_ajax_debug_trigger_async',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}

		run_scheduled_import_async();
		wp_die( 'Async function triggered - check debug.log' );
	}
);

// Debug endpoint to clear import status
add_action(
	'wp_ajax_debug_clear_import_status',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}

		delete_option( 'job_import_status' );
		delete_transient( 'import_cancel' );
		wp_die( 'Import status cleared - you can now try Run Now again' );
	}
);

/**
 * Save import schedule settings via AJAX.
 */
function save_import_schedule_ajax() {
	PuntWorkLogger::logAjaxRequest( 'save_import_schedule', $_POST );

	// Use comprehensive security validation
	$validation = SecurityUtils::validateAjaxRequest( 'save_import_schedule', 'job_import_nonce' );
	if ( is_wp_error( $validation ) ) {
		AjaxErrorHandler::sendError( $validation );

		return;
	}

	try {
		// Set defaults for missing fields
		$input_data = array(
			'enabled'   => $_POST['enabled'] ?? false,
			'frequency' => $_POST['frequency'] ?? 'daily',
			'interval'  => $_POST['interval'] ?? 24,
			'hour'      => $_POST['hour'] ?? 9,
			'minute'    => $_POST['minute'] ?? 0,
		);

		// Validate and sanitize input fields
		$validation_rules = array(
			'enabled'   => array( 'type' => 'boolean' ),
			'frequency' => array(
				'type' => 'string',
				'enum' => array( 'hourly', '3hours', '6hours', '12hours', 'daily', 'custom' ),
			),
			'interval'  => array(
				'type' => 'integer',
				'min'  => 1,
				'max'  => 168,
			),
			'hour'      => array(
				'type' => 'integer',
				'min'  => 0,
				'max'  => 23,
			),
			'minute'    => array(
				'type' => 'integer',
				'min'  => 0,
				'max'  => 59,
			),
		);

		$validated_data = SecurityUtils::sanitizeDataArray( $input_data, $validation_rules );
		if ( is_wp_error( $validated_data ) ) {
			AjaxErrorHandler::sendError( $validated_data );
			return;
		}

		$enabled   = $validated_data['enabled'];
		$frequency = $validated_data['frequency'];
		$interval  = $validated_data['interval'];
		$hour      = $validated_data['hour'];
		$minute    = $validated_data['minute'];

		PuntWorkLogger::info(
			'Saving import schedule',
			PuntWorkLogger::CONTEXT_SCHEDULING,
			array(
				'enabled'   => $enabled,
				'frequency' => $frequency,
				'interval'  => $interval,
				'hour'      => $hour,
				'minute'    => $minute,
			)
		);

		$schedule_data = array(
			'enabled'    => $enabled,
			'frequency'  => $frequency,
			'interval'   => $interval,
			'hour'       => $hour,
			'minute'     => $minute,
			'updated_at' => current_time( 'timestamp' ),
			'updated_by' => get_current_user_id(),
		);

		update_option( 'puntwork_import_schedule', $schedule_data );

		// Update WordPress cron
		update_cron_schedule( $schedule_data );

		$last_run         = get_option( 'puntwork_last_import_run', null );
		$last_run_details = get_option( 'puntwork_last_import_details', null );

		PuntWorkLogger::info( 'Import schedule saved successfully', PuntWorkLogger::CONTEXT_SCHEDULING );

		PuntWorkLogger::logAjaxResponse(
			'save_import_schedule',
			array(
				'message'          => 'Schedule saved successfully',
				'schedule'         => $schedule_data,
				'next_run'         => get_next_scheduled_time(),
				'last_run'         => $last_run,
				'last_run_details' => $last_run_details,
			)
		);
		AjaxErrorHandler::sendSuccess(
			array(
				'message'          => 'Schedule saved successfully',
				'schedule'         => $schedule_data,
				'next_run'         => get_next_scheduled_time(),
				'last_run'         => $last_run,
				'last_run_details' => $last_run_details,
			)
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Save schedule failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING );
		AjaxErrorHandler::sendError( 'Save schedule failed: ' . $e->getMessage() );
	}
}

/**
 * Get current import schedule settings via AJAX.
 */
function get_import_schedule_ajax() {
	PuntWorkLogger::logAjaxRequest( 'get_import_schedule', $_POST );

	// Simple validation for debugging
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'job_import_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed' ) );

		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );

		return;
	}

	try {
		$schedule = safe_get_option(
			'puntwork_import_schedule',
			array(
				'enabled'    => false,
				'frequency'  => 'daily',
				'interval'   => 24,
				'hour'       => 9,
				'minute'     => 0,
				'updated_at' => null,
				'updated_by' => null,
			)
		);

		PuntWorkLogger::info(
			'Retrieved import schedule',
			PuntWorkLogger::CONTEXT_SCHEDULING,
			array(
				'enabled'   => $schedule['enabled'],
				'frequency' => $schedule['frequency'],
			)
		);

		$last_run         = safe_get_option( 'puntwork_last_import_run', null );
		$last_run_details = safe_get_option( 'puntwork_last_import_details', null );

		// Add formatted date to last run if it exists
		if ( $last_run && isset( $last_run['timestamp'] ) ) {
			// Timestamps are now stored in UTC using time(), wp_date() handles timezone conversion
			$last_run['formatted_date'] = wp_date( 'M j, Y H:i', $last_run['timestamp'] );
		}

		$next_run = get_next_scheduled_time();

		PuntWorkLogger::logAjaxResponse(
			'get_import_schedule',
			array(
				'schedule'         => $schedule,
				'next_run'         => $next_run,
				'last_run'         => $last_run,
				'last_run_details' => $last_run_details,
			)
		);
		AjaxErrorHandler::sendSuccess(
			array(
				'schedule'         => $schedule,
				'next_run'         => $next_run,
				'last_run'         => $last_run,
				'last_run_details' => $last_run_details,
			)
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Get schedule failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING );
		AjaxErrorHandler::sendError( 'Get schedule failed: ' . $e->getMessage() );
	} catch ( \Throwable $e ) {
		PuntWorkLogger::error( 'Get schedule fatal error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING );
		AjaxErrorHandler::sendError( 'Get schedule failed with fatal error: ' . $e->getMessage() );
	}
}

/**
 * Get import run history via AJAX.
 */
function get_import_run_history_ajax() {
	PuntWorkLogger::logAjaxRequest( 'get_import_run_history', $_POST );

	// Simple validation for debugging
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'job_import_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed' ) );

		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );

		return;
	}

	try {
		$history = safe_get_option( 'puntwork_import_run_history', array() );

		// Format dates for history entries - timestamps are stored in UTC
		foreach ( $history as &$entry ) {
			if ( isset( $entry['timestamp'] ) ) {
				$entry['formatted_date'] = wp_date( 'M j, Y H:i', $entry['timestamp'] );
			}
		}

		PuntWorkLogger::info(
			'Retrieved import run history',
			PuntWorkLogger::CONTEXT_SCHEDULING,
			array(
				'history_count' => count( $history ),
			)
		);

		PuntWorkLogger::logAjaxResponse(
			'get_import_run_history',
			array(
				'history' => $history,
				'count'   => count( $history ),
			)
		);
		AjaxErrorHandler::sendSuccess(
			array(
				'history' => $history,
				'count'   => count( $history ),
			)
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Get run history failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING );
		AjaxErrorHandler::sendError( 'Get run history failed: ' . $e->getMessage() );
	} catch ( \Throwable $e ) {
		PuntWorkLogger::error( 'Get run history fatal error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING );
		AjaxErrorHandler::sendError( 'Get run history failed with fatal error: ' . $e->getMessage() );
	}
}

/**
 * Test import schedule via AJAX.
 */
function test_import_schedule_ajax() {
	PuntWorkLogger::logAjaxRequest( 'test_import_schedule', $_POST );

	// Use comprehensive security validation
	$validation = SecurityUtils::validateAjaxRequest( 'test_import_schedule', 'job_import_nonce' );
	if ( is_wp_error( $validation ) ) {
		AjaxErrorHandler::sendError( $validation );

		return;
	}

	try {
		PuntWorkLogger::info( 'Starting test import schedule', PuntWorkLogger::CONTEXT_SCHEDULING );

		// Run a test import
		$result = run_scheduled_import( true ); // true = test mode

		PuntWorkLogger::info(
			'Test import schedule completed',
			PuntWorkLogger::CONTEXT_SCHEDULING,
			array(
				'success' => $result['success'] ?? false,
			)
		);

		PuntWorkLogger::logAjaxResponse(
			'test_import_schedule',
			array(
				'message' => 'Test import completed',
				'result'  => $result,
			)
		);
		AjaxErrorHandler::sendSuccess(
			array(
				'message' => 'Test import completed',
				'result'  => $result,
			)
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Test import schedule failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING );
		AjaxErrorHandler::sendError( 'Test import schedule failed: ' . $e->getMessage() );
	}
}

/**
 * Run scheduled import immediately via AJAX
 * Now triggers the import asynchronously like the manual Start Import button.
 */
function run_scheduled_import_ajax() {
	PuntWorkLogger::logAjaxRequest( 'run_scheduled_import', $_POST );

	// Use comprehensive security validation
	$validation = SecurityUtils::validateAjaxRequest( 'run_scheduled_import', 'job_import_nonce' );
	if ( is_wp_error( $validation ) ) {
		AjaxErrorHandler::sendError( $validation );

		return;
	}

	try {
		// Check if an import is already running
		$import_status = get_option( 'job_import_status', array() );
		if ( isset( $import_status['complete'] ) && ! $import_status['complete'] ) {
			// Calculate actual time elapsed
			$time_elapsed = 0;
			if ( isset( $import_status['start_time'] ) && $import_status['start_time'] > 0 ) {
				$time_elapsed = microtime( true ) - $import_status['start_time'];
			} elseif ( isset( $import_status['time_elapsed'] ) ) {
				$time_elapsed = $import_status['time_elapsed'];
			}

			// Check if it's a stuck import (processed = 0 and old)
			$is_stuck = ( ! isset( $import_status['processed'] ) || $import_status['processed'] === 0 ) &&
						( $time_elapsed > 300 ); // 5 minutes

			if ( $is_stuck ) {
				PuntWorkLogger::warn(
					'Detected stuck import, clearing status for new run',
					PuntWorkLogger::CONTEXT_SCHEDULING,
					array(
						'processed'    => $import_status['processed'] ?? 'null',
						'time_elapsed' => $time_elapsed,
					)
				);
				delete_option( 'job_import_status' );
				delete_transient( 'import_cancel' );
			} else {
				PuntWorkLogger::error( 'Import already running', PuntWorkLogger::CONTEXT_SCHEDULING );
				AjaxErrorHandler::sendError( 'An import is already running' );

				return;
			}
		}

		PuntWorkLogger::info( 'Starting scheduled import', PuntWorkLogger::CONTEXT_SCHEDULING );

		// Initialize import status for immediate UI feedback
		$initial_status = array(
			'total'              => 0, // Will be updated as import progresses
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
			'logs'               => array( 'Scheduled import started - preparing feeds...' ),
		);
		update_option( 'job_import_status', $initial_status, false );

		// Clear any previous cancellation before starting
		delete_transient( 'import_cancel' );

		// Schedule the import to run asynchronously
		if ( function_exists( 'as_schedule_single_action' ) ) {
			// Use Action Scheduler if available
			PuntWorkLogger::info( 'Scheduling async import using Action Scheduler', PuntWorkLogger::CONTEXT_SCHEDULING );
			as_schedule_single_action( time(), 'puntwork_scheduled_import_async' );
		} elseif ( function_exists( 'wp_schedule_single_event' ) ) {
			// Fallback: Use WordPress cron for near-immediate execution
			PuntWorkLogger::info( 'Action Scheduler not available, using WordPress cron', PuntWorkLogger::CONTEXT_SCHEDULING );
			wp_schedule_single_event( time() + 1, 'puntwork_scheduled_import_async' );
		} else {
			// Final fallback: Run synchronously (not ideal for UI but maintains functionality)
			PuntWorkLogger::warn( 'No async scheduling available, running synchronously', PuntWorkLogger::CONTEXT_SCHEDULING );
			$result = run_scheduled_import();

			if ( $result['success'] ) {
				PuntWorkLogger::info( 'Synchronous scheduled import completed successfully', PuntWorkLogger::CONTEXT_SCHEDULING );
				PuntWorkLogger::logAjaxResponse(
					'run_scheduled_import',
					array(
						'message' => 'Import completed successfully',
						'result'  => $result,
						'async'   => false,
					)
				);
				AjaxErrorHandler::sendSuccess(
					array(
						'message' => 'Import completed successfully',
						'result'  => $result,
						'async'   => false,
					)
				);
			} else {
				PuntWorkLogger::error(
					'Synchronous scheduled import failed',
					PuntWorkLogger::CONTEXT_SCHEDULING,
					array(
						'message' => $result['message'] ?? 'Unknown error',
					)
				);
				// Reset import status on failure so future attempts can start
				delete_option( 'job_import_status' );
				AjaxErrorHandler::sendError( array( 'message' => 'Import failed: ' . ( $result['message'] ?? 'Unknown error' ) ) );
			}

			return;
		}

		// Return success immediately so UI can start polling
		PuntWorkLogger::info( 'Scheduled import initiated asynchronously', PuntWorkLogger::CONTEXT_SCHEDULING );
		PuntWorkLogger::logAjaxResponse(
			'run_scheduled_import',
			array(
				'message' => 'Import started successfully',
				'async'   => true,
			)
		);
		AjaxErrorHandler::sendSuccess(
			array(
				'message' => 'Import started successfully',
				'async'   => true,
			)
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Run scheduled import failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_SCHEDULING );
		AjaxErrorHandler::sendError( 'Failed to start import: ' . $e->getMessage() );
	}
}

// Register AJAX actions
add_action( 'wp_ajax_save_import_schedule', __NAMESPACE__ . '\\save_import_schedule_ajax' );
add_action( 'wp_ajax_get_import_schedule', __NAMESPACE__ . '\\get_import_schedule_ajax' );
add_action( 'wp_ajax_get_import_run_history', __NAMESPACE__ . '\\get_import_run_history_ajax' );
add_action( 'wp_ajax_test_import_schedule', __NAMESPACE__ . '\\test_import_schedule_ajax' );
add_action( 'wp_ajax_run_scheduled_import', __NAMESPACE__ . '\\run_scheduled_import_ajax' );

// Register cron hook for manual imports
add_action( 'puntwork_manual_import', __NAMESPACE__ . '\\run_manual_import_cron' );

// Register async action hooks
add_action( 'puntwork_scheduled_import_async', __NAMESPACE__ . '\\run_scheduled_import_async' );

// Register analytics cleanup hook
add_action( 'puntwork_analytics_cleanup', array( __NAMESPACE__ . '\\ImportAnalytics', 'cleanup_old_data' ) );

/**
 * Run scheduled import asynchronously (non-blocking).
 */
function run_scheduled_import_async() {
	// Clear any previous cancellation before starting
	delete_transient( 'import_cancel' );

	// Check if an import is already running
	$import_status = get_option( 'job_import_status', array() );

	if (
		isset( $import_status['complete'] ) && $import_status['complete'] == false
		&& isset( $import_status['processed'] ) && $import_status['processed'] > 0
	) {
		return;
	}

	// Clear import_cancel transient again just before starting the import
	delete_transient( 'import_cancel' );

	try {
		// Get test mode and trigger type from status if set
		$current_status    = get_option( 'job_import_status', array() );
		$test_mode_flag    = $current_status['test_mode'] ?? false;
		$trigger_type_flag = $current_status['trigger_type'] ?? 'scheduled';

		$result = run_scheduled_import( $test_mode_flag, $trigger_type_flag );

		// Import runs to completion without pausing
		if ( $result['success'] ) {
			// Success is logged in run_scheduled_import
		} else {
			// Reset import status on failure so future attempts can start
			delete_option( 'job_import_status' );
		}
	} catch ( \Exception $e ) {
		// Reset import status on exception so future attempts can start
		delete_option( 'job_import_status' );
	}
}

/**
 * Run the complete scheduled import process: feed processing -> combination -> import.
 */
function run_scheduled_import( bool $test_mode = false, string $trigger_type = 'scheduled' ): array {
	$start_time = microtime( true );

	// Initialize memory management for large batch operations
	\Puntwork\Utilities\MemoryManager::reset();
	\Puntwork\Utilities\MemoryManager::optimizeForLargeBatch();

	try {
		// Step 1: Get all configured feeds
		$feeds = get_feeds();
		if ( empty( $feeds ) ) {
			$error_msg = 'No feeds configured for import';

			return array(
				'success' => false,
				'message' => $error_msg,
				'logs'    => array( $error_msg ),
			);
		}

		// Step 2: Process all feeds (download and convert to JSONL)
		$output_dir      = puntwork_get_feeds_directory();
		$fallback_domain = 'belgiumjobs.work';
		$total_items     = 0;
		$all_logs        = array();

		// Ensure output directory exists
		if ( ! wp_mkdir_p( $output_dir ) || ! is_writable( $output_dir ) ) {
			$error_msg = 'Feeds directory not writable: ' . $output_dir;

			return array(
				'success' => false,
				'message' => $error_msg,
				'logs'    => array( $error_msg ),
			);
		}

		// Load required functions
		if ( ! function_exists( 'process_one_feed' ) ) {
			require_once __DIR__ . '/../core/core-structure-logic.php';
		}

		foreach ( $feeds as $feed_key => $feed_url ) {
			$logs         = array();
			$item_count   = process_one_feed( $feed_key, $feed_url, $output_dir, $fallback_domain, $logs );
			$total_items += $item_count;

			// Check if feed processing failed and log specific error
			if ( $item_count === 0 && ! empty( $logs ) ) {
				$last_log = end( $logs );
				if ( strpos( $last_log, 'Download error:' ) !== false || strpos( $last_log, 'ERROR' ) !== false ) {
					PuntWorkLogger::error(
						'Feed processing failed',
						PuntWorkLogger::CONTEXT_FEED_PROCESSING,
						array(
							'feed_key'      => $feed_key,
							'feed_url'      => $feed_url,
							'error_details' => $last_log,
							'all_logs'      => $logs,
						)
					);
				}
			}

			$all_logs = array_merge( $all_logs, $logs );

			// Check memory usage after each feed processing
			$memory_check = \Puntwork\Utilities\MemoryManager::checkMemoryUsage( $total_items );
			if ( ! empty( $memory_check['actions_taken'] ) ) {
				$all_logs[] = 'Memory management triggered: ' . implode( ', ', $memory_check['actions_taken'] ) . ' (Usage: ' . round( $memory_check['memory_ratio'] * 100, 1 ) . '%)';
			}
		}

		// Step 3: Combine JSONL files
		if ( ! function_exists( 'combine_jsonl_files' ) ) {
			require_once __DIR__ . '/../import/combine-jsonl.php';
		}

		// Check memory before combining
		$memory_check = \Puntwork\Utilities\MemoryManager::checkMemoryUsage( $total_items );
		if ( $memory_check['memory_ratio'] > 0.7 ) {
			$all_logs[] = 'High memory usage before combining: ' . round( $memory_check['memory_ratio'] * 100, 1 ) . '% - forcing cleanup';
			gc_collect_cycles();
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
		}

		$combine_logs = array();
		combine_jsonl_files( $feeds, $output_dir, $total_items, $combine_logs );
		$all_logs = array_merge( $all_logs, $combine_logs );

		// Check if combined file was created
		$combined_file = $output_dir . 'combined-jobs.jsonl';
		if ( ! file_exists( $combined_file ) ) {
			$error_msg = 'Combined JSONL file was not created';

			return array(
				'success' => false,
				'message' => $error_msg,
				'logs'    => $all_logs,
			);
		}

		$file_size = filesize( $combined_file );

		if ( $file_size == 0 ) {
			$error_msg = 'Combined JSONL file is empty';

			return array(
				'success' => false,
				'message' => $error_msg,
				'logs'    => $all_logs,
			);
		}

		// Step 4: Run the import
		// Check memory before import
		$memory_check = \Puntwork\Utilities\MemoryManager::checkMemoryUsage( $total_items );
		if ( $memory_check['memory_ratio'] > 0.8 ) {
			$all_logs[] = 'High memory usage before import: ' . round( $memory_check['memory_ratio'] * 100, 1 ) . '% - forcing cleanup';
			gc_collect_cycles();
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
		}

		$import_result = import_all_jobs_from_json();

		$total_duration = microtime( true ) - $start_time;

		if ( $import_result['success'] ) {
			// Log the successful run
			include_once __DIR__ . '/../scheduling/scheduling-history.php';
			if ( function_exists( 'log_manual_import_run' ) ) {
				log_manual_import_run(
					array(
						'timestamp'     => time(),
						'duration'      => $total_duration,
						'success'       => true,
						'processed'     => $import_result['processed'] ?? 0,
						'total'         => $import_result['total'] ?? 0,
						'published'     => $import_result['published'] ?? 0,
						'updated'       => $import_result['updated'] ?? 0,
						'skipped'       => $import_result['skipped'] ?? 0,
						'error_message' => '',
					)
				);
			}

			return array_merge(
				$import_result,
				array(
					'logs'                 => array_merge( $all_logs, $import_result['logs'] ?? array() ),
					'feed_processing_time' => $total_duration - ( $import_result['time_elapsed'] ?? 0 ),
				)
			);
		} else {
			$error_msg = $import_result['message'] ?? 'Import failed';

			// Log the failed run
			include_once __DIR__ . '/../scheduling/scheduling-history.php';
			if ( function_exists( 'log_manual_import_run' ) ) {
				log_manual_import_run(
					array(
						'timestamp'     => time(),
						'duration'      => $total_duration,
						'success'       => false,
						'processed'     => $import_result['processed'] ?? 0,
						'total'         => $import_result['total'] ?? 0,
						'published'     => $import_result['published'] ?? 0,
						'updated'       => $import_result['updated'] ?? 0,
						'skipped'       => $import_result['skipped'] ?? 0,
						'error_message' => $error_msg,
					)
				);
			}

			return array(
				'success' => false,
				'message' => $error_msg,
				'logs'    => array_merge( $all_logs, $import_result['logs'] ?? array() ),
			);
		}
	} catch ( \Exception $e ) {
		$error_msg = 'Scheduled import failed: ' . $e->getMessage();

		return array(
			'success' => false,
			'message' => $error_msg,
			'logs'    => $all_logs ?? array(),
		);
	}
}
