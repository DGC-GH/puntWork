<?php

/**
 * JSONL file combination utilities.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function combine_jsonl_files( $feeds, $output_dir, $total_items, &$logs, $chunk_size = 0, $chunk_offset = 0 ) {
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [JSONL-COMBINE-START] ===== COMBINE_JSONL_FILES START =====' );
		error_log( '[PUNTWORK] [JSONL-COMBINE-START] feeds count: ' . count( $feeds ) );
		error_log( '[PUNTWORK] [JSONL-COMBINE-START] total_items: ' . $total_items );
		error_log( '[PUNTWORK] [JSONL-COMBINE-START] chunk_size: ' . $chunk_size );
		error_log( '[PUNTWORK] [JSONL-COMBINE-START] chunk_offset: ' . $chunk_offset );
		error_log( '[PUNTWORK] [JSONL-COMBINE-START] output_dir: ' . $output_dir );
		error_log( '[PUNTWORK] [JSONL-COMBINE-START] Memory usage at start: ' . memory_get_usage( true ) . ' bytes' );
	}

	// Use advanced JSONL processor for better performance
	$combined_json_path = $output_dir . 'combined-jobs.jsonl';
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] Combined JSONL path: ' . $combined_json_path );
	}

	// Determine processing mode based on feed count and system capabilities
	$feed_files = array();
	foreach ( $feeds as $feed_key => $url ) {
		$feed_file    = $output_dir . $feed_key . '.jsonl';
		$feed_files[] = $feed_file;
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] Feed file for ' . $feed_key . ': ' . $feed_file . ' (exists: ' . ( file_exists( $feed_file ) ? 'yes' : 'no' ) . ')' );
			if ( file_exists( $feed_file ) ) {
				$size = filesize( $feed_file );
				error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG]   - Size: ' . $size . ' bytes' );
			}
		}
	}

	// Filter to existing files only
	$existing_feeds = array_filter( $feed_files, 'file_exists' );
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] Found ' . count( $existing_feeds ) . ' existing feed files out of ' . count( $feed_files ) );
	}

	if ( empty( $existing_feeds ) ) {
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'No feed files found to combine - check if feed processing completed successfully';
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-COMBINE-ERROR] No feed files found to combine' );
		}
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-COMBINE-END] ===== COMBINE_JSONL_FILES END (NO FILES) =====' );
		}

		return;
	}

	// For chunked processing, use a different approach
	if ( $chunk_size > 0 ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] Using chunked processing mode' );
		}
		return combine_jsonl_files_chunked( $feeds, $output_dir, $total_items, $logs, $chunk_size, $chunk_offset );
	}

	// Log details of existing files
	foreach ( $existing_feeds as $feed_file ) {
		$size     = filesize( $feed_file );
		$basename = basename( $feed_file );
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] Will process: ' . $basename . ' (' . $size . ' bytes)' );
			if ( $size > 0 ) {
				// Check first line
				$handle = fopen( $feed_file, 'r' );
				if ( $handle ) {
					$first_line = fgets( $handle );
					fclose( $handle );
					error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG]   First line: ' . substr( trim( $first_line ), 0, 100 ) . ( strlen( $first_line ) > 100 ? '...[truncated]' : '' ) );
				}
			}
		}
	}

	// Choose processing strategy
	$feed_count      = count( $existing_feeds );
	$use_parallel    = $feed_count > 3 && function_exists( 'pcntl_fork' ) && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX );
	$use_progressive = file_exists( $combined_json_path ) && $feed_count < $feed_count; // Use progressive for small updates

	$processing_stats = array();

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] Processing strategy: feed_count=' . $feed_count . ', use_parallel=' . ( $use_parallel ? 'true' : 'false' ) . ', use_progressive=' . ( $use_progressive ? 'true' : 'false' ) );
	}

	if ( $use_parallel ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] Using parallel processing' );
		}
		$success = \Puntwork\Utilities\AdvancedJsonlProcessor::combineJsonlParallel( $existing_feeds, $combined_json_path, $processing_stats );
		$method  = 'parallel';
	} elseif ( $use_progressive ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] Using progressive processing' );
		}
		$success = \Puntwork\Utilities\AdvancedJsonlProcessor::combineJsonlProgressive( $existing_feeds, $combined_json_path, $processing_stats );
		$method  = 'progressive';
	} else {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] Using streaming processing' );
		}
		$success = \Puntwork\Utilities\AdvancedJsonlProcessor::combineJsonlStreaming( $existing_feeds, $combined_json_path, $processing_stats );
		$method  = 'streaming';
	}

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] Processing completed with success=' . ( $success ? 'true' : 'false' ) );
	}

	if ( ! $success ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-COMBINE-ERROR] Advanced processing failed, falling back to basic method' );
			error_log( '[PUNTWORK] [JSONL-COMBINE-ERROR] Advanced error: ' . ( $processing_stats['error'] ?? 'Unknown error' ) );
		}

		// Fall back to basic combination method
		combine_jsonl_files_fallback( $feeds, $output_dir, $total_items, $logs );
		return;
	}

	// Log results
	$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . sprintf(
		'Advanced JSONL combination (%s): %d files processed, %d unique items, %d duplicates removed in %.3f seconds',
		$method,
		$processing_stats['total_files'] ?? count( $existing_feeds ),
		$processing_stats['unique_items'] ?? 0,
		$processing_stats['duplicates_removed'] ?? 0,
		$processing_stats['processing_time'] ?? 0
	);

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] Results: files=' . ( $processing_stats['total_files'] ?? count( $existing_feeds ) ) . ', unique=' . ( $processing_stats['unique_items'] ?? 0 ) . ', duplicates=' . ( $processing_stats['duplicates_removed'] ?? 0 ) . ', time=' . ( $processing_stats['processing_time'] ?? 0 ) );
	}

	PuntWorkLogger::info(
		'Advanced JSONL combination completed',
		PuntWorkLogger::CONTEXT_FEED,
		array(
			'method'             => $method,
			'files_processed'    => count( $existing_feeds ),
			'unique_items'       => $processing_stats['unique_items'] ?? 0,
			'duplicates_removed' => $processing_stats['duplicates_removed'] ?? 0,
			'processing_time'    => $processing_stats['processing_time'] ?? 0,
			'memory_peak_mb'     => $processing_stats['memory_peak_mb'] ?? 0,
		)
	);

	// Check if combined file was created
	if ( file_exists( $combined_json_path ) ) {
		$combined_size = filesize( $combined_json_path );
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] Combined file created: ' . $combined_size . ' bytes' );
		}
	} elseif ( $debug_mode ) {
		error_log( '[PUNTWORK] [JSONL-COMBINE-ERROR] Combined file was not created' );
	}

	// Compress the final file
	gzip_file( $combined_json_path, $combined_json_path . '.gz' );
	PuntWorkLogger::info(
		'GZIP compression completed',
		PuntWorkLogger::CONTEXT_FEED,
		array(
			'gz_file' => $combined_json_path . '.gz',
		)
	);
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] GZIP compression completed for ' . $combined_json_path );
	}

	// Optimize JSONL for batch processing synergy
	$optimization_stats   = array();
	$optimization_success = \Puntwork\Utilities\JsonlOptimizer::optimizeForBatchProcessing(
		$combined_json_path,
		$combined_json_path . '.optimized',
		$optimization_stats
	);

	if ( $optimization_success ) {
		// Replace original with optimized version
		rename( $combined_json_path . '.optimized', $combined_json_path );

		// Re-compress the optimized file
		gzip_file( $combined_json_path, $combined_json_path . '.gz' );

		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . sprintf(
			'JSONL optimization completed: %d strategies applied, %.3f seconds, %.1f MB memory',
			count( $optimization_stats['strategies_applied'] ),
			$optimization_stats['optimization_time'],
			$optimization_stats['memory_peak_mb']
		);

		PuntWorkLogger::info(
			'JSONL optimization completed',
			PuntWorkLogger::CONTEXT_FEED,
			array(
				'strategies_applied' => $optimization_stats['strategies_applied'],
				'optimization_time'  => $optimization_stats['optimization_time'],
				'memory_peak_mb'     => $optimization_stats['memory_peak_mb'],
				'grouping_stats'     => $optimization_stats['grouping_stats'],
			)
		);
	} else {
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'JSONL optimization failed, using unoptimized version';
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] JSONL optimization failed, proceeding with unoptimized file' );
		}
	}

	// Final check
	if ( file_exists( $combined_json_path ) ) {
		$final_size = filesize( $combined_json_path );
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-COMBINE-DEBUG] Final combined file size: ' . $final_size . ' bytes' );
			error_log( '[PUNTWORK] [JSONL-COMBINE-END] ===== COMBINE_JSONL_FILES END =====' );
		}
	}
}

/**
 * Fallback JSONL combination method (original implementation).
 */
