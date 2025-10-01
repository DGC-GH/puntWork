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
				function() use ( $import_lock_key, $debug_mode ) {
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
				function() use ( $batch_start, $debug_mode ) {
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [IMPORT-SETUP] Calling prepare_import_setup...' );
					}

					$setup = prepare_import_setup( $batch_start );
					if ( $debug_mode ) {
						error_log(
							'[PUNTWORK] [IMPORT-SETUP] prepare_import_setup returned: ' . json_encode(
								array(
									'success'          => $setup['success'] ?? 'not set',
									'total'            => $setup['total'] ?? 'not set',
									'start_index'      => $setup['start_index'] ?? 'not set',
									'complete'         => $setup['complete'] ?? 'not set',
									'json_path_exists' => isset( $setup['json_path'] ) ? file_exists( $setup['json_path'] ) : 'no json_path',
									'json_path'        => $setup['json_path'] ?? 'not set',
								)
							)
						);
						error_log( '[PUNTWORK] [IMPORT-SETUP] prepare_import_setup completed' );
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
			// Release import lock
			delete_transient( 'puntwork_import_lock' );
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [IMPORT-LOCK] Import lock released' );
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
		}

		try {
			// Get total items first
			$json_path = get_option( 'job_import_json_path', 'feeds/combined-jobs.jsonl' );
			if ( ! file_exists( $json_path ) ) {
				$json_path = PUNTWORK_PATH . '/' . $json_path;
			}
			$total_items = get_json_item_count( $json_path );

	// Set default batch size
	$batch_size = 10; // Reduced from 65 to prevent timeouts			// Loop through batches
			$current_batch_start = 0;
			while ( $current_batch_start < $total_items ) {
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [BATCH-LOOP] Processing batch starting at ' . $current_batch_start . ' of ' . $total_items );
				}

				// Call import for this batch
				$batch_result = import_jobs_from_json( true, $current_batch_start );

				if ( ! $batch_result['success'] ) {
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [BATCH-LOOP] Batch failed at ' . $current_batch_start . ': ' . ( $batch_result['message'] ?? 'Unknown error' ) );
					}
					return $batch_result; // Return failure
				}

				// Accumulate results
				$total_processed += $batch_result['processed'] ?? 0;
				$total_published += $batch_result['published'] ?? 0;
				$total_updated += $batch_result['updated'] ?? 0;
				$total_skipped += $batch_result['skipped'] ?? 0;
				$total_duplicates_drafted += $batch_result['duplicates_drafted'] ?? 0;
				$batch_count++;

				if ( ! empty( $batch_result['logs'] ) ) {
					$all_logs = array_merge( $all_logs, $batch_result['logs'] );
				}

				// Check if this batch completed the import
				if ( isset( $batch_result['complete'] ) && $batch_result['complete'] ) {
					break;
				}

				// Move to next batch
				$current_batch_start += $batch_size;

				// Safety check to prevent infinite loops
				if ( $batch_count > 1000 ) {
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [BATCH-LOOP] Safety break: too many batches (' . $batch_count . ')' );
					}
					break;
				}
			}

			$end_time = microtime( true );
			$total_duration = $end_time - $start_time;

			$final_result = array(
				'success'            => true,
				'processed'          => $total_processed,
				'total'              => $total_items,
				'published'          => $total_published,
				'updated'            => $total_updated,
				'skipped'            => $total_skipped,
				'duplicates_drafted' => $total_duplicates_drafted,
				'time_elapsed'       => $total_duration,
				'complete'           => true,
				'logs'               => $all_logs,
				'batches_processed'  => $batch_count,
				'message'            => sprintf(
					'Full import completed successfully - Processed: %d/%d items ' .
					'(Published: %d, Updated: %d, Skipped: %d) in %.1f seconds',
					$total_processed,
					$total_items,
					$total_published,
					$total_updated,
					$total_skipped,
					$total_duration
				),
			);

			if ( $debug_mode ) {
				error_log(
					sprintf(
						'[PUNTWORK] Full import completed - Duration: %.2fs, Batches: %d, Total: %d, ' .
						'Processed: %d, Published: %d, Updated: %d, Skipped: %d',
						$total_duration,
						$batch_count,
						$total_items,
						$total_processed,
						$total_published,
						$total_updated,
						$total_skipped
					)
				);
			}

			// Ensure final status is updated for UI
			$current_status = get_option( 'job_import_status', array() );
			$final_status   = array_merge(
				$current_status,
				array(
					'total'              => $total_items,
					'processed'          => $total_processed,
					'published'          => $total_published,
					'updated'            => $total_updated,
					'skipped'            => $total_skipped,
					'duplicates_drafted' => $total_duplicates_drafted,
					'time_elapsed'       => $total_duration,
					'complete'           => true,
					'success'            => true,
					'error_message'      => '',
					'end_time'           => $end_time,
					'last_update'        => time(),
					'logs'               => array_slice( $all_logs, -50 ),
				)
			);
			// When complete, ensure processed equals total
			if ( $final_status['complete'] && $final_status['processed'] < $final_status['total'] ) {
				$final_status['processed'] = $final_status['total'];
			}
			update_option( 'job_import_status', $final_status, false );
			if ( $debug_mode ) {
				error_log(
					'[PUNTWORK] Final import status updated: ' . json_encode(
						array(
							'total'     => $total_items,
							'processed' => $total_processed,
							'complete'  => true,
							'success'   => true,
						)
					)
				);
			}

			// Ensure cache is cleared so AJAX can see the updated status
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}

			return finalize_batch_import( $final_result );
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
