<?php

/**
 * Batch processing core functions.
 *
 * @since      1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include required batch processing files
require_once __DIR__ . '/batch-loading.php';
require_once __DIR__ . '/batch-metadata.php';
require_once __DIR__ . '/batch-duplicates.php';
require_once __DIR__ . '/batch-size-management.php';
require_once __DIR__ . '/../utilities/performance-functions.php';
require_once __DIR__ . '/../utilities/database-optimization.php';
require_once __DIR__ . '/../mappings/mappings-fields.php';
require_once __DIR__ . '/../utilities/async-processing.php';
require_once __DIR__ . '/../utilities/CacheManager.php';
require_once __DIR__ . '/../utilities/MemoryManager.php';
require_once __DIR__ . '/../utilities/PuntWorkLogger.php';
require_once __DIR__ . '/../import/process-batch-items.php';

/**
 * Process batch items and handle imports.
 *
 * @param  array $setup Setup data from prepare_import_setup.
 * @return array Processing results.
 */
function process_batch_items_logic( array $setup ): array {
	// Temporarily disable Jetpack autoloader to prevent database conflicts
	$jetpack_autoloader_disabled = false;
	if ( class_exists( 'Automattic\Jetpack\Autoloader\Autoloader' ) ) {
		$jetpack_autoloader_disabled = true;
		error_log( '[PUNTWORK] [JETPACK] Temporarily disabling Jetpack autoloader to prevent database conflicts' );
		// Note: Jetpack autoloader cannot be easily disabled once loaded, but we can try to minimize its impact
	}

	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	// Temporarily disable spam logging - uncomment for debugging
	// error_log(
	// '[PUNTWORK] [BATCH-START] ===== PROCESS_BATCH_ITEMS_LOGIC START ====='
	// );
	// error_log(
	// '[PUNTWORK] [BATCH-START] process_batch_items_logic called with setup: ' . json_encode(
	//	array(
	//		'start_index'    => $setup['start_index'] ?? 'not set',
	//		'total'          => $setup['total'] ?? 'not set',
	//		'json_path'      => isset( $setup['json_path'] ) ? basename( $setup['json_path'] ) : 'not set',
	//		'json_path_full' => $setup['json_path'] ?? 'not set',
	//	)
	// )
	// );
	// error_log( '[PUNTWORK] [BATCH-START] Memory usage at start: ' . memory_get_usage( true ) . ' bytes' );
	// error_log( '[PUNTWORK] [BATCH-START] Peak memory usage: ' . memory_get_peak_usage( true ) . ' bytes' );

	// Log batch processing start
	\Puntwork\PuntWorkLogger::info(
		'Starting batch processing',
		\Puntwork\PuntWorkLogger::CONTEXT_BATCH,
		array(
			'start_index' => $setup['start_index'] ?? 0,
			'total'       => $setup['total'] ?? 0,
			'json_path'   => $setup['json_path'] ?? '',
			'timestamp'   => time(),
		)
	);

	// Check if json_path exists and is readable
	if ( isset( $setup['json_path'] ) ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [BATCH-FILE] JSON file check: exists=' . ( file_exists( $setup['json_path'] ) ? 'yes' : 'no' ) . ', readable=' . ( is_readable( $setup['json_path'] ) ? 'yes' : 'no' ) . ', size=' . ( file_exists( $setup['json_path'] ) ? filesize( $setup['json_path'] ) : 'N/A' ) );
		}
		if ( ! file_exists( $setup['json_path'] ) ) {
			error_log( '[PUNTWORK] [BATCH-ERROR] JSON file does not exist: ' . $setup['json_path'] );

			\Puntwork\PuntWorkLogger::error(
				'JSON file does not exist',
				\Puntwork\PuntWorkLogger::CONTEXT_BATCH,
				array(
					'json_path' => $setup['json_path'],
				)
			);

			return array(
				'success' => false,
				'message' => 'JSON file not found: ' . basename( $setup['json_path'] ),
				'logs'    => array( 'JSON file not found - feeds may need to be processed first' ),
			);
		}
		if ( ! is_readable( $setup['json_path'] ) ) {
			error_log( '[PUNTWORK] [BATCH-ERROR] JSON file not readable: ' . $setup['json_path'] );

			\Puntwork\PuntWorkLogger::error(
				'JSON file not readable',
				\Puntwork\PuntWorkLogger::CONTEXT_BATCH,
				array(
					'json_path' => $setup['json_path'],
				)
			);

			return array(
				'success' => false,
				'message' => 'JSON file not readable: ' . basename( $setup['json_path'] ),
				'logs'    => array( 'JSON file not readable - check file permissions' ),
			);
		}

		\Puntwork\PuntWorkLogger::debug(
			'JSON file validation passed',
			\Puntwork\PuntWorkLogger::CONTEXT_BATCH,
			array(
				'json_path' => $setup['json_path'],
				'file_size' => filesize( $setup['json_path'] ),
			)
		);
	}

	// Start tracing span for batch processing (only if available)
	$span = null;
	if ( class_exists( '\Puntwork\PuntworkTracing' ) ) {
		$span = \Puntwork\PuntworkTracing::startActiveSpan(
			'process_batch_items_logic',
			array(
				'batch.start_index' => $setup['start_index'] ?? 0,
				'batch.total'       => $setup['total'] ?? 0,
				'batch.json_path'   => $setup['json_path'] ?? '',
			)
		);
	}

	try {
	// Temporarily disable spam logging - uncomment for debugging
	// error_log( '[PUNTWORK] [BATCH-DEBUG] Starting performance monitoring' );
	// error_log( '[PUNTWORK] [BATCH-DEBUG] Performance monitoring started with ID: ' . $perf_id );
	// error_log( '[PUNTWORK] [BATCH-DEBUG] Memory limit increased to 1024M' );
	// error_log( '[PUNTWORK] [BATCH-DEBUG] Analytics cache cleared' );
	// error_log( '[PUNTWORK] [BATCH-DEBUG] Database performance monitoring started' );
	// error_log( '[PUNTWORK] [BATCH-DEBUG] Memory optimization completed' );
	// error_log( '[PUNTWORK] [BATCH-DEBUG] Memory manager reset completed' );
	// error_log( '[PUNTWORK] [BATCH-DEBUG] Expensive plugins disabled for batch processing' );

		// Extract setup variables explicitly for security
		$start_index = $setup['start_index'] ?? 0;
		$total       = $setup['total'] ?? 0;
		$json_path   = $setup['json_path'] ?? '';
		$start_time  = $setup['start_time'] ?? microtime( true );

		$batch_start_time = microtime( true ); // Record start time for this batch

		// Validate and adjust batch size
		$batch_size_info = validate_and_adjust_batch_size( $setup );
		$batch_size      = $batch_size_info['batch_size'];
		$threshold       = $batch_size_info['threshold'];

	// Temporarily disable spam logging - uncomment for debugging
	// error_log( '[PUNTWORK] [BATCH-DEBUG] Calling validate_and_adjust_batch_size' );
	// error_log( '[PUNTWORK] [BATCH-DEBUG] validate_and_adjust_batch_size completed, batch_size=' . $batch_size );

		// Add batch size logging to UI logs
		$logs = array();
		if ( ! empty( $batch_size_info['logs'] ) ) {
			$logs = array_merge( $logs, $batch_size_info['logs'] );
		}
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Batch size set to {$batch_size} for this import batch";

		// Initialize performance monitoring
		$perf_id = start_performance_monitoring( 'batch_processing' );

		// Start database performance monitoring
		start_db_performance_monitoring();

		// Store original memory limit for restoration
		$original_memory_limit = ini_get( 'memory_limit' );

		// Increase memory limit for batch processing
		ini_set( 'memory_limit', '1024M' );

		// Clear analytics cache
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		\Puntwork\Utilities\CacheManager::clearGroup( \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS );

		// Optimize memory for large batch operations
		optimize_memory_for_batch();

		// Disable expensive plugin operations during batch processing
		disable_expensive_plugins();

		// Initialize variables
		$end_index       = $start_index;
		$lines_read      = 0;
		$processed_guids = array();

		// Re-align start_index with new batch_size to avoid skips
		// Removed to prevent stuck imports when batch_size changes

		$published          = 0;
		$updated            = 0;
		$skipped            = 0;
		$duplicates_drafted = 0;
		$inferred_languages = 0;
		$inferred_benefits  = 0;
		$schema_generated   = 0;

		try {
			$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Starting batch from {$start_index} to $end_index (size $batch_size, lines_read $lines_read)";

			// Checkpoint: Batch setup complete
			checkpoint_performance(
				$perf_id,
				'batch_setup',
				array(
					'batch_size'  => $batch_size,
					'start_index' => $start_index,
					'end_index'   => $end_index,
				)
			);

	// Temporarily disable spam logging - uncomment for debugging
	// error_log( '[PUNTWORK] [BATCH-LOAD] Calling load_and_prepare_batch_items' );
	// error_log( '[PUNTWORK] [BATCH-LOAD] load_and_prepare_batch_items completed, loaded ' . count( $batch_guids ) . ' GUIDs, lines_read=' . $lines_read . ', end_index=' . $end_index );
	// error_log( '[PUNTWORK] [BATCH-LOAD] Batch items count: ' . count( $batch_items ) );
	// error_log( '[PUNTWORK] [BATCH-LOAD] First few GUIDs: ' . implode( ', ', array_slice( $batch_guids, 0, 5 ) ) );

			// Load and prepare batch items
			$batch_load_info = load_and_prepare_batch_items( $json_path, $start_index, $batch_size, $threshold, $logs );
			$batch_items     = $batch_load_info['batch_items'];
			$batch_guids     = $batch_load_info['batch_guids'];
			$lines_read      = $batch_load_info['lines_read'];
			$end_index       = $start_index + count( $batch_guids );

			// Checkpoint: Batch items loaded
			checkpoint_performance(
				$perf_id,
				'batch_loaded',
				array(
					'items_loaded' => count( $batch_guids ),
					'memory_usage' => memory_get_usage( true ),
				)
			);

			if ( $batch_load_info['cancelled'] ) {
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [BATCH-CANCEL] Batch was cancelled, returning early' );
				}
				update_option( 'job_import_progress', $start_index + count( $batch_guids ), false ); // Advance by items processed so far
				update_option( 'job_import_processed_guids', $processed_guids, false );
				$time_elapsed = microtime( true ) - $start_time;
				$batch_time   = microtime( true ) - $batch_start_time; // Calculate actual batch processing time

				// End performance monitoring
				$perf_data = end_performance_monitoring( $perf_id );

				// Update import status for UI polling
				$current_status                       = get_option( 'job_import_status', array() );
				$current_status['total']              = $total;
				$current_processed                    = $current_status['processed'] ?? 0;
				$new_processed                        = $current_processed; // No items processed since cancelled
				$current_status['processed']          = $new_processed;
				$current_status['published']          = $current_status['published'] ?? 0;
				$current_status['updated']            = $current_status['updated'] ?? 0;
				$current_status['skipped']            = ( $current_status['skipped'] ?? 0 ) + $skipped;
				$current_status['duplicates_drafted'] = $current_status['duplicates_drafted'] ?? 0;
				$current_status['time_elapsed']       = $time_elapsed;
				$current_status['complete']           = ( $new_processed >= $total );
				$current_status['success']            = true;
				$current_status['error_message']      = '';
				$current_status['batch_size']         = $batch_size;
				$current_status['inferred_languages'] = ( $current_status['inferred_languages'] ?? 0 ) + $inferred_languages;
				$current_status['inferred_benefits']  = ( $current_status['inferred_benefits'] ?? 0 ) + $inferred_benefits;
				$current_status['schema_generated']   = ( $current_status['schema_generated'] ?? 0 ) + $schema_generated;
				$current_status['start_time']         = $start_time;
				$current_status['end_time']           = $current_status['complete'] ? microtime( true ) : null;
				$current_status['last_update']        = time();
				$current_status['logs']               = array_slice( $logs, -50 );
				update_option( 'job_import_status', $current_status, false );

				// Flush cache for real-time status updates
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}

				if ( $span ) {
					$span->setAttribute( 'batch.cancelled', true );
					$span->end();
				}

				// Re-enable expensive plugin operations
				enable_expensive_plugins();
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [BATCH-DEBUG] Expensive plugins re-enabled after batch cancellation' );
				}

				// Restore memory limit
				ini_set( 'memory_limit', $original_memory_limit );

				return array(
					'success'            => true,
					'processed'          => $new_processed,
					'total'              => $setup['total'],
					'published'          => $published,
					'updated'            => $updated,
					'skipped'            => $skipped,
					'duplicates_drafted' => $duplicates_drafted,
					'time_elapsed'       => $time_elapsed,
					'complete'           => ( $new_processed >= $setup['total'] ),
					'logs'               => $logs,
					'batch_size'         => $batch_size,
					'inferred_languages' => $inferred_languages,
					'inferred_benefits'  => $inferred_benefits,
					'schema_generated'   => $schema_generated,
					'batch_time'         => $batch_time,
					'batch_processed'    => 0,
					'performance'        => $perf_data,
					'message'            => '', // No error message for success
				);
			}

			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [BATCH-PROCESS] Calling process_batch_data' );
			}

			// Update status to show batch processing has started
			$current_status                = get_option( 'job_import_status', array() );
			$current_status['last_update'] = time();
			$current_status['logs'][]      = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Starting batch processing...';
			update_option( 'job_import_status', $current_status, false );
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}

	// Temporarily disable spam logging - uncomment for debugging
	// error_log( '[PUNTWORK] [BATCH-PROCESS] Calling process_batch_data' );
	// error_log( '[PUNTWORK] [BATCH-PROCESS] process_batch_data completed, processed_count=' . $result['processed_count'] );
	// error_log( '[PUNTWORK] [BATCH-PROCESS] Results: published=' . $published . ', updated=' . $updated . ', skipped=' . $skipped . ', duplicates_drafted=' . $duplicates_drafted );

			// Process batch data
			$result = process_batch_data( $batch_guids, $batch_items, $logs, $published, $updated, $skipped, $duplicates_drafted );

			// Checkpoint: Batch processing complete
			checkpoint_performance(
				$perf_id,
				'batch_processed',
				array(
					'items_processed'    => $result['processed_count'],
					'published'          => $published,
					'updated'            => $updated,
					'skipped'            => $skipped,
					'duplicates_drafted' => $duplicates_drafted,
				)
			);

			unset( $batch_items, $batch_guids );

			update_option( 'job_import_progress', $end_index, false );
			update_option( 'job_import_processed_guids', $processed_guids, false );
			$time_elapsed = microtime( true ) - $start_time;
			$batch_time   = microtime( true ) - $batch_start_time; // Calculate actual batch processing time
			$logs[]       = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Batch complete: Processed {$result['processed_count']} items (published: $published, updated: $updated, skipped: $skipped, duplicates: $duplicates_drafted)";

			// Update performance metrics with batch time, not total time
			update_batch_metrics( $batch_time, $result['processed_count'], $batch_size );

			// Store batch timing data for status retrieval
			update_option( 'job_import_last_batch_time', $batch_time, false );
			update_option( 'job_import_last_batch_processed', $result['processed_count'], false );

			// End performance monitoring
			$perf_data = end_performance_monitoring( $perf_id );

			// End database performance monitoring
			$db_perf_data = end_db_performance_monitoring();

			// Include DB performance in main performance data
			$perf_data['database'] = $db_perf_data;

			// Update import status for UI polling
			$current_status = get_option( 'job_import_status', array() );

			// Check if there's a recent intermediate update that should be preserved
			$has_recent_intermediate = isset( $current_status['is_intermediate_update'] ) &&
										isset( $current_status['intermediate_update_time'] ) &&
										( microtime( true ) - $current_status['intermediate_update_time'] ) < 2; // Within last 2 seconds

			if ( $has_recent_intermediate ) {
				error_log( '[PUNTWORK] [UI-STATUS] Preserving recent intermediate update from ' . round( microtime( true ) - $current_status['intermediate_update_time'], 2 ) . ' seconds ago' );
				// Update the processed count even when preserving intermediate update
				$current_processed = $current_status['processed'] ?? 0;
				$new_processed     = $current_processed + $result['processed_count'];
				
				// Update only the essential fields without overwriting the intermediate update
				$current_status['processed']          = $new_processed;
				$current_status['published']          = ( $current_status['published'] ?? 0 ) + $published;
				$current_status['updated']            = ( $current_status['updated'] ?? 0 ) + $updated;
				$current_status['skipped']            = ( $current_status['skipped'] ?? 0 ) + $skipped;
				$current_status['duplicates_drafted'] = ( $current_status['duplicates_drafted'] ?? 0 ) + $duplicates_drafted;
				$current_status['complete']           = ( $new_processed >= $total );
				$current_status['inferred_languages'] = ( $current_status['inferred_languages'] ?? 0 ) + $inferred_languages;
				$current_status['inferred_benefits']  = ( $current_status['inferred_benefits'] ?? 0 ) + $inferred_benefits;
				$current_status['schema_generated']   = ( $current_status['schema_generated'] ?? 0 ) + $schema_generated;
				$current_status['end_time']           = $current_status['complete'] ? microtime( true ) : null;
				
				update_option( 'job_import_status', $current_status, false );
				
				// Flush cache for real-time status updates
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
			} else {
				$current_processed = $current_status['processed'] ?? 0;
				$new_processed     = $current_processed + $result['processed_count'];

				$current_status['total']              = $total;
				$current_status['processed']          = $new_processed;
				$current_status['published']          = ( $current_status['published'] ?? 0 ) + $published;
				$current_status['updated']            = ( $current_status['updated'] ?? 0 ) + $updated;
				$current_status['skipped']            = ( $current_status['skipped'] ?? 0 ) + $skipped;
				$current_status['duplicates_drafted'] = ( $current_status['duplicates_drafted'] ?? 0 ) + $duplicates_drafted;
				$current_status['time_elapsed']       = $time_elapsed;
				$current_status['complete']           = ( $new_processed >= $total );
				$current_status['success']            = true;
				$current_status['error_message']      = '';
				$current_status['batch_size']         = $batch_size;
				$current_status['inferred_languages'] = ( $current_status['inferred_languages'] ?? 0 ) + $inferred_languages;
				$current_status['inferred_benefits']  = ( $current_status['inferred_benefits'] ?? 0 ) + $inferred_benefits;
				$current_status['schema_generated']   = ( $current_status['schema_generated'] ?? 0 ) + $schema_generated;
				$current_status['start_time']         = $start_time;
				$current_status['end_time']           = $current_status['complete'] ? microtime( true ) : null;
				$current_status['last_update']        = time();
				$current_status['logs']               = array_slice( $logs, -50 ); // Keep last 50 log entries

				// Clear intermediate update flags
				unset( $current_status['is_intermediate_update'], $current_status['intermediate_update_time'] );

				update_option( 'job_import_status', $current_status, false );

				// Flush cache for real-time status updates
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
			}            // Schedule async analytics update for better performance
			$analytics_data = array(
				'import_id'          => wp_generate_uuid4(),
				'start_time'         => $start_time,
				'end_time'           => microtime( true ),
				'batch_time'         => $batch_time,
				'total'              => $total,
				'processed'          => $result['processed_count'],
				'published'          => $published,
				'updated'            => $updated,
				'skipped'            => $skipped,
				'duplicates_drafted' => $duplicates_drafted,
				'performance'        => $perf_data,
				'message'            => '',
			);
			if ( is_callable( 'schedule_async_analytics_update' ) ) {
				schedule_async_analytics_update( $analytics_data );
			}

	// Temporarily disable spam logging - uncomment for debugging
	// error_log( '[PUNTWORK] [BATCH-COMPLETE] process_batch_items_logic completed successfully' );
	// error_log( '[PUNTWORK] [BATCH-END] ===== PROCESS_BATCH_ITEMS_LOGIC END =====' );

			\Puntwork\PuntWorkLogger::info(
				'Batch processing completed successfully',
				\Puntwork\PuntWorkLogger::CONTEXT_BATCH,
				array(
					'processed'    => $new_processed,
					'total'        => $total,
					'published'    => $published,
					'updated'      => $updated,
					'skipped'      => $skipped,
					'batch_size'   => $batch_size,
					'time_elapsed' => $time_elapsed,
				)
			);

			// Restore original memory limit
			ini_set( 'memory_limit', $original_memory_limit );
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [BATCH-DEBUG] Memory limit restored to ' . $original_memory_limit );
			}

			// Re-enable expensive plugin operations
			enable_expensive_plugins();
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [BATCH-DEBUG] Expensive plugins re-enabled' );
			}

			return array(
				'success'            => true,
				'processed'          => $new_processed,
				'total'              => $total,
				'published'          => $published,
				'updated'            => $updated,
				'skipped'            => $skipped,
				'duplicates_drafted' => $duplicates_drafted,
				'time_elapsed'       => $time_elapsed,
				'complete'           => ( $new_processed >= $total ),
				'logs'               => $logs,
				'batch_size'         => $batch_size,
				'inferred_languages' => $inferred_languages,
				'inferred_benefits'  => $inferred_benefits,
				'schema_generated'   => $schema_generated,
				'batch_time'         => $batch_time,  // Time for this specific batch
				'batch_processed'    => $result['processed_count'],  // Items processed in this batch
				'start_time'         => $start_time,
				'performance'        => $perf_data,
				'message'            => '', // No error message for success
			);
		} catch ( \Exception $e ) {
			// End performance monitoring on error
			$perf_data = end_performance_monitoring( $perf_id );

			$error_msg = 'Batch import error: ' . $e->getMessage();
			error_log( '[PUNTWORK] [BATCH-ERROR] ' . $error_msg );
			$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . $error_msg;

			\Puntwork\PuntWorkLogger::error(
				'Batch processing failed',
				\Puntwork\PuntWorkLogger::CONTEXT_BATCH,
				array(
					'error_message' => $e->getMessage(),
					'error_file'    => $e->getFile(),
					'error_line'    => $e->getLine(),
					'start_index'   => $start_index,
					'batch_size'    => $batch_size ?? 0,
				)
			);

			if ( $span ) {
				$span->recordException( $e );
				$span->setStatus( \OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $e->getMessage() );
				$span->end();
			}

			// Re-enable expensive plugin operations
			enable_expensive_plugins();
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [BATCH-DEBUG] Expensive plugins re-enabled after batch error' );
			}

			// Restore memory limit
			ini_set( 'memory_limit', $original_memory_limit );

			return array(
				'success'     => false,
				'message'     => 'Batch failed: ' . $e->getMessage(),
				'logs'        => $logs,
				'performance' => $perf_data,
			);
		}
	} catch ( \Exception $e ) {
		// Handle outer try exceptions (setup/initialization errors)
		if ( $span ) {
			$span->recordException( $e );
			$span->setStatus( \OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $e->getMessage() );
			$span->end();
		}

		// Re-enable expensive plugin operations (in case they were disabled during setup)
		enable_expensive_plugins();
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [BATCH-DEBUG] Expensive plugins re-enabled after setup error' );
		}

		// Restore memory limit
		ini_set( 'memory_limit', $original_memory_limit ?? '512M' );

		return array(
			'success'     => false,
			'message'     => 'Batch setup failed: ' . $e->getMessage(),
			'logs'        => array(),
			'performance' => null,
		);
	}
}

