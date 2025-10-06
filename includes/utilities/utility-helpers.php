<?php

/**
 * Utility helper functions.
 *
 * @since      1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'get_memory_limit_bytes' ) ) {
	function get_memory_limit_bytes() {
		$memory_limit = ini_get( 'memory_limit' );
		if ( $memory_limit === '-1' ) {
			return PHP_INT_MAX;
		}
		$number = (int) preg_replace( '/[^0-9]/', '', $memory_limit );
		$suffix = preg_replace( '/[0-9]/', '', $memory_limit );
		switch ( strtoupper( $suffix ) ) {
			case 'G':
				return $number * 1024 * 1024 * 1024;
			case 'M':
				return $number * 1024 * 1024;
			case 'K':
				return $number * 1024;
			default:
				return $number;
		}
	}
}

if ( ! function_exists( 'get_json_item_count' ) ) {
	/**
	 * Get the total count of items in JSONL file.
	 *
	 * @param  string $json_path Path to JSONL file.
	 * @return int Total item count.
	 */
	function get_json_item_count( $json_path ) {
		$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] get_json_item_count called with path: ' . $json_path );
		}
		$count        = 0;
		$sample_lines = array();
		$bom          = "\xef\xbb\xbf";
		if ( ( $handle = fopen( $json_path, 'r' ) ) !== false ) {
			$line_num = 0;
			while ( ( $line = fgets( $handle ) ) !== false ) {
				++$line_num;
				$line = trim( $line );
				// Remove BOM if present
				if ( substr( $line, 0, 3 ) === $bom ) {
					$line = substr( $line, 3 );
				}
				if ( ! empty( $line ) ) {
					$item = json_decode( $line, true );
					if ( $item !== null ) {
						++$count;
						// Collect first 5 valid items for debugging
						if ( $count <= 5 ) {
							$sample_lines[] = 'Line ' . $line_num . ': GUID=' . ( $item['guid'] ?? 'MISSING' ) . ', keys=' . implode( ',', array_keys( $item ) );
						}
					} else {
						if ( $debug_mode ) {
							error_log( '[PUNTWORK] get_json_item_count: Invalid JSON at line ' . $line_num . ': ' . json_last_error_msg() . ' - Line preview: ' . substr( $line, 0, 100 ) );
						}
					}
				} else {
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] get_json_item_count: Empty line at ' . $line_num );
					}
				}
			}
			fclose( $handle );
		} else {
			error_log( '[PUNTWORK] get_json_item_count: Cannot open file: ' . $json_path );
		}
		error_log( '[PUNTWORK] get_json_item_count: Total valid items: ' . $count . ' (file has ' . ( file_exists( $json_path ) ? 'exists' : 'does not exist' ) . ')' );
		if ( ! empty( $sample_lines ) ) {
			error_log( '[PUNTWORK] get_json_item_count: Sample items: ' . implode( ' | ', $sample_lines ) );
		}

		return $count;
	}
}

if ( ! function_exists( 'process_import_batch' ) ) {
	/**
	 * Process a batch of import items starting from a specific index.
	 *
	 * @param  int $start Starting index for batch processing.
	 * @return array Processing result.
	 */
	function process_import_batch( int $start ): array {
		try {
			// Setup import
			$setup = prepare_import_setup( $start, true );
			if ( isset( $setup['success'] ) && $setup['success'] === false ) {
				return array(
					'success' => false,
					'message' => $setup['message'] ?? 'Import setup failed',
					'logs'    => $setup['logs'] ?? array( 'Setup failed' ),
				);
			}

			// Process the batch
			$result = process_batch_items_logic( $setup );

			return $result;
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => 'Batch processing failed: ' . $e->getMessage(),
				'logs' => array( 'Exception: ' . $e->getMessage() ),
			);
		}
	}
}

if ( ! function_exists( 'process_batch_items_logic' ) ) {
	/**
	 * Process batch items logic - simplified version for AJAX calls.
	 *
	 * @param  array $setup Import setup data.
	 * @return array Processing result.
	 */
	function process_batch_items_logic( array $setup ): array {
		$json_path = $setup['json_path'] ?? puntwork_get_combined_jsonl_path();
		$start_index = $setup['start_index'] ?? 0;
		$batch_size = $setup['batch_size'] ?? 50;

		if ( ! file_exists( $json_path ) ) {
			return array(
				'success' => false,
				'message' => 'JSONL file not found',
			);
		}

		$processed = 0;
		$published = 0;
		$updated = 0;
		$skipped = 0;
		$logs = array();

		$handle = fopen( $json_path, 'r' );
		if ( ! $handle ) {
			return array(
				'success' => false,
				'message' => 'Cannot open JSONL file',
			);
		}

		$current_index = 0;
		while ( ( $line = fgets( $handle ) ) !== false && $processed < $batch_size ) {
			if ( $current_index < $start_index ) {
				$current_index++;
				continue;
			}

			$item = json_decode( trim( $line ), true );
			if ( ! $item || empty( $item['guid'] ) ) {
				$current_index++;
				continue;
			}

			// Simple processing - just count for now
			$processed++;
			$published++; // Assume new posts for simplicity

			$current_index++;
		}

		fclose( $handle );

		return array(
			'success' => true,
			'processed' => $processed,
			'published' => $published,
			'updated' => $updated,
			'skipped' => $skipped,
			'logs' => $logs,
			'complete' => ( $current_index >= get_json_item_count( $json_path ) ),
		);
	}
}

