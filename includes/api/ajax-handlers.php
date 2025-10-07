<?php

/**
 * AJAX handlers for job import plugin.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register AJAX handlers
 */
add_action( 'admin_init', __NAMESPACE__ . '\\register_ajax_handlers' );
function register_ajax_handlers() {
	// Schedule-related handlers
	add_action( 'wp_ajax_get_import_schedule', function() { ajax_get_import_schedule(); } );
	add_action( 'wp_ajax_save_import_schedule', function() { ajax_save_import_schedule(); } );
	add_action( 'wp_ajax_test_import_schedule', function() { ajax_test_import_schedule(); } );

	// History-related handlers
	add_action( 'wp_ajax_get_import_run_history', function() { ajax_get_import_run_history(); } );

	// Status-related handlers
	add_action( 'wp_ajax_get_job_import_status', function() { ajax_get_job_import_status(); } );
	add_action( 'wp_ajax_get_async_status', function() { ajax_get_async_status(); } );

	// Import-related handlers
	add_action( 'wp_ajax_run_job_import_batch', function() { ajax_run_job_import_batch(); } );
	add_action( 'wp_ajax_run_scheduled_import', function() { ajax_run_scheduled_import(); } );
	add_action( 'wp_ajax_cancel_job_import', function() { ajax_cancel_job_import(); } );
	add_action( 'wp_ajax_reset_job_import', function() { ajax_reset_job_import(); } );
	add_action( 'wp_ajax_reset_job_import_status', function() { ajax_reset_job_import_status(); } );

	// API-related handlers
	add_action( 'wp_ajax_get_api_key', function() { ajax_get_api_key(); } );

	// Data status handlers
	add_action( 'wp_ajax_check_import_data_status', function() { ajax_check_import_data_status(); } );

	// Database optimization handlers
	add_action( 'wp_ajax_get_db_optimization_status', function() { ajax_get_db_optimization_status(); } );
	add_action( 'wp_ajax_create_database_indexes', function() { ajax_create_database_indexes(); } );

	// Async processing handlers
	add_action( 'wp_ajax_save_async_settings', function() { ajax_save_async_settings(); } );

	// Feed processing handlers
	add_action( 'wp_ajax_process_feed', function() { ajax_process_feed(); } );
	add_action( 'wp_ajax_schedule_feed_processing', function() { ajax_schedule_feed_processing(); } );
	add_action( 'wp_ajax_get_feed_processing_status', function() { ajax_get_feed_processing_status(); } );

	// Cleanup handlers
	add_action( 'wp_ajax_job_import_cleanup_duplicates', function() { ajax_cleanup_duplicates(); } );
	add_action( 'wp_ajax_job_import_cleanup_continue', function() { ajax_cleanup_continue(); } );

	// Clear import cancel flag
	add_action( 'wp_ajax_clear_import_cancel', function() { ajax_clear_import_cancel(); } );

	// Disable scheduled imports
	add_action( 'wp_ajax_disable_scheduled_imports', function() { ajax_disable_scheduled_imports(); } );
}

/**
 * Get import schedule settings
 */
function ajax_get_import_schedule() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$schedule = get_option( 'puntwork_import_schedule', array(
			'enabled'  => false,
			'frequency' => 'daily',
			'interval' => 24,
			'hour'     => 9,
			'minute'   => 0,
		) );

		$next_run = get_next_scheduled_time();
		$last_run = get_option( 'puntwork_last_import_run' );
		$last_run_details = get_option( 'puntwork_last_import_details', array() );

		$response = array(
			'schedule' => $schedule,
			'next_run' => $next_run,
			'last_run' => $last_run,
			'last_run_details' => $last_run_details,
		);
		wp_send_json_success( $response );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to get import schedule: ' . $e->getMessage() ) );
	}
}

/**
 * Save import schedule settings
 */
function ajax_save_import_schedule() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$enabled = isset( $_POST['enabled'] ) ? ( $_POST['enabled'] === '1' ) : false;
		$frequency = sanitize_text_field( $_POST['frequency'] ?? 'daily' );
		$interval = intval( $_POST['interval'] ?? 24 );
		$hour = intval( $_POST['hour'] ?? 9 );
		$minute = intval( $_POST['minute'] ?? 0 );

		$schedule = array(
			'enabled' => $enabled,
			'frequency' => $frequency,
			'interval' => $interval,
			'hour' => $hour,
			'minute' => $minute,
		);

		// Clear existing schedule
		wp_clear_scheduled_hook( 'puntwork_scheduled_import' );

		// Schedule new import if enabled
		if ( $enabled ) {
			$next_run = get_next_run_time( $schedule );
			if ( $next_run ) {
				wp_schedule_single_action( $next_run, 'puntwork_scheduled_import' );
			}
		}

		$next_run = get_next_scheduled_time();
		$response = array(
			'schedule' => $schedule,
			'next_run' => $next_run,
		);
		wp_send_json_success( $response );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to save import schedule: ' . $e->getMessage() ) );
	}
}