function combine_jsonl_files_fallback( $feeds, $output_dir, $total_items, &$logs ) {
	// Ensure output directory exists
	if ( ! wp_mkdir_p( $output_dir ) || ! is_writable( $output_dir ) ) {
		throw new \Exception( 'Feeds directory not writable' );
	}

	$combined_json_path = $output_dir . 'combined-jobs.jsonl';
	$combined_gz_path   = $combined_json_path . '.gz';
	$combined_handle    = fopen( $combined_json_path, 'w' );
	if ( ! $combined_handle ) {
		throw new \Exception( 'Cant open combined JSONL' );
	}

	$seen_guids      = array();
	$duplicate_count = 0;
	$unique_count    = 0;

	PuntWorkLogger::info(
		'Fallback JSONL file combination started',
		PuntWorkLogger::CONTEXT_FEED,
		array(
			'feeds_count' => count( $feeds ),
			'output_dir'  => $output_dir,
			'total_items' => $total_items,
		)
	);
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [JSONL-COMBINE] Fallback: Starting JSONL file combination, feeds count: ' . count( $feeds ) . ', output_dir: ' . $output_dir );
	}

	foreach ( $feeds as $feed_key => $url ) {
		$feed_json_path = $output_dir . $feed_key . '.jsonl';
		PuntWorkLogger::debug(
			"Processing feed file: {$feed_key}",
			PuntWorkLogger::CONTEXT_FEED,
			array(
				'feed_file' => $feed_json_path,
				'exists'    => file_exists( $feed_json_path ),
			)
		);
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [JSONL-COMBINE] Fallback: Processing feed: ' . $feed_key . ', file: ' . $feed_json_path . ', exists: ' . ( file_exists( $feed_json_path ) ? 'yes' : 'no' ) );
		}
		if ( file_exists( $feed_json_path ) ) {
			$feed_handle = fopen( $feed_json_path, 'r' );
			if ( $feed_handle ) {
				$feed_line_count = 0;
				while ( ( $line = fgets( $feed_handle ) ) !== false ) {
					$line = trim( $line );
					if ( empty( $line ) ) {
						continue;
					}

					// Parse JSON to check GUID
					$job_data = json_decode( $line, true );
					if ( $job_data == null ) {
						// Invalid JSON, skip
						PuntWorkLogger::debug( 'Skipping invalid JSON line in feed: ' . $feed_key, PuntWorkLogger::CONTEXT_FEED );

						continue;
					}

					$guid = isset( $job_data['guid'] ) ? trim( $job_data['guid'] ) : '';
					if ( empty( $guid ) ) {
						// No GUID, include but log
						fwrite( $combined_handle, $line . "\n" );
						++$unique_count;

						continue;
					}

					// Check for duplicates
					if ( isset( $seen_guids[ $guid ] ) ) {
						++$duplicate_count;

						continue; // Skip duplicate
					}

					// New unique job
					$seen_guids[ $guid ] = true;
					fwrite( $combined_handle, $line . "\n" );
					++$unique_count;
					++$feed_line_count;
				}
				fclose( $feed_handle );
				PuntWorkLogger::debug(
					"Feed processed: {$feed_key}",
					PuntWorkLogger::CONTEXT_FEED,
					array(
						'lines_added' => $feed_line_count,
					)
				);
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[PUNTWORK] [JSONL-COMBINE] Fallback: Feed ' . $feed_key . ' processed, lines added: ' . $feed_line_count );
				}
			} else {
				PuntWorkLogger::error( "Could not open feed file: {$feed_json_path}", PuntWorkLogger::CONTEXT_FEED );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[PUNTWORK] [JSONL-COMBINE] Fallback: Could not open feed file: ' . $feed_json_path );
				}
			}
		} else {
			PuntWorkLogger::warn( "Feed file not found: {$feed_json_path}", PuntWorkLogger::CONTEXT_FEED );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [JSONL-COMBINE] Fallback: Feed file not found: ' . $feed_json_path );
			}
		}
	}

	fclose( $combined_handle );
	@chmod( $combined_json_path, 0644 );

	$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Combined JSONL ($unique_count unique items, $duplicate_count duplicates removed)";
	PuntWorkLogger::info(
		'Fallback JSONL combination completed',
		PuntWorkLogger::CONTEXT_FEED,
		array(
			'unique_count'    => $unique_count,
			'duplicate_count' => $duplicate_count,
			'total_processed' => $unique_count + $duplicate_count,
		)
	);
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( "Combined JSONL ($unique_count unique items, $duplicate_count duplicates removed)" );
	}
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [JSONL-COMBINE] Fallback: JSONL combination completed, unique_count=' . $unique_count . ', duplicate_count=' . $duplicate_count );
	}

	gzip_file( $combined_json_path, $combined_gz_path );
	PuntWorkLogger::info(
		'GZIP compression completed',
		PuntWorkLogger::CONTEXT_FEED,
		array(
			'gz_file' => $combined_gz_path,
		)
	);
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [JSONL-COMBINE] Fallback: GZIP compression completed for ' . $combined_gz_path );
	}
}

