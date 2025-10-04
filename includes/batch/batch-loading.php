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

class JsonlIterator implements \Iterator {

	private string $filePath;
	private int $startIndex;
	private int $batchSize;
	private $handle;
	private int $currentIndex = 0;
	private int $loadedCount  = 0;
	private $currentItem      = null;
	private int $key          = 0;

	public function __construct( string $filePath, int $startIndex, int $batchSize ) {
		$this->filePath   = $filePath;
		$this->startIndex = $startIndex;
		$this->batchSize  = $batchSize;
	}

	public function rewind(): void {
		if ( $this->handle ) {
			fclose( $this->handle );
		}
		$this->handle       = fopen( $this->filePath, 'r' );
		$this->currentIndex = 0;
		$this->loadedCount  = 0;
		$this->key          = 0;
		$this->currentItem  = null;
		$this->skipToStart();
	}

	private function skipToStart(): void {
		while ( $this->currentIndex < $this->startIndex && ( $line = fgets( $this->handle ) ) !== false ) {
			++$this->currentIndex;
		}
	}

	#[\ReturnTypeWillChange]
	public function current() {
		return $this->currentItem;
	}

	public function key(): int {
		return $this->key;
	}

	public function next(): void {
		++$this->key;
		$this->currentItem = null;

		if ( $this->loadedCount >= $this->batchSize ) {
			return;
		}

		while ( ( $line = fgets( $this->handle ) ) !== false ) {
			++$this->currentIndex;
			$line = trim( $line );
			if ( ! empty( $line ) ) {
				$item = json_decode( $line, true );
				if ( $item !== null ) {
					$this->currentItem = $item;
					++$this->loadedCount;

					return;
				}
			}
		}
	}

	public function valid(): bool {
		return $this->currentItem !== null && $this->loadedCount <= $this->batchSize;
	}