/**
 * Update intermediate batch status during processing to keep UI responsive.
 *
 * @param int   $processed_count Current processed count in this batch
 * @param int   $total_in_batch  Total items in this batch
 * @param int   $published       Published count so far
 * @param int   $updated         Updated count so far
 * @param int   $skipped         Skipped count so far
 * @param array $logs            Current logs
 */
function update_intermediate_batch_status( int $processed_count, int $total_in_batch, int $published, int $updated, int $skipped, array $logs ): void {
	error_log( '[PUNTWORK] [UI-STATUS] update_intermediate_batch_status called with: processed_count=' . $processed_count . ', total_in_batch=' . $total_in_batch . ', published=' . $published . ', updated=' . $updated . ', skipped=' . $skipped );

	// Get current status
	$current_status = get_option( 'job_import_status', array() );
	if (!is_array($current_status)) {
		$current_status = array();
	}
	error_log( '[PUNTWORK] [UI-STATUS] Current status from DB: processed=' . ( $current_status['processed'] ?? 'null' ) . ', total=' . ( $current_status['total'] ?? 'null' ) . ', last_update=' . ( isset( $current_status['last_update'] ) ? date( 'H:i:s', $current_status['last_update'] ) : 'null' ) );

	// Calculate total processed so far (previous batches + current batch progress)
	$previous_processed = $current_status['processed'] ?? 0;
	$total_processed    = $previous_processed + $processed_count;

	error_log( '[PUNTWORK] [UI-STATUS] Calculated: previous_processed=' . $previous_processed . ', total_processed=' . $total_processed );

	// Update status with intermediate values
	$intermediate_status                 = $current_status;
	$intermediate_status['processed']    = $total_processed;
	$intermediate_status['published']    = ($current_status['published'] ?? 0) + $published;
	$intermediate_status['updated']      = ($current_status['updated'] ?? 0) + $updated;
	$intermediate_status['skipped']      = ($current_status['skipped'] ?? 0) + $skipped;
	$intermediate_status['time_elapsed'] = microtime( true ) - ( $current_status['start_time'] ?? microtime( true ) );
	$intermediate_status['complete']     = false; // Not complete until batch finishes
	$intermediate_status['success']      = true; // Still successful so far
	$intermediate_status['last_update']  = time();
	$intermediate_status['logs']         = array_slice( $logs, -50 ); // Keep last 50 log entries

	// Add intermediate progress message with more detail
	$progress_percent              = $total_in_batch > 0 ? round( ( $processed_count / $total_in_batch ) * 100, 1 ) : 0;
	$intermediate_status['logs'][] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Processing batch: {$processed_count}/{$total_in_batch} items completed ({$progress_percent}%)";

	// Add intermediate status flag to prevent immediate overwriting
	$intermediate_status['is_intermediate_update']   = true;
	$intermediate_status['intermediate_update_time'] = microtime( true );

	error_log( '[PUNTWORK] [UI-STATUS] Updated status: processed=' . ($intermediate_status['processed'] ?? 0) . ', published=' . ($intermediate_status['published'] ?? 0) . ', updated=' . ($intermediate_status['updated'] ?? 0) . ', skipped=' . ($intermediate_status['skipped'] ?? 0) . ', time_elapsed=' . round( $intermediate_status['time_elapsed'] ?? 0, 2 ) . 's' );

	update_option( 'job_import_status', $intermediate_status, false );
	error_log( '[PUNTWORK] [UI-STATUS] Status saved to database' );

	// Flush cache for real-time status updates
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
		error_log( '[PUNTWORK] [UI-STATUS] Cache flushed' );
	} else {
		error_log( '[PUNTWORK] [UI-STATUS] wp_cache_flush not available' );
	}
}
function process_batch_data( array $batch_guids, array $batch_items, array &$logs, int &$published, int &$updated, int &$skipped, int &$duplicates_drafted ): array {
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	// Temporarily disable spam logging - uncomment for debugging
	// error_log( '[PUNTWORK] process_batch_data called with ' . count( $batch_guids ) . ' GUIDs' );

	if ( empty( $batch_guids ) ) {
		error_log( '[PUNTWORK] ERROR: process_batch_data called with empty batch_guids! This means load_and_prepare_batch_items failed to load valid items.' );
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'ERROR: No GUIDs to process in this batch';

		return array( 'processed_count' => 0 );
	}

	global $wpdb;

	try {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [BATCH-DEBUG] About to call get_posts_by_guids_with_status' );
		}
		// Use optimized function to get posts by GUIDs with status
		$existing_by_guid = get_posts_by_guids_with_status( $batch_guids );
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [BATCH-DEBUG] get_posts_by_guids_with_status completed, found ' . count( $existing_by_guid ) . ' existing GUIDs' );
		}
	} catch ( \Exception $e ) {
		error_log( '[PUNTWORK] Error getting existing posts: ' . $e->getMessage() );

		throw $e;
	}

	$post_ids_by_guid = array();

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-DEBUG] About to call handle_batch_duplicates' );
	}
	// Handle duplicates
	handle_batch_duplicates( $batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid );
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-DEBUG] handle_batch_duplicates completed, post_ids_by_guid has ' . count( $post_ids_by_guid ) . ' entries' );
	}

	// Clear cache to prevent memory accumulation
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
	\Puntwork\Utilities\CacheManager::clearGroup( \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS );
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-DEBUG] Cache cleared after duplicates' );
	}

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-DEBUG] About to call prepare_batch_metadata' );
	}
	// Prepare batch metadata
	$batch_metadata = prepare_batch_metadata( $post_ids_by_guid );
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-DEBUG] prepare_batch_metadata completed' );
	}

	// Clear cache again after metadata preparation
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
	\Puntwork\Utilities\CacheManager::clearGroup( \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS );
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-DEBUG] Cache cleared after metadata' );
	}

	// Check for batch-level changes before processing individual items
	$batch_change_check = check_batch_for_changes( $batch_guids, $batch_items, $batch_metadata, $post_ids_by_guid );
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-DEBUG] Batch change check: ' . ( $batch_change_check['has_changes'] ? 'CHANGES DETECTED' : 'NO CHANGES' ) );
		error_log( '[PUNTWORK] [BATCH-DEBUG] Batch change details: ' . json_encode( $batch_change_check ) );
	}

	// DISABLED: Batch-level optimization was causing imports to appear complete without processing items
	// The batch hash check was too aggressive and skipped batches even when individual items needed processing
	// if ( ! $batch_change_check['has_changes'] ) {
	// 	// Entire batch unchanged - skip all processing
	// 	$skipped += count( $batch_guids );
	// 	$logs[]   = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Batch optimization: Skipped entire batch of ' . count( $batch_guids ) . ' items (no changes detected)';
	// 	error_log( '[PUNTWORK] [BATCH-OPTIMIZATION] Skipped entire batch of ' . count( $batch_guids ) . ' items - no changes detected' );
	//
	// 	// Update status immediately when batch is skipped to prevent stuck detection
	// 	$current_status = get_option( 'job_import_status', array() );
	// 	$current_status['processed'] = $end_index;
	// 	$current_status['skipped']   = ( $current_status['skipped'] ?? 0 ) + $skipped;
	// 	$current_status['last_update'] = time();
	// 	$current_status['logs'] = array_slice( $logs, -50 );
	// 	update_option( 'job_import_status', $current_status, false );
	// 	if ( function_exists( 'wp_cache_flush' ) ) {
	// 		wp_cache_flush();
	// 	}
	//
	// 	return array( 'processed_count' => count( $batch_guids ) );
	// }

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-DEBUG] Batch has changes, proceeding with individual item processing' );
	}

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-DEBUG] About to call process_batch_items_with_metadata' );
	}
	// Process items - use direct processing for now
	$processed_count = process_batch_items_with_metadata( $batch_guids, $batch_items, $batch_metadata, $post_ids_by_guid, $logs, $updated, $published, $skipped );
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-DEBUG] process_batch_items_with_metadata completed, processed_count=' . $processed_count );
	}

	// Temporarily disable spam logging - uncomment for debugging
	// error_log( '[PUNTWORK] [BATCH-DATA] Batch processing completed: processed=' . $processed_count . ', published=' . $published . ', updated=' . $updated . ', skipped=' . $skipped . ', duplicates_drafted=' . $duplicates_drafted );

	return array( 'processed_count' => $processed_count );
}

