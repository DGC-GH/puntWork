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

// Include performance utilities
require_once __DIR__ . '/../utilities/performance-functions.php';

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
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [LOAD-START] ===== LOAD_AND_PREPARE_BATCH_ITEMS START =====' );
		error_log(
			'[PUNTWORK] [LOAD-START] load_and_prepare_batch_items called with: ' . json_encode(
				array(
					'json_path'   => basename( $json_path ),
					'start_index' => $start_index,
					'batch_size'  => $batch_size,
					'threshold'   => $threshold,
					'file_exists' => file_exists( $json_path ),
					'file_size'   => file_exists( $json_path ) ? filesize( $json_path ) : 'N/A',
					'is_readable' => is_readable( $json_path ),
				)
			)
		);
		error_log( '[PUNTWORK] [LOAD-START] Memory usage at start: ' . memory_get_usage( true ) . ' bytes' );
	}

	// Check if file exists and is readable
	if ( ! file_exists( $json_path ) ) {
		error_log( '[PUNTWORK] [LOAD-ERROR] JSON file does not exist: ' . $json_path );
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'ERROR: JSON file not found: ' . basename( $json_path );

		return array(
			'batch_items' => array(),
			'batch_guids' => array(),
			'cancelled'   => false,
			'lines_read'  => 0,
		);
	}

	if ( ! is_readable( $json_path ) ) {
		error_log( '[PUNTWORK] [LOAD-ERROR] JSON file not readable: ' . $json_path );
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'ERROR: JSON file not readable: ' . basename( $json_path );

		return array(
			'batch_items' => array(),
			'batch_guids' => array(),
			'cancelled'   => false,
			'lines_read'  => 0,
		);
	}

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [LOAD-DEBUG] About to call load_json_batch with parameters:' );
		error_log( '[PUNTWORK] [LOAD-DEBUG]   json_path: ' . $json_path );
		error_log( '[PUNTWORK] [LOAD-DEBUG]   start_index: ' . $start_index );
		error_log( '[PUNTWORK] [LOAD-DEBUG]   batch_size: ' . $batch_size );
		error_log( '[PUNTWORK] [LOAD-DEBUG]   file_size: ' . (file_exists($json_path) ? filesize($json_path) : 'N/A') );
		error_log( '[PUNTWORK] [LOAD-DEBUG]   get_json_item_count: ' . get_json_item_count($json_path) );
	}

	$batch_json_result = load_json_batch( $json_path, $start_index, $batch_size );
	$batch_json_items  = $batch_json_result['items'] ?? $batch_json_result; // fallback for array
	$lines_read        = $batch_json_result['lines_read'] ?? count( $batch_json_items );

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [LOAD-DEBUG] load_json_batch returned ' . count( $batch_json_items ) . ' items, lines_read=' . $lines_read );
		error_log( '[PUNTWORK] [LOAD-DEBUG] Memory usage after load_json_batch: ' . memory_get_usage( true ) . ' bytes' );
	}

	$batch_items  = array();
	$batch_guids  = array();
	$loaded_count = count( $batch_json_items );

	$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Loaded $loaded_count items from JSONL (batch size: $batch_size)";

	if ( $loaded_count == 0 ) {
		error_log( '[PUNTWORK] [LOAD-ERROR] NO ITEMS LOADED FROM JSONL! This is the root cause of 0 processed items.' );
		error_log( '[PUNTWORK] [LOAD-ERROR] json_path=' . $json_path . ', start_index=' . $start_index . ', batch_size=' . $batch_size );
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'WARNING: No items loaded from JSONL file - check file integrity';

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [LOAD-END] ===== LOAD_AND_PREPARE_BATCH_ITEMS END (NO ITEMS) =====' );
		}

		return array(
			'batch_items' => $batch_items,
			'batch_guids' => $batch_guids,
			'cancelled'   => false,
			'lines_read'  => $lines_read,
		);
	}

	$valid_items   = 0;
	$skipped_items = 0;
	$missing_guids = 0;

	$total_items = count( $batch_json_items );
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [LOAD-DEBUG] Processing ' . $total_items . ' loaded items' );
	}

	for ( $i = 0; $i < $total_items; $i++ ) {
		$current_index = $start_index + $i;

		if ( get_transient( 'import_cancel' ) == true ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [LOAD-CANCEL] Import cancelled at index ' . $current_index );
			}
			$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Import cancelled at #' . ( $current_index + 1 );
			update_option( 'job_import_progress', $current_index, false );

			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [LOAD-END] ===== LOAD_AND_PREPARE_BATCH_ITEMS END (CANCELLED) =====' );
			}

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
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [LOAD-WARN] Empty GUID at index ' . $current_index . ', item keys: ' . implode( ', ', array_keys( $item ) ) );
			}
			$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Skipped #' . ( $current_index + 1 ) . ': Empty GUID - Item keys: ' . implode( ', ', array_keys( $item ) );

			continue;
		}

		$batch_guids[]        = $guid;
		$logs[]               = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Processing #' . ( $current_index + 1 ) . ' GUID: ' . $guid;
		$batch_items[ $guid ] = array(
			'item'  => $item,
			'index' => $current_index,
		);
		++$valid_items;

		// Enhanced memory management
		$memory_status = check_batch_memory_usage( $current_index, $threshold * 0.8 ); // More aggressive threshold
		if ( ! empty( $memory_status['actions_taken'] ) ) {
			$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Memory management: ' . implode( ', ', $memory_status['actions_taken'] );
		}

		// Memory check
		if ( memory_get_usage( true ) > $threshold ) {
			$batch_size = max( 1, (int) ( $batch_size * 0.8 ) );
			update_option( 'job_import_batch_size', $batch_size, false );
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [LOAD-WARN] Memory high, reduced batch to ' . $batch_size );
			}
			$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Memory high, reduced batch to ' . $batch_size;
		}

		if ( $i % 5 == 0 ) {
			if ( ob_get_level() > 0 ) {
				ob_flush();
				flush();
			}
		}
		unset( $batch_json_items[ $i ] );
	}
	unset( $batch_json_items );

	$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Prepared $valid_items valid items for processing (skipped $skipped_items items, $missing_guids missing GUIDs)";

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [LOAD-DEBUG] Prepared ' . $valid_items . ' valid items for processing (skipped: ' . $skipped_items . ', missing GUIDs: ' . $missing_guids . ')' );
		error_log( '[PUNTWORK] [LOAD-DEBUG] Final memory usage: ' . memory_get_usage( true ) . ' bytes' );
		error_log( '[PUNTWORK] [LOAD-END] ===== LOAD_AND_PREPARE_BATCH_ITEMS END =====' );
	}

	return array(
		'batch_items' => $batch_items,
		'batch_guids' => $batch_guids,
		'cancelled'   => false,
		'lines_read'  => $lines_read,
	);
}

