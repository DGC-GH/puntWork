<?php

/**
 * Batch import processing with timeout protection.
 *
 * @since      1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Temporarily disable spam logging - uncomment for debugging
// error_log(
// '[PUNTWORK] import-batch.php loaded - is_admin: ' . ( is_admin() ? 'true' : 'false' ) .
// ', DOING_AJAX: ' . ( defined('DOING_AJAX') && DOING_AJAX ? 'true' : 'false' )
// );

/**
 * Main import batch processing file
 * Includes all import-related modules and provides the main import function.
 */

// Include batch size management
require_once __DIR__ . '/../batch/batch-size-management.php';

// Include import setup
require_once __DIR__ . '/import-setup.php';

// Include batch processing
require_once __DIR__ . '/../batch/batch-processing.php';

// Include import finalization
require_once __DIR__ . '/import-finalization.php';

// Include error handling system
require_once __DIR__ . '/../utilities/ErrorHandler.php';
require_once __DIR__ . '/../exceptions/PuntworkExceptions.php';

// Include JSONL combination utilities
require_once __DIR__ . '/combine-jsonl.php';

// Include core structure logic for get_feeds function
require_once __DIR__ . '/../core/core-structure-logic.php';

/**
 * Check if the current import process has exceeded time limits
 * Similar to WooCommerce's time_exceeded() method.
 *
 * @return bool True if time limit exceeded
 */
function import_time_exceeded(): bool {
	$start_time   = get_option( 'job_import_start_time', microtime( true ) );
	$time_limit   = apply_filters( 'puntwork_import_time_limit', 600 ); // 600 seconds (10 minutes) default
	$current_time = microtime( true );
	$elapsed_time = $current_time - $start_time;

	// Debug logging - temporarily disabled to reduce spam
	// error_log(
	// sprintf(
	// '[PUNTWORK] [TIME-DEBUG] import_time_exceeded check: start_time=%.6f, current_time=%.6f, ' .
	// 'elapsed=%.2fs, limit=%ds, exceeded=%s',
	// $start_time,
	// $current_time,
	// $elapsed_time,
	// $time_limit,
	// ( $elapsed_time >= $time_limit ? 'YES' : 'NO' )
	// )
	// );

	if ( $elapsed_time >= $time_limit ) {
		$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
		if ( $debug_mode ) {
			error_log(
				sprintf(
					'[PUNTWORK] [TIME-LIMIT] Import time limit exceeded: %.2fs elapsed, limit was %ds',
					$elapsed_time,
					$time_limit
				)
			);
		}

		return true;
	}

	// Log remaining time for debugging - temporarily disabled to reduce spam
	// $remaining_time = $time_limit - $elapsed_time;
	// if ($remaining_time <= 30) { // Log when less than 30 seconds remaining
	// error_log(
	// sprintf(
	// '[PUNTWORK] [TIME-WARNING] Import time limit approaching: %.1fs remaining (elapsed: %.2fs, limit: %ds)',
	// $remaining_time,
	// $elapsed_time,
	// $time_limit
	// )
	// );
	// }

	return apply_filters( 'puntwork_import_time_exceeded', false );
}

/**
 * Check if the current import process has exceeded memory limits
 * Similar to WooCommerce's memory_exceeded() method.
 *
 * @return bool True if memory limit exceeded
 */
function import_memory_exceeded(): bool {
	$memory_limit   = get_memory_limit_bytes() * 0.9; // 90% of max memory
	$current_memory = memory_get_usage( true );

	if ( $current_memory >= $memory_limit ) {
		return true;
	}

	return apply_filters( 'puntwork_import_memory_exceeded', false );
}

/**
 * Check if batch processing should continue
 * Returns false if time or memory limits exceeded.
 *
 * @return bool True if processing should continue
 */
function should_continue_batch_processing(): bool {
	if ( import_time_exceeded() ) {
		$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] Import time limit exceeded - pausing batch processing' );
		}

		return false;
	}

	if ( import_memory_exceeded() ) {
		$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] Import memory limit exceeded - pausing batch processing' );
		}

		return false;
	}

	return true;
}