/**
 * Process batch items with prepared metadata.
 */
function process_batch_items_with_metadata( array $batch_guids, array $batch_items, array $batch_metadata, array $post_ids_by_guid, array &$logs, int &$updated, int &$published, int &$skipped ): int {
	$processed_count   = 0;
	$acf_fields        = get_acf_fields();
	$zero_empty_fields = get_zero_empty_fields();

	// Include the optimized batch processing file
	require_once __DIR__ . '/../import/process-batch-items-optimized.php';

	// Check if we should use streaming processing for large batches
	$use_streaming = count( $batch_guids ) > 1000 || memory_get_usage( true ) > 100 * 1024 * 1024; // 100MB threshold

	if ( $use_streaming && isset( $batch_metadata['json_path'] ) && file_exists( $batch_metadata['json_path'] ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [STREAMING] Using streaming processing for large batch of ' . count( $batch_guids ) . ' items' );
		}

		// Use streaming processing for memory efficiency
		process_batch_items_streaming(
			$batch_metadata['json_path'],
			$batch_guids,
			$batch_metadata['last_updates'],
			$batch_metadata['hashes_by_post'],
			$acf_fields,
			$zero_empty_fields,
			$post_ids_by_guid,
			$logs,
			$updated,
			$published,
			$skipped,
			$processed_count
		);
	} else {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [BATCH] Using traditional processing for batch of ' . count( $batch_guids ) . ' items' );
		}

		// Use traditional processing for smaller batches
		process_batch_items(
			$batch_guids,
			$batch_items,
			$batch_metadata['last_updates'],
			$batch_metadata['hashes_by_post'],
			$acf_fields,
			$zero_empty_fields,
			$post_ids_by_guid,
			$logs,
			$updated,
			$published,
			$skipped,
			$processed_count
		);
	}

	return $processed_count;
}

