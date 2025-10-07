<?php

/**
 * History and logging functionality for scheduling
 * Handles import run history, logging, and cleanup operations.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mark existing jobs as processed when no feeds are configured
 * Used when we want to show existing jobs as "processed" without re-importing.
 */
function mark_existing_jobs_as_processed() {
	global $wpdb;

	try {
		// Count existing published jobs
		$job_count = $wpdb->get_var(
			"
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'job' 
            AND p.post_status = 'publish'
        "
		);

		if ( $job_count == null ) {
			$job_count = 0;
		}

		error_log( '[PUNTWORK] Found ' . $job_count . ' existing published jobs' );

		return array(
			'success' => true,
			'message' => 'Existing jobs marked as processed',
			'total'   => (int) $job_count,
		);
	} catch ( \Exception $e ) {
		error_log( '[PUNTWORK] Failed to count existing jobs: ' . $e->getMessage() );

		return array(
			'success' => false,
			'message' => $e->getMessage(),
			'total'   => 0,
		);
	}
}

/**
 * Log a scheduled run to history.
 */
function log_scheduled_run( $details, $test_mode = false, $trigger_type = 'scheduled' ) {
	$run_entry = array(
		'timestamp'      => $details['timestamp'],
		'formatted_date' => date( 'M j, Y H:i', $details['timestamp'] ),
		'duration'       => $details['duration'] ?? 0,
		'success'        => $details['success'],
		'processed'      => $details['processed'],
		'total'          => $details['total'],
		'published'      => $details['published'],
		'updated'        => $details['updated'],
		'skipped'        => $details['skipped'],
		'error_message'  => $details['error_message'] ?? '',
		'test_mode'      => $test_mode,
		'trigger_type'   => $trigger_type,
	);

	// Get existing history
	$history = get_option( 'puntwork_import_run_history', array() );

	// Add new entry to the beginning
	array_unshift( $history, $run_entry );

	// Keep only the last 20 runs to prevent the option from growing too large
	if ( count( $history ) > 20 ) {
		$history = array_slice( $history, 0, 20 );
	}

	update_option( 'puntwork_import_run_history', $history );

	// Log to debug log
	$status = $details['success'] ? 'SUCCESS' : 'FAILED';
	$mode   = $test_mode ? ' (TEST)' : '';
	error_log(
		sprintf(
			'[PUNTWORK] Scheduled import %s%s - Duration: %.2fs, Processed: %d/%d, Published: %d, Updated: %d, Skipped: %d',
			$status,
			$mode,
			$details['duration'],
			$details['processed'],
			$details['total'],
			$details['published'],
			$details['updated'],
			$details['skipped']
		)
	);
}

/**
 * Log a manual import run to history.
 */
function log_manual_import_run( $details ) {
	$run_entry = array(
		'timestamp'      => $details['timestamp'],
		'formatted_date' => date( 'M j, Y H:i', $details['timestamp'] ),
		'duration'       => $details['duration'] ?? 0,
		'success'        => $details['success'],
		'processed'      => $details['processed'],
		'total'          => $details['total'],
		'published'      => $details['published'],
		'updated'        => $details['updated'],
		'skipped'        => $details['skipped'],
		'error_message'  => $details['error_message'] ?? '',
		'test_mode'      => false,
		'trigger_type'   => 'manual',
	);

	// Get existing history
	$history = get_option( 'puntwork_import_run_history', array() );

	// Add new entry to the beginning
	array_unshift( $history, $run_entry );

	// Keep only the last 20 runs to prevent the option from growing too large
	if ( count( $history ) > 20 ) {
		$history = array_slice( $history, 0, 20 );
	}

	update_option( 'puntwork_import_run_history', $history );

	// Log to debug log
	$status = $details['success'] ? 'SUCCESS' : 'FAILED';
	error_log(
		sprintf(
			'[PUNTWORK] Manual import %s - Duration: %.2fs, Processed: %d/%d, Published: %d, Updated: %d, Skipped: %d',
			$status,
			$details['duration'],
			$details['processed'],
			$details['total'],
			$details['published'],
			$details['updated'],
			$details['skipped']
		)
	);
}

/**
 * Run a scheduled import
 * This function is called by the cron system to execute automated imports.
 */
function run_scheduled_import( $test_mode = false, $trigger = 'scheduled' ) {
	error_log( '[PUNTWORK] [IMPORT] run_scheduled_import called with test_mode=' . ($test_mode ? 'true' : 'false') . ', trigger=' . $trigger );
	
	// Set test mode if requested
	if ( $test_mode ) {
		update_option( 'puntwork_test_mode', true );
	}

	// Log the import start
	log_manual_import_run( array(
		'timestamp'     => time(),
		'duration'      => 0,
		'success'       => false, // Will be updated
		'processed'     => 0,
		'total'         => 0,
		'published'     => 0,
		'updated'       => 0,
		'skipped'       => 0,
		'error_message' => '',
	) );

	try {
		error_log( '[PUNTWORK] [IMPORT] Starting import process' );
		
		// For manual imports, process feeds first
		if ( $trigger === 'manual' ) {
			error_log( '[PUNTWORK] [IMPORT] Manual import detected - processing feeds first' );

			// Process feeds to create combined JSONL file
			$feed_result = process_feeds_to_jsonl();

			if ( ! $feed_result['success'] ) {
				throw new \Exception( 'Feed processing failed: ' . $feed_result['message'] );
			}

			error_log( '[PUNTWORK] [IMPORT] Feed processing completed for manual import' );
		}

		error_log( '[PUNTWORK] [IMPORT] Starting job import from JSON' );
		
		// Run the import
		$result = import_all_jobs_from_json();

		error_log( '[PUNTWORK] [IMPORT] Job import completed with result: ' . json_encode( $result ) );

		// Update the last run time
		update_option( 'puntwork_last_import_run', time() );

		// Log the completion
		if ( $result['success'] ) {
			log_scheduled_run( array(
				'timestamp'     => time(),
				'duration'      => 0,
				'success'       => true,
				'processed'     => $result['processed'] ?? 0,
				'total'         => $result['total'] ?? 0,
				'published'     => $result['published'] ?? 0,
				'updated'       => $result['updated'] ?? 0,
				'skipped'       => $result['skipped'] ?? 0,
				'error_message' => '',
			), $test_mode, $trigger );
		}

		error_log( '[PUNTWORK] [IMPORT] Import process completed successfully' );
		return $result;

	} catch ( \Exception $e ) {
		error_log( '[PUNTWORK] [IMPORT] Import failed with exception: ' . $e->getMessage() );
		error_log( '[PUNTWORK] [IMPORT] Exception stack trace: ' . $e->getTraceAsString() );
		
		// Log the error
		log_scheduled_run( array(
			'timestamp'     => time(),
			'duration'      => 0,
			'success'       => false,
			'processed'     => 0,
			'total'         => 0,
			'published'     => 0,
			'updated'       => 0,
			'skipped'       => 0,
			'error_message' => $e->getMessage(),
		), $test_mode, $trigger );

		return array(
			'success' => false,
			'message' => 'Scheduled import failed: ' . $e->getMessage(),
		);
	}
}