/**
 * Load a batch of items from JSONL file with improved performance.
 *
 * @param  string $json_path   Path to JSONL file.
 * @param  int    $start_index Starting index.
 * @param  int    $batch_size  Batch size.
 * @return array Array of JSON items.
 */
function load_json_batch( $json_path, $start_index, $batch_size ) {
	// Ensure batch_size is at least 1
	$batch_size = max( 1, (int) $batch_size );

	// Resolve relative paths to absolute paths
	if ( ! str_starts_with( $json_path, '/' ) ) {
		$json_path = realpath( $json_path ) ?: $json_path;
	}

	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
	// Always log critical information for debugging import issues
	error_log( '[PUNTWORK] load_json_batch: ===== LOAD_JSON_BATCH START =====' );
	error_log( '[PUNTWORK] load_json_batch: called with start_index=' . $start_index . ', batch_size=' . $batch_size . ', json_path=' . $json_path );
	error_log( '[PUNTWORK] load_json_batch: file_exists=' . (file_exists($json_path) ? 'yes' : 'no') . ', is_readable=' . (is_readable($json_path) ? 'yes' : 'no') . ', filesize=' . (file_exists($json_path) ? filesize($json_path) : 'N/A') );
	error_log( '[PUNTWORK] load_json_batch: get_json_item_count=' . get_json_item_count($json_path) );
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] load_json_batch: WP_DEBUG is enabled' );
	} else {
		error_log( '[PUNTWORK] load_json_batch: WP_DEBUG is disabled' );
	}

	$items = array();
	$lines_read = 0;

	if ( ( $handle = fopen( $json_path, 'r' ) ) === false ) {
		error_log( '[PUNTWORK] load_json_batch: CRITICAL - Cannot open file: ' . $json_path . ', error: ' . error_get_last()['message'] ?? 'unknown' );
		return array( 'items' => $items, 'lines_read' => $lines_read );
	}

	error_log( '[PUNTWORK] load_json_batch: File opened successfully, handle is valid: ' . (is_resource($handle) ? 'yes' : 'no') );

	// Skip to start_index
	$current_line = 0;
	$bom = "\xef\xbb\xbf";
	$skipped_lines = 0;
	while ( $current_line < $start_index && ( $line = fgets( $handle ) ) !== false ) {
		$current_line++;
		$skipped_lines++;
		if ( $debug_mode && $skipped_lines <= 5 ) {
			error_log( '[PUNTWORK] load_json_batch: Skipping line ' . $current_line . ', length=' . strlen($line) );
		}
	}

	error_log( '[PUNTWORK] load_json_batch: Skipped ' . $skipped_lines . ' lines to reach start_index=' . $start_index . ', current position at line ' . $current_line );

	// Read batch_size items
	$items_read = 0;
	$empty_lines = 0;
	$invalid_json_lines = 0;
	$total_lines_attempted = 0;

	while ( $items_read < $batch_size && ( $line = fgets( $handle ) ) !== false ) {
		$current_line++;
		$lines_read++;
		$total_lines_attempted++;
		$original_line = $line;
		$line = trim( $line );

		if ( $debug_mode && $total_lines_attempted <= 5 ) {
			error_log( '[PUNTWORK] load_json_batch: Reading line ' . $current_line . ', original length=' . strlen($original_line) . ', trimmed length=' . strlen($line) );
		}

		// Remove BOM if present
		if ( substr( $line, 0, 3 ) === $bom ) {
			$line = substr( $line, 3 );
			if ( $debug_mode && $total_lines_attempted <= 5 ) {
				error_log( '[PUNTWORK] load_json_batch: Removed BOM from line ' . $current_line );
			}
		}

		if ( empty( $line ) ) {
			$empty_lines++;
			if ( $debug_mode && $empty_lines <= 3 ) {
				error_log( '[PUNTWORK] load_json_batch: Empty line at ' . $current_line );
			}
			continue;
		}

		$item = json_decode( $line, true );
		if ( $item !== null ) {
			$items[] = $item;
			$items_read++;
			if ( $debug_mode && $items_read <= 3 ) {
				error_log( '[PUNTWORK] load_json_batch: Successfully decoded item ' . $items_read . ' at line ' . $current_line . ', GUID=' . ($item['guid'] ?? 'MISSING') );
			}
		} else {
			$invalid_json_lines++;
			if ( $debug_mode && $invalid_json_lines <= 3 ) {
				error_log( '[PUNTWORK] load_json_batch: Invalid JSON at line ' . $current_line . ': ' . json_last_error_msg() . ', preview: ' . substr($line, 0, 100) );
			}
		}

		// Safety check to prevent infinite loops
		if ( $total_lines_attempted > $batch_size * 10 ) {
			error_log( '[PUNTWORK] load_json_batch: SAFETY BREAK - attempted ' . $total_lines_attempted . ' lines, breaking to prevent infinite loop' );
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] load_json_batch: SAFETY BREAK - attempted ' . $total_lines_attempted . ' lines, breaking to prevent infinite loop' );
			}
			break;
		}
	}

	error_log( '[PUNTWORK] load_json_batch: Read loop completed - items_read=' . $items_read . ', lines_read=' . $lines_read . ', total_attempted=' . $total_lines_attempted . ', empty=' . $empty_lines . ', invalid=' . $invalid_json_lines );

	fclose( $handle );

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] load_json_batch: returning ' . count( $items ) . ' items (read ' . $lines_read . ' lines, attempted ' . $total_lines_attempted . ' lines, empty=' . $empty_lines . ', invalid_json=' . $invalid_json_lines . ')' );
		if ( count( $items ) == 0 && $lines_read > 0 ) {
			error_log( '[PUNTWORK] load_json_batch: CRITICAL - Read ' . $lines_read . ' lines but got 0 valid items! This indicates file corruption or format issues.' );
		}
		error_log( '[PUNTWORK] load_json_batch: ===== LOAD_JSON_BATCH END =====' );
	}

	// Always log the result for debugging
	error_log( '[PUNTWORK] load_json_batch: RESULT - returning ' . count( $items ) . ' items (lines_read=' . $lines_read . ', attempted=' . $total_lines_attempted . ', empty=' . $empty_lines . ', invalid=' . $invalid_json_lines . ')' );
	if ( count( $items ) == 0 ) {
		error_log( '[PUNTWORK] load_json_batch: CRITICAL - Returning 0 items! This is the root cause of the import failure.' );
	}

	return array( 'items' => $items, 'lines_read' => $lines_read );
}