/**
 * Queue batch items for processing instead of processing directly.
 */
function queue_batch_items( array $batch_guids, array $batch_items, array $batch_metadata, array $post_ids_by_guid, array &$logs, int &$updated, int &$published, int &$skipped ): int {
	global $puntwork_queue_manager;

	if ( ! $puntwork_queue_manager ) {
		// Fallback to direct processing
		return process_batch_items_with_metadata( $batch_guids, $batch_items, $batch_metadata, $post_ids_by_guid, $logs, $updated, $published, $skipped );
	}

	// Check for batch-level changes before queuing individual items
	$batch_change_check = check_batch_for_changes( $batch_guids, $batch_items, $batch_metadata, $post_ids_by_guid );
	$debug_mode         = defined( 'WP_DEBUG' ) && WP_DEBUG;

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [QUEUE-OPTIMIZATION] Batch change check: ' . ( $batch_change_check['has_changes'] ? 'CHANGES DETECTED' : 'NO CHANGES' ) );
	}

	// DISABLED: Batch-level optimization was causing imports to appear complete without processing items
	// The batch hash check was too aggressive and skipped batches even when individual items needed processing
	// if ( ! $batch_change_check['has_changes'] ) {
	// 	// Entire batch unchanged - skip all queuing
	// 	$skipped += count( $batch_guids );
	// 	$logs[]   = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Queue optimization: Skipped entire batch of ' . count( $batch_guids ) . ' items (no changes detected)';
	// 	error_log( '[PUNTWORK] [QUEUE-OPTIMIZATION] Skipped queuing entire batch of ' . count( $batch_guids ) . ' items - no changes detected' );
	//
	// 	return count( $batch_guids ); // Return count as "processed"
	// }

	// Ensure queue table exists
	$puntwork_queue_manager->ensureTableExists();

	$queued_count = 0;
	$chunk_size   = 100; // Process in chunks to prevent timeouts
	$chunks       = array_chunk( $batch_guids, $chunk_size, true );

	foreach ( $chunks as $chunk_index => $chunk_guids ) {
		$chunk_queued = 0;

		foreach ( $chunk_guids as $index => $guid ) {
			$job_data = isset( $batch_items[ $index ] ) ? $batch_items[ $index ] : null;

			if ( ! $job_data ) {
				continue;
			}

			// Check if job needs updating (same logic as direct processing)
			$post_id = $post_ids_by_guid[ $guid ] ?? null;
			if ( $post_id ) {
				$last_update = $batch_metadata['last_updates'][ $post_id ] ?? null;
				$xml_updated = $job_data['updated'] ?? $job_data['pubdate'] ?? null;

				if ( $last_update && $xml_updated ) {
					$last_update_ts = strtotime( $last_update );
					$xml_updated_ts = strtotime( $xml_updated );

					if ( $last_update_ts >= $xml_updated_ts ) {
						++$skipped;

						continue;
					}
				}
				// Existing job, will be updated
				++$updated;
			} else {
				// New job, will be published
				++$published;
			}

			// Add to queue
			$queue_data = array(
				'guid'     => $guid,
				'job_data' => $job_data,
				'post_id'  => $post_id,
			);

			$job_id = $puntwork_queue_manager->addJob( 'job_import', $queue_data, 10 );

			if ( $job_id ) {
				++$queued_count;
				++$chunk_queued;
			} else {
				$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Failed to queue job import for GUID: $guid";
				// Revert the count since queuing failed
				if ( $post_id ) {
					--$updated;
				} else {
					--$published;
				}
			}
		}

		// Trigger immediate queue processing after each chunk
		if ( $chunk_queued > 0 ) {
			$puntwork_queue_manager->processQueue();
			$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Processed chunk ' . ( $chunk_index + 1 ) . '/' . count( $chunks ) . " ($chunk_queued jobs queued)";
		}
	}

	$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Total queued jobs: $queued_count";

	return $queued_count;
}

