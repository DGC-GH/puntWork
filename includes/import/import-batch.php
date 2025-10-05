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

		// Define import lock key
		$import_lock_key = 'puntwork_import_lock';

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [IMPORT-ENTRY] ===== IMPORT_JOBS_FROM_JSON ENTRY POINT =====' );
			error_log( '[PUNTWORK] [IMPORT-ENTRY] import_jobs_from_json ENTRY POINT - is_batch=' . ( $is_batch ? 'true' : 'false' ) . ', batch_start=' . $batch_start );
			error_log( '[PUNTWORK] [IMPORT-ENTRY] Memory usage at start: ' . memory_get_usage( true ) . ' bytes' );
			error_log( '[PUNTWORK] [IMPORT-ENTRY] Peak memory usage: ' . memory_get_peak_usage( true ) . ' bytes' );
			error_log( '[PUNTWORK] [IMPORT-ENTRY] PHP version: ' . PHP_VERSION );
			error_log( '[PUNTWORK] [IMPORT-ENTRY] WordPress version: ' . get_bloginfo( 'version' ) );
			error_log( '[PUNTWORK] [IMPORT-ENTRY] Current user: ' . get_current_user_id() );
			error_log( '[PUNTWORK] [IMPORT-ENTRY] Server: ' . $_SERVER['SERVER_SOFTWARE'] ?? 'unknown' );
			error_log( '[PUNTWORK] [IMPORT-ENTRY] Request method: ' . $_SERVER['REQUEST_METHOD'] ?? 'unknown' );
			error_log( '[PUNTWORK] [IMPORT-ENTRY] Timestamp: ' . date( 'Y-m-d H:i:s T' ) );
		}

		try {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [IMPORT-START] Starting import_jobs_from_json with is_batch=' . ( $is_batch ? 'true' : 'false' ) . ', batch_start=' . $batch_start );
			}

			// Check for concurrent import lock with recovery
			$result = \Puntwork\ErrorHandler::executeWithRecovery(
				'import_lock_check',
				function() use ( $import_lock_key, $debug_mode, $is_batch ) {
					// Skip lock check for batch processing (lock already set by caller)
					if ( $is_batch ) {
						if ( $debug_mode ) {
							error_log( '[PUNTWORK] [IMPORT-LOCK] Skipping lock check for batch processing' );
						}
						return true;
					}

					if ( get_transient( $import_lock_key ) ) {
						if ( $debug_mode ) {
							error_log( '[PUNTWORK] [IMPORT-LOCK] Import lock detected: ' . $import_lock_key );
						}

						// Check if the lock is stale (import status shows complete or last update > 30 minutes ago)
						$import_status = get_option( 'job_import_status', array() );
						if ( $debug_mode ) {
							error_log( '[PUNTWORK] [IMPORT-LOCK] Current import status: ' . json_encode( $import_status ) );
						}
						$is_stale = false;

						if ( ! empty( $import_status ) ) {
							$last_update       = $import_status['last_update'] ?? 0;
							$is_complete       = $import_status['complete'] ?? false;
							$time_since_update = time() - $last_update;
							if ( $debug_mode ) {
								error_log( '[PUNTWORK] [IMPORT-LOCK] Lock check: last_update=' . $last_update . ', is_complete=' . ( $is_complete ? 'true' : 'false' ) . ', time_since_update=' . $time_since_update . 's' );
							}

							if ( $is_complete || $time_since_update > 1800 ) { // 30 minutes
								$is_stale = true;
								delete_transient( $import_lock_key );
								if ( $debug_mode ) {
									error_log( '[PUNTWORK] [IMPORT-LOCK] Cleared stale import lock (complete: ' . ( $is_complete ? 'yes' : 'no' ) . ', time since update: ' . $time_since_update . 's)' );
								}
							}
						} else {
							if ( $debug_mode ) {
								error_log( '[PUNTWORK] [IMPORT-LOCK] No import status found, considering lock stale' );
							}
							$is_stale = true;
							delete_transient( $import_lock_key );
						}

						if ( ! $is_stale ) {
							throw new \Puntwork\Exceptions\ImportException( 'Import already running - concurrent imports not allowed' );
						}
					}

					// Set import lock
					set_transient( $import_lock_key, true, 1200 ); // 20 minutes timeout
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [IMPORT-LOCK] Import lock set successfully' );
					}

					return true;
				},
				array( 'lock_key' => $import_lock_key )
			);

			if ( ! $result ) {
				return array(
					'success' => false,
					'message' => 'Import already running',
					'logs'    => array( 'Import already running - concurrent imports not allowed' ),
				);
			}

			// Execute import setup with error recovery
			$setup = \Puntwork\ErrorHandler::executeWithRecovery(
				'import_setup',
				function() use ( $batch_start, $debug_mode, $is_batch ) {
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [IMPORT-SETUP] Calling prepare_import_setup...' );
					}

					$setup = prepare_import_setup( $batch_start, $is_batch );
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [IMPORT-SETUP] prepare_import_setup returned: ' . json_encode( $setup ) );
						error_log( '[PUNTWORK] [IMPORT-SETUP] isset(setup[success]) = ' . ( isset( $setup['success'] ) ? 'true' : 'false' ) );
					}

					// Add setup completion log
					error_log( '[PUNTWORK] [SETUP-COMPLETE] Setup completed - Feeds: ' . ( $setup['feed_count'] ?? 'unknown' ) . ', Batch size: ' . ( $setup['batch_size'] ?? 'unknown' ) . ', GUID cache: initialized' );

					if ( is_wp_error( $setup ) ) {
						throw new \Puntwork\Exceptions\ImportException( 'Setup failed: ' . $setup->get_error_message() );
					}

					return $setup;
				},
				array( 'batch_start' => $batch_start )
			);

			if ( ! $setup ) {
				return array(
					'success' => false,
					'message' => 'Import setup failed',
					'logs'    => array( 'Setup failed with unrecoverable error' ),
				);
			}

			if ( isset( $setup['success'] ) ) {
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [IMPORT-EARLY] EARLY RETURN - setup returned success/complete' );
					error_log( '[PUNTWORK] [IMPORT-EARLY] Early return details: ' . json_encode( $setup ) );
				}

				return $setup; // Early return for empty or completed cases
			}

			// Execute batch processing with error recovery
			$result = \Puntwork\ErrorHandler::executeWithRecovery(
				'batch_processing',
				function() use ( $setup, $debug_mode ) {
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [IMPORT-PROCESS] Setup successful, calling process_batch_items_logic...' );
					}
					$result = process_batch_items_logic( $setup );
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [IMPORT-PROCESS] process_batch_items_logic completed, success=' . ( isset( $result['success'] ) ? $result['success'] : 'not set' ) );
						error_log(
							'[PUNTWORK] [IMPORT-PROCESS] process_batch_items_logic result summary: ' . json_encode(
								array(
									'success'    => $result['success'] ?? false,
									'processed'  => $result['processed'] ?? 0,
									'total'      => $result['total'] ?? 0,
									'published'  => $result['published'] ?? 0,
									'updated'    => $result['updated'] ?? 0,
									'skipped'    => $result['skipped'] ?? 0,
									'complete'   => $result['complete'] ?? false,
									'logs_count' => isset( $result['logs'] ) ? count( $result['logs'] ) : 0,
								)
							)
						);
					}
					return $result;
				},
				array( 'setup' => $setup )
			);

			if ( ! $result ) {
				return array(
					'success' => false,
					'message' => 'Batch processing failed with unrecoverable error',
					'logs'    => array( 'Batch processing failed' ),
				);
			}

			// Execute finalization with error recovery
			$final_result = \Puntwork\ErrorHandler::executeWithRecovery(
				'batch_finalization',
				function() use ( $result, $debug_mode ) {
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [IMPORT-FINALIZE] Calling finalize_batch_import...' );
					}
					$final_result = finalize_batch_import( $result );
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [IMPORT-FINALIZE] finalize_batch_import completed' );
						error_log(
							'[PUNTWORK] [IMPORT-FINALIZE] Final result summary: ' . json_encode(
								array(
									'success'   => $final_result['success'] ?? false,
									'processed' => $final_result['processed'] ?? 0,
									'total'     => $final_result['total'] ?? 0,
									'published' => $final_result['published'] ?? 0,
									'updated'   => $final_result['updated'] ?? 0,
									'skipped'   => $final_result['skipped'] ?? 0,
									'complete'  => $final_result['complete'] ?? false,
								)
							)
						);

						error_log( '[PUNTWORK] [IMPORT-COMPLETE] Import process completed successfully' );
						error_log( '[PUNTWORK] [IMPORT-DEBUG] == PUNTWORK IMPORT DEBUG: import_jobs_from_json COMPLETED ==' );
					}
					return $final_result;
				},
				array( 'result' => $result )
			);

			if ( ! $final_result ) {
				return array(
					'success' => false,
					'message' => 'Batch finalization failed with unrecoverable error',
					'logs'    => array( 'Finalization failed' ),
				);
			}

			return $final_result;
		} catch ( \Puntwork\Exceptions\PuntworkException $e ) {
			// Handle custom PuntWork exceptions with enhanced logging
			\Puntwork\ErrorHandler::logError( array(
				'level' => \Puntwork\ErrorHandler::ERROR_LEVEL_ERROR,
				'type' => $e->getErrorType(),
				'message' => $e->getDetailedMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'context' => array_merge( $e->getContext(), array(
					'operation' => 'import_jobs_from_json',
					'is_batch' => $is_batch,
					'batch_start' => $batch_start
				) ),
				'recovery_suggestions' => $e->getRecoverySuggestions()
			) );

			return array(
				'success' => false,
				'message' => 'Import failed: ' . $e->getMessage(),
				'logs' => array( 'Exception: ' . $e->getMessage() ),
				'recovery_suggestions' => $e->getRecoverySuggestions()
			);
		} catch ( \Exception $e ) {
			// Handle standard exceptions
			\Puntwork\ErrorHandler::logError( array(
				'level' => \Puntwork\ErrorHandler::ERROR_LEVEL_ERROR,
				'type' => \Puntwork\ErrorHandler::ERROR_TYPE_SYSTEM,
				'message' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTraceAsString(),
				'context' => array(
					'operation' => 'import_jobs_from_json',
					'is_batch' => $is_batch,
					'batch_start' => $batch_start
				)
			) );

			return array(
				'success' => false,
				'message' => 'Import failed: ' . $e->getMessage(),
				'logs' => array( 'Exception: ' . $e->getMessage() ),
			);
		} catch ( \Throwable $e ) {
			// Handle fatal errors
			\Puntwork\ErrorHandler::logError( array(
				'level' => \Puntwork\ErrorHandler::ERROR_LEVEL_CRITICAL,
				'type' => \Puntwork\ErrorHandler::ERROR_TYPE_SYSTEM,
				'message' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTraceAsString(),
				'context' => array(
					'operation' => 'import_jobs_from_json',
					'is_batch' => $is_batch,
					'batch_start' => $batch_start
				)
			) );

			return array(
				'success' => false,
				'message' => 'Import failed with fatal error: ' . $e->getMessage(),
				'logs' => array( 'Fatal error: ' . $e->getMessage() ),
			);
		} finally {
			// Release import lock (only for non-batch calls)
			if ( ! $is_batch ) {
				delete_transient( 'puntwork_import_lock' );
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [IMPORT-LOCK] Import lock released' );
				}
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
		$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

		$start_time               = microtime( true );
		$total_processed          = 0;
		$total_published          = 0;
		$total_updated            = 0;
		$total_skipped            = 0;
		$total_duplicates_drafted = 0;
		$all_logs                 = array();
		$batch_count              = 0;
		$total_items              = 0;

		// Check for concurrent import lock
		$import_lock_key = 'puntwork_import_lock';
		if ( get_transient( $import_lock_key ) ) {
			// Check if the lock is stale (import status shows complete or last update > 30 minutes ago)
			$import_status = get_option( 'job_import_status', array() );
			$is_stale      = false;

			if ( ! empty( $import_status ) ) {
				$last_update       = $import_status['last_update'] ?? 0;
				$is_complete       = $import_status['complete'] ?? false;
				$time_since_update = time() - $last_update;

				if ( $is_complete || $time_since_update > 1800 ) { // 30 minutes
					$is_stale = true;
					delete_transient( $import_lock_key );
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [IMPORT-LOCK] Cleared stale import lock (complete: ' . ( $is_complete ? 'yes' : 'no' ) . ', time since update: ' . $time_since_update . 's)' );
					}
				}
			}

			if ( ! $is_stale ) {
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] Import already running - skipping concurrent import' );
				}

				return array(
					'success' => false,
					'message' => 'Import already running',
					'logs'    => array( 'Import already running - concurrent imports not allowed' ),
				);
			}
		}

		// Set import lock
		set_transient( $import_lock_key, true, 1200 ); // 20 minutes timeout
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] Import lock set for import_all_jobs_from_json' );
		}

		if ( $debug_mode ) {
			error_log(
				'[PUNTWORK] import_all_jobs_from_json started with preserve_status=' .
				( $preserve_status ? 'true' : 'false' )
			);
			error_log( '[PUNTWORK] Action Scheduler available: ' . ( function_exists( 'as_schedule_single_action' ) ? 'YES' : 'NO' ) );
		}

		try {
			// Check prerequisites before starting import
			$json_path = puntwork_get_combined_jsonl_path();

			// Ensure combined JSONL file exists
			if ( ! file_exists( $json_path ) ) {
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [IMPORT-PREREQ] Combined JSONL file not found: ' . $json_path );
					error_log( '[PUNTWORK] [IMPORT-PREREQ] Checking for individual feed files...' );

					// Check if there are individual feed files that need to be combined
					$feed_files = glob( puntwork_get_feeds_directory() . '*.jsonl' );
					$individual_feeds = array_filter( $feed_files, function( $file ) {
						return basename( $file ) !== 'combined-jobs.jsonl';
					} );

					error_log( '[PUNTWORK] [IMPORT-PREREQ] Found ' . count( $individual_feeds ) . ' individual feed files' );
					if ( ! empty( $individual_feeds ) ) {
						error_log( '[PUNTWORK] [IMPORT-PREREQ] Individual feeds exist but combined file missing - automatically combining feeds' );

						// Automatically combine the feeds
						$feeds = get_feeds();
						$import_logs = array(); // Reset logs for combination
						combine_jsonl_files( $feeds, puntwork_get_feeds_directory(), 0, $import_logs );

						// Check if combination was successful
						if ( ! file_exists( $json_path ) || filesize( $json_path ) === 0 ) {
							error_log( '[PUNTWORK] [IMPORT-PREREQ] Automatic feed combination failed' );
							return array(
								'success' => false,
								'message' => 'Combined JSONL file not found and automatic combination failed. Please run feed processing to download and convert feeds to JSONL format.',
								'logs' => array( 'Combined JSONL file not found - automatic combination failed' ),
							);
						}

						error_log( '[PUNTWORK] [IMPORT-PREREQ] Automatic feed combination completed successfully' );
					} else {
						error_log( '[PUNTWORK] [IMPORT-PREREQ] No feed files found - feeds may need to be processed first' );
					}
				}

				// Check again after potential automatic combination
				if ( ! file_exists( $json_path ) ) {
					return array(
						'success' => false,
						'message' => 'Combined JSONL file not found - feeds may need to be processed first. Run feed processing to download and convert feeds to JSONL format, then combine them.',
						'logs' => array( 'Combined JSONL file not found - run feed processing first' ),
					);
				}
			}

			// Ensure combined file is not empty
			if ( filesize( $json_path ) === 0 ) {
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [IMPORT-PREREQ] Combined JSONL file exists but is empty: ' . $json_path );
				}

				return array(
					'success' => false,
					'message' => 'Combined JSONL file is empty - feeds may need to be processed first',
					'logs' => array( 'Combined JSONL file is empty - run feed processing first' ),
				);
			}

			// Get total items first
			$total_items = get_json_item_count( $json_path );

			// Check if Action Scheduler is available for async processing
			if ( function_exists( 'as_schedule_single_action' ) ) {
				// Use Action Scheduler for async batch processing
				// Use smaller batches for Action Scheduler to prevent memory exhaustion
				$batch_size = 50; // Reduced from 200 to prevent memory issues in individual jobs

				// Calculate total number of batches needed
				$total_batches = ceil( $total_items / $batch_size );

				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [IMPORT-INIT] Total items: ' . $total_items . ', Batch size: ' . $batch_size . ', Total batches: ' . $total_batches );
					error_log( '[PUNTWORK] [IMPORT-INIT] Using Action Scheduler for async processing' );
				}				// Schedule individual batch jobs using Action Scheduler
				$scheduled_batches = 0;
				for ( $batch_index = 0; $batch_index < $total_batches; $batch_index++ ) {
					$batch_start = $batch_index * $batch_size;

					// Schedule each batch with a small delay to prevent overwhelming the system
					$delay = $batch_index * 5; // 5 seconds between batches
					$job_id = as_schedule_single_action( time() + $delay, 'puntwork_process_batch', array(
						'batch_start' => $batch_start,
						'batch_size' => $batch_size,
						'batch_index' => $batch_index,
						'total_batches' => $total_batches,
						'import_id' => uniqid( 'import_', true )
					) );

					if ( $job_id ) {
						$scheduled_batches++;
						if ( $debug_mode ) {
							error_log( '[PUNTWORK] [BATCH-SCHEDULE] Scheduled batch ' . ($batch_index + 1) . '/' . $total_batches . ' starting at ' . $batch_start . ', job ID: ' . $job_id );
						}
					} else {
						if ( $debug_mode ) {
							error_log( '[PUNTWORK] [BATCH-SCHEDULE-ERROR] Failed to schedule batch ' . ($batch_index + 1) . ' starting at ' . $batch_start );
						}
					}
				}

				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [IMPORT-SCHEDULE] Successfully scheduled ' . $scheduled_batches . ' out of ' . $total_batches . ' batches' );
				}

				// Return success with scheduling information
				return array(
					'success' => true,
					'message' => 'Import scheduled successfully - ' . $scheduled_batches . ' batches scheduled for processing',
					'total' => $total_items,
					'batches_scheduled' => $scheduled_batches,
					'total_batches' => $total_batches,
					'batch_size' => $batch_size,
					'logs' => array( 'Import scheduled with ' . $scheduled_batches . ' batches using Action Scheduler' ),
				);
			} else {
				// Fallback to synchronous processing when Action Scheduler is not available
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [IMPORT-FALLBACK] Action Scheduler not available, falling back to synchronous processing' );
					error_log( '[PUNTWORK] [IMPORT-FALLBACK] Total items: ' . $total_items );
				}

				// Process synchronously in batches to avoid timeouts
				$batch_size = 25; // Smaller batches for synchronous processing
				$total_processed = 0;
				$total_published = 0;
				$total_updated = 0;
				$total_skipped = 0;
				$total_duplicates_drafted = 0;
				$all_logs = array();
				$batch_count = 0;

				for ( $batch_start = 0; $batch_start < $total_items; $batch_start += $batch_size ) {
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [SYNC-BATCH] Processing batch starting at ' . $batch_start . ' (batch ' . ($batch_count + 1) . ')' );
					}

					// Check for timeout before processing each batch
					if ( import_time_exceeded() ) {
						if ( $debug_mode ) {
							error_log( '[PUNTWORK] [SYNC-BATCH] Time limit exceeded, pausing import' );
						}

						// Update status with current progress
						$import_status = get_option( 'job_import_status', array() );
						$import_status['processed'] = $total_processed;
						$import_status['published'] = $total_published;
						$import_status['updated'] = $total_updated;
						$import_status['skipped'] = $total_skipped;
						$import_status['duplicates_drafted'] = $total_duplicates_drafted;
						$import_status['paused'] = true;
						$import_status['pause_reason'] = 'Time limit exceeded during synchronous processing';
						$import_status['last_update'] = time();
						$import_status['logs'] = array_slice( $all_logs, -50 );
						update_option( 'job_import_status', $import_status, false );

						return array(
							'success' => false,
							'message' => 'Import paused due to time limit - processed ' . $total_processed . ' of ' . $total_items . ' items',
							'processed' => $total_processed,
							'total' => $total_items,
							'paused' => true,
							'logs' => array_slice( $all_logs, -10 ),
						);
					}

					// Process this batch synchronously
					$batch_result = import_jobs_from_json( true, $batch_start );

					if ( $batch_result['success'] ) {
						$total_processed += $batch_result['processed'] ?? 0;
						$total_published += $batch_result['published'] ?? 0;
						$total_updated += $batch_result['updated'] ?? 0;
						$total_skipped += $batch_result['skipped'] ?? 0;
						$total_duplicates_drafted += $batch_result['duplicates_drafted'] ?? 0;

						if ( isset( $batch_result['logs'] ) && is_array( $batch_result['logs'] ) ) {
							$all_logs = array_merge( $all_logs, $batch_result['logs'] );
						}

						$batch_count++;

						if ( $debug_mode ) {
							error_log( '[PUNTWORK] [SYNC-BATCH] Batch ' . $batch_count . ' completed: processed=' . ($batch_result['processed'] ?? 0) . ', published=' . ($batch_result['published'] ?? 0) );
						}
					} else {
						if ( $debug_mode ) {
							error_log( '[PUNTWORK] [SYNC-BATCH-ERROR] Batch failed: ' . ($batch_result['message'] ?? 'Unknown error') );
						}

						// Update status with error
						$import_status = get_option( 'job_import_status', array() );
						$import_status['processed'] = $total_processed;
						$import_status['published'] = $total_published;
						$import_status['updated'] = $total_updated;
						$import_status['skipped'] = $total_skipped;
						$import_status['duplicates_drafted'] = $total_duplicates_drafted;
						$import_status['complete'] = false;
						$import_status['success'] = false;
						$import_status['error_message'] = $batch_result['message'] ?? 'Batch processing failed';
						$import_status['last_update'] = time();
						$import_status['logs'] = array_slice( $all_logs, -50 );
						update_option( 'job_import_status', $import_status, false );

						return array(
							'success' => false,
							'message' => 'Import failed during batch processing: ' . ($batch_result['message'] ?? 'Unknown error'),
							'processed' => $total_processed,
							'total' => $total_items,
							'logs' => array_slice( $all_logs, -10 ),
						);
					}
				}

				// All batches completed successfully
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [SYNC-COMPLETE] All batches completed synchronously' );
				}

				// Update final status
				$import_status = get_option( 'job_import_status', array() );
				$import_status['processed'] = $total_processed;
				$import_status['published'] = $total_published;
				$import_status['updated'] = $total_updated;
				$import_status['skipped'] = $total_skipped;
				$import_status['duplicates_drafted'] = $total_duplicates_drafted;
				$import_status['complete'] = true;
				$import_status['success'] = true;
				$import_status['time_elapsed'] = microtime( true ) - $start_time;
				$import_status['end_time'] = microtime( true );
				$import_status['last_update'] = time();
				$import_status['logs'] = array_slice( $all_logs, -50 );
				update_option( 'job_import_status', $import_status, false );

				return array(
					'success' => true,
					'message' => 'Import completed successfully - processed ' . $total_processed . ' of ' . $total_items . ' items',
					'processed' => $total_processed,
					'published' => $total_published,
					'updated' => $total_updated,
					'skipped' => $total_skipped,
					'duplicates_drafted' => $total_duplicates_drafted,
					'total' => $total_items,
					'complete' => true,
					'logs' => array_slice( $all_logs, -10 ),
				);
			}

		} catch ( \Puntwork\Exceptions\PuntworkException $e ) {
			// Handle custom PuntWork exceptions with enhanced logging
			\Puntwork\ErrorHandler::logError( array(
				'level' => \Puntwork\ErrorHandler::ERROR_LEVEL_ERROR,
				'type' => $e->getErrorType(),
				'message' => $e->getDetailedMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'context' => array_merge( $e->getContext(), array(
					'operation' => 'import_all_jobs_from_json',
					'preserve_status' => $preserve_status,
					'total_processed' => $total_processed,
					'batch_count' => $batch_count
				) ),
				'recovery_suggestions' => $e->getRecoverySuggestions()
			) );

			return array(
				'success' => false,
				'message' => 'Import failed: ' . $e->getMessage(),
				'logs' => array_merge( $all_logs, array( 'Exception: ' . $e->getMessage() ) ),
				'recovery_suggestions' => $e->getRecoverySuggestions()
			);
		} catch ( \Exception $e ) {
			// Handle standard exceptions
			\Puntwork\ErrorHandler::logError( array(
				'level' => \Puntwork\ErrorHandler::ERROR_LEVEL_ERROR,
				'type' => \Puntwork\ErrorHandler::ERROR_TYPE_SYSTEM,
				'message' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTraceAsString(),
				'context' => array(
					'operation' => 'import_all_jobs_from_json',
					'preserve_status' => $preserve_status,
					'total_processed' => $total_processed,
					'batch_count' => $batch_count
				)
			) );

			return array(
				'success' => false,
				'message' => 'Import failed: ' . $e->getMessage(),
				'logs' => $all_logs,
			);
		} finally {
			// Release import lock
			delete_transient( 'puntwork_import_lock' );
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] Import lock released at end of import_all_jobs_from_json' );
			}
		}
	}
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
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SCHEDULED-IMPORT] ===== START_SCHEDULED_IMPORT START =====' );
		error_log( '[PUNTWORK] [SCHEDULED-IMPORT] Current timestamp: ' . date( 'Y-m-d H:i:s T' ) );
		error_log( '[PUNTWORK] [SCHEDULED-IMPORT] Memory usage: ' . memory_get_usage( true ) . ' bytes' );
		error_log( '[PUNTWORK] [SCHEDULED-IMPORT] Peak memory usage: ' . memory_get_peak_usage( true ) . ' bytes' );
		error_log( '[PUNTWORK] [SCHEDULED-IMPORT] PHP version: ' . PHP_VERSION );
		error_log( '[PUNTWORK] [SCHEDULED-IMPORT] WordPress version: ' . get_bloginfo( 'version' ) );
		error_log( '[PUNTWORK] [SCHEDULED-IMPORT] Current user ID: ' . get_current_user_id() );
		error_log( '[PUNTWORK] [SCHEDULED-IMPORT] Request method: ' . $_SERVER['REQUEST_METHOD'] ?? 'cron' );
		error_log( '[PUNTWORK] [SCHEDULED-IMPORT] Server: ' . $_SERVER['SERVER_SOFTWARE'] ?? 'unknown' );
	}

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] Starting scheduled import process' );
	}

	// Check if import is already running
	$import_lock_key = 'puntwork_import_lock';
	if ( get_transient( $import_lock_key ) ) {
		// Check if the lock is stale (import status shows complete or last update > 30 minutes ago)
		$import_status = get_option( 'job_import_status', array() );
		$is_stale      = false;

		if ( ! empty( $import_status ) ) {
			$last_update       = $import_status['last_update'] ?? 0;
			$is_complete       = $import_status['complete'] ?? false;
			$time_since_update = time() - $last_update;

			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [SCHEDULED-IMPORT] Import lock check - last_update: ' . $last_update . ', is_complete: ' . ( $is_complete ? 'true' : 'false' ) . ', time_since_update: ' . $time_since_update . 's' );
			}

			if ( $is_complete || $time_since_update > 1800 ) { // 30 minutes
				$is_stale = true;
				delete_transient( $import_lock_key );
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [SCHEDULED-IMPORT] Cleared stale import lock (complete: ' . ( $is_complete ? 'yes' : 'no' ) . ', time since update: ' . $time_since_update . 's)' );
				}
			}
		}

		if ( ! $is_stale ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [SCHEDULED-IMPORT] Scheduled import already running - skipping' );
			}

			return;
		}
	}

	// Start the import
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SCHEDULED-IMPORT] About to call import_all_jobs_from_json' );
	}
	$result = import_all_jobs_from_json( true ); // preserve status

	if ( $result['success'] ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SCHEDULED-IMPORT] Scheduled import completed successfully' );
			error_log( '[PUNTWORK] [SCHEDULED-IMPORT] Result summary: processed=' . ( $result['processed'] ?? 0 ) . ', total=' . ( $result['total'] ?? 0 ) . ', published=' . ( $result['published'] ?? 0 ) . ', updated=' . ( $result['updated'] ?? 0 ) . ', skipped=' . ( $result['skipped'] ?? 0 ) );
		}
	} elseif ( $debug_mode ) {
		error_log( '[PUNTWORK] [SCHEDULED-IMPORT] Scheduled import failed: ' . ( $result['message'] ?? 'Unknown error' ) );
		error_log( '[PUNTWORK] [SCHEDULED-IMPORT] Result details: ' . json_encode( $result ) );
	}

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SCHEDULED-IMPORT] ===== START_SCHEDULED_IMPORT END =====' );
	}
}

