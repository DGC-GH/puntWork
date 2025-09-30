<?php

/**
 * AJAX handlers for purge operations.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * AJAX handlers for purge operations
 * Handles cleanup of old/unprocessed job posts
 */

// Explicitly load required utility classes for AJAX context
require_once __DIR__ . '/../utilities/SecurityUtils.php';
require_once __DIR__ . '/../utilities/AjaxErrorHandler.php';
require_once __DIR__ . '/../utilities/PuntWorkLogger.php';

add_action( 'wp_ajax_job_import_cleanup_duplicates', __NAMESPACE__ . '\\job_import_cleanup_duplicates_ajax' );
function job_import_cleanup_duplicates_ajax() {
	PuntWorkLogger::logAjaxRequest( 'job_import_cleanup_duplicates', $_POST );

	// Use comprehensive security validation
	$validation = SecurityUtils::validateAjaxRequest( 'job_import_cleanup_duplicates', 'job_import_nonce' );
	if ( is_wp_error( $validation ) ) {
		AjaxErrorHandler::sendError( $validation );

		return;
	}

	global $wpdb;

	try {
		// Get batch parameters with validation - start with smaller batch size for dynamic adjustment
		$batch_size  = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 10;
		$offset      = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
		$is_continue = isset( $_POST['is_continue'] ) ? filter_var( $_POST['is_continue'], FILTER_VALIDATE_BOOLEAN ) : false;

		PuntWorkLogger::info(
			'Starting cleanup duplicates batch',
			PuntWorkLogger::CONTEXT_PURGE,
			array(
				'batch_size'  => $batch_size,
				'offset'      => $offset,
				'is_continue' => $is_continue,
			)
		);

		// Initialize progress tracking for first batch
		if ( ! $is_continue ) {
			update_option(
				'job_import_cleanup_progress',
				array(
					'total_processed' => 0,
					'total_deleted'   => 0,
					'current_offset'  => 0,
					'complete'        => false,
					'start_time'      => microtime( true ),
					'batch_size'      => 10, // Start with 10
					'last_batch_time' => 0,
					'logs'            => array(),
				),
				false
			);
		}

		$progress = get_option(
			'job_import_cleanup_progress',
			array(
				'total_processed' => 0,
				'total_deleted'   => 0,
				'current_offset'  => 0,
				'complete'        => false,
				'start_time'      => microtime( true ),
				'batch_size'      => 10,
				'last_batch_time' => 0,
				'logs'            => array(),
			)
		);

		// Use dynamic batch size from progress if continuing
		if ( $is_continue && isset( $progress['batch_size'] ) ) {
			$batch_size = $progress['batch_size'];
		}

		// Set lock for this batch
		$lock_start = microtime( true );
		while ( get_transient( 'job_import_cleanup_lock' ) ) {
			usleep( 50000 );
			if ( microtime( true ) - $lock_start > 30 ) {
				PuntWorkLogger::error( 'Cleanup lock timeout', PuntWorkLogger::CONTEXT_PURGE );
				AjaxErrorHandler::sendError( 'Cleanup lock timeout' );

				return;
			}
		}
		set_transient( 'job_import_cleanup_lock', true, 30 );

		$logs             = $progress['logs'];
		$deleted_count    = 0;
		$batch_start_time = microtime( true );

		// Get total count for progress calculation (only on first batch)
		if ( ! $is_continue ) {
			$total_jobs             = $wpdb->get_var(
				"
                SELECT COUNT(*) FROM {$wpdb->posts} p
                WHERE p.post_type = 'job'
                AND p.post_status IN ('draft', 'trash')
            "
			);
			$progress['total_jobs'] = $total_jobs;
			update_option( 'job_import_cleanup_progress', $progress, false );
		}

		// Get total count for progress calculation (only on first batch)
		if ( ! $is_continue ) {
			$total_jobs             = $wpdb->get_var(
				"
                SELECT COUNT(*) FROM {$wpdb->posts} p
                WHERE p.post_type = 'job'
                AND p.post_status IN ('draft', 'trash')
            "
			);
			$progress['total_jobs'] = $total_jobs;
			update_option( 'job_import_cleanup_progress', $progress, false );
		}

		// Get batch of jobs
		$batch_jobs = $wpdb->get_results(
			$wpdb->prepare(
				"
            SELECT p.ID, p.post_status, p.post_title
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'job'
            AND p.post_status IN ('draft', 'trash')
            ORDER BY p.ID
            LIMIT %d OFFSET %d
        ",
				$batch_size,
				$offset
			)
		);

		if ( empty( $batch_jobs ) ) {
			// No more jobs to process
			$progress['complete']     = true;
			$progress['end_time']     = microtime( true );
			$progress['time_elapsed'] = $progress['end_time'] - $progress['start_time'];
			update_option( 'job_import_cleanup_progress', $progress, false );
			delete_transient( 'job_import_cleanup_lock' );

			$message = "Cleanup completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} draft/trash posts";
			PuntWorkLogger::info( $message, PuntWorkLogger::CONTEXT_PURGE );

			PuntWorkLogger::logAjaxResponse(
				'job_import_cleanup_duplicates',
				array(
					'message'         => $message,
					'complete'        => true,
					'total_processed' => $progress['total_processed'],
					'total_deleted'   => $progress['total_deleted'],
					'time_elapsed'    => $progress['time_elapsed'],
					'logs_count'      => count( $logs ),
				)
			);
			AjaxErrorHandler::sendSuccess(
				array(
					'message'         => $message,
					'complete'        => true,
					'total_processed' => $progress['total_processed'],
					'total_deleted'   => $progress['total_deleted'],
					'time_elapsed'    => $progress['time_elapsed'],
					'logs'            => array_slice( $logs, -50 ),
				)
			);

			return;
		}

		// Defer term and comment counting for better performance during bulk operations
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		// Process this batch
		foreach ( $batch_jobs as $job ) {
			$result = wp_delete_post( $job->ID, true ); // Force delete
			if ( $result ) {
				++$deleted_count;
				$log_entry = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Permanently deleted ' . $job->post_status . ' job ID: ' . $job->ID . ' - ' . $job->post_title;
				$logs[]    = $log_entry;
				PuntWorkLogger::info(
					'Deleted draft/trash job',
					PuntWorkLogger::CONTEXT_PURGE,
					array(
						'job_id'      => $job->ID,
						'post_status' => $job->post_status,
						'title'       => $job->post_title,
					)
				);
			} else {
				$log_entry = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Error: Failed to delete job ID: ' . $job->ID;
				$logs[]    = $log_entry;
				PuntWorkLogger::error( 'Failed to delete job', PuntWorkLogger::CONTEXT_PURGE, array( 'job_id' => $job->ID ) );
			}
		}

		// Re-enable term and comment counting
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		// Update progress
		$progress['total_processed'] += count( $batch_jobs );
		$progress['total_deleted']   += $deleted_count;
		$progress['current_offset']   = $offset + $batch_size;
		$progress['logs']             = $logs;

		// Calculate batch processing time and adjust batch size dynamically
		$batch_end_time              = microtime( true );
		$batch_processing_time       = $batch_end_time - $batch_start_time;
		$progress['last_batch_time'] = $batch_processing_time;

		// Dynamic batch size adjustment (only for continuation batches)
		if ( $is_continue ) {
			$new_batch_size         = job_import_adjust_cleanup_batch_size( $batch_size, $batch_processing_time, count( $batch_jobs ) );
			$progress['batch_size'] = $new_batch_size;
			PuntWorkLogger::info(
				'Batch size adjusted',
				PuntWorkLogger::CONTEXT_PURGE,
				array(
					'old_batch_size'  => $batch_size,
					'new_batch_size'  => $new_batch_size,
					'processing_time' => $batch_processing_time,
					'items_processed' => count( $batch_jobs ),
				)
			);
		}

		update_option( 'job_import_cleanup_progress', $progress, false );

		delete_transient( 'job_import_cleanup_lock' );

		// Calculate progress percentage
		$progress_percentage = $progress['total_jobs'] > 0 ? round( ( $progress['total_processed'] / $progress['total_jobs'] ) * 100, 1 ) : 0;

		$message = "Batch processed: {$progress['total_processed']}/{$progress['total_jobs']} jobs ({$progress_percentage}%), deleted {$deleted_count} draft/trash posts this batch";
		PuntWorkLogger::info( $message, PuntWorkLogger::CONTEXT_PURGE );

		PuntWorkLogger::logAjaxResponse(
			'job_import_cleanup_duplicates',
			array(
				'message'             => $message,
				'complete'            => false,
				'next_offset'         => $progress['current_offset'],
				'batch_size'          => $progress['batch_size'] ?? $batch_size, // Use adjusted batch size for next batch
				'total_processed'     => $progress['total_processed'],
				'total_deleted'       => $progress['total_deleted'],
				'progress_percentage' => $progress_percentage,
				'logs_count'          => count( $logs ),
			)
		);
		AjaxErrorHandler::sendSuccess(
			array(
				'message'             => $message,
				'complete'            => false,
				'next_offset'         => $progress['current_offset'],
				'batch_size'          => $progress['batch_size'] ?? $batch_size, // Use adjusted batch size for next batch
				'total_processed'     => $progress['total_processed'],
				'total_deleted'       => $progress['total_deleted'],
				'progress_percentage' => $progress_percentage,
				'logs'                => array_slice( $logs, -20 ),
			)
		);
	} catch ( \Exception $e ) {
		delete_transient( 'job_import_cleanup_lock' );
		PuntWorkLogger::error( 'Cleanup failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_PURGE );

		PuntWorkLogger::logAjaxResponse( 'job_import_cleanup_duplicates', array( 'message' => 'Cleanup failed: ' . $e->getMessage() ), false );
		AjaxErrorHandler::sendError( 'Cleanup failed: ' . $e->getMessage() );
	}
}

add_action( 'wp_ajax_job_import_purge', __NAMESPACE__ . '\\job_import_purge_ajax' );
function job_import_purge_ajax() {
	PuntWorkLogger::logAjaxRequest( 'job_import_purge', $_POST );

	// Use comprehensive security validation
	$validation = SecurityUtils::validateAjaxRequest( 'job_import_purge', 'job_import_nonce' );
	if ( is_wp_error( $validation ) ) {
		AjaxErrorHandler::sendError( $validation );

		return;
	}

	global $wpdb;

	try {
		// Get batch parameters with validation
		$batch_size  = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 50;
		$offset      = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
		$is_continue = isset( $_POST['is_continue'] ) ? filter_var( $_POST['is_continue'], FILTER_VALIDATE_BOOLEAN ) : false;

		PuntWorkLogger::info(
			'Starting purge batch',
			PuntWorkLogger::CONTEXT_PURGE,
			array(
				'batch_size'  => $batch_size,
				'offset'      => $offset,
				'is_continue' => $is_continue,
			)
		);

		// Initialize progress tracking for first batch
		if ( ! $is_continue ) {
			update_option(
				'job_import_purge_progress',
				array(
					'total_processed' => 0,
					'total_deleted'   => 0,
					'current_offset'  => 0,
					'complete'        => false,
					'start_time'      => microtime( true ),
					'logs'            => array(),
				),
				false
			);
		}

		$progress = get_option(
			'job_import_purge_progress',
			array(
				'total_processed' => 0,
				'total_deleted'   => 0,
				'current_offset'  => 0,
				'complete'        => false,
				'start_time'      => microtime( true ),
				'logs'            => array(),
			)
		);

		// Set lock for this batch
		$lock_start = microtime( true );
		while ( get_transient( 'job_import_purge_lock' ) ) {
			usleep( 50000 );
			if ( microtime( true ) - $lock_start > 10 ) {
				PuntWorkLogger::error( 'Purge lock timeout', PuntWorkLogger::CONTEXT_PURGE );
				AjaxErrorHandler::sendError( 'Purge lock timeout' );

				return;
			}
		}
		set_transient( 'job_import_purge_lock', true, 10 );

		$processed_guids = get_option( 'job_import_processed_guids' ) ?: array();
		$logs            = $progress['logs'];

		// Check if import is complete (only on first batch)
		if ( ! $is_continue ) {
			$import_progress = get_option( 'job_import_status' ) ?: array(
				'total'     => 0,
				'processed' => 0,
				'complete'  => false,
			);

			// More permissive check - allow purge if we have processed GUIDs or if total > 0
			$processed_guids    = get_option( 'job_import_processed_guids' ) ?: array();
			$has_processed_data = ! empty( $processed_guids ) || $import_progress['total'] > 0;

			if ( ! $has_processed_data ) {
				delete_transient( 'job_import_purge_lock' );
				PuntWorkLogger::error( 'Purge skipped: no processed data found', PuntWorkLogger::CONTEXT_PURGE );
				AjaxErrorHandler::sendError( 'No import data found. Please run an import first before purging.' );

				return;
			}

			// Log the current state for debugging
			PuntWorkLogger::debug(
				'Purge check - Import progress',
				PuntWorkLogger::CONTEXT_PURGE,
				array(
					'import_progress'       => $import_progress,
					'processed_guids_count' => count( $processed_guids ),
					'has_processed_data'    => $has_processed_data,
				)
			);

			// Get total count for progress calculation
			$total_jobs             = $wpdb->get_var(
				"
                SELECT COUNT(*) FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
                WHERE p.post_type = 'job'
            "
			);
			$progress['total_jobs'] = $total_jobs;
			update_option( 'job_import_purge_progress', $progress, false );
		}

		// Get batch of jobs to check
		$batch_jobs = $wpdb->get_results(
			$wpdb->prepare(
				"
            SELECT p.ID, pm.meta_value AS guid
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
            WHERE p.post_type = 'job'
            ORDER BY p.ID
            LIMIT %d OFFSET %d
        ",
				$batch_size,
				$offset
			)
		);

		if ( empty( $batch_jobs ) ) {
			// No more jobs to process
			$progress['complete']     = true;
			$progress['end_time']     = microtime( true );
			$progress['time_elapsed'] = $progress['end_time'] - $progress['start_time'];
			update_option( 'job_import_purge_progress', $progress, false );
			delete_transient( 'job_import_purge_lock' );

			// Clean up options
			delete_option( 'job_import_processed_guids' );
			delete_option( 'job_existing_guids' );

			$message = "Purge completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} old jobs";
			PuntWorkLogger::info( $message, PuntWorkLogger::CONTEXT_PURGE );

			PuntWorkLogger::logAjaxResponse(
				'job_import_purge',
				array(
					'message'         => $message,
					'complete'        => true,
					'total_processed' => $progress['total_processed'],
					'total_deleted'   => $progress['total_deleted'],
					'time_elapsed'    => $progress['time_elapsed'],
					'logs_count'      => count( $logs ),
				)
			);
			AjaxErrorHandler::sendSuccess(
				array(
					'message'         => $message,
					'complete'        => true,
					'total_processed' => $progress['total_processed'],
					'total_deleted'   => $progress['total_deleted'],
					'time_elapsed'    => $progress['time_elapsed'],
					'logs'            => array_slice( $logs, -50 ),
				)
			);

			return;
		}

		// Defer term and comment counting for better performance during bulk operations
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		// Process this batch
		$deleted_count = 0;
		foreach ( $batch_jobs as $job ) {
			if ( ! in_array( $job->guid, $processed_guids ) ) {
				// This job is no longer in the feed, delete it
				$result = wp_delete_post( $job->ID, true ); // true = force delete, skip trash
				if ( $result ) {
					++$deleted_count;
					$log_entry = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Permanently deleted ID: ' . $job->ID . ' GUID: ' . $job->guid . ' - No longer in feed';
					$logs[]    = $log_entry;
					PuntWorkLogger::info(
						'Deleted old job',
						PuntWorkLogger::CONTEXT_PURGE,
						array(
							'job_id' => $job->ID,
							'guid'   => $job->guid,
						)
					);
				} else {
					$log_entry = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Failed to delete ID: ' . $job->ID . ' GUID: ' . $job->guid;
					$logs[]    = $log_entry;
					PuntWorkLogger::error(
						'Failed to delete job',
						PuntWorkLogger::CONTEXT_PURGE,
						array(
							'job_id' => $job->ID,
							'guid'   => $job->guid,
						)
					);
				}
			}
		}

		// Re-enable term and comment counting
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		// Update progress
		$progress['total_processed'] += count( $batch_jobs );
		$progress['total_deleted']   += $deleted_count;
		$progress['current_offset']   = $offset + $batch_size;
		$progress['logs']             = $logs;
		update_option( 'job_import_purge_progress', $progress, false );

		delete_transient( 'job_import_purge_lock' );

		// Calculate progress percentage
		$progress_percentage = $progress['total_jobs'] > 0 ? round( ( $progress['total_processed'] / $progress['total_jobs'] ) * 100, 1 ) : 0;

		$message = "Batch processed: {$progress['total_processed']}/{$progress['total_jobs']} jobs ({$progress_percentage}%), deleted {$deleted_count} old jobs this batch";
		PuntWorkLogger::info( $message, PuntWorkLogger::CONTEXT_PURGE );

		PuntWorkLogger::logAjaxResponse(
			'job_import_purge',
			array(
				'message'             => $message,
				'complete'            => false,
				'next_offset'         => $progress['current_offset'],
				'batch_size'          => $batch_size,
				'total_processed'     => $progress['total_processed'],
				'total_deleted'       => $progress['total_deleted'],
				'progress_percentage' => $progress_percentage,
				'logs_count'          => count( $logs ),
			)
		);
		AjaxErrorHandler::sendSuccess(
			array(
				'message'             => $message,
				'complete'            => false,
				'next_offset'         => $progress['current_offset'],
				'batch_size'          => $batch_size,
				'total_processed'     => $progress['total_processed'],
				'total_deleted'       => $progress['total_deleted'],
				'progress_percentage' => $progress_percentage,
				'logs'                => array_slice( $logs, -20 ),
			)
		);
	} catch ( \Exception $e ) {
		delete_transient( 'job_import_purge_lock' );
		PuntWorkLogger::error( 'Purge failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_PURGE );

		PuntWorkLogger::logAjaxResponse( 'job_import_purge', array( 'message' => 'Purge failed: ' . $e->getMessage() ), false );
		AjaxErrorHandler::sendError( 'Purge failed: ' . $e->getMessage() );
	}
}

add_action( 'wp_ajax_job_import_cleanup_continue', __NAMESPACE__ . '\\job_import_cleanup_continue_ajax' );
function job_import_cleanup_continue_ajax() {
	PuntWorkLogger::logAjaxRequest( 'job_import_cleanup_continue', $_POST );

	// Use comprehensive security validation
	$validation = SecurityUtils::validateAjaxRequest( 'job_import_cleanup_continue', 'job_import_nonce' );
	if ( is_wp_error( $validation ) ) {
		AjaxErrorHandler::sendError( $validation );

		return;
	}

	try {
		$progress = get_option( 'job_import_cleanup_progress' );
		if ( ! $progress || $progress['complete'] ) {
			PuntWorkLogger::error( 'No active cleanup operation found', PuntWorkLogger::CONTEXT_PURGE );
			AjaxErrorHandler::sendError( 'No active cleanup operation found' );

			return;
		}

		// Get batch parameters with validation
		$batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 50;

		PuntWorkLogger::info(
			'Continuing cleanup operation',
			PuntWorkLogger::CONTEXT_PURGE,
			array(
				'batch_size'     => $batch_size,
				'current_offset' => $progress['current_offset'],
			)
		);

		// Call the main cleanup function with continue parameters
		$_POST['batch_size']  = $batch_size;
		$_POST['offset']      = $progress['current_offset'];
		$_POST['is_continue'] = true;

		job_import_cleanup_duplicates_ajax();
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'Cleanup continue failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_PURGE );
		AjaxErrorHandler::sendError( 'Cleanup continue failed: ' . $e->getMessage() );
	}
}

/**
 * Adjust batch size dynamically based on processing performance.
 *
 * @param  int   $current_batch_size Current batch size
 * @param  float $processing_time    Time taken to process the batch
 * @param  int   $items_processed    Number of items processed in this batch
 * @return int New batch size
 */
function job_import_adjust_cleanup_batch_size( $current_batch_size, $processing_time, $items_processed ) {
	$target_time    = 8.0; // Target processing time per batch (seconds)
	$min_batch_size = 5;  // Minimum batch size
	$max_batch_size = 100; // Maximum batch size

	// If no items were processed, keep current batch size
	if ( $items_processed === 0 ) {
		return $current_batch_size;
	}

	// Calculate processing time per item
	$time_per_item = $processing_time / $items_processed;

	// If processing time is too long, reduce batch size
	if ( $processing_time > $target_time * 1.2 ) { // 20% over target
		$new_batch_size = max( $min_batch_size, (int) ( $current_batch_size * 0.8 ) ); // Reduce by 20%
		PuntWorkLogger::debug(
			'Reducing batch size due to slow processing',
			PuntWorkLogger::CONTEXT_PURGE,
			array(
				'old_size'        => $current_batch_size,
				'new_size'        => $new_batch_size,
				'processing_time' => $processing_time,
				'target_time'     => $target_time,
			)
		);

		return $new_batch_size;
	}

	// If processing time is comfortably under target, increase batch size
	if ( $processing_time < $target_time * 0.6 ) { // Less than 60% of target
		$new_batch_size = min( $max_batch_size, (int) ( $current_batch_size * 1.2 ) ); // Increase by 20%
		PuntWorkLogger::debug(
			'Increasing batch size due to fast processing',
			PuntWorkLogger::CONTEXT_PURGE,
			array(
				'old_size'        => $current_batch_size,
				'new_size'        => $new_batch_size,
				'processing_time' => $processing_time,
				'target_time'     => $target_time,
			)
		);

		return $new_batch_size;
	}

	// Keep current batch size if processing time is within acceptable range
	return $current_batch_size;
}
