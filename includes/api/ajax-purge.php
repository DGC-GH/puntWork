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
	// Basic error handling for debugging
	try {
		error_log( '[PUNTWORK] [CLEANUP] AJAX handler called' );

		// Check if required classes exist
		if ( ! class_exists( 'Puntwork\\SecurityUtils' ) ) {
			error_log( '[PUNTWORK] [CLEANUP] SecurityUtils class not found' );
			wp_send_json_error( 'SecurityUtils class not found' );
			return;
		}

		if ( ! class_exists( 'Puntwork\\AjaxErrorHandler' ) ) {
			error_log( '[PUNTWORK] [CLEANUP] AjaxErrorHandler class not found' );
			wp_send_json_error( 'AjaxErrorHandler class not found' );
			return;
		}

		if ( ! class_exists( 'Puntwork\\PuntWorkLogger' ) ) {
			error_log( '[PUNTWORK] [CLEANUP] PuntWorkLogger class not found' );
			wp_send_json_error( 'PuntWorkLogger class not found' );
			return;
		}

		error_log( '[PUNTWORK] [CLEANUP] All classes found, proceeding with validation' );

		// Safely log AJAX request with error handling
		try {
			// Skip heavy logging for cleanup operations to save memory
			// PuntWorkLogger::logAjaxRequest( 'job_import_cleanup_duplicates', $_POST );
		} catch ( \Throwable $e ) {
			error_log( '[PUNTWORK] [CLEANUP] Error logging AJAX request: ' . $e->getMessage() );
			// Continue without logging
		}

		// Use comprehensive security validation
		$validation = SecurityUtils::validateAjaxRequest( 'job_import_cleanup_duplicates', 'job_import_nonce' );
		if ( is_wp_error( $validation ) ) {
			error_log( '[PUNTWORK] [CLEANUP] Security validation failed: ' . $validation->get_error_message() );
			AjaxErrorHandler::sendError( $validation );
			return;
		}

		error_log( '[PUNTWORK] [CLEANUP] Security validation passed' );

		global $wpdb;

		// Test database connection with better error handling
		if ( ! $wpdb ) {
			error_log( '[PUNTWORK] [CLEANUP] Database object not available' );
			AjaxErrorHandler::sendError( 'Database connection error' );
			return;
		}

		// Check if database is ready (safely)
		$db_ready = true;
		if ( property_exists( $wpdb, 'ready' ) ) {
			$db_ready = $wpdb->ready;
		}

		if ( ! $db_ready ) {
			error_log( '[PUNTWORK] [CLEANUP] Database not ready' );
			AjaxErrorHandler::sendError( 'Database connection error' );
			return;
		}

		// Increase memory limit for cleanup operations if possible
		if ( function_exists( 'ini_set' ) && function_exists( 'wp_convert_hr_to_bytes' ) ) {
			$current_limit = ini_get( 'memory_limit' );
			if ( $current_limit && wp_convert_hr_to_bytes( $current_limit ) < 2147483648 ) {
				@ini_set( 'memory_limit', '2048M' );
				error_log( '[PUNTWORK] [CLEANUP] Increased memory limit to 2048M for cleanup' );
			}
		}

		error_log( '[PUNTWORK] [CLEANUP] Database connection OK' );

		try {
		// Get batch parameters with validation - start with very small batch size for memory safety
		$batch_size  = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 1; // Reduced to 1 for maximum memory safety
		$offset      = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
		$is_continue = isset( $_POST['is_continue'] ) ? filter_var( $_POST['is_continue'], FILTER_VALIDATE_BOOLEAN ) : false;

		error_log( '[PUNTWORK] [CLEANUP] Batch parameters: batch_size=' . $batch_size . ', offset=' . $offset . ', is_continue=' . ($is_continue ? 'true' : 'false') );

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
					'batch_size'      => 1, // Start with very small batch size
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
				'batch_size'      => 1,
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
			error_log( '[PUNTWORK] [CLEANUP] Total jobs query result: ' . ($total_jobs !== null ? $total_jobs : 'NULL') );
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
		error_log( '[PUNTWORK] [CLEANUP] Batch jobs query result: ' . (is_array($batch_jobs) ? count($batch_jobs) : 'NOT_ARRAY') . ' jobs found' );
		if ( $batch_jobs === false ) {
			error_log( '[PUNTWORK] [CLEANUP] Database error in batch jobs query: ' . $wpdb->last_error );
		}

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
					'total_jobs'      => $progress['total_jobs'],
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

		// Process this batch with memory monitoring
		$initial_memory = memory_get_usage();
		foreach ( $batch_jobs as $job ) {
			// Check memory usage before each deletion
			$current_memory = memory_get_usage();
			$memory_limit = ini_get('memory_limit');
			
			// Safe memory limit parsing with fallbacks
			if (function_exists('wp_convert_hr_to_bytes') && $memory_limit) {
				$memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit);
			} elseif (preg_match('/^(\d+)([MG])$/', $memory_limit, $matches)) {
				$value = (int)$matches[1];
				$unit = $matches[2];
				$memory_limit_bytes = $unit === 'G' ? $value * 1024 * 1024 * 1024 : $value * 1024 * 1024;
			} elseif (is_numeric($memory_limit)) {
				$memory_limit_bytes = (int)$memory_limit;
			} else {
				// Default fallback: 128MB
				$memory_limit_bytes = 128 * 1024 * 1024;
			}
			
			// Handle unlimited memory (-1) or invalid values
			if ($memory_limit_bytes <= 0) {
				$memory_limit_bytes = 128 * 1024 * 1024; // Default to 128MB
			}
			
			$memory_usage_percent = ($current_memory / $memory_limit_bytes) * 100;

			// If memory usage is over 30%, stop processing this batch early (more conservative)
			if ($memory_usage_percent > 30) {
				PuntWorkLogger::warning(
					'Memory usage too high during cleanup, stopping batch early',
					PuntWorkLogger::CONTEXT_PURGE,
					array(
						'memory_usage_percent' => round($memory_usage_percent, 1),
						'current_memory_mb' => round($current_memory / 1024 / 1024, 1),
						'memory_limit_mb' => round($memory_limit_bytes / 1024 / 1024, 1),
						'jobs_processed_in_batch' => $deleted_count,
					)
				);
				break;
			}

			// Use direct SQL deletion for memory efficiency instead of wp_delete_post()
			$result = job_import_delete_post_efficiently( $job->ID );
			if ( $result ) {
				++$deleted_count;
				$log_entry = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Permanently deleted ' . $job->post_status . ' job ID: ' . $job->ID . ' - ' . $job->post_title;
				$logs[]    = $log_entry;
				// Limit logs array to last 100 entries to prevent memory accumulation
				if (count($logs) > 100) {
					$logs = array_slice($logs, -100);
				}
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
				error_log( '[PUNTWORK] [CLEANUP] job_import_delete_post_efficiently failed for job ID: ' . $job->ID . ', post_status: ' . $job->post_status );
				$log_entry = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Error: Failed to delete job ID: ' . $job->ID;
				$logs[]    = $log_entry;
				// Limit logs array to last 100 entries to prevent memory accumulation
				if (count($logs) > 100) {
					$logs = array_slice($logs, -100);
				}
				PuntWorkLogger::error( 'Failed to delete job', PuntWorkLogger::CONTEXT_PURGE, array( 'job_id' => $job->ID ) );
			}

			// Force garbage collection to prevent memory accumulation
			if (function_exists('gc_collect_cycles')) {
				gc_collect_cycles();
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
			$new_batch_size         = job_import_adjust_cleanup_batch_size( $batch_size, $batch_processing_time, count( $batch_jobs ), $progress );
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
				'total_jobs'          => $progress['total_jobs'],
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
	} catch ( \Throwable $e ) {
		error_log( '[PUNTWORK] AJAX: Fatal error in job_import_cleanup_duplicates_ajax: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		error_log( '[PUNTWORK] AJAX: Stack trace: ' . $e->getTraceAsString() );
		wp_die( 'Internal server error', '500 Internal Server Error', array( 'response' => 500 ) );
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
		error_log( '[PUNTWORK] [CLEANUP] Starting cleanup_duplicates_ajax, database connected: ' . ($wpdb->check_connection() ? 'YES' : 'NO') );
		error_log( '[PUNTWORK] [CLEANUP] Database last error before query: ' . $wpdb->last_error );

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
	// PuntWorkLogger::logAjaxRequest( 'job_import_cleanup_continue', $_POST );

	// Use comprehensive security validation
	$validation = SecurityUtils::validateAjaxRequest( 'job_import_cleanup_continue', 'job_import_nonce' );
	if ( is_wp_error( $validation ) ) {
		AjaxErrorHandler::sendError( $validation );

		return;
	}

	global $wpdb;

	try {
		$progress = get_option( 'job_import_cleanup_progress' );
		if ( ! $progress || $progress['complete'] ) {
			PuntWorkLogger::error( 'No active cleanup operation found', PuntWorkLogger::CONTEXT_PURGE );
			AjaxErrorHandler::sendError( 'No active cleanup operation found' );

			return;
		}

		// Increase memory limit for cleanup operations if possible
		if ( function_exists( 'ini_set' ) ) {
			$current_limit = ini_get( 'memory_limit' );
			// Try to increase to 2GB if current is less
			if ( wp_convert_hr_to_bytes( $current_limit ) < 2147483648 ) {
				@ini_set( 'memory_limit', '2048M' );
				error_log( '[PUNTWORK] [CLEANUP] Increased memory limit to 2048M for cleanup continue' );
			}
		}

		// Get batch parameters with validation
		$batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 1;

		PuntWorkLogger::info(
			'Continuing cleanup operation',
			PuntWorkLogger::CONTEXT_PURGE,
			array(
				'batch_size'     => $batch_size,
				'current_offset' => $progress['current_offset'],
			)
		);

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
				$progress['current_offset']
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
				'job_import_cleanup_continue',
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
					'total_jobs'      => $progress['total_jobs'],
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

		// Process this batch with memory monitoring
		$initial_memory = memory_get_usage();
		foreach ( $batch_jobs as $job ) {
			// Check memory usage before each deletion
			$current_memory = memory_get_usage();
			$memory_limit = ini_get('memory_limit');
			
			// Safe memory limit parsing with fallbacks
			if (function_exists('wp_convert_hr_to_bytes') && $memory_limit) {
				$memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit);
			} elseif (preg_match('/^(\d+)([MG])$/', $memory_limit, $matches)) {
				$value = (int)$matches[1];
				$unit = $matches[2];
				$memory_limit_bytes = $unit === 'G' ? $value * 1024 * 1024 * 1024 : $value * 1024 * 1024;
			} elseif (is_numeric($memory_limit)) {
				$memory_limit_bytes = (int)$memory_limit;
			} else {
				// Default fallback: 128MB
				$memory_limit_bytes = 128 * 1024 * 1024;
			}
			
			// Handle unlimited memory (-1) or invalid values
			if ($memory_limit_bytes <= 0) {
				$memory_limit_bytes = 128 * 1024 * 1024; // Default to 128MB
			}
			
			$memory_usage_percent = ($current_memory / $memory_limit_bytes) * 100;

			// If memory usage is over 30%, stop processing this batch early
			if ($memory_usage_percent > 30) {
				PuntWorkLogger::warning(
					'Memory usage too high during cleanup continue, stopping batch early',
					PuntWorkLogger::CONTEXT_PURGE,
					array(
						'memory_usage_percent' => round($memory_usage_percent, 1),
						'current_memory_mb' => round($current_memory / 1024 / 1024, 1),
						'memory_limit_mb' => round($memory_limit_bytes / 1024 / 1024, 1),
						'jobs_processed_in_batch' => $deleted_count,
					)
				);
				break;
			}

			$result = job_import_delete_post_efficiently( $job->ID );
			if ( $result ) {
				++$deleted_count;
				$log_entry = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Permanently deleted ' . $job->post_status . ' job ID: ' . $job->ID . ' - ' . $job->post_title;
				$logs[]    = $log_entry;
				// Limit logs array to last 100 entries to prevent memory accumulation
				if (count($logs) > 100) {
					$logs = array_slice($logs, -100);
				}
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
				// Limit logs array to last 100 entries to prevent memory accumulation
				if (count($logs) > 100) {
					$logs = array_slice($logs, -100);
				}
				PuntWorkLogger::error( 'Failed to delete job', PuntWorkLogger::CONTEXT_PURGE, array( 'job_id' => $job->ID ) );
			}

			// Force garbage collection to prevent memory accumulation
			if (function_exists('gc_collect_cycles')) {
				gc_collect_cycles();
			}
		}

		// Re-enable term and comment counting
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		// Update progress
		$progress['total_processed'] += count( $batch_jobs );
		$progress['total_deleted']   += $deleted_count;
		$progress['current_offset']   = $progress['current_offset'] + $batch_size;
		$progress['logs']             = $logs;

		// Calculate batch processing time and adjust batch size dynamically
		$batch_end_time              = microtime( true );
		$batch_processing_time       = $batch_end_time - $batch_start_time;
		$progress['last_batch_time'] = $batch_processing_time;

		// Dynamic batch size adjustment (only for continuation batches)
		$new_batch_size         = job_import_adjust_cleanup_batch_size( $batch_size, $batch_processing_time, count( $batch_jobs ), $progress );
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

		update_option( 'job_import_cleanup_progress', $progress, false );

		delete_transient( 'job_import_cleanup_lock' );

		// Calculate progress percentage
		$progress_percentage = $progress['total_jobs'] > 0 ? round( ( $progress['total_processed'] / $progress['total_jobs'] ) * 100, 1 ) : 0;

		$message = "Batch processed: {$progress['total_processed']}/{$progress['total_jobs']} jobs ({$progress_percentage}%), deleted {$deleted_count} draft/trash posts this batch";
		PuntWorkLogger::info( $message, PuntWorkLogger::CONTEXT_PURGE );

		PuntWorkLogger::logAjaxResponse(
			'job_import_cleanup_continue',
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
				'total_jobs'          => $progress['total_jobs'],
				'total_deleted'       => $progress['total_deleted'],
				'progress_percentage' => $progress_percentage,
				'logs'                => array_slice( $logs, -20 ),
			)
		);
	} catch ( \Exception $e ) {
		delete_transient( 'job_import_cleanup_lock' );
		PuntWorkLogger::error( 'Cleanup continue failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_PURGE );
		AjaxErrorHandler::sendError( 'Cleanup continue failed: ' . $e->getMessage() );
	}
}

/**
 * Efficiently delete a post using direct SQL queries to avoid memory overhead.
 * This bypasses wp_delete_post() which loads the entire post object and all metadata.
 *
 * @param int $post_id Post ID to delete
 * @return bool True on success, false on failure
 */
function job_import_delete_post_efficiently( $post_id ) {
	// Call the centralized version from import-finalization.php (global namespace)
	if ( function_exists( '\\job_import_delete_post_efficiently' ) ) {
		return \job_import_delete_post_efficiently( $post_id );
	}

	// Fallback implementation if the global function isn't available
	global $wpdb;

	$post_id = (int) $post_id;
	if ( ! $post_id ) {
		return false;
	}

	// Start transaction for data integrity
	$wpdb->query( 'START TRANSACTION' );

	try {
		// Delete post meta
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $post_id ) );

		// Delete term relationships
		$wpdb->delete( $wpdb->term_relationships, array( 'object_id' => $post_id ) );

		// Delete comments
		$wpdb->delete( $wpdb->comments, array( 'comment_post_ID' => $post_id ) );

		// Delete comment meta for these comments
		$comment_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d",
			$post_id
		) );
		if ( ! empty( $comment_ids ) ) {
			$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN (" . implode( ',', $comment_ids ) . ")" );
		}

		// Delete revisions
		$wpdb->delete( $wpdb->posts, array(
			'post_parent' => $post_id,
			'post_type'   => 'revision'
		) );

		// Finally delete the post itself
		$result = $wpdb->delete( $wpdb->posts, array( 'ID' => $post_id ) );

		if ( $result === false ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		// Commit transaction
		$wpdb->query( 'COMMIT' );

		// Clean caches without loading post object
		wp_cache_delete( $post_id, 'posts' );
		wp_cache_delete( $post_id, 'post_meta' );

		return true;

	} catch ( Exception $e ) {
		$wpdb->query( 'ROLLBACK' );
		error_log( '[PUNTWORK] [CLEANUP] SQL error in efficient deletion: ' . $e->getMessage() );
		return false;
	}
}