/**
 * Test import schedule
 */
function ajax_test_import_schedule() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		// Set test mode
		update_option( 'puntwork_test_mode', true );

		// Run a test import
		$result = run_scheduled_import( true, 'test' );

		// Clear test mode
		delete_option( 'puntwork_test_mode' );

		wp_send_json_success( array(
			'message' => 'Test import completed',
			'result' => $result,
		) );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Test import failed: ' . $e->getMessage() ) );
	}
}

/**
 * Get import run history
 */
function ajax_get_import_run_history() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$history = get_option( 'puntwork_import_history', array() );
		$count = count( $history );

		// Ensure history is an array and sort by timestamp descending
		if ( is_array( $history ) ) {
			usort( $history, function( $a, $b ) {
				return ( $b['timestamp'] ?? 0 ) - ( $a['timestamp'] ?? 0 );
			} );
		} else {
			$history = array();
		}

		wp_send_json_success( array(
			'history' => array_slice( $history, 0, 50 ), // Limit to last 50 runs
			'count' => $count,
		) );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to get import history: ' . $e->getMessage() ) );
	}
}

/**
 * Get job import status
 */
function ajax_get_job_import_status() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$status = get_option( 'job_import_status', array() );
		$progress = get_option( 'job_import_progress', 0 );

		wp_send_json_success( array(
			'status' => $status,
			'progress' => $progress,
		) );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to get job import status: ' . $e->getMessage() ) );
	}
}

/**
 * Get async processing status
 */
function ajax_get_async_status() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$async_enabled = get_option( 'puntwork_async_processing_enabled', false );
		$running_jobs = array();

		// Check for running Action Scheduler jobs
		if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( '\ActionScheduler_Store' ) ) {
			$actions = as_get_scheduled_actions( array(
				'hook' => 'puntwork_process_import_batch',
				'status' => \ActionScheduler_Store::STATUS_RUNNING,
			) );

			foreach ( $actions as $action ) {
				$running_jobs[] = array(
					'id' => $action->get_id(),
					'scheduled' => $action->get_schedule()->get_date()->getTimestamp(),
				);
			}
		}

		wp_send_json_success( array(
			'enabled' => $async_enabled,
			'running_jobs' => $running_jobs,
		) );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to get async status: ' . $e->getMessage() ) );
	}
}

/**
 * Run job import batch
 */
function ajax_run_job_import_batch() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$start = intval( $_POST['start'] ?? 0 );

		// Run batch processing
		$result = process_import_batch( $start );

		wp_send_json_success( $result );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to run import batch: ' . $e->getMessage() ) );
	}
}

/**
 * Run scheduled import
 */
function ajax_run_scheduled_import() {
	try {
		error_log( '[PUNTWORK] [AJAX] ajax_run_scheduled_import called - REQUEST: ' . json_encode( $_REQUEST ) );
		error_log( '[PUNTWORK] [AJAX] ajax_run_scheduled_import called - POST: ' . json_encode( $_POST ) );
		error_log( '[PUNTWORK] [AJAX] ajax_run_scheduled_import called - SERVER: ' . json_encode( array(
			'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
			'CONTENT_TYPE' => $_SERVER['CONTENT_TYPE'] ?? 'unknown',
			'HTTP_X_REQUESTED_WITH' => $_SERVER['HTTP_X_REQUESTED_WITH'] ?? 'unknown',
		) ) );

		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			error_log( '[PUNTWORK] [AJAX] Nonce verification failed' );
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		error_log( '[PUNTWORK] [AJAX] Nonce verification passed' );

		// For manual imports, run synchronously but with better timeout handling
		// Set a reasonable time limit for the request
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 ); // 10 minutes for large imports
		}

		// Increase memory limit
		if ( function_exists( 'ini_set' ) ) {
			ini_set( 'memory_limit', '1024M' );
		}

		error_log( '[PUNTWORK] [AJAX] Starting manual import synchronously - increased limits: time=600s, memory=1024M' );

		$result = run_scheduled_import( false, 'manual' );

		error_log( '[PUNTWORK] [AJAX] Manual import completed successfully with result: ' . json_encode( $result ) );

		wp_send_json_success( array(
			'message' => 'Import completed successfully',
			'result' => $result,
			'async' => false,
		) );
	} catch ( \Exception $e ) {
		error_log( '[PUNTWORK] [AJAX] Manual import failed with error: ' . $e->getMessage() );
		error_log( '[PUNTWORK] [AJAX] Error stack trace: ' . $e->getTraceAsString() );
		wp_send_json_error( array( 'message' => 'Failed to run scheduled import: ' . $e->getMessage() ) );
	}
}