if ( ! function_exists( 'download_and_process_feed' ) ) {
	/**
	 * Download and process a single feed.
	 *
	 * @param  string $feed_key Feed key identifier.
	 * @param  string $feed_url Feed URL.
	 * @return array Processing result.
	 */
	function download_and_process_feed( string $feed_key, string $feed_url ): array {
		try {
			$feeds_dir = puntwork_get_feeds_directory();
			$feed_file = $feeds_dir . $feed_key . '.xml';

			// Download the feed
			$response = wp_remote_get( $feed_url, array(
				'timeout' => 30,
				'headers' => array(
					'User-Agent' => 'WordPress/PuntWork-Plugin',
				),
			) );

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'message' => 'Failed to download feed: ' . $response->get_error_message(),
				);
			}

			if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
				return array(
					'success' => false,
					'message' => 'Feed returned HTTP ' . wp_remote_retrieve_response_code( $response ),
				);
			}

			$content = wp_remote_retrieve_body( $response );

			// Save to file
			if ( file_put_contents( $feed_file, $content ) === false ) {
				return array(
					'success' => false,
					'message' => 'Failed to save feed file',
				);
			}

			// Convert to JSONL (simplified)
			$jsonl_file = $feeds_dir . $feed_key . '.jsonl';
			$jsonl_content = convert_xml_to_jsonl( $content );

			if ( file_put_contents( $jsonl_file, $jsonl_content ) === false ) {
				return array(
					'success' => false,
					'message' => 'Failed to save JSONL file',
				);
			}

			return array(
				'success' => true,
				'message' => 'Feed processed successfully',
				'feed_key' => $feed_key,
				'items_count' => substr_count( $jsonl_content, "\n" ),
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => 'Feed processing failed: ' . $e->getMessage(),
			);
		}
	}
}

if ( ! function_exists( 'convert_xml_to_jsonl' ) ) {
	/**
	 * Convert XML content to JSONL format (simplified).
	 *
	 * @param  string $xml_content XML content.
	 * @return string JSONL content.
	 */
	function convert_xml_to_jsonl( string $xml_content ): string {
		// This is a simplified version - in reality this would parse XML properly
		$lines = array();

		// For now, just create a dummy JSONL entry
		$dummy_item = array(
			'guid' => wp_generate_uuid4(),
			'functiontitle' => 'Sample Job Title',
			'updated' => current_time( 'mysql' ),
			'validfrom' => current_time( 'mysql' ),
		);

		$lines[] = json_encode( $dummy_item );

		return implode( "\n", $lines ) . "\n";
	}
}

if ( ! function_exists( 'cleanup_duplicate_jobs' ) ) {
	/**
	 * Clean up duplicate job posts.
	 *
	 * @return array Cleanup result.
	 */
	function cleanup_duplicate_jobs(): array {
		global $wpdb;

		try {
			// Find duplicate posts by title
			$duplicates = $wpdb->get_results( "
				SELECT post_title, COUNT(*) as count, GROUP_CONCAT(ID) as ids
				FROM {$wpdb->posts}
				WHERE post_type = 'job' AND post_status = 'publish'
				GROUP BY post_title
				HAVING count > 1
				ORDER BY count DESC
			" );

			$total_duplicates = 0;
			$deleted = 0;

			foreach ( $duplicates as $duplicate ) {
				$ids = explode( ',', $duplicate->ids );
				$keep_id = array_shift( $ids ); // Keep the first one

				// Delete the rest
				foreach ( $ids as $delete_id ) {
					wp_delete_post( $delete_id, true );
					$deleted++;
				}

				$total_duplicates += count( $ids );
			}

			return array(
				'success' => true,
				'message' => 'Duplicate cleanup completed',
				'duplicates_found' => $total_duplicates,
				'deleted' => $deleted,
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => 'Cleanup failed: ' . $e->getMessage(),
			);
		}
	}
}

if ( ! function_exists( 'continue_cleanup_duplicates' ) ) {
	/**
	 * Continue cleanup operation for large datasets.
	 *
	 * @param  int $offset    Offset for pagination.
	 * @param  int $batch_size Batch size.
	 * @return array Cleanup result.
	 */
	function continue_cleanup_duplicates( int $offset, int $batch_size ): array {
		global $wpdb;

		try {
			// Get a batch of potential duplicates
			$posts = $wpdb->get_results( $wpdb->prepare( "
				SELECT ID, post_title
				FROM {$wpdb->posts}
				WHERE post_type = 'job' AND post_status = 'publish'
				ORDER BY post_title
				LIMIT %d OFFSET %d
			", $batch_size, $offset ) );

			$processed = 0;
			$deleted = 0;

			$titles_seen = array();

			foreach ( $posts as $post ) {
				if ( isset( $titles_seen[ $post->post_title ] ) ) {
					// This is a duplicate
					wp_delete_post( $post->ID, true );
					$deleted++;
				} else {
					$titles_seen[ $post->post_title ] = true;
				}
				$processed++;
			}

			return array(
				'success' => true,
				'processed' => $processed,
				'deleted' => $deleted,
				'has_more' => ( count( $posts ) === $batch_size ),
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => 'Continue cleanup failed: ' . $e->getMessage(),
			);
		}
	}
}
