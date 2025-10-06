<?php

/**
 * Import setup and initialization.
 *
 * @since      1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import setup and validation
 * Handles preparation and prerequisite validation for job imports.
 */

// Include field mappings
require_once __DIR__ . '/../mappings/mappings-fields.php';

// Include utility helpers
require_once __DIR__ . '/../utilities/utility-helpers.php';

// Include database optimization utilities
require_once __DIR__ . '/../utilities/database-optimization.php';

// Include REST API utilities
require_once __DIR__ . '/../api/rest-api.php';

// Include batch loading utilities
require_once __DIR__ . '/../batch/batch-loading.php';

/**
 * Validate JSONL file integrity by checking a sample of lines.
 *
 * @param  string $json_path Path to JSONL file.
 * @return true|WP_Error True if valid, WP_Error if invalid.
 */
function validate_jsonl_file( $json_path ) {
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [VALIDATE-START] ===== VALIDATE_JSONL_FILE START =====' );
		error_log( '[PUNTWORK] [VALIDATE-START] validate_jsonl_file: Starting validation of ' . basename( $json_path ) );
	}

	if ( ( $handle = fopen( $json_path, 'r' ) ) == false ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [VALIDATE-ERROR] validate_jsonl_file: Cannot open file for validation' );
		}

		return new WP_Error( 'file_open_failed', 'Cannot open JSONL file for validation' );
	}

	$bom           = "\xef\xbb\xbf";
	$checked_lines = 0;
	$valid_lines   = 0;
	$invalid_lines = 0;
	$empty_lines   = 0;
	$missing_guids = 0;
	$file_size     = filesize( $json_path );
	$max_check     = min( 100, max( 10, $file_size / 1000 ) ); // Check up to 100 lines or 0.1% of file, minimum 10

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [VALIDATE-DEBUG] File size: ' . $file_size . ' bytes, checking up to ' . $max_check . ' lines' );
	}

	while ( $checked_lines < $max_check && ( $line = fgets( $handle ) ) !== false ) {
		++$checked_lines;
		$original_line = $line;
		$line          = trim( $line );

		// Remove BOM if present
		if ( substr( $line, 0, 3 ) === $bom ) {
			$line = substr( $line, 3 );
		}

		if ( empty( $line ) ) {
			++$empty_lines;

			continue;
		}

		$item = json_decode( $line, true );
		if ( $item == null && json_last_error() !== JSON_ERROR_NONE ) {
			++$invalid_lines;
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [VALIDATE-ERROR] INVALID JSON at line ' . $checked_lines . ': ' . json_last_error_msg() );
				error_log( '[PUNTWORK] [VALIDATE-ERROR] Line preview: ' . substr( $original_line, 0, 100 ) . ( strlen( $original_line ) > 100 ? '...[truncated]' : '' ) );
			}

			continue;
		}

		++$valid_lines;

		// Check for required fields
		if ( ! isset( $item['guid'] ) || empty( $item['guid'] ) ) {
			++$missing_guids;
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [VALIDATE-WARN] Missing or empty GUID at line ' . $checked_lines . ', item keys: ' . implode( ', ', array_keys( $item ) ) );
			}
		}
	}

	fclose( $handle );

	$valid_percentage = $checked_lines > 0 ? round( ( $valid_lines / $checked_lines ) * 100, 2 ) : 0;
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [VALIDATE-RESULT] Validation complete - Checked: ' . $checked_lines . ' lines, Valid: ' . $valid_lines . ' (' . $valid_percentage . '%), Invalid: ' . $invalid_lines . ', Empty: ' . $empty_lines . ', Missing GUIDs: ' . $missing_guids );
	}

	if ( $invalid_lines > 0 ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [VALIDATE-WARN] WARNING - Found ' . $invalid_lines . ' invalid JSON lines in sample' );
		}
	}

	if ( $valid_percentage < 80 ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [VALIDATE-ERROR] CRITICAL - Only ' . $valid_percentage . '% of sampled lines are valid JSON' );
		}

		return new WP_Error( 'low_valid_percentage', 'Only ' . $valid_percentage . '% of sampled lines contain valid JSON' );
	}

	if ( $missing_guids > 0 ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [VALIDATE-WARN] WARNING - Found ' . $missing_guids . ' items missing GUIDs' );
		}
	}

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [VALIDATE-END] File validation passed' );
		error_log( '[PUNTWORK] [VALIDATE-END] ===== VALIDATE_JSONL_FILE END =====' );
	}

	return true;
}

