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
		'formatted_date' => wp_date( 'M j, Y H:i', $details['timestamp'] ),
		'duration'       => $details['duration'],
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
		'formatted_date' => wp_date( 'M j, Y H:i', $details['timestamp'] ),
		'duration'       => $details['duration'],
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
 * Cleanup scheduled imports on plugin deactivation.
 */
function cleanup_scheduled_imports() {
	wp_clear_scheduled_hook( 'puntwork_scheduled_import' );
	delete_option( 'puntwork_import_schedule' );
	delete_option( 'puntwork_last_import_run' );
	delete_option( 'puntwork_last_import_details' );
	delete_option( 'puntwork_import_run_history' );
}