	public function __destruct() {
		if ( $this->handle ) {
			fclose( $this->handle );
		}
	}
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
		error_log( '[PUNTWORK] [LOAD-DEBUG] Calling load_json_batch' );
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

	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] load_json_batch called with: path=' . basename( $json_path ) . ', start_index=' . $start_index . ', batch_size=' . $batch_size );
		error_log( '[PUNTWORK] load_json_batch: file exists: ' . ( file_exists( $json_path ) ? 'yes' : 'no' ) );
	}

	if ( file_exists( $json_path ) ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] load_json_batch: file size: ' . filesize( $json_path ) . ' bytes' );
			error_log( '[PUNTWORK] load_json_batch: file readable: ' . ( is_readable( $json_path ) ? 'yes' : 'no' ) );
		}
	} else {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] load_json_batch: FILE DOES NOT EXIST: ' . $json_path );
		}

		return array();
	}

	$items         = array();
	$count         = 0;
	$current_index = 0;
	$lines_read    = 0;
	$empty_lines   = 0;
	$invalid_json  = 0;
	$bom           = "\xef\xbb\xbf";

	try {
		$handle = fopen( $json_path, 'r' );
		if ( $handle == false ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] load_json_batch: Cannot open file: ' . $json_path . ', error: ' . ( error_get_last()['message'] ?? 'unknown' ) );
			}

			return array();
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] load_json_batch: File opened successfully, skipping to start_index=' . $start_index );
		}

		// Skip to start_index
		$skip_count = 0;
		while ( $current_index < $start_index && ( $line = fgets( $handle ) ) !== false ) {
			++$current_index;
			++$skip_count;
			if ( $skip_count > $start_index + 1000 ) { // Safety check to prevent infinite loop
				error_log( '[PUNTWORK] load_json_batch: Safety break - skipped ' . $skip_count . ' lines, current_index=' . $current_index . ', start_index=' . $start_index );
				break;
			}
		}

		if ( $debug_mode && $current_index < $start_index ) {
			error_log( '[PUNTWORK] load_json_batch: Could not skip to start_index=' . $start_index . ', reached current_index=' . $current_index . ', file may be shorter than expected' );
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] load_json_batch: Skipped to index ' . $current_index . ', now reading batch_size=' . $batch_size . ' items' );
		}

		// Read batch_size items
		$read_attempts = 0;
		$fgets_failed = false;
		while ( $count < $batch_size && ( $line = fgets( $handle ) ) !== false ) {
			++$lines_read;
			++$read_attempts;
			if ( $read_attempts > $batch_size + 100 ) { // Safety check
				error_log( '[PUNTWORK] load_json_batch: Safety break - read ' . $read_attempts . ' lines, count=' . $count . ', batch_size=' . $batch_size );
				break;
			}
			$line = trim( $line );
			// Remove BOM if present
			if ( substr( $line, 0, 3 ) === $bom ) {
				$line = substr( $line, 3 );
			}
			if ( ! empty( $line ) ) {
				$item = json_decode( $line, true );
				if ( $item !== null ) {
					$items[] = $item;
					++$count;
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] load_json_batch: Successfully decoded item ' . $count . ' at file position ' . ( $start_index + $lines_read ) . ' with GUID: ' . ( $item['guid'] ?? 'MISSING' ) );
					}
				} else {
					++$invalid_json;
					if ( $debug_mode ) {
						$json_error       = json_last_error_msg();
						$json_error_code  = json_last_error();
						$line_preview     = substr( $line, 0, 200 );
						$line_length      = strlen( $line );
						$file_line_number = $start_index + $lines_read;

						error_log( '[PUNTWORK] load_json_batch: INVALID JSON at file line ' . $file_line_number . ' (position ' . ( $start_index + $lines_read ) . ')' );
						error_log( '[PUNTWORK] load_json_batch: JSON Error: ' . $json_error . ' (code: ' . $json_error_code . ')' );
						error_log( '[PUNTWORK] load_json_batch: Line length: ' . $line_length . ' characters' );
						error_log( '[PUNTWORK] load_json_batch: Line preview: ' . $line_preview . ( strlen( $line ) > 200 ? '...[truncated]' : '' ) );
						error_log( '[PUNTWORK] load_json_batch: Line ends with: ' . substr( $line, -50 ) );

						// Log if line appears to be truncated or malformed
						if ( strpos( $line, '{' ) == false || strpos( $line, '}' ) == false ) {
							error_log( '[PUNTWORK] load_json_batch: WARNING - Line missing JSON braces' );
						}
						if ( substr_count( $line, '{' ) !== substr_count( $line, '}' ) ) {
							error_log( '[PUNTWORK] load_json_batch: WARNING - Unmatched braces: ' . substr_count( $line, '{' ) . ' opening, ' . substr_count( $line, '}' ) . ' closing' );
						}
					}
				}
			} else {
				++$empty_lines;
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] load_json_batch: Empty line at ' . ( $start_index + $lines_read ) );
				}
			}
			++$current_index;
		}

		// Check if fgets failed
		if ( $line === false && $read_attempts == 0 ) {
			$fgets_failed = true;
			error_log( '[PUNTWORK] load_json_batch: fgets failed immediately after skipping to start_index=' . $start_index );
		}

		fclose( $handle );
	} catch ( \Exception $e ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] load_json_batch: Exception: ' . $e->getMessage() );
		}
		if ( isset( $handle ) && $handle ) {
			fclose( $handle );
		}

		return array();
	}

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] load_json_batch: returning ' . count( $items ) . ' items (read ' . $lines_read . ' lines, empty: ' . $empty_lines . ', invalid JSON: ' . $invalid_json . ', start_index: ' . $start_index . ', batch_size: ' . $batch_size . ')' );

		// Log detailed breakdown for debugging
		if ( $lines_read > 0 ) {
			$valid_percentage = round( ( count( $items ) / $lines_read ) * 100, 2 );
			error_log( '[PUNTWORK] load_json_batch: BREAKDOWN - Total lines read: ' . $lines_read . ', Valid items: ' . count( $items ) . ' (' . $valid_percentage . '%), Empty lines: ' . $empty_lines . ', Invalid JSON: ' . $invalid_json );

			if ( $invalid_json > 0 ) {
				error_log( '[PUNTWORK] load_json_batch: WARNING - Found ' . $invalid_json . ' invalid JSON lines in this batch!' );
			}

			if ( $valid_percentage < 50 && $lines_read > 5 ) {
				error_log( '[PUNTWORK] load_json_batch: CRITICAL - Only ' . $valid_percentage . '% of lines are valid JSON. File may be corrupted or not a proper JSONL file.' );
			}
		}

		if ( empty( $items ) ) {
			error_log( '[PUNTWORK] load_json_batch: WARNING - NO ITEMS LOADED! This will cause 0 processed items.' );
			error_log( '[PUNTWORK] load_json_batch: DEBUG - start_index=' . $start_index . ', batch_size=' . $batch_size . ', lines_read=' . $lines_read . ', empty_lines=' . $empty_lines . ', invalid_json=' . $invalid_json );
			// Try to read first line manually
			$debug_handle = fopen( $json_path, 'r' );
			if ( $debug_handle ) {
				$first_line = fgets( $debug_handle );
				error_log( '[PUNTWORK] load_json_batch: DEBUG - First line: ' . substr( trim( $first_line ), 0, 100 ) );
				fclose( $debug_handle );
			}
		}
	}

	// Always log critical information
	error_log( '[PUNTWORK] load_json_batch: returning ' . count( $items ) . ' items (read ' . $lines_read . ' lines, empty: ' . $empty_lines . ', invalid JSON: ' . $invalid_json . ', start_index: ' . $start_index . ', batch_size: ' . $batch_size . ')' );

	if ( empty( $items ) ) {
		error_log( '[PUNTWORK] load_json_batch: WARNING - NO ITEMS LOADED! This will cause 0 processed items.' );
		error_log( '[PUNTWORK] load_json_batch: DEBUG - start_index=' . $start_index . ', batch_size=' . $batch_size . ', lines_read=' . $lines_read . ', empty_lines=' . $empty_lines . ', invalid_json=' . $invalid_json );
		// Try to read first line manually
		$debug_handle = fopen( $json_path, 'r' );
		if ( $debug_handle ) {
			$first_line = fgets( $debug_handle );
			error_log( '[PUNTWORK] load_json_batch: DEBUG - First line: ' . substr( trim( $first_line ), 0, 100 ) );
			fclose( $debug_handle );
		}
	}

	return array(
		'items'      => $items,
		'lines_read' => $lines_read,
	);
}