/**
 * Cancel job import
 */
function ajax_cancel_job_import() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		set_transient( 'import_cancel', true, 3600 );

		wp_send_json_success( array( 'message' => 'Import cancelled' ) );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to cancel import: ' . $e->getMessage() ) );
	}
}

/**
 * Reset job import
 */
function ajax_reset_job_import() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		delete_option( 'job_import_status' );
		delete_option( 'job_import_progress' );
		delete_option( 'job_import_processed_guids' );
		delete_transient( 'import_cancel' );

		wp_send_json_success( array( 'message' => 'Import reset successfully' ) );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to reset import: ' . $e->getMessage() ) );
	}
}

/**
 * Reset job import status (alias for reset_job_import)
 */
function ajax_reset_job_import_status() {
	ajax_reset_job_import();
}

/**
 * Get API key
 */
function ajax_get_api_key() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$api_key = get_or_create_api_key();

		wp_send_json_success( array( 'api_key' => $api_key ) );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to get API key: ' . $e->getMessage() ) );
	}
}

/**
 * Check import data status
 */
function ajax_check_import_data_status() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$combined_file = puntwork_get_combined_jsonl_path();
		$feeds = get_feeds();

		$status = array(
			'combined_file_exists' => file_exists( $combined_file ),
			'combined_file_size' => file_exists( $combined_file ) ? filesize( $combined_file ) : 0,
			'feeds_count' => count( $feeds ),
			'feeds_available' => ! empty( $feeds ),
		);
		wp_send_json_success( $status );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to check import data status: ' . $e->getMessage() ) );
	}
}

/**
 * Get database optimization status
 */
function ajax_get_db_optimization_status() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		global $wpdb;

		$status = array(
			'indexes_created' => get_option( 'puntwork_db_indexes_created', false ),
			'table_optimization' => array(
				'job_posts' => $wpdb->get_var( "SHOW TABLE STATUS LIKE '{$wpdb->posts}'" ),
			),
			'optimization_complete' => get_option( 'puntwork_db_indexes_created', false ), // Same as indexes_created for now
			'indexes_html' => '', // Placeholder for HTML display
		);
		wp_send_json_success( array( 'status' => $status ) );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to get DB optimization status: ' . $e->getMessage() ) );
	}
}

/**
 * Create database indexes
 */
function ajax_create_database_indexes() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$result = create_database_indexes();

		wp_send_json_success( array(
			'message' => 'Database indexes created successfully',
			'result' => $result,
			'status' => array(
				'indexes_created' => true,
				'optimization_complete' => true,
				'indexes_html' => '', // Placeholder for HTML display
			),
		) );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to create database indexes: ' . $e->getMessage() ) );
	}
}

/**
 * Save async processing settings
 */
function ajax_save_async_settings() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$enabled = isset( $_POST['enabled'] ) && $_POST['enabled'] === 'true';

		update_option( 'puntwork_async_processing_enabled', $enabled );

		wp_send_json_success( array(
			'message' => 'Async settings saved successfully',
			'enabled' => $enabled,
		) );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to save async settings: ' . $e->getMessage() ) );
	}
}

/**
 * Process feed
 */
function ajax_process_feed() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$feed_key = sanitize_text_field( $_POST['feed_key'] ?? '' );

		if ( empty( $feed_key ) ) {
			wp_send_json_error( array( 'message' => 'Feed key is required' ) );
			return;
		}

		$feeds = get_feeds();
		if ( ! isset( $feeds[ $feed_key ] ) ) {
			wp_send_json_error( array( 'message' => 'Feed not found' ) );
			return;
		}

		$result = download_and_process_feed( $feed_key, $feeds[ $feed_key ] );

		wp_send_json_success( $result );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to process feed: ' . $e->getMessage() ) );
	}
}

/**
 * Schedule feed processing
 */