/**
 * Check if an entire batch has changes before processing individual items.
 * This optimization can skip entire batches that haven't changed.
 *
 * @param array $batch_guids     GUIDs in this batch
 * @param array $batch_items     Batch items data
 * @param array $batch_metadata  Prepared batch metadata
 * @param array $post_ids_by_guid Post IDs mapped by GUID
 * @return array Change detection result
 */
function check_batch_for_changes( array $batch_guids, array $batch_items, array $batch_metadata, array $post_ids_by_guid ): array {
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-CHANGE-CHECK] Starting batch change detection for ' . count( $batch_guids ) . ' items' );
	}

	// Generate batch identifier for caching
	$batch_identifier = 'batch_' . md5( implode( ',', $batch_guids ) );
	$batch_hash_key   = 'puntwork_batch_hash_' . $batch_identifier;

	// Calculate current batch hash
	$batch_hash_data = array(
		'guids'          => $batch_guids,
		'items'          => array(),
		'existing_posts' => array(),
	);

	// Include item data in hash
	foreach ( $batch_guids as $guid ) {
		if ( isset( $batch_items[ $guid ] ) ) {
			$item                              = $batch_items[ $guid ]['item'];
			$batch_hash_data['items'][ $guid ] = array(
				'title'   => $item['functiontitle'] ?? '',
				'company' => $item['company'] ?? '',
				'updated' => $item['updated'] ?? '',
				'hash'    => md5( json_encode( $item ) ), // Content hash
			);
		}
	}

	// Include existing post data in hash
	foreach ( $post_ids_by_guid as $guid => $post_id ) {
		$last_update                                = $batch_metadata['last_updates'][ $post_id ] ?? '';
		$content_hash                               = $batch_metadata['hashes_by_post'][ $post_id ] ?? '';
		$batch_hash_data['existing_posts'][ $guid ] = array(
			'post_id'      => $post_id,
			'last_update'  => $last_update,
			'content_hash' => $content_hash,
		);
	}

	// Calculate batch hash
	$current_batch_hash = md5( json_encode( $batch_hash_data ) );

	// Get stored batch hash
	$stored_batch_hash = get_transient( $batch_hash_key );

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-CHANGE-CHECK] Current batch hash: ' . substr( $current_batch_hash, 0, 8 ) . '...' );
		error_log( '[PUNTWORK] [BATCH-CHANGE-CHECK] Stored batch hash: ' . ( $stored_batch_hash ? substr( $stored_batch_hash, 0, 8 ) . '...' : 'none' ) );
	}

	// Check if batch has changed
	$has_changes = ( $stored_batch_hash !== $current_batch_hash );

	// Store current hash for future comparisons (expires in 24 hours)
	set_transient( $batch_hash_key, $current_batch_hash, 24 * HOUR_IN_SECONDS );

	$result = array(
		'has_changes'          => $has_changes,
		'batch_identifier'     => $batch_identifier,
		'current_hash'         => $current_batch_hash,
		'stored_hash'          => $stored_batch_hash,
		'items_count'          => count( $batch_guids ),
		'existing_posts_count' => count( $post_ids_by_guid ),
	);

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [BATCH-CHANGE-CHECK] Result: ' . ( $has_changes ? 'CHANGES DETECTED' : 'NO CHANGES' ) . ' for batch ' . $batch_identifier );
	}

	return $result;
}