// Register the continuation hook
add_action( 'puntwork_continue_import', 'continue_paused_import' );

// Register the scheduled import start hook
add_action( 'puntwork_start_scheduled_import', 'start_scheduled_import' );

// Register the batch import start hook (used by combine_jsonl_ajax)
add_action( 'puntwork_start_batch_import', __NAMESPACE__ . '\\start_batch_import' );
function start_batch_import() {
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [START-BATCH-IMPORT] ===== START_BATCH_IMPORT START =====' );
		error_log( '[PUNTWORK] [START-BATCH-IMPORT] Current timestamp: ' . date( 'Y-m-d H:i:s T' ) );
		error_log( '[PUNTWORK] [START-BATCH-IMPORT] Memory usage: ' . memory_get_usage( true ) . ' bytes' );
	}

	// Start the batch import process
	$result = import_all_jobs_from_json( true ); // preserve status

	if ( $result['success'] ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [START-BATCH-IMPORT] Batch import completed successfully' );
			error_log( '[PUNTWORK] [START-BATCH-IMPORT] Result summary: processed=' . ( $result['processed'] ?? 0 ) . ', total=' . ( $result['total'] ?? 0 ) . ', published=' . ( $result['published'] ?? 0 ) . ', updated=' . ( $result['updated'] ?? 0 ) . ', skipped=' . ( $result['skipped'] ?? 0 ) );
		}
	} elseif ( $debug_mode ) {
		error_log( '[PUNTWORK] [START-BATCH-IMPORT] Batch import failed: ' . ( $result['message'] ?? 'Unknown error' ) );
		error_log( '[PUNTWORK] [START-BATCH-IMPORT] Result details: ' . json_encode( $result ) );
	}

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [START-BATCH-IMPORT] ===== START_BATCH_IMPORT END =====' );
	}
}

