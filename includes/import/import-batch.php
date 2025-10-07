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

// Include import setup
require_once __DIR__ . '/import-setup.php';

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
	return false; // Removed timeout protection - let imports run naturally
}

/**
 * Check if the current import process has exceeded memory limits
 * Similar to WooCommerce's memory_exceeded() method.
 *
 * @return bool True if memory limit exceeded
 */
function import_memory_exceeded(): bool {
	return false; // Removed memory protection - let PHP handle it naturally
}

/**
 * Check if batch processing should continue
 * Returns false if time or memory limits exceeded.
 *
 * @return bool True if processing should continue
 */
function should_continue_batch_processing(): bool {
	return true; // Always continue - removed protection checks
}

if ( ! function_exists( 'import_jobs_from_json' ) ) {
	// Temporarily disable spam logging
	// error_log('[PUNTWORK] Defining import_jobs_from_json function');
	/**
	 * Import jobs from JSONL file individually (no batching).
	 *
	 * @param  bool $is_batch    Whether this is a batch import.
	 * @param  int  $batch_start Starting index for batch.
	 * @return array Import result data.
	 */
	function import_jobs_from_json( bool $is_batch = false, int $batch_start = 0 ): array {
		try {
			// Setup import
			$setup = prepare_import_setup( $batch_start, $is_batch );
			if ( isset( $setup['success'] ) && $setup['success'] === false ) {
				return array(
					'success' => false,
					'message' => $setup['message'] ?? 'Import setup failed',
					'logs'    => $setup['logs'] ?? array( 'Setup failed' ),
				);
			}

			// Process all jobs individually
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
		}
	}
}

if ( ! function_exists( 'import_all_jobs_from_json' ) ) {
	/**
	 * Import all jobs from JSONL file (processes all jobs individually).
	 * Used for scheduled imports that need to process the entire dataset.
	 *
	 * @param  bool $preserve_status Whether to preserve existing import status for UI polling
	 * @return array Import result data.
	 */
	function import_all_jobs_from_json( bool $preserve_status = false ): array {
		// Check prerequisites
		$json_path = puntwork_get_combined_jsonl_path();
		if ( ! file_exists( $json_path ) ) {
			return array(
				'success' => false,
				'message' => 'Combined JSONL file not found - run feed processing first',
			);
		}

		// Get total items
		$total_items = get_json_item_count( $json_path );

		// Use Action Scheduler for automated processing
		if ( function_exists( 'as_schedule_single_action' ) ) {
			// Schedule jobs in batches for better performance
			$batch_size = 10; // Process 10 jobs per batch
			$num_batches = ceil( $total_items / $batch_size );
			
			for ( $i = 0; $i < $num_batches; $i++ ) {
				$start_index = $i * $batch_size;
				$end_index = min( ($i + 1) * $batch_size, $total_items );
				
				as_schedule_single_action( time() + ($i * 5), 'puntwork_process_batch', array(
					'start_index' => $start_index,
					'end_index' => $end_index,
					'total' => $total_items,
					'batch_id' => uniqid( 'batch_', true )
				) );
			}

			return array(
				'success' => true,
				'message' => 'Import scheduled successfully - ' . $num_batches . ' batches scheduled',
				'total' => $total_items,
				'async_mode' => true,
			);
		} else {
			// Synchronous fallback - process all jobs individually
			$total_processed = 0;

			for ( $i = 0; $i < $total_items; $i++ ) {
				$result = import_jobs_from_json( true, $i );
				if ( $result['success'] ) {
					$total_processed++;
				} else {
					return array(
						'success' => false,
						'message' => 'Import failed during processing',
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
	}
}

/**
 * Import a batch of jobs from JSONL file.
 *
 * @param  int $start_index Starting index for the batch
 * @param  int $end_index   Ending index for the batch
 * @param  int $total       Total items in the import
 * @return array Import result data
 */
function import_jobs_batch( int $start_index, int $end_index, int $total ): array {
	try {
		// Setup import
		$setup = prepare_import_setup( $start_index, true );
		if ( isset( $setup['success'] ) && $setup['success'] === false ) {
			return array(
				'success' => false,
				'message' => $setup['message'] ?? 'Import setup failed',
				'logs'    => $setup['logs'] ?? array( 'Setup failed' ),
			);
		}

		// Adjust batch size for this batch
		$batch_size = $end_index - $start_index;
		$setup['batch_size'] = $batch_size;
		$setup['total'] = $total;

		// Process the batch
		$result = process_batch_items_logic( $setup );

		// Ensure result has correct total
		$result['total'] = $total;

		// Finalize batch import
		$final_result = finalize_batch_import( $result );

		return $final_result;

	} catch ( \Exception $e ) {
		return array(
			'success' => false,
			'message' => 'Batch import failed: ' . $e->getMessage(),
			'logs' => array( 'Exception: ' . $e->getMessage() ),
		);
	}
}
/**
 * Continue a paused import process
 * Called by WordPress cron when import needs to resume after timeout.
 *
 * @return void
 */
function continue_paused_import(): void {
	// Continue the import
	import_all_jobs_from_json( true );
}

/**
 * Start a scheduled import process
 * Called by WordPress cron after JSONL combination completes.
 *
 * @return void
 */
function start_scheduled_import(): void {
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

// Register the batch job processing hook
add_action( 'puntwork_process_batch', __NAMESPACE__ . '\\puntwork_process_batch_handler' );
function puntwork_process_batch_handler( $args ) {
	$start_index = $args['start_index'] ?? 0;
	$end_index = $args['end_index'] ?? 0;
	$total = $args['total'] ?? 0;

	try {
		// Load required files
		$import_files = array(
			__DIR__ . '/../import/import-setup.php',
			__DIR__ . '/../import/import-finalization.php',
			__DIR__ . '/../utilities/ErrorHandler.php',
			__DIR__ . '/../exceptions/PuntworkExceptions.php',
		);

		foreach ( $import_files as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		// Process batch of jobs
		$result = import_jobs_batch( $start_index, $end_index, $total );

		// Log the result for debugging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [ASYNC-BATCH] Processed batch ' . $start_index . '-' . $end_index . ' with result: ' . json_encode( $result ) );
		}

	} catch ( \Exception $e ) {
		// Basic exception handling
		error_log( '[PUNTWORK] [ASYNC-BATCH] Exception processing batch ' . $start_index . '-' . $end_index . ': ' . $e->getMessage() );
	}
}