function ajax_schedule_feed_processing() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$feed_keys = isset( $_POST['feed_keys'] ) ? (array) $_POST['feed_keys'] : array();

		if ( empty( $feed_keys ) ) {
			wp_send_json_error( array( 'message' => 'Feed keys are required' ) );
			return;
		}

		$scheduled_jobs = array();

		foreach ( $feed_keys as $feed_key ) {
			$feed_key = sanitize_text_field( (string) $feed_key );

			if ( function_exists( 'as_schedule_single_action' ) ) {
				$job_id = as_schedule_single_action( time(), 'puntwork_process_feed', array( 'feed_key' => $feed_key ) );
				$scheduled_jobs[] = array(
					'feed_key' => $feed_key,
					'job_id' => $job_id,
				);
			}
		}

		wp_send_json_success( array(
			'message' => 'Feed processing scheduled',
			'scheduled_jobs' => $scheduled_jobs,
		) );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to schedule feed processing: ' . $e->getMessage() ) );
	}
}

/**
 * Get feed processing status
 */
function ajax_get_feed_processing_status() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$feed_keys = isset( $_POST['feed_keys'] ) ? (array) $_POST['feed_keys'] : array();

		$status = array();

		if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( '\ActionScheduler_Store' ) ) {
			foreach ( $feed_keys as $feed_key ) {
				$feed_key = sanitize_text_field( (string) $feed_key );

				$actions = as_get_scheduled_actions( array(
					'hook' => 'puntwork_process_feed',
					'args' => array( 'feed_key' => $feed_key ),
					'status' => array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ),
				) );

				$status[ $feed_key ] = array(
					'scheduled' => ! empty( $actions ),
					'running' => count( array_filter( $actions, function( $action ) {
						return $action->get_status() === \ActionScheduler_Store::STATUS_RUNNING;
					} ) ) > 0,
				);
			}
		}

		wp_send_json_success( array( 'status' => $status ) );
        } catch ( \Exception $e ) {
                error_log( 'AJAX get_feed_processing_status error' . ": " . $e->getMessage() );
                wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
}

/**
 * Cleanup duplicates
 */
function ajax_cleanup_duplicates() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$result = cleanup_duplicate_jobs();

		wp_send_json_success( array(
			'message' => 'Duplicate cleanup completed',
			'result' => $result,
		) );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to cleanup duplicates: ' . $e->getMessage() ) );
	}
}

/**
 * Continue cleanup operation
 */
function ajax_cleanup_continue() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		$offset = intval( $_POST['offset'] ?? 0 );
		$batch_size = intval( $_POST['batch_size'] ?? 100 );

		$result = continue_cleanup_duplicates( $offset, $batch_size );

		wp_send_json_success( $result );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to continue cleanup: ' . $e->getMessage() ) );
	}
}

/**
 * Clear import cancel flag
 */
function ajax_clear_import_cancel() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		delete_transient( 'import_cancel' );

		wp_send_json_success( array( 'message' => 'Import cancel flag cleared' ) );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to clear import cancel flag: ' . $e->getMessage() ) );
	}
}

/**
 * Disable scheduled imports
 */
function ajax_disable_scheduled_imports() {
	try {
		// Verify nonce
		if ( ! check_ajax_referer( 'job_import_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		// Include the scheduling core functions
		require_once plugin_dir_path( __FILE__ ) . '../../scheduling/scheduling-core.php';

		$result = disable_scheduled_imports();

		wp_send_json_success( array(
			'message' => 'Scheduled imports disabled successfully',
			'result' => $result,
		) );
	} catch ( \Exception $e ) {
		wp_send_json_error( array( 'message' => 'Failed to disable scheduled imports: ' . $e->getMessage() ) );
	}
}

/**
 * Helper function to get next run time
 */
function get_next_run_time( $schedule ) {
	$now = current_time( 'timestamp' );

	if ( $schedule['frequency'] === 'custom' ) {
		$interval_hours = max( 1, intval( $schedule['interval'] ) );
		$interval_seconds = $interval_hours * HOUR_IN_SECONDS;

		// Find next interval
		$last_run = get_option( 'puntwork_last_import_run' );
		if ( $last_run ) {
			return $last_run + $interval_seconds;
		} else {
			return $now + $interval_seconds;
		}
	} else {
		// Daily schedule
		$hour = intval( $schedule['hour'] ?? 9 );
		$minute = intval( $schedule['minute'] ?? 0 );

		$next_run = strtotime( date( 'Y-m-d', $now ) . " {$hour}:{$minute}:00" );

		if ( $next_run <= $now ) {
			$next_run = strtotime( '+1 day', $next_run );
		}

		return $next_run;
	}
}