/**
 * Chunked JSONL combination to avoid timeouts with large datasets.
 */
function combine_jsonl_files_chunked( $feeds, $output_dir, $total_items, &$logs, $chunk_size, $chunk_offset ) {
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [JSONL-CHUNKED] ===== COMBINE_JSONL_FILES_CHUNKED START =====' );
		error_log( '[PUNTWORK] [JSONL-CHUNKED] chunk_size: ' . $chunk_size . ', chunk_offset: ' . $chunk_offset );
	}

	$combined_json_path = $output_dir . 'combined-jobs.jsonl';
	$temp_json_path     = $output_dir . 'combined-jobs.jsonl.temp';

	// For first chunk, initialize the temp file
	if ( $chunk_offset === 0 ) {
		if ( file_exists( $temp_json_path ) ) {
			unlink( $temp_json_path );
		}
		if ( file_exists( $combined_json_path ) ) {
			unlink( $combined_json_path );
		}
	}

	// Open temp file for appending
	$temp_handle = fopen( $temp_json_path, 'a' );
	if ( ! $temp_handle ) {
		throw new \Exception( 'Cannot open temp file for writing: ' . $temp_json_path );
	}

	$seen_guids      = array();
	$duplicate_count = 0;
	$unique_count    = 0;
	$processed_count = 0;
	$max_to_process  = $chunk_size;

	PuntWorkLogger::info(
		'Chunked JSONL file combination started',
		PuntWorkLogger::CONTEXT_FEED,
		array(
			'feeds_count'  => count( $feeds ),
			'output_dir'   => $output_dir,
			'total_items'  => $total_items,
			'chunk_size'   => $chunk_size,
			'chunk_offset' => $chunk_offset,
		)
	);

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [JSONL-CHUNKED] Starting chunked JSONL combination, chunk_size=' . $chunk_size . ', offset=' . $chunk_offset );
	}

	foreach ( $feeds as $feed_key => $url ) {
		$feed_json_path = $output_dir . $feed_key . '.jsonl';

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-CHUNKED] Processing feed: ' . $feed_key . ', file: ' . $feed_json_path );
		}

		if ( ! file_exists( $feed_json_path ) ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [JSONL-CHUNKED] Feed file not found: ' . $feed_json_path );
			}
			continue;
		}

		$feed_handle = fopen( $feed_json_path, 'r' );
		if ( ! $feed_handle ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [JSONL-CHUNKED] Could not open feed file: ' . $feed_json_path );
			}
			continue;
		}

		$line_number = 0;
		while ( ( $line = fgets( $feed_handle ) ) !== false ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			// Skip lines before offset
			if ( $processed_count < $chunk_offset ) {
				++$processed_count;
				continue;
			}

			// Stop if we've processed enough for this chunk
			if ( $processed_count >= $chunk_offset + $max_to_process ) {
				break 2; // Break out of both loops
			}

			// Parse JSON to check GUID
			$job_data = json_decode( $line, true );
			if ( $job_data == null ) {
				// Invalid JSON, skip
				PuntWorkLogger::debug( 'Skipping invalid JSON line in feed: ' . $feed_key, PuntWorkLogger::CONTEXT_FEED );
				++$processed_count;
				continue;
			}

			$guid = isset( $job_data['guid'] ) ? trim( $job_data['guid'] ) : '';
			if ( empty( $guid ) ) {
				// No GUID, include but log
				fwrite( $temp_handle, $line . "\n" );
				++$unique_count;
				++$processed_count;
				continue;
			}

			// Check for duplicates (in this chunk only, since we can't maintain full state across chunks)
			if ( isset( $seen_guids[ $guid ] ) ) {
				++$duplicate_count;
				++$processed_count;
				continue; // Skip duplicate
			}

			// New unique job
			$seen_guids[ $guid ] = true;
			fwrite( $temp_handle, $line . "\n" );
			++$unique_count;
			++$processed_count;
		}

		fclose( $feed_handle );

		// Check if we've reached the chunk limit
		if ( $processed_count >= $chunk_offset + $max_to_process ) {
			break;
		}
	}

	fclose( $temp_handle );

	$chunk_processed = $processed_count - $chunk_offset;

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [JSONL-CHUNKED] Chunk processed: ' . $chunk_processed . ' items, unique_count=' . $unique_count . ', duplicate_count=' . $duplicate_count );
	}

	// Check if this is the final chunk
	$is_final_chunk = ( $processed_count >= $total_items );

	if ( $is_final_chunk ) {
		// Rename temp file to final file
		if ( file_exists( $combined_json_path ) ) {
			unlink( $combined_json_path );
		}
		rename( $temp_json_path, $combined_json_path );

		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Chunked JSONL combination completed ($unique_count unique items, $duplicate_count duplicates removed)";

		PuntWorkLogger::info(
			'Chunked JSONL combination completed',
			PuntWorkLogger::CONTEXT_FEED,
			array(
				'unique_count'    => $unique_count,
				'duplicate_count' => $duplicate_count,
				'total_processed' => $unique_count + $duplicate_count,
				'chunks_used'     => ceil( $total_items / $chunk_size ),
			)
		);

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-CHUNKED] Final chunk completed, unique_count=' . $unique_count . ', duplicate_count=' . $duplicate_count );
		}
	} else {
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Chunked JSONL combination progress: processed $chunk_processed items in this chunk";

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [JSONL-CHUNKED] Chunk completed, more chunks needed' );
		}
	}

	return array(
		'processed_in_chunk' => $chunk_processed,
		'unique_count'       => $unique_count,
		'duplicate_count'    => $duplicate_count,
		'is_final_chunk'     => $is_final_chunk,
		'next_offset'        => $processed_count,
	);
}