if ( ! function_exists( 'import_jobs_from_json' ) ) {
	// Temporarily disable spam logging
	// error_log('[PUNTWORK] Defining import_jobs_from_json function');
	/**
	 * Import jobs from JSONL file in batches.
	 *
	 * @param  bool $is_batch    Whether this is a batch import.
	 * @param  int  $batch_start Starting index for batch.
	 * @return array Import result data.
	 */
	function import_jobs_from_json( bool $is_batch = false, int $batch_start = 0 ): array {
		$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

		try {
			// Check for concurrent import lock
			$import_lock_key = 'puntwork_import_lock';
			if ( ! $is_batch && get_transient( $import_lock_key ) ) {
				return array(
					'success' => false,
					'message' => 'Import already running',
					'logs'    => array( 'Import already running - concurrent imports not allowed' ),
				);
			}

			// Set import lock
			if ( ! $is_batch ) {
				set_transient( $import_lock_key, true, 1200 );
			}

			// Setup import
			$setup = prepare_import_setup( $batch_start, $is_batch );
			if ( isset( $setup['success'] ) && $setup['success'] === false ) {
				return array(
					'success' => false,
					'message' => $setup['message'] ?? 'Import setup failed',
					'logs'    => $setup['logs'] ?? array( 'Setup failed' ),
				);
			}

			// Process batch
			$result = process_batch_items_logic( $setup );

			// Finalize import
			$final_result = finalize_batch_import( $result );

			return $final_result;

		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => 'Import failed: ' . $e->getMessage(),
				'logs' => array( 'Exception: ' . $e->getMessage() ),
			);
		} finally {
			// Release import lock
			if ( ! $is_batch ) {
				delete_transient( 'puntwork_import_lock' );
			}
		}
	}
}

if ( ! function_exists( 'import_all_jobs_from_json' ) ) {
	/**
	 * Import all jobs from JSONL file (processes all batches sequentially).
	 * Used for scheduled imports that need to process the entire dataset.
	 *
	 * @param  bool $preserve_status Whether to preserve existing import status for UI polling
	 * @return array Import result data.
	 */
	function import_all_jobs_from_json( bool $preserve_status = false ): array {
		// Check for concurrent import lock
		$import_lock_key = 'puntwork_import_lock';
		if ( get_transient( $import_lock_key ) ) {
			return array(
				'success' => false,
				'message' => 'Import already running',
				'logs'    => array( 'Import already running - concurrent imports not allowed' ),
			);
		}

		// Set import lock
		set_transient( $import_lock_key, true, 1200 );

		try {
			// Check prerequisites
			$json_path = puntwork_get_combined_jsonl_path();
			if ( ! file_exists( $json_path ) ) {
				return array(
					'success' => false,
					'message' => 'Combined JSONL file not found - run feed processing first',
					'logs' => array( 'Combined JSONL file not found' ),
				);
			}

			// Get total items
			$total_items = get_json_item_count( $json_path );

			// Use Action Scheduler if available
			if ( function_exists( 'as_schedule_single_action' ) ) {
				$batch_size = 50;
				$total_batches = ceil( $total_items / $batch_size );

				// Schedule batches
				for ( $batch_index = 0; $batch_index < $total_batches; $batch_index++ ) {
					$batch_start = $batch_index * $batch_size;
					$delay = $batch_index * 5;
					as_schedule_single_action( time() + $delay, 'puntwork_process_batch', array(
						'batch_start' => $batch_start,
						'batch_size' => $batch_size,
						'batch_index' => $batch_index,
						'total_batches' => $total_batches,
						'import_id' => uniqid( 'import_', true )
					) );
				}

				return array(
					'success' => true,
					'message' => 'Import scheduled successfully - ' . $total_batches . ' batches scheduled',
					'total' => $total_items,
					'batches_scheduled' => $total_batches,
					'batch_size' => $batch_size,
					'async_mode' => true,
					'logs' => array( 'Import scheduled with Action Scheduler' ),
				);
			} else {
				// Synchronous fallback
				$batch_size = 25;
				$total_processed = 0;

				for ( $batch_start = 0; $batch_start < $total_items; $batch_start += $batch_size ) {
					if ( import_time_exceeded() ) {
						return array(
							'success' => false,
							'message' => 'Import paused due to time limit',
							'processed' => $total_processed,
							'total' => $total_items,
							'paused' => true,
						);
					}

					$batch_result = import_jobs_from_json( true, $batch_start );
					if ( $batch_result['success'] ) {
						$total_processed += $batch_result['processed'] ?? 0;
					} else {
						return array(
							'success' => false,
							'message' => 'Import failed during batch processing',
							'processed' => $total_processed,
							'total' => $total_items,
						);
					}
				}

				return array(
					'success' => true,
					'message' => 'Import completed successfully',
					'processed' => $total_processed,
					'total' => $total_items,
					'complete' => true,
				);
			}

		} finally {
			delete_transient( $import_lock_key );
		}
	}
}