/**
 * Prepare import setup and validate prerequisites.
 *
 * @param  int $batch_start Starting index for batch.
 * @return array|WP_Error Setup data or error.
 */
function prepare_import_setup( $batch_start = 0, $is_batch = false ) {
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	do_action( 'qm/cease' ); // Disable Query Monitor data collection to reduce memory usage
	ini_set( 'memory_limit', '512M' );
	set_time_limit( 1800 );
	ignore_user_abort( true );

	global $wpdb;
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SETUP-START] ===== PREPARE_IMPORT_SETUP START =====' );
		error_log( '[PUNTWORK] [SETUP-START] prepare_import_setup called with batch_start=' . $batch_start . ', memory_limit=' . ini_get( 'memory_limit' ) . ', time_limit=' . ini_get( 'max_execution_time' ) );
		error_log( '[PUNTWORK] [SETUP-START] Current memory usage: ' . memory_get_usage( true ) . ' bytes' );
		error_log( '[PUNTWORK] [SETUP-START] Peak memory usage: ' . memory_get_peak_usage( true ) . ' bytes' );
		error_log( '[PUNTWORK] [SETUP-START] WordPress version: ' . get_bloginfo( 'version' ) );
		error_log( '[PUNTWORK] [SETUP-START] PHP version: ' . PHP_VERSION );
		error_log( '[PUNTWORK] [SETUP-START] Database prefix: ' . $wpdb->prefix );
	}

	try {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-ACF] Getting ACF fields...' );
		}
		// Skip ACF loading for batch processing to save memory
		if ( $is_batch ) {
			$acf_fields = array(); // Empty array for batch processing
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [SETUP-ACF] Skipping ACF field loading for batch processing to save memory' );
			}
		} else {
			$acf_fields = get_acf_fields();
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [SETUP-ACF] Got ACF fields: ' . count( $acf_fields ) . ' fields' );
				if ( empty( $acf_fields ) ) {
					error_log( '[PUNTWORK] [SETUP-WARNING] No ACF fields found - this may cause import issues' );
				}
			}
		}
	} catch ( \Exception $e ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-ERROR] Error getting ACF fields: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		}

		return new WP_Error( 'acf_error', 'Failed to get ACF fields: ' . $e->getMessage() );
	}

	// Ensure database indexes are created before import processing
	try {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-INDEXES] Ensuring database indexes are created...' );
		}
		create_database_indexes();
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-INDEXES] Database indexes creation completed' );
		}
	} catch ( \Exception $e ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-ERROR] Error creating database indexes: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		}

		return new WP_Error( 'db_indexes_error', 'Failed to create database indexes: ' . $e->getMessage() );
	}

	// Ensure API key is created for SSE connections
	try {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-API-KEY] Ensuring API key is created...' );
		}
		$api_key = \Puntwork\get_or_create_api_key();
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-API-KEY] API key creation completed: ' . ( empty( $api_key ) ? 'empty' : 'created/retrieved' ) );
		}
	} catch ( \Exception $e ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-ERROR] Error creating API key: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		}

		return new WP_Error( 'api_key_error', 'Failed to create API key: ' . $e->getMessage() );
	}

	try {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-ZERO] Getting zero empty fields...' );
		}
		$zero_empty_fields = get_zero_empty_fields();
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-ZERO] Got zero empty fields: ' . count( $zero_empty_fields ) . ' fields' );
		}
	} catch ( \Exception $e ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-ERROR] Error getting zero empty fields: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		}

		return new WP_Error( 'zero_fields_error', 'Failed to get zero empty fields: ' . $e->getMessage() );
	}

	if ( ! defined( 'WP_IMPORTING' ) ) {
		define( 'WP_IMPORTING', true );
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-CONSTANT] Defined WP_IMPORTING constant' );
		}
	}
	wp_suspend_cache_invalidation( true );
	remove_action( 'post_updated', 'wp_save_post_revision' );
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SETUP-WP] Suspended cache invalidation and removed post revision action' );
	}

	// Check if there's an existing import in progress and use its start time
	$existing_status = safe_get_option( 'job_import_status' );
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SETUP-STATUS] Existing status: ' . json_encode( $existing_status ) );
	}
	if ( $existing_status && isset( $existing_status['start_time'] ) && $existing_status['start_time'] > 0 ) {
		$start_time = $existing_status['start_time'];
		\Puntwork\PuntWorkLogger::info( 'Using existing import start time: ' . $start_time, \Puntwork\PuntWorkLogger::CONTEXT_BATCH );
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-TIME] Using existing import start time: ' . date( 'Y-m-d H:i:s', (int) $start_time ) . ' (' . $start_time . ')' );
		}
	} else {
		$start_time = microtime( true );
		\Puntwork\PuntWorkLogger::info( 'Starting new import with start time: ' . $start_time, \Puntwork\PuntWorkLogger::CONTEXT_BATCH );
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-TIME] Starting new import with start time: ' . date( 'Y-m-d H:i:s', (int) $start_time ) . ' (' . $start_time . ')' );
		}
	}

	$json_path = puntwork_get_combined_jsonl_path();

	// Ensure the path is absolute for consistency
	if ( ! str_starts_with( $json_path, '/' ) ) {
		$json_path = realpath( $json_path ) ?: $json_path;
	}
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SETUP-FILE] JSONL path: ' . $json_path );
		error_log( '[PUNTWORK] [SETUP-FILE] ABSPATH: ' . ABSPATH );
	}

	// Ensure feeds directory exists and is writable
	$ensure_result = puntwork_ensure_feeds_directory();
	if ( is_wp_error( $ensure_result ) ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-ERROR] ' . $ensure_result->get_error_message() );
		}

		return array(
			'success' => false,
			'message' => $ensure_result->get_error_message(),
			'logs'    => array( $ensure_result->get_error_message() ),
		);
	}

	if ( $debug_mode ) {
		$feeds_dir = puntwork_get_feeds_directory();
		error_log( '[PUNTWORK] [SETUP-FILE] feeds/ directory exists: ' . ( is_dir( $feeds_dir ) ? 'yes' : 'no' ) );
		error_log( '[PUNTWORK] [SETUP-FILE] feeds/ directory writable: ' . ( is_writable( $feeds_dir ) ? 'yes' : 'no' ) );

		$files_in_feeds = glob( $feeds_dir . '*' );
		error_log( '[PUNTWORK] [SETUP-FILE] Files in feeds/ directory: ' . ( is_array( $files_in_feeds ) ? count( $files_in_feeds ) : 'glob_failed' ) . ' files' );
		if ( is_array( $files_in_feeds ) && ! empty( $files_in_feeds ) ) {
			foreach ( $files_in_feeds as $file ) {
				$size = file_exists( $file ) ? filesize( $file ) : 'N/A';
				error_log( '[PUNTWORK] [SETUP-FILE]   - ' . basename( $file ) . ' (' . $size . ' bytes)' );
			}
		}
	}

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SETUP-FILE] Combined JSONL file exists: ' . ( file_exists( $json_path ) ? 'yes' : 'no' ) );
	}
	if ( file_exists( $json_path ) ) {
		$size  = filesize( $json_path );
		$mtime = filemtime( $json_path );
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-FILE] File size: ' . $size . ' bytes' );
			error_log( '[PUNTWORK] [SETUP-FILE] File mtime: ' . date( 'Y-m-d H:i:s', $mtime ) . ', age: ' . ( time() - $mtime ) . ' seconds' );
		}

		if ( $size === 0 ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [SETUP-ERROR] JSONL file exists but is empty (0 bytes)' );
			}

			return array(
				'success' => false,
				'message' => 'JSONL file is empty - feeds may need to be processed first',
				'logs'    => array( 'JSONL file exists but is empty - run feed processing first' ),
			);
		}

		$first_line = '';
		$handle     = fopen( $json_path, 'r' );
		if ( $handle ) {
			$first_line = fgets( $handle );
			fclose( $handle );
			$first_line_length = strlen( $first_line );
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [SETUP-FILE] First line length: ' . $first_line_length . ' characters' );
				if ( $first_line_length > 0 ) {
					error_log( '[PUNTWORK] [SETUP-FILE] First line preview: ' . substr( $first_line, 0, 200 ) . ( $first_line_length > 200 ? '...[truncated]' : '' ) );
				} else {
					error_log( '[PUNTWORK] [SETUP-ERROR] First line is empty - file may be corrupted' );
				}
			}
		} elseif ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-ERROR] Could not open JSONL file for reading' );
		}
	}

	if ( ! file_exists( $json_path ) ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-ERROR] JSONL file not found: ' . $json_path . ' - checking if feeds need to be processed first' );

			// Check if there are any individual feed files
			$feed_files = glob( puntwork_get_feeds_directory() . '*.jsonl' );
			error_log( '[PUNTWORK] [SETUP-ERROR] Individual feed files found: ' . ( is_array( $feed_files ) ? count( $feed_files ) : 'glob_failed' ) );
			if ( is_array( $feed_files ) && ! empty( $feed_files ) ) {
				error_log( '[PUNTWORK] [SETUP-ERROR] Individual feeds exist but combined file missing - attempting to combine JSONL files' );
				foreach ( $feed_files as $feed_file ) {
					$size = file_exists( $feed_file ) ? filesize( $feed_file ) : 'N/A';
					error_log( '[PUNTWORK] [SETUP-ERROR]   - ' . basename( $feed_file ) . ' (' . $size . ' bytes)' );
				}

				// Attempt to combine the JSONL files
				try {
					$feeds = get_feeds(); // Get feeds configuration
					if ( ! empty( $feeds ) ) {
						$combine_logs = array();
						$total_items  = 0;
						foreach ( $feed_files as $feed_file ) {
							if ( basename( $feed_file ) !== 'combined-jobs.jsonl' ) {
								// Count items in each feed file
								$handle = fopen( $feed_file, 'r' );
								if ( $handle ) {
									while ( ( $line = fgets( $handle ) ) !== false ) {
										$line = trim( $line );
										if ( ! empty( $line ) ) {
											++$total_items;
										}
									}
									fclose( $handle );
								}
							}
						}

						// Include combine-jsonl.php if not already included
						if ( ! function_exists( 'combine_jsonl_files' ) ) {
							require_once __DIR__ . '/combine-jsonl.php';
						}

						combine_jsonl_files( $feeds, puntwork_get_feeds_directory(), $total_items, $combine_logs );

						if ( file_exists( $json_path ) ) {
							error_log( '[PUNTWORK] [SETUP-SUCCESS] Combined JSONL file created successfully during import setup' );
							$combine_logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'JSONL files combined successfully - ready for import';
						} else {
							error_log( '[PUNTWORK] [SETUP-ERROR] Failed to create combined JSONL file' );
							return array(
								'success' => false,
								'message' => 'Failed to combine JSONL files - combined file not created',
								'logs'    => array_merge( array( 'Failed to combine JSONL files' ), $combine_logs ),
							);
						}
					} else {
						error_log( '[PUNTWORK] [SETUP-ERROR] No feeds configuration found' );
						return array(
							'success' => false,
							'message' => 'No feeds configuration found',
							'logs'    => array( 'No feeds configuration found - cannot combine files' ),
						);
					}
				} catch ( \Exception $e ) {
					error_log( '[PUNTWORK] [SETUP-ERROR] Exception during JSONL combination: ' . $e->getMessage() );
					return array(
						'success' => false,
						'message' => 'Exception during JSONL combination: ' . $e->getMessage(),
						'logs'    => array( 'Exception during JSONL combination: ' . $e->getMessage() ),
					);
				}
			} else {
				error_log( '[PUNTWORK] [SETUP-ERROR] No individual feed files found - feeds may not be configured or processed' );
				return array(
					'success' => false,
					'message' => 'JSONL file not found - feeds may need to be processed first. Run feed processing to download and convert feeds to JSONL format.',
					'logs'    => array( 'JSONL file not found - run feed processing first to create individual feed files, then combine them' ),
				);
			}
		}
	}

	if ( ! is_readable( $json_path ) ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-ERROR] JSONL file not readable: ' . $json_path . ' - permissions issue?' );
		}

		return array(
			'success' => false,
			'message' => 'JSONL file not readable',
			'logs'    => array( 'JSONL file not readable - check file permissions' ),
		);
	}

	// Validate JSONL file integrity
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SETUP-VALIDATION] Starting JSONL file validation...' );
	}
	$validation = validate_jsonl_file( $json_path );
	if ( is_wp_error( $validation ) ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-ERROR] JSONL validation failed: ' . $validation->get_error_message() );
		}

		return array(
			'success' => false,
			'message' => 'JSONL file validation failed: ' . $validation->get_error_message(),
			'logs'    => array( 'JSONL file validation failed: ' . $validation->get_error_message() ),
		);
	}
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SETUP-VALIDATION] JSONL file validation passed' );
	}

	// Add additional validation - check if we can actually read items
	$test_read = load_json_batch( $json_path, 0, 1 );
	$test_items = $test_read['items'] ?? $test_read;
	if ( empty( $test_items ) ) {
		error_log( '[PUNTWORK] [SETUP-ERROR] CRITICAL: Cannot read any items from JSONL file, even though validation passed. File may be corrupted.' );
		return array(
			'success' => false,
			'message' => 'Cannot read items from JSONL file - file may be corrupted despite passing validation',
			'logs'    => array( 'Cannot read items from JSONL file - file may be corrupted despite passing validation' ),
		);
	}
	error_log( '[PUNTWORK] [SETUP-VALIDATION] Successfully read test item from JSONL file' );

	try {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-COUNT] Starting JSONL item count...' );
			error_log( '[PUNTWORK] [SETUP-COUNT] JSON path for counting: ' . $json_path );
			error_log( '[PUNTWORK] [SETUP-COUNT] File exists: ' . ( file_exists( $json_path ) ? 'yes' : 'no' ) );
			error_log( '[PUNTWORK] [SETUP-COUNT] File readable: ' . ( is_readable( $json_path ) ? 'yes' : 'no' ) );
			if ( file_exists( $json_path ) ) {
				error_log( '[PUNTWORK] [SETUP-COUNT] File size: ' . filesize( $json_path ) . ' bytes' );
			}
		}
		$total = get_json_item_count( $json_path );
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-COUNT] Total items in JSONL: ' . $total );
			if ( $total === 0 ) {
				error_log( '[PUNTWORK] [SETUP-WARNING] JSONL file exists but contains 0 valid items - checking file content...' );
				// Debug: try to read first few lines manually
				if ( file_exists( $json_path ) && is_readable( $json_path ) ) {
					$debug_handle = fopen( $json_path, 'r' );
					if ( $debug_handle ) {
						$line_num = 0;
						while ( ( $line = fgets( $debug_handle ) ) !== false && $line_num < 3 ) {
							++$line_num;
							$line = trim( $line );
							if ( ! empty( $line ) ) {
								$item = json_decode( $line, true );
								if ( $item !== null ) {
									error_log( '[PUNTWORK] [SETUP-DEBUG] Line ' . $line_num . ' is valid JSON with GUID: ' . ( $item['guid'] ?? 'MISSING' ) );
								} else {
									error_log( '[PUNTWORK] [SETUP-DEBUG] Line ' . $line_num . ' is INVALID JSON: ' . json_last_error_msg() . ' - Preview: ' . substr( $line, 0, 100 ) );
								}
							} else {
								error_log( '[PUNTWORK] [SETUP-DEBUG] Line ' . $line_num . ' is empty' );
							}
						}
						fclose( $debug_handle );
					}
				}
			}
		}
	} catch ( \Exception $e ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-ERROR] Error counting JSONL items: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
		}

		return new WP_Error( 'count_error', 'Failed to count JSONL items: ' . $e->getMessage() );
	}

	if ( $total === 0 ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-EARLY] EARLY RETURN - total is 0, no items to import' );
		}

		return array(
			'success' => false,
			'message' => 'No items found in JSONL file - feeds may need to be processed first',
			'processed' => 0,
			'total' => 0,
			'published' => 0,
			'updated' => 0,
			'skipped' => 0,
			'duplicates_drafted' => 0,
			'time_elapsed' => 0,
			'complete' => true,
			'logs' => array( 'No items found in JSONL file - run feed processing first' ),
			'batch_size' => 0,
			'inferred_languages' => 0,
			'inferred_benefits' => 0,
			'schema_generated' => 0,
			'batch_time' => 0,
			'batch_processed' => 0,
		);
	}

	// Cache existing job GUIDs if not already cached
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SETUP-GUIDS] Checking existing job GUIDs cache...' );
	}
	if ( false === safe_get_option( 'job_existing_guids' ) ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-GUIDS] Caching existing job GUIDs...' );
		}
		$all_jobs = $wpdb->get_results( "SELECT p.ID, pm.meta_value AS guid FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID = pm.post_id WHERE p.post_type = 'job' AND pm.meta_key = 'guid'" );
		update_option( 'job_existing_guids', $all_jobs, false );
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-GUIDS] Cached ' . count( $all_jobs ) . ' existing job GUIDs' );
		}
	} else {
		$cached_guids = safe_get_option( 'job_existing_guids' );
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-GUIDS] Using cached GUIDs: ' . ( is_array( $cached_guids ) ? count( $cached_guids ) : 'invalid' ) . ' jobs' );
		}
	}

	$processed_guids = safe_get_option( 'job_import_processed_guids' ) ?: array();
	
	// For batch processing, use batch_start as absolute starting index
	if ( $is_batch ) {
		$start_index = $batch_start;
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-BATCH] Batch processing mode: using batch_start=' . $batch_start . ' as absolute start_index' );
		}
	} else {
		$start_index = max( (int) safe_get_option( 'job_import_progress' ), $batch_start );
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-INDEX] Initial start_index calculation: max(' . (int) safe_get_option( 'job_import_progress' ) . ', ' . $batch_start . ') = ' . $start_index );
		}
	}

	// For fresh starts (batch_start = 0), reset the status and create new start time
	// But only if there's no existing valid status OR if the existing status is complete
	$existing_status  = safe_get_option( 'job_import_status' );
	$has_valid_status = ! empty( $existing_status ) && isset( $existing_status['total'] ) && $existing_status['total'] > 0 && ( ! isset( $existing_status['complete'] ) || ! $existing_status['complete'] );
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SETUP-STATUS] Existing status check: has_valid_status=' . ( $has_valid_status ? 'true' : 'false' ) . ', batch_start=' . $batch_start . ', existing_complete=' . ( $existing_status['complete'] ?? 'not set' ) );
		error_log( '[PUNTWORK] [SETUP-STATUS] has_valid_status calculation: !empty=' . ( ! empty( $existing_status ) ? 'true' : 'false' ) . ', isset(total)=' . ( isset( $existing_status['total'] ) ? 'true' : 'false' ) . ', total=' . ( $existing_status['total'] ?? 'not set' ) . ', total>0=' . ( ( $existing_status['total'] ?? 0 ) > 0 ? 'true' : 'false' ) . ', !isset(complete)=' . ( ! isset( $existing_status['complete'] ) ? 'true' : 'false' ) . ', !complete=' . ( ! ( $existing_status['complete'] ?? false ) ? 'true' : 'false' ) );
	}

	if ( $batch_start == 0 && ! $has_valid_status ) {
		$start_index = 0;
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-FRESH] Fresh start detected (no valid existing status or previous import complete), setting start_index to 0' );
		}
		// Clear processed GUIDs for fresh start
		$processed_guids = array();
		// Clear existing status for fresh start
		delete_option( 'job_import_status' );
		// Clear progress for fresh start
		update_option( 'job_import_progress', 0, false );
		// Reset batch size for fresh start to allow dynamic adjustment
		delete_option( 'job_import_batch_size' );
		$start_time = microtime( true );
		\Puntwork\PuntWorkLogger::info( 'Fresh import start - resetting status and progress to 0', \Puntwork\PuntWorkLogger::CONTEXT_BATCH );

		// Clear batch hash transients for fresh import
		if ( function_exists( 'clear_batch_hash_transients' ) ) {
			$cleared = clear_batch_hash_transients();
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [SETUP-FRESH] Cleared ' . $cleared . ' batch hash transients for fresh import' );
			}
		}

		// Initialize status for manual import
		$initial_status = array(
			'total'              => $total,
			'processed'          => 0,
			'published'          => 0,
			'updated'            => 0,
			'skipped'            => 0,
			'duplicates_drafted' => 0,
			'time_elapsed'       => 0,
			'complete'           => false,
			'success'            => false,
			'error_message'      => '',
			'batch_size'         => safe_get_option( 'job_import_batch_size' ) ?: 1,
			'inferred_languages' => 0,
			'inferred_benefits'  => 0,
			'schema_generated'   => 0,
			'start_time'         => $start_time,
			'end_time'           => null,
			'last_update'        => time(),
			'logs'               => array( 'Manual import started - preparing to process items...' ),
		);
		update_option( 'job_import_status', $initial_status, false );
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-FRESH] Initialized fresh import status with total=' . $total . ', batch_size=' . $initial_status['batch_size'] );
		}
	} elseif ( $batch_start == 0 && $has_valid_status ) {
		// Resuming from existing status - reset batch size for dynamic adjustment but keep other status
		$start_index = 0; // Reset to start from beginning
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-RESUME] Restarting import from beginning, resetting batch size for dynamic adjustment' );
		}
		// Reset batch size to allow dynamic adjustment to start fresh
		delete_option( 'job_import_batch_size' );
		// Reset progress for restart
		update_option( 'job_import_progress', 0, false );

		// Clear batch hash transients for restart
		if ( function_exists( 'clear_batch_hash_transients' ) ) {
			$cleared = clear_batch_hash_transients();
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [SETUP-RESUME] Cleared ' . $cleared . ' batch hash transients for restart' );
			}
		}

		// Update existing status for restart
		$existing_status['processed'] = 0;
		$existing_status['published'] = 0;
		$existing_status['updated'] = 0;
		$existing_status['skipped'] = 0;
		$existing_status['duplicates_drafted'] = 0;
		$existing_status['time_elapsed'] = 0;
		$existing_status['complete'] = false;
		$existing_status['success'] = false;
		$existing_status['error_message'] = '';
		$existing_status['start_time'] = microtime( true );
		$existing_status['end_time'] = null;
		$existing_status['last_update'] = time();
		$existing_status['logs'][] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Import restarted from beginning';
		update_option( 'job_import_status', $existing_status, false );
	} else {
		// Batch processing or continuation - don't reset batch size
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-BATCH] Batch processing mode, not resetting batch size' );
		}
	}

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SETUP-EARLY-CHECK] Checking for early return: start_index=' . $start_index . ', total=' . $total . ', start_index >= total = ' . ( $start_index >= $total ? 'true' : 'false' ) );
	}