// Register the individual batch processing hook
add_action( 'puntwork_process_batch', __NAMESPACE__ . '\\puntwork_process_batch_handler' );
function puntwork_process_batch_handler( $args ) {
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	$batch_start = $args['batch_start'] ?? 0;
	$batch_size = $args['batch_size'] ?? 50;
	$batch_index = $args['batch_index'] ?? 0;
	$total_batches = $args['total_batches'] ?? 1;
	$import_id = $args['import_id'] ?? 'unknown';

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-ASYNC] ===== PROCESSING BATCH ' . ($batch_index + 1) . '/' . $total_batches . ' =====' );
		error_log( '[PUNTWORK] [BATCH-ASYNC] Batch start: ' . $batch_start . ', Batch size: ' . $batch_size . ', Import ID: ' . $import_id );
		error_log( '[PUNTWORK] [BATCH-ASYNC] Memory usage: ' . memory_get_usage( true ) . ' bytes' );
		error_log( '[PUNTWORK] [BATCH-ASYNC] Peak memory usage: ' . memory_get_peak_usage( true ) . ' bytes' );
	}

	try {
		// Load required files for batch processing
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

		// Memory management for Action Scheduler jobs
		$current_memory = memory_get_usage( true );
		$memory_limit = get_memory_limit_bytes();
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [BATCH-ASYNC] Memory check - Current: ' . round( $current_memory / 1024 / 1024, 2 ) . 'MB, Limit: ' . round( $memory_limit / 1024 / 1024, 2 ) . 'MB' );
		}

		// If memory usage is already high at start, this is a problem
		if ( $current_memory > $memory_limit * 0.7 ) {
			error_log( '[PUNTWORK] [BATCH-ASYNC-ERROR] Memory usage already high at batch start: ' . round( $current_memory / 1024 / 1024, 2 ) . 'MB of ' . round( $memory_limit / 1024 / 1024, 2 ) . 'MB' );
			return; // Skip this batch to prevent memory exhaustion
		}

		// Prepare import setup for this specific batch
		$setup = prepare_import_setup( $batch_start, true );
		if ( is_wp_error( $setup ) ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [BATCH-ASYNC-ERROR] Setup failed for batch ' . ($batch_index + 1) . ': ' . $setup->get_error_message() );
			}
			return;
		}
		if ( isset( $setup['success'] ) && ! $setup['success'] ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [BATCH-ASYNC-ERROR] Setup returned early for batch ' . ($batch_index + 1) . ': ' . ( $setup['message'] ?? 'Unknown' ) );
			}
			return;
		}

		// Mark this as Action Scheduler processing to allow larger batches
		$setup['is_action_scheduler'] = true;

		// Process this batch
		$batch_result = process_batch_items_logic( $setup );

		if ( $batch_result['success'] ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [BATCH-ASYNC-SUCCESS] Batch ' . ($batch_index + 1) . '/' . $total_batches . ' completed successfully' );
				error_log( '[PUNTWORK] [BATCH-ASYNC-SUCCESS] Processed: ' . ( $batch_result['processed'] ?? 0 ) . ', Published: ' . ( $batch_result['published'] ?? 0 ) . ', Updated: ' . ( $batch_result['updated'] ?? 0 ) . ', Skipped: ' . ( $batch_result['skipped'] ?? 0 ) );
				$final_memory = memory_get_usage( true );
				error_log( '[PUNTWORK] [BATCH-ASYNC] Final memory usage: ' . round( $final_memory / 1024 / 1024, 2 ) . 'MB' );
			}
		} else {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [BATCH-ASYNC-ERROR] Batch ' . ($batch_index + 1) . '/' . $total_batches . ' failed: ' . ( $batch_result['message'] ?? 'Unknown error' ) );
			}
		}

		// Check if this is the last batch and finalize if needed
		if ( $batch_index + 1 >= $total_batches ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [BATCH-ASYNC-FINALIZE] Last batch completed, finalizing import' );
			}
			finalize_batch_import( $batch_result );
		}

	} catch ( \Exception $e ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [BATCH-ASYNC-EXCEPTION] Exception in batch ' . ($batch_index + 1) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
			error_log( '[PUNTWORK] [BATCH-ASYNC-EXCEPTION] Stack trace: ' . $e->getTraceAsString() );
		}
	} catch ( \Throwable $e ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [BATCH-ASYNC-FATAL] Fatal error in batch ' . ($batch_index + 1) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		}
	}

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-ASYNC] ===== BATCH ' . ($batch_index + 1) . '/' . $total_batches . ' PROCESSING COMPLETE =====' );
	}
}