/**
 * Synchronous version of import_all_jobs_from_json for fallback when ActionScheduler fails
 *
 * @param  int $expected_total_items Expected total items to process
 * @return array Import result
 */
function import_all_jobs_from_json_sync( int $expected_total_items = 0 ): array {
	// Get the combined JSONL file path
	$json_path = puntwork_get_combined_jsonl_path();

	if ( ! file_exists( $json_path ) ) {
		return array(
			'success' => false,
			'message' => 'Combined JSONL file not found for synchronous fallback',
		);
	}

	// Get actual total items from file
	$total_items = get_json_item_count( $json_path );

	// Process in batches synchronously
	$batch_size = 25;
	$total_processed = 0;

	for ( $batch_start = 0; $batch_start < $total_items; $batch_start += $batch_size ) {
		// Check for timeout
		if ( import_time_exceeded() ) {
			return array(
				'success' => false,
				'message' => 'Import paused due to time limit',
				'processed' => $total_processed,
				'total' => $total_items,
				'paused' => true,
			);
		}

		// Process batch
		$setup = prepare_import_setup( $batch_start, true );
		if ( is_wp_error( $setup ) || ( isset( $setup['success'] ) && ! $setup['success'] ) ) {
			return array(
				'success' => false,
				'message' => 'Setup failed during synchronous processing',
			);
		}

		$batch_result = process_batch_items_logic( $setup );

		if ( $batch_result['success'] ) {
			$total_processed += $batch_result['processed'] ?? 0;
		} else {
			return array(
				'success' => false,
				'message' => 'Batch processing failed during synchronous fallback',
				'processed' => $total_processed,
				'total' => $total_items,
			);
		}
	}

	return array(
		'success' => true,
		'message' => 'Synchronous import completed successfully',
		'processed' => $total_processed,
		'total' => $total_items,
		'complete' => true,
		'fallback_mode' => true,
	);
}

/**
 * Continue a paused import process
 * Called by WordPress cron when import needs to resume after timeout.
 *
 * @return void
 */
function continue_paused_import(): void {
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] Continuing paused import process' );
	}

	// Check if import is actually paused
	$status = get_option( 'job_import_status', array() );
	if ( ! isset( $status['paused'] ) || ! $status['paused'] ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] No paused import found - skipping continuation' );
		}

		return;
	}

	// Reset pause status
	$status['paused'] = false;
	unset( $status['pause_reason'] );
	$status['logs'][] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] Resuming paused import';
	update_option( 'job_import_status', $status, false );

	// Reset start time for new timeout window
	update_option( 'job_import_start_time', microtime( true ), false );

	// Continue the import
	$result = import_all_jobs_from_json( true ); // preserve status

	if ( $result['success'] ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] Paused import continuation completed successfully' );
		}
	} elseif ( $debug_mode ) {
		error_log( '[PUNTWORK] Paused import continuation failed: ' . ( $result['message'] ?? 'Unknown error' ) );
	}
}

/**
 * Start a scheduled import process
 * Called by WordPress cron after JSONL combination completes.
 *
 * @return void
 */