/**
 * Advanced dynamic batch size adjustment for cleanup operations using exponential growth with backoff.
 * Starts from 1 item per batch and exponentially increases until performance degrades,
 * then backtracks to find the optimal batch size.
 *
 * @param int   $current_batch_size Current batch size
 * @param float $processing_time    Time taken to process the batch in seconds
 * @param int   $items_processed    Number of items actually processed
 * @param array $progress          Current progress array (passed by reference for state management)
 * @return int New batch size
 */
function job_import_adjust_cleanup_batch_size( $current_batch_size, $processing_time, $items_processed, &$progress ) {
	// If no items were processed, keep the same batch size
	if ( $items_processed == 0 ) {
		return $current_batch_size;
	}

	// Initialize optimization state if not exists
	if ( ! isset( $progress['batch_optimization'] ) ) {
		$progress['batch_optimization'] = array(
			'phase'              => 'exploring', // 'exploring', 'backtracking', 'optimizing'
			'performance_history' => array(),   // Array of [batch_size => time_per_item]
			'last_batch_size'    => 1,
			'best_batch_size'    => 1,
			'best_time_per_item' => PHP_FLOAT_MAX,
			'exploration_step'   => 0,
			'backtrack_count'    => 0,
			'stable_count'       => 0,
		);
	}

	$opt = &$progress['batch_optimization'];

	// Calculate current performance metrics
	$time_per_item = $processing_time / $items_processed;
	$max_batch_size = get_option( 'puntwork_cleanup_batch_size', 100 ); // Increased default max
	$min_batch_size = 1;

	// Store performance data
	$opt['performance_history'][$current_batch_size] = $time_per_item;
	$opt['last_batch_size'] = $current_batch_size;

	// Update best performance if this is better
	if ( $time_per_item < $opt['best_time_per_item'] ) {
		$opt['best_time_per_item'] = $time_per_item;
		$opt['best_batch_size'] = $current_batch_size;
		$opt['stable_count'] = 0; // Reset stability counter when we find better performance
	} else {
		$opt['stable_count']++;
	}

	// Determine next batch size based on current phase
	$new_batch_size = $current_batch_size;

	switch ( $opt['phase'] ) {
		case 'exploring':
			// Exponential growth phase: double the batch size each time
			$new_batch_size = min( $max_batch_size, $current_batch_size * 2 );

			// Check if we should stop exploring and start backtracking
			// If current performance is significantly worse than best performance (>20% degradation)
			if ( $time_per_item > $opt['best_time_per_item'] * 1.2 && $current_batch_size > 1 ) {
				$opt['phase'] = 'backtracking';
				$opt['backtrack_count'] = 0;
				// Go back to the best performing batch size
				$new_batch_size = $opt['best_batch_size'];
				PuntWorkLogger::info(
					'Switching to backtracking phase - performance degradation detected',
					PuntWorkLogger::CONTEXT_PURGE,
					array(
						'current_time_per_item' => round($time_per_item, 4),
						'best_time_per_item' => round($opt['best_time_per_item'], 4),
						'current_batch_size' => $current_batch_size,
						'best_batch_size' => $opt['best_batch_size'],
					)
				);
			} elseif ( $new_batch_size >= $max_batch_size ) {
				// Reached maximum batch size, switch to optimizing
				$opt['phase'] = 'optimizing';
				$new_batch_size = $opt['best_batch_size'];
				PuntWorkLogger::info(
					'Switching to optimizing phase - reached maximum batch size',
					PuntWorkLogger::CONTEXT_PURGE,
					array(
						'max_batch_size' => $max_batch_size,
						'best_batch_size' => $opt['best_batch_size'],
					)
				);
			}
			break;

		case 'backtracking':
			// Backtracking phase: try smaller increments around the best batch size
			$opt['backtrack_count']++;

			if ( $opt['backtrack_count'] >= 3 ) {
				// After 3 backtrack attempts, switch to optimizing
				$opt['phase'] = 'optimizing';
				$new_batch_size = $opt['best_batch_size'];
				PuntWorkLogger::info(
					'Switching to optimizing phase after backtracking',
					PuntWorkLogger::CONTEXT_PURGE,
					array(
						'backtrack_attempts' => $opt['backtrack_count'],
						'optimal_batch_size' => $opt['best_batch_size'],
					)
				);
			} else {
				// Try a batch size between current and best
				$range = abs( $current_batch_size - $opt['best_batch_size'] );
				if ( $range > 1 ) {
					// Try halfway between current and best
					$new_batch_size = (int) round( ( $current_batch_size + $opt['best_batch_size'] ) / 2 );
				} else {
					// Try small variations around the best
					$variation = $opt['backtrack_count'] % 2 == 0 ? 1 : -1;
					$new_batch_size = max( $min_batch_size, min( $max_batch_size, $opt['best_batch_size'] + $variation ) );
				}
			}
			break;

		case 'optimizing':
		default:
			// Optimization phase: stay at the best batch size with small adjustments
			$new_batch_size = $opt['best_batch_size'];

			// Occasionally test if we can do better (every 10 batches)
			if ( $opt['stable_count'] > 0 && $opt['stable_count'] % 10 == 0 ) {
				// Test a slightly larger batch size
				$test_batch_size = min( $max_batch_size, $opt['best_batch_size'] + 1 );
				if ( $test_batch_size != $opt['best_batch_size'] ) {
					$new_batch_size = $test_batch_size;
					PuntWorkLogger::info(
						'Testing potential optimization',
						PuntWorkLogger::CONTEXT_PURGE,
						array(
							'current_best' => $opt['best_batch_size'],
							'test_batch_size' => $test_batch_size,
							'stable_batches' => $opt['stable_count'],
						)
					);
				}
			}
			break;
	}

	// Ensure batch size stays within bounds
	$new_batch_size = max( $min_batch_size, min( $max_batch_size, $new_batch_size ) );

	// Log optimization progress
	if ( $new_batch_size != $current_batch_size ) {
		PuntWorkLogger::info(
			'Batch size optimization',
			PuntWorkLogger::CONTEXT_PURGE,
			array(
				'phase' => $opt['phase'],
				'old_batch_size' => $current_batch_size,
				'new_batch_size' => $new_batch_size,
				'best_batch_size' => $opt['best_batch_size'],
				'current_time_per_item' => round($time_per_item, 4),
				'best_time_per_item' => round($opt['best_time_per_item'], 4),
				'stable_count' => $opt['stable_count'],
			)
		);
	}

	return $new_batch_size;
}
