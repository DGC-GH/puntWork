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
require_once __DIR__ . '/../mappings/mappings-fields.php';
require_once __DIR__ . '/../import/process-batch-items.php';

/**
 * Process batch items and handle imports.
 *
 * @param  array $setup Setup data from prepare_import_setup.
 * @return array Processing results.
 */
function process_batch_items_logic( array $setup ): array {
	// Extract setup variables
	$start_index = $setup['start_index'] ?? 0;
	$total       = $setup['total'] ?? 0;
	$json_path   = $setup['json_path'] ?? '';
	$start_time  = $setup['start_time'] ?? microtime( true );

	// Check if json_path exists and is readable
	if ( isset( $setup['json_path'] ) ) {
		if ( ! file_exists( $setup['json_path'] ) ) {
			$error_message = 'JSON file does not exist: ' . basename( $setup['json_path'] );
			return array(
				'success' => false,
				'message' => $error_message,
				'logs'    => array( 'JSON file not found - feeds may need to be processed first' ),
			);
		}
		if ( ! is_readable( $setup['json_path'] ) ) {
			$error_message = 'JSON file not readable: ' . basename( $setup['json_path'] );
			return array(
				'success' => false,
				'message' => $error_message,
				'logs'    => array( 'JSON file not readable - check file permissions' ),
			);
		}
	}

	try {
		// Validate and adjust batch size
		$batch_size_info = validate_and_adjust_batch_size( $setup );
		$batch_size      = $batch_size_info['batch_size'];

		$logs = array();
		if ( ! empty( $batch_size_info['logs'] ) ) {
			$logs = array_merge( $logs, $batch_size_info['logs'] );
		}
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Batch size set to {$batch_size} for this import batch";

		// Initialize variables
		$published          = 0;
		$updated            = 0;
		$skipped            = 0;
		$duplicates_drafted = 0;

		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Starting batch from {$start_index}";

		// Load and prepare batch items
		$batch_load_info = load_and_prepare_batch_items( $json_path, $start_index, $batch_size, $batch_size, $logs );
		$batch_items     = $batch_load_info['batch_items'];
		$batch_guids     = $batch_load_info['batch_guids'];
		$end_index       = $start_index + count( $batch_guids );

		if ( $batch_load_info['cancelled'] ) {
			update_option( 'job_import_progress', $start_index + count( $batch_guids ), false );
			$time_elapsed = microtime( true ) - $start_time;

			return array(
				'success'   => true,
				'processed' => $start_index + count( $batch_guids ),
				'total'     => $setup['total'],
				'published' => $published,
				'updated'   => $updated,
				'skipped'   => $skipped,
				'time_elapsed' => $time_elapsed,
				'complete'  => ( ($start_index + count( $batch_guids )) >= $total ),
				'logs'      => $logs,
				'batch_size' => $batch_size,
				'message'   => '',
			);
		}

		// Process batch data
		$result = process_batch_data( $batch_guids, $batch_items, $logs, $published, $updated, $skipped, $duplicates_drafted, $start_index, $setup );

		update_option( 'job_import_progress', $end_index, false );
		$time_elapsed = microtime( true ) - $start_time;
		$batch_time   = microtime( true ) - $start_time;
		$logs[]       = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Batch complete: Processed {$result['processed_count']} items (published: $published, updated: $updated, skipped: $skipped, duplicates: $duplicates_drafted)";

		// Update import status
		$current_status = get_option( 'job_import_status', array() );
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
		$current_status['start_time']         = $start_time;
		$current_status['end_time']           = $current_status['complete'] ? microtime( true ) : null;
		$current_status['last_update']        = time();
		$current_status['logs']               = array_slice( $logs, -50 );
		update_option( 'job_import_status', $current_status, false );

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
			'batch_time'         => $batch_time,
			'batch_processed'    => $result['processed_count'],
			'start_time'         => $start_time,
			'message'            => '',
		);
	} catch ( \Exception $e ) {
		$error_msg = 'Batch import error: ' . $e->getMessage();
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . $error_msg;

		return array(
			'success' => false,
			'message' => 'Batch failed: ' . $e->getMessage(),
			'logs'    => $logs,
		);
	}
}

/**
 * Process batch data.
 */
function process_batch_data( array $batch_guids, array $batch_items, array &$logs, int &$published, int &$updated, int &$skipped, int &$duplicates_drafted, int $start_index, array $setup ): array {
	if ( empty( $batch_guids ) ) {
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'ERROR: No GUIDs to process in this batch';
		return array( 'processed_count' => 0 );
	}

	try {
		// Get existing posts by GUIDs
		$existing_by_guid = get_posts_by_guids_with_status( $batch_guids );
	} catch ( \Exception $e ) {
		throw $e;
	}

	$post_ids_by_guid = array();

	// Handle duplicates
	handle_batch_duplicates( $batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid );

	// Prepare batch metadata
	$batch_metadata = prepare_batch_metadata( $post_ids_by_guid );
	$batch_metadata['json_path'] = $setup['json_path'];

	// Process items
	$processed_count = process_batch_items_with_metadata( $batch_guids, $batch_items, $batch_metadata, $post_ids_by_guid, $logs, $updated, $published, $skipped );

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

	// Use streaming processing for memory efficiency
	if ( isset( $batch_metadata['json_path'] ) && file_exists( $batch_metadata['json_path'] ) ) {
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
			$processed_count,
			$processed_guids
		);
	} else {
		// Fallback to traditional processing
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
			$processed_count,
			$processed_guids
		);
	}

	return $processed_count;
}
