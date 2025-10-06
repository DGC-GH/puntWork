<?php

/**
 * Multi-format feed processing utilities.
 *
 * @since      1.0.13
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed format detection and processing.
 */
class FeedProcessor {

	public const FORMAT_XML  = 'xml';
	public const FORMAT_JSON = 'json';
	public const FORMAT_CSV  = 'csv';

	/**
	 * Detect feed format from URL or content.
	 *
	 * Format detection priority:
	 * 1. URL file extension (.xml, .json, .csv)
	 * 2. Content analysis (XML declaration, JSON brackets, CSV structure)
	 * 3. Default to JSON for modern feeds
	 *
	 * @param  string      $url     Feed URL
	 * @param  string|null $content Optional content to analyze
	 * @return string Detected format (FORMAT_XML, FORMAT_JSON, or FORMAT_CSV)
	 */
	public static function detectFormat( string $url, ?string $content = null ): string {
		// Check URL extension first
		$extension = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		switch ( $extension ) {
			case 'xml':
				return self::FORMAT_XML;
			case 'json':
				return self::FORMAT_JSON;
			case 'csv':
				return self::FORMAT_CSV;
		}

		// If no extension or unknown, try content analysis
		if ( $content !== null ) {
			$content = trim( $content );

			// Check for XML
			if ( strpos( $content, '<?xml' ) === 0 || strpos( $content, '<' ) === 0 ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log(
						'[PUNTWORK] detectFormat: Detected XML from content starting with: ' .
						substr( $content, 0, 50 )
					);
				}

				return self::FORMAT_XML;
			}

			// Check for JSON
			if ( ( strpos( $content, '{' ) === 0 || strpos( $content, '[' ) === 0 ) ) {
				json_decode( $content );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log(
							'[PUNTWORK] detectFormat: Detected JSON from content starting with: ' .
							substr( $content, 0, 50 )
						);
					}

					return self::FORMAT_JSON;
				} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log(
						'[PUNTWORK] detectFormat: Content starts with { or [, but invalid JSON: ' .
						json_last_error_msg()
					);
				}
			}

			// Check for CSV (look for comma-separated values with headers)
			$lines = explode( "\n", $content );
			if ( count( $lines ) > 1 ) {
				$first_line  = trim( $lines[0] );
				$second_line = trim( $lines[1] ?? '' );

				// Simple heuristic: if first line has commas and second line exists
				if ( strpos( $first_line, ',' ) !== false && ! empty( $second_line ) ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[PUNTWORK] detectFormat: Detected CSV from content' );
					}

					return self::FORMAT_CSV;
				}
			}
		}

		// Default to JSON for modern feeds
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] detectFormat: Defaulting to JSON for URL: ' . $url );
		}

		return self::FORMAT_JSON;
	}

	/**
	 * Process feed based on detected format.
	 *
	 * @param  string $feed_path       Path to downloaded feed file
	 * @param  string $format          Feed format
	 * @param  string $handle          Feed handle/key
	 * @param  string $output_dir      Output directory
	 * @param  string $fallback_domain Fallback domain
	 * @param  int    $batch_size      Batch size
	 * @param  int    &$total_items    Total items counter
	 * @param  array  &$logs           Logs array
	 * @return array Processed batch data
	 */
	public static function processFeed(
		string $feed_path,
		string $format,
		$handle,
		string $feed_key,
		string $output_dir,
		string $fallback_domain,
		int $batch_size,
		int &$total_items,
		array &$logs
	): int {
		$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [FEED-PROCESS-START] ===== PROCESS_FEED START =====' );
			error_log( '[PUNTWORK] [FEED-PROCESS-START] feed_path: ' . $feed_path );
			error_log( '[PUNTWORK] [FEED-PROCESS-START] format: ' . $format );
			error_log( '[PUNTWORK] [FEED-PROCESS-START] feed_key: ' . $feed_key );
			error_log( '[PUNTWORK] [FEED-PROCESS-START] output_dir: ' . $output_dir );
			error_log( '[PUNTWORK] [FEED-PROCESS-START] batch_size: ' . $batch_size );
			error_log( '[PUNTWORK] [FEED-PROCESS-START] total_items before: ' . $total_items );
			error_log( '[PUNTWORK] [FEED-PROCESS-START] Memory usage at start: ' . memory_get_usage( true ) . ' bytes' );
		}

		try {
			switch ( $format ) {
				case self::FORMAT_XML:
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [FEED-PROCESS-DEBUG] Processing XML feed' );
					}

					return self::processXmlFeed( $feed_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs );
				case self::FORMAT_JSON:
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [FEED-PROCESS-DEBUG] Processing JSON feed' );
					}

					return self::processJsonFeed( $feed_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs );
				case self::FORMAT_CSV:
					if ( $debug_mode ) {
						error_log( '[PUNTWORK] [FEED-PROCESS-DEBUG] Processing CSV feed' );
					}

					return self::processCsvFeed( $feed_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs );
				default:
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[PUNTWORK] [FEED-PROCESS-ERROR] Unsupported feed format: ' . $format );
					}

					throw new \Exception( "Unsupported feed format: $format" );
			}
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [FEED-PROCESS-ERROR] processFeed exception: ' . $e->getMessage() );
			}
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [FEED-PROCESS-END] ===== PROCESS_FEED END (ERROR) =====' );
			}

			throw $e;
		}
	}

	/**
	 * Process XML feed (existing functionality).
	 */
	private static function processXmlFeed(
		$xml_path,
		$handle,
		$feed_key,
		$output_dir,
		$fallback_domain,
		$batch_size,
		&$total_items,
		&$logs
	) {
		return process_xml_batch( $xml_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs );
	}

	/**
	 * Detect language from item.
	 *
	 * @param  object $item Job item object
	 * @return string Detected language code (en, fr, nl)
	 */
	private static function detectLanguage( object $item ): string {
		$lang = isset( $item->languagecode ) ? strtolower( (string) $item->languagecode ) : 'en';
		if ( strpos( $lang, 'fr' ) !== false ) {
			return 'fr';
		} elseif ( strpos( $lang, 'nl' ) !== false ) {
			return 'nl';
		}

		return 'en';
	}

	/**
	 * Process JSON feed.
	 *
	 * @param  string   $json_path       Path to JSON file
	 * @param  resource $handle          File handle for writing
	 * @param  string   $feed_key        Feed handle/key
	 * @param  string   $output_dir      Output directory
	 * @param  string   $fallback_domain Fallback domain
	 * @param  int      $batch_size      Batch size
	 * @param  int      &$total_items    Total items counter
	 * @param  array    &$logs           Logs array
	 * @return int Number of items processed
	 * @throws \Exception If JSON processing fails
	 */
	private static function processJsonFeed(
		string $json_path,
		$handle,
		string $feed_key,
		string $output_dir,
		string $fallback_domain,
		int $batch_size,
		int &$total_items,
		array &$logs
	): int {
		$feed_item_count = 0;
		$batch           = array();

		try {
			$file_size = filesize( $json_path );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [JSON-PROCESS] Processing JSON file: ' . $json_path . ' (' . $file_size . ' bytes)' );
			}

			// For large files (>10MB), use streaming processing to avoid memory issues
			if ( $file_size > 10 * 1024 * 1024 ) { // 10MB threshold
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[PUNTWORK] [JSON-PROCESS] Using streaming processing for large file' );
				}
				return self::processJsonFeedStreaming( $json_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs );
			}

			// For smaller files, use the original method
			$content = file_get_contents( $json_path );
			if ( $content == false ) {
				throw new \Exception( "Could not read JSON file: $json_path" );
			}

			$data = json_decode( $content, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				throw new \Exception( 'Invalid JSON: ' . json_last_error_msg() );
			}

			// Handle different JSON structures
			$items = self::extractJsonItems( $data );

			foreach ( $items as $item_data ) {
				try {
					if ( ! is_array( $item_data ) && ! is_object( $item_data ) ) {
						$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key item skipped: Invalid item structure";

						continue;
					}

					// Convert to object for consistent processing
					$item = is_object( $item_data ) ? $item_data : (object) $item_data;

					// Normalize field names to lowercase
					$normalized_item = new \stdClass();
					foreach ( $item as $key => $value ) {
						$normalized_key                   = strtolower( $key );
						$normalized_item->$normalized_key = $value;
					}
					$item = $normalized_item;

					// Generate GUID if missing
					if ( ! isset( $item->guid ) || empty( $item->guid ) ) {
						// Generate GUID from title, company, and location if available
						$guid_source = '';
						if ( isset( $item->functiontitle ) ) {
							$guid_source .= (string) $item->functiontitle;
						}
						if ( isset( $item->company ) ) {
							$guid_source .= (string) $item->company;
						}
						if ( isset( $item->location ) ) {
							$guid_source .= (string) $item->location;
						}
						if ( isset( $item->url ) ) {
							$guid_source .= (string) $item->url;
						}

						if ( ! empty( $guid_source ) ) {
							$item->guid = md5( $guid_source );
							$logs[]     = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: Generated GUID for item: " . $item->guid;
						} else {
							$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: Skipping item - no unique fields for GUID generation";

							continue;
						}
					}

					// Skip empty items
					if ( empty( (array) $item ) ) {
						$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key item skipped: No fields collected";

						continue;
					}

					// Log item fields for debugging
					$item_fields = array_keys( (array) $item );
					$logs[]      = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: Processing item with GUID {$item->guid}, fields: " . implode( ', ', $item_fields );

					clean_item_fields( $item );

					// Language detection
					$lang = self::detectLanguage( $item );

					$job_obj = json_decode( json_encode( $item ), true );
					infer_item_details( $item, $fallback_domain, $lang, $job_obj );

					// Validate JSON encoding before adding to batch
					$json_line = json_encode( $job_obj, JSON_UNESCAPED_UNICODE );
					if ( $json_line === false ) {
						$json_error = json_last_error_msg();
						$logs[]     = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: JSON encoding failed for item with GUID {$item->guid}: $json_error";
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( "$feed_key: JSON encoding failed: $json_error" );
						}
						continue; // Skip this item
					}

					$batch[] = $json_line . "\n";
					++$feed_item_count;

					// Process in batches
					if ( count( $batch ) >= $batch_size ) {
						fwrite( $handle, implode( '', $batch ) );
						$batch        = array();
						$total_items += $batch_size;
					}
				} catch ( \Exception $e ) {
					$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: Error processing JSON item: " . $e->getMessage();
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( "$feed_key: Error processing JSON item: " . $e->getMessage() );
					}
					// Continue with next item
				}
			}

			// Write remaining items
			if ( ! empty( $batch ) ) {
				fwrite( $handle, implode( '', $batch ) );
				$total_items += count( $batch );
			}

			return $feed_item_count;
		} catch ( \Exception $e ) {
			$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key JSON processing error: " . $e->getMessage();

			throw $e;
		}
	}

	/**
	 * Process large JSON feed using streaming to avoid memory issues.
	 *
	 * @param  string   $json_path       Path to JSON file
	 * @param  resource $handle          File handle for writing
	 * @param  string   $feed_key        Feed handle/key
	 * @param  string   $output_dir      Output directory
	 * @param  string   $fallback_domain Fallback domain
	 * @param  int      $batch_size      Batch size
	 * @param  int      &$total_items    Total items counter
	 * @param  array    &$logs           Logs array
	 * @return int Number of items processed
	 * @throws \Exception If JSON processing fails
	 */
	private static function processJsonFeedStreaming(
		string $json_path,
		$handle,
		string $feed_key,
		string $output_dir,
		string $fallback_domain,
		int $batch_size,
		int &$total_items,
		array &$logs
	): int {
		$feed_item_count = 0;
		$batch           = array();

		try {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [JSON-STREAM] Starting streaming JSON processing for: ' . $json_path );
			}

			// For JSON arrays, we need to stream the file and extract individual objects
			$file_handle = fopen( $json_path, 'r' );
			if ( ! $file_handle ) {
				throw new \Exception( "Could not open JSON file for streaming: $json_path" );
			}

			$content = fread( $file_handle, 1024 ); // Read first 1KB to detect structure
			rewind( $file_handle );

			if ( $content === false ) {
				throw new \Exception( "Could not read from JSON file: $json_path" );
			}

			// Check if it's a JSON array
			$content = trim( $content );
			if ( strpos( $content, '[' ) === 0 ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[PUNTWORK] [JSON-STREAM] Detected JSON array, using array streaming' );
				}
				return self::processJsonArrayStreaming( $file_handle, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs );
			} elseif ( strpos( $content, '{' ) === 0 ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[PUNTWORK] [JSON-STREAM] Detected JSON object, attempting to extract items array' );
				}
				// For JSON objects, try to load a small portion and extract the items array
				$full_content = file_get_contents( $json_path );
				if ( $full_content === false ) {
					throw new \Exception( "Could not read JSON file: $json_path" );
				}

				$data = json_decode( $full_content, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					throw new \Exception( 'Invalid JSON: ' . json_last_error_msg() );
				}

				$items = self::extractJsonItems( $data );
				fclose( $file_handle );

				// Process items normally (but log that we had to load the full file)
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[PUNTWORK] [JSON-STREAM] JSON object structure detected, processing ' . count( $items ) . ' items normally' );
				}

				foreach ( $items as $item_data ) {
					try {
						if ( ! is_array( $item_data ) && ! is_object( $item_data ) ) {
							$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key item skipped: Invalid item structure";
							continue;
						}

						// Convert to object for consistent processing
						$item = is_object( $item_data ) ? $item_data : (object) $item_data;

						// Normalize field names to lowercase
						$normalized_item = new \stdClass();
						foreach ( $item as $key => $value ) {
							$normalized_key                   = strtolower( $key );
							$normalized_item->$normalized_key = $value;
						}
						$item = $normalized_item;

						// Generate GUID if missing
						if ( ! isset( $item->guid ) || empty( $item->guid ) ) {
							$guid_source = '';
							if ( isset( $item->functiontitle ) ) {
								$guid_source .= (string) $item->functiontitle;
							}
							if ( isset( $item->company ) ) {
								$guid_source .= (string) $item->company;
							}
							if ( isset( $item->location ) ) {
								$guid_source .= (string) $item->location;
							}
							if ( isset( $item->url ) ) {
								$guid_source .= (string) $item->url;
							}

							if ( ! empty( $guid_source ) ) {
								$item->guid = md5( $guid_source );
								$logs[]     = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: Generated GUID for item: " . $item->guid;
							} else {
								$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: Skipping item - no unique fields for GUID generation";
								continue;
							}
						}

						// Skip empty items
						if ( empty( (array) $item ) ) {
							$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key item skipped: No fields collected";
							continue;
						}

						clean_item_fields( $item );

						// Language detection
						$lang = self::detectLanguage( $item );

						$job_obj = json_decode( json_encode( $item ), true );
						infer_item_details( $item, $fallback_domain, $lang, $job_obj );

						// Validate JSON encoding before adding to batch
						$json_line = json_encode( $job_obj, JSON_UNESCAPED_UNICODE );
						if ( $json_line === false ) {
							$json_error = json_last_error_msg();
							$logs[]     = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: JSON encoding failed for item with GUID {$item->guid}: $json_error";
							if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
								error_log( "$feed_key: JSON encoding failed: $json_error" );
							}
							continue;
						}

						$batch[] = $json_line . "\n";
						++$feed_item_count;

						// Process in batches
						if ( count( $batch ) >= $batch_size ) {
							fwrite( $handle, implode( '', $batch ) );
							$batch        = array();
							$total_items += $batch_size;
						}
					} catch ( \Exception $e ) {
						$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: Error processing JSON item: " . $e->getMessage();
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( "$feed_key: Error processing JSON item: " . $e->getMessage() );
						}
					}
				}

				// Write remaining items
				if ( ! empty( $batch ) ) {
					fwrite( $handle, implode( '', $batch ) );
					$total_items += count( $batch );
				}

				return $feed_item_count;
			} else {
				fclose( $file_handle );
				throw new \Exception( 'Unsupported JSON structure for streaming processing' );
			}
		} catch ( \Exception $e ) {
			$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key streaming JSON processing error: " . $e->getMessage();
			throw $e;
		}
	}

	/**
	 * Process JSON array using streaming to extract individual objects.
	 *
	 * @param  resource $file_handle     Open file handle
	 * @param  resource $handle          File handle for writing
	 * @param  string   $feed_key        Feed handle/key
	 * @param  string   $output_dir      Output directory
	 * @param  string   $fallback_domain Fallback domain
	 * @param  int      $batch_size      Batch size
	 * @param  int      &$total_items    Total items counter
	 * @param  array    &$logs           Logs array
	 * @return int Number of items processed
	 */
	private static function processJsonArrayStreaming(
		$file_handle,
		$handle,
		string $feed_key,
		string $output_dir,
		string $fallback_domain,
		int $batch_size,
		int &$total_items,
		array &$logs
	): int {
		$feed_item_count = 0;
		$batch           = array();
		$buffer          = '';
		$brace_count     = 0;
		$in_string       = false;
		$escape_next     = false;
		$item_start      = -1;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [JSON-ARRAY-STREAM] Starting JSON array streaming processing' );
		}

		while ( ! feof( $file_handle ) ) {
			$chunk = fread( $file_handle, 8192 ); // Read 8KB chunks
			if ( $chunk === false ) {
				break;
			}

			$buffer .= $chunk;

			// Process buffer character by character to find JSON objects
			for ( $i = 0; $i < strlen( $buffer ); $i++ ) {
				$char = $buffer[ $i ];

				if ( $escape_next ) {
					$escape_next = false;
					continue;
				}

				if ( $char === '\\' && $in_string ) {
					$escape_next = true;
					continue;
				}

				if ( $char === '"' ) {
					$in_string = ! $in_string;
					continue;
				}

				if ( ! $in_string ) {
					if ( $char === '{' ) {
						if ( $brace_count === 0 ) {
							$item_start = $i;
						}
						$brace_count++;
					} elseif ( $char === '}' ) {
						$brace_count--;
						if ( $brace_count === 0 && $item_start !== -1 ) {
							// Found a complete JSON object
							$json_object = substr( $buffer, $item_start, $i - $item_start + 1 );

							try {
								$item_data = json_decode( $json_object, true );
								if ( json_last_error() !== JSON_ERROR_NONE ) {
									if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
										error_log( '[PUNTWORK] [JSON-ARRAY-STREAM] JSON decode error: ' . json_last_error_msg() );
									}
									continue;
								}

								// Process the item
								$item = (object) $item_data;

								// Normalize field names to lowercase
								$normalized_item = new \stdClass();
								foreach ( $item as $key => $value ) {
									$normalized_key                   = strtolower( $key );
									$normalized_item->$normalized_key = $value;
								}
								$item = $normalized_item;

								// Generate GUID if missing
								if ( ! isset( $item->guid ) || empty( $item->guid ) ) {
									$guid_source = '';
									if ( isset( $item->functiontitle ) ) {
										$guid_source .= (string) $item->functiontitle;
									}
									if ( isset( $item->company ) ) {
										$guid_source .= (string) $item->company;
									}
									if ( isset( $item->location ) ) {
										$guid_source .= (string) $item->location;
									}
									if ( isset( $item->url ) ) {
										$guid_source .= (string) $item->url;
									}

									if ( ! empty( $guid_source ) ) {
										$item->guid = md5( $guid_source );
									} else {
										continue; // Skip items without unique fields
									}
								}

								// Skip empty items
								if ( empty( (array) $item ) ) {
									continue;
								}

								clean_item_fields( $item );

								// Language detection
								$lang = self::detectLanguage( $item );

								$job_obj = json_decode( json_encode( $item ), true );
								infer_item_details( $item, $fallback_domain, $lang, $job_obj );

								$json_line = json_encode( $job_obj, JSON_UNESCAPED_UNICODE );
								if ( $json_line === false ) {
									continue;
								}

								$batch[] = $json_line . "\n";
								++$feed_item_count;

								// Process in batches
								if ( count( $batch ) >= $batch_size ) {
									fwrite( $handle, implode( '', $batch ) );
									$batch        = array();
									$total_items += $batch_size;
								}
							} catch ( \Exception $e ) {
								// Continue with next item
								continue;
							}

							$item_start = -1;
						}
					}
				}
			}

			// Keep some buffer for next iteration if we have incomplete objects
			if ( $brace_count > 0 ) {
				// Keep the last portion that might contain incomplete objects
				$keep_length = min( 1000, strlen( $buffer ) ); // Keep up to 1KB
				$buffer      = substr( $buffer, -$keep_length );
			} else {
				$buffer = '';
			}
		}

		// Write remaining items
		if ( ! empty( $batch ) ) {
			fwrite( $handle, implode( '', $batch ) );
			$total_items += count( $batch );
		}

		fclose( $file_handle );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [JSON-ARRAY-STREAM] Streaming processing completed, processed ' . $feed_item_count . ' items' );
		}

		return $feed_item_count;
	}

	/**
	 * Process CSV feed.
	 *
	 * @param  string   $csv_path        Path to CSV file
	 * @param  resource $handle          File handle for writing
	 * @param  string   $feed_key        Feed handle/key
	 * @param  string   $output_dir      Output directory
	 * @param  string   $fallback_domain Fallback domain
	 * @param  int      $batch_size      Batch size
	 * @param  int      &$total_items    Total items counter
	 * @param  array    &$logs           Logs array
	 * @return int Number of items processed
	 * @throws \Exception If CSV processing fails
	 */
	private static function processCsvFeed(
		string $csv_path,
		$handle,
		string $feed_key,
		string $output_dir,
		string $fallback_domain,
		int $batch_size,
		int &$total_items,
		array &$logs
	): int {
		$feed_item_count = 0;
		$batch           = array();

		try {
			if ( ! file_exists( $csv_path ) ) {
				throw new \Exception( "CSV file not found: $csv_path" );
			}

			$handle_resource = fopen( $csv_path, 'r' );
			if ( $handle_resource == false ) {
				throw new \Exception( "Could not open CSV file: $csv_path" );
			}

			// Detect delimiter and read headers
			$delimiter = self::detectCsvDelimiter( $csv_path );
			$headers   = fgetcsv( $handle_resource, 0, $delimiter );

			if ( ! $headers || count( $headers ) < 2 ) {
				throw new \Exception( 'Invalid CSV format or no headers found' );
			}

			// Normalize headers to lowercase
			$headers = array_map( 'strtolower', $headers );

			while ( ( $row = fgetcsv( $handle_resource, 0, $delimiter ) ) !== false ) {
				try {
					if ( count( $row ) !== count( $headers ) ) {
						$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key row skipped: Column count mismatch";

						continue;
					}

					// Convert row to object
					$item = new \stdClass();
					foreach ( $headers as $index => $header ) {
						$item->$header = $row[ $index ] ?? '';
					}

					// Skip empty items
					if ( empty( (array) $item ) ) {
						$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key item skipped: No fields collected";

						continue;
					}

					clean_item_fields( $item );

					// Generate GUID if missing
					if ( ! isset( $item->guid ) || empty( $item->guid ) ) {
						// Generate GUID from title, company, and location if available
						$guid_source = '';
						if ( isset( $item->functiontitle ) ) {
							$guid_source .= (string) $item->functiontitle;
						}
						if ( isset( $item->company ) ) {
							$guid_source .= (string) $item->company;
						}
						if ( isset( $item->location ) ) {
							$guid_source .= (string) $item->location;
						}
						if ( isset( $item->url ) ) {
							$guid_source .= (string) $item->url;
						}

						if ( ! empty( $guid_source ) ) {
							$item->guid = md5( $guid_source );
							$logs[]     = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: Generated GUID for item: " . $item->guid;
						} else {
							$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: Skipping item - no unique fields for GUID generation";

							continue;
						}
					}

					// Language detection
					$lang = self::detectLanguage( $item );

					$job_obj = json_decode( json_encode( $item ), true );
					infer_item_details( $item, $fallback_domain, $lang, $job_obj );

					// Validate JSON encoding before adding to batch
					$json_line = json_encode( $job_obj, JSON_UNESCAPED_UNICODE );
					if ( $json_line === false ) {
						$json_error = json_last_error_msg();
						$logs[]     = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: JSON encoding failed for item with GUID {$item->guid}: $json_error";
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( "$feed_key: JSON encoding failed: $json_error" );
						}
						continue; // Skip this item
					}

					$batch[] = $json_line . "\n";
					++$feed_item_count;

					// Process in batches
					if ( count( $batch ) >= $batch_size ) {
						fwrite( $handle, implode( '', $batch ) );
						$batch        = array();
						$total_items += $batch_size;
					}
				} catch ( \Exception $e ) {
					$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: Error processing CSV row: " . $e->getMessage();
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( "$feed_key: Error processing CSV row: " . $e->getMessage() );
					}
					// Continue with next row
				}
			}

			// Write remaining items
			if ( ! empty( $batch ) ) {
				fwrite( $handle, implode( '', $batch ) );
				$total_items += count( $batch );
			}

			fclose( $handle_resource );

			return $feed_item_count;
		} catch ( \Exception $e ) {
			if ( isset( $handle_resource ) && is_resource( $handle_resource ) ) {
				fclose( $handle_resource );
			}
			$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key CSV processing error: " . $e->getMessage();

			throw $e;
		}
	}

	/**
	 * Extract items from various JSON structures.
	 *
	 * Handles different JSON feed formats:
	 * - Array of job objects: [{"title": "...", ...}, ...]
	 * - Object with items array: {"jobs": [{"title": "...", ...}], ...}
	 * - Single job object: {"title": "...", ...}
	 *
	 * Searches for common container keys: jobs, items, data, results, feed, entries
	 *
	 * @param  mixed $data JSON data structure
	 * @return array Extracted items array
	 */
	private static function extractJsonItems( $data ): array {
		// If it's an array of objects, return as is
		if ( is_array( $data ) && ! empty( $data ) && ( is_array( $data[0] ) || is_object( $data[0] ) ) ) {
			return $data;
		}

		// If it's an object with a common items array
		if ( is_object( $data ) || is_array( $data ) ) {
			$possible_keys = array( 'jobs', 'items', 'data', 'results', 'feed', 'entries' );

			foreach ( $possible_keys as $key ) {
				if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
					return $data[ $key ];
				}
			}
		}

		// If it's a single object, wrap in array
		if ( is_object( $data ) || is_array( $data ) ) {
			return array( $data );
		}

		return array();
	}

	/**
	 * Detect CSV delimiter by analyzing the first few lines.
	 *
	 * Analyzes the first 5 lines of the CSV file and counts occurrences
	 * of common delimiters: comma (,), semicolon (;), tab (\t), pipe (|)
	 * Returns the delimiter with the highest count.
	 *
	 * Note: This is a heuristic and may not work for all CSV formats.
	 * For complex CSV files, the delimiter should be explicitly configured.
	 *
	 * @param  string $file_path Path to CSV file
	 * @return string Detected delimiter character
	 */
	private static function detectCsvDelimiter( string $file_path ): string {
		$handle     = fopen( $file_path, 'r' );
		$delimiters = array( ',', ';', '\t', '|' );
		$counts     = array_fill_keys( $delimiters, 0 );

		// Read first 5 lines to analyze
		for ( $i = 0; $i < 5 && ( $line = fgets( $handle ) ); $i++ ) {
			foreach ( $delimiters as $delimiter ) {
				$counts[ $delimiter ] += substr_count( $line, $delimiter );
			}
		}

		fclose( $handle );

		// Return delimiter with highest count
		arsort( $counts );

		return key( $counts );
	}
}