// Don't do early return if the logs indicate this is a fresh import ready to start
$current_logs = $existing_status['logs'] ?? array();
$is_ready_for_import = in_array('JSONL files combined successfully - ready for import', $current_logs);
if ( $is_ready_for_import ) {
	// This is a fresh import ready to start, don't do early return even if start_index >= total
	// (which shouldn't happen anyway for fresh imports)
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SETUP-READY] Import is ready for batch processing, skipping early return check' );
	}
} else {
	// Check for early return
	if ( $start_index >= $total ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [SETUP-EARLY] EARLY RETURN - start_index (' . $start_index . ') >= total (' . $total . ') - import appears complete' );
		}

		return array(
			'success'            => true,
			'processed'          => $total,
			'total'              => $total,
			'published'          => 0,
			'updated'            => 0,
			'skipped'            => 0,
			'duplicates_drafted' => 0,
			'time_elapsed'       => 0,
			'complete'           => true,
			'logs'               => array( 'Start index beyond total items - import appears complete' ),
			'batch_size'         => 0,
			'inferred_languages' => 0,
			'inferred_benefits'  => 0,
			'schema_generated'   => 0,
			'batch_time'         => 0,
			'batch_processed'    => 0,
		);
	}
}

if ( $debug_mode ) {
	error_log( '[PUNTWORK] [SETUP-FINAL] NORMAL RETURN - start_index=' . $start_index . ', total=' . $total . ', json_path=' . $json_path . ', processed_guids_count=' . count( $processed_guids ) );
	error_log( '[PUNTWORK] [SETUP-END] ===== PREPARE_IMPORT_SETUP END =====' );
}

return array(
	'acf_fields'        => $acf_fields,
	'zero_empty_fields' => $zero_empty_fields,
	'start_time'        => $start_time,
	'json_path'         => $json_path,
	'total'             => $total,
	'processed_guids'   => $processed_guids,
	'start_index'       => $start_index,
);
}