function start_scheduled_import(): void {
	// Check if import is already running
	$import_lock_key = 'puntwork_import_lock';
	if ( get_transient( $import_lock_key ) ) {
		// Check if the lock is stale
		$import_status = get_option( 'job_import_status', array() );
		$last_update = $import_status['last_update'] ?? 0;
		$is_complete = $import_status['complete'] ?? false;
		$time_since_update = time() - $last_update;

		if ( $is_complete || $time_since_update > 1800 ) { // 30 minutes
			delete_transient( $import_lock_key );
		} else {
			return; // Import already running
		}
	}

	// Start the import
	import_all_jobs_from_json( true );
}

// Register the continuation hook
add_action( 'puntwork_continue_import', 'continue_paused_import' );

// Register the scheduled import start hook
add_action( 'puntwork_start_scheduled_import', 'start_scheduled_import' );

// Register the batch import start hook (used by combine_jsonl_ajax)
add_action( 'puntwork_start_batch_import', __NAMESPACE__ . '\\start_batch_import' );
function start_batch_import() {
	// Start the batch import process
	import_all_jobs_from_json( true );
}

// Register the async fallback check hook
add_action( 'puntwork_check_async_fallback', __NAMESPACE__ . '\\check_async_fallback' );
function check_async_fallback( $args ) {
	// Check if import has started
	$import_status = get_option( 'job_import_status', array() );
	$processed = $import_status['processed'] ?? 0;

	if ( $processed > 0 ) {
		// Import is working, clean up
		delete_option( 'puntwork_scheduled_import_jobs' );
		return;
	}

	// Check if ActionScheduler jobs are still pending
	$jobs_pending = false;
	if ( function_exists( 'as_get_scheduled_actions' ) ) {
		$pending_jobs = as_get_scheduled_actions( array(
			'hook' => 'puntwork_process_batch',
			'status' => 'pending'
		) );
		$jobs_pending = ! empty( $pending_jobs );
	}

	if ( $jobs_pending ) {
		// Still pending, check again later
		as_schedule_single_action( time() + 30, 'puntwork_check_async_fallback', array(
			'import_id' => 'retry_' . time()
		) );
		return;
	}

	// No progress and no pending jobs - ActionScheduler failed, fall back to sync
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'puntwork_process_batch' );
	}

	delete_option( 'puntwork_scheduled_import_jobs' );

	$scheduled_jobs = get_option( 'puntwork_scheduled_import_jobs', array() );
	import_all_jobs_from_json_sync( $scheduled_jobs['total_items'] ?? 0 );
}

// Register the individual batch processing hook
add_action( 'puntwork_process_batch', __NAMESPACE__ . '\\puntwork_process_batch_handler' );
function puntwork_process_batch_handler( $args ) {
	$batch_start = $args['batch_start'] ?? 0;
	$batch_size = $args['batch_size'] ?? 50;
	$batch_index = $args['batch_index'] ?? 0;
	$total_batches = $args['total_batches'] ?? 1;

	try {
		// Load required files
		$import_files = array(
			__DIR__ . '/../batch/batch-size-management.php',
			__DIR__ . '/../import/import-setup.php',
			__DIR__ . '/../batch/batch-processing.php',
			__DIR__ . '/../import/import-finalization.php',
			__DIR__ . '/../utilities/ErrorHandler.php',
			__DIR__ . '/../exceptions/PuntworkExceptions.php',
		);

		foreach ( $import_files as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		// Prepare and process batch
		$setup = prepare_import_setup( $batch_start, true );
		if ( is_wp_error( $setup ) || ( isset( $setup['success'] ) && ! $setup['success'] ) ) {
			return;
		}

		$setup['is_action_scheduler'] = true;
		$batch_result = process_batch_items_logic( $setup );

		// Finalize if this is the last batch
		if ( $batch_index + 1 >= $total_batches ) {
			finalize_batch_import( $batch_result );
		}

	} catch ( \Exception $e ) {
		// Basic exception handling
	}
}
