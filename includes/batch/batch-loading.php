<?php

/**
 * Batch loading and preparation utilities.
 *
 * @since      1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load and prepare batch items from JSONL.
 *
 * @param  string $json_path   Path to JSONL file.
 * @param  int    $start_index Start index.
 * @param  int    $batch_size  Batch size.
 * @param  int    $threshold   Memory threshold.
 * @param  array  &$logs       Logs array.
 * @return array Prepared batch data.
 */
function load_and_prepare_batch_items( string $json_path, int $start_index, int $batch_size, float $threshold, array &$logs ): array {
	// Check if file exists and is readable
	if ( ! file_exists( $json_path ) ) {
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'ERROR: JSON file not found: ' . basename( $json_path );
		return array(
			'batch_items' => array(),
			'batch_guids' => array(),
			'cancelled'   => false,
			'lines_read'  => 0,
		);
	}

	if ( ! is_readable( $json_path ) ) {
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'ERROR: JSON file not readable: ' . basename( $json_path );
		return array(
			'batch_items' => array(),
			'batch_guids' => array(),
			'cancelled'   => false,
			'lines_read'  => 0,
		);
	}

	$batch_json_result = load_json_batch_streaming( $json_path, $start_index, $batch_size );
	$batch_json_items  = $batch_json_result['items'];
	$lines_read        = $batch_json_result['lines_read'];

	$batch_items = array();
	$batch_guids = array();
	$loaded_count = count( $batch_json_items );

	$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Loaded $loaded_count items from JSONL (batch size: $batch_size)";

	if ( $loaded_count == 0 ) {
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'WARNING: No items loaded from JSONL file - check file integrity';
		return array(
			'batch_items' => $batch_items,
			'batch_guids' => $batch_guids,
			'cancelled'   => false,
			'lines_read'  => $lines_read,
		);
	}

	$valid_items = 0;
	$missing_guids = 0;

	for ( $i = 0; $i < $loaded_count; $i++ ) {
		$current_index = $start_index + $i;

		if ( get_transient( 'import_cancel' ) == true ) {
			$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Import cancelled at #' . ( $current_index + 1 );
			update_option( 'job_import_progress', $current_index, false );

			return array(
				'cancelled'  => true,
				'logs'       => $logs,
				'lines_read' => 0,
			);
		}

		$item = $batch_json_items[ $i ];
		$guid = $item['guid'] ?? '';

		if ( empty( $guid ) ) {
			++$missing_guids;
			$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Skipped #' . ( $current_index + 1 ) . ': Empty GUID';
			continue;
		}

		$batch_guids[]        = $guid;
		$batch_items[ $guid ] = array(
			'item'  => $item,
			'index' => $current_index,
		);
		++$valid_items;
	}

	$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Prepared $valid_items valid items for processing ($missing_guids missing GUIDs)";

	return array(
		'batch_items' => $batch_items,
		'batch_guids' => $batch_guids,
		'cancelled'   => false,
		'lines_read'  => $lines_read,
	);
}

/**
 * Load a batch of items from JSONL file with memory-efficient streaming.
 *
 * @param  string $json_path   Path to JSONL file.
 * @param  int    $start_index Starting index.
 * @param  int    $batch_size  Batch size.
 * @return array Array of JSON items.
 */
function load_json_batch_streaming( $json_path, $start_index, $batch_size ) {
	$batch_size = max( 1, (int) $batch_size );

	$items = array();
	$lines_read = 0;

	if ( ( $handle = fopen( $json_path, 'r' ) ) === false ) {
		return array( 'items' => $items, 'lines_read' => $lines_read );
	}

	// Skip to start_index
	$current_line = 0;
	while ( $current_line < $start_index && fgets( $handle ) !== false ) {
		$current_line++;
	}

	// Read batch_size items
	$items_read = 0;
	$bom = "\xef\xbb\xbf";

	while ( $items_read < $batch_size && ( $line = fgets( $handle ) ) !== false ) {
		$lines_read++;
		$line = trim( $line );

		// Remove BOM if present
		if ( substr( $line, 0, 3 ) === $bom ) {
			$line = substr( $line, 3 );
		}

		if ( empty( $line ) ) {
			continue;
		}

		$item = json_decode( $line, true );
		if ( $item !== null ) {
			$items[] = $item;
			$items_read++;
		}
	}

	fclose( $handle );

	return array( 'items' => $items, 'lines_read' => $lines_read );
}
