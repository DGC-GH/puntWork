<?php

/**
 * Batch item processing.
 *
 * @since      1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure required utilities are loaded
require_once __DIR__ . '/../utilities/database-optimization.php';

if ( ! function_exists( 'process_batch_items' ) ) {
	function process_batch_items( $batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, &$logs, &$updated, &$published, &$skipped, &$processed_count, &$processed_guids = array() ) {
		$script_start_time = microtime( true );
		
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [ITEMS-DEBUG] process_batch_items called with ' . count( $batch_guids ) . ' GUIDs' );
			error_log( '[PUNTWORK] [ITEMS-DEBUG] batch_items keys: ' . implode( ', ', array_keys( $batch_items ) ) );
		}
		if ( empty( $batch_guids ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [ITEMS-DEBUG] process_batch_items called with empty batch_guids - no items to process' );
			}

			return;
		}
		$user_id = get_user_by( 'login', 'admin' ) ? get_user_by( 'login', 'admin' )->ID : get_current_user_id();
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [ITEMS-DEBUG] Got user_id: ' . $user_id );
		}

		// Bulk fetch post statuses to avoid N+1 queries
		$post_ids_for_status = array_values( $post_ids_by_guid );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [ITEMS-DEBUG] Post IDs for status: ' . count( $post_ids_for_status ) );
			error_log( '[PUNTWORK] [ITEMS-DEBUG] About to call bulk_get_post_statuses' );
		}
		if ( ! function_exists( 'bulk_get_post_statuses' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [ERROR] bulk_get_post_statuses function not found' );
			}

			throw new Exception( 'bulk_get_post_statuses function not available' );
		}
		$post_statuses = bulk_get_post_statuses( $post_ids_for_status );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [ITEMS-DEBUG] bulk_get_post_statuses returned ' . count( $post_statuses ) . ' statuses' );
		}

		// Preload post meta to avoid N+1 queries during ACF updates
		if ( ! empty( $post_ids_for_status ) ) {
			$preloaded_meta = preload_post_meta_batch( $post_ids_for_status );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [ITEMS-DEBUG] Preloaded meta for ' . count( $preloaded_meta ) . ' posts' );
			}
		}

		$total_to_process = count( $batch_guids );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [ITEMS-DEBUG] Starting to process ' . $total_to_process . ' items' );
			error_log( '[PUNTWORK] [ITEMS-DEBUG] Current counts before processing: published=' . $published . ', updated=' . $updated . ', skipped=' . $skipped . ', processed_count=' . $processed_count );
		}

		// Log batch size and timing info
		$batch_size          = count( $batch_guids );
		$previous_batch_time = get_option( 'job_import_previous_batch_time', 0 );
		$last_batch_time     = get_option( 'job_import_last_batch_time', 0 );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [BATCH-TIMING] Processing batch of ' . $batch_size . ' items' );
			error_log( '[PUNTWORK] [BATCH-TIMING] Previous batch time: ' . $previous_batch_time . 's, Last batch time: ' . $last_batch_time . 's' );
		}

		// Collect all ACF updates for batch processing
		$all_acf_updates = array();
		$posts_to_update = array();

		$item_counter                 = 0;
		$intermediate_update_interval = 5; // Update status every 5 items for better UI responsiveness
		$last_intermediate_update     = 0;
		$item_timeout_limit          = 60; // 60 seconds per item max
		$memory_limit_bytes          = get_memory_limit_bytes();
		$memory_warning_threshold    = $memory_limit_bytes * 0.85; // Warn at 85%
		$memory_critical_threshold   = $memory_limit_bytes * 0.95; // Critical at 95%

		foreach ( $batch_guids as $guid ) {
			// Check for import cancellation
			if ( get_transient( 'import_cancel' ) ) {
				error_log( '[PUNTWORK] [CANCEL] Import cancelled by user' );
				break;
			}

			++$item_counter;
			if ( $item_counter % 100 == 0 ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[PUNTWORK] [ITEMS-DEBUG] ==== STARTING ITEM ' . $item_counter . '/' . $total_to_process . ' ===' );
				}
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [ITEMS-DEBUG] Processing GUID: ' . $guid );
				error_log( '[PUNTWORK] [ITEMS-DEBUG] GUID exists in batch_items: ' . ( isset( $batch_items[ $guid ] ) ? 'yes' : 'no' ) );
			}

			// Check memory usage before processing each item
			$current_memory = memory_get_usage( true );
			if ( $current_memory > $memory_critical_threshold ) {
				error_log( '[PUNTWORK] [MEMORY-CRITICAL] Memory usage critical before processing item ' . $item_counter . ': ' . round( $current_memory / 1024 / 1024, 2 ) . 'MB of ' . round( $memory_limit_bytes / 1024 / 1024, 2 ) . 'MB' );
				$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Memory usage critical - stopping batch processing early';
				break; // Stop processing to prevent crash
			} elseif ( $current_memory > $memory_warning_threshold ) {
				error_log( '[PUNTWORK] [MEMORY-WARNING] Memory usage high before processing item ' . $item_counter . ': ' . round( $current_memory / 1024 / 1024, 2 ) . 'MB of ' . round( $memory_limit_bytes / 1024 / 1024, 2 ) . 'MB' );
			}

			// Process item with fork-based timeout protection
			$item_result = process_item_with_fork($guid, $batch_items, $post_ids_by_guid, $last_updates, $post_statuses, $all_hashes_by_post, $item_counter, $item_timeout_limit);

			// Debug: Check what we got back
			if (!is_array($item_result)) {
				error_log('[PUNTWORK] [ERROR] process_item_with_fork returned non-array for GUID: ' . $guid . ', type: ' . gettype($item_result) . ', value: ' . var_export($item_result, true));
				$item_result = array(
					'success' => false,
					'error' => 'Non-array result returned',
					'published' => 0,
					'updated' => 0,
					'skipped' => 0,
					'logs' => array(),
					'acf_updates' => null,
					'post_id' => null,
				);
			}

			if ($item_result['success']) {
				$processed_count++;
				$published += $item_result['published'];
				$updated += $item_result['updated'];
				$skipped += $item_result['skipped'];
				$logs = array_merge($logs, $item_result['logs']);

				// Collect processed GUID for cleanup operations
				$processed_guids[] = $guid;

				// Collect ACF updates for bulk processing
				if ($item_result['acf_updates'] && $item_result['post_id']) {
					$all_acf_updates[] = $item_result['acf_updates'];
					$posts_to_update[] = $item_result['post_id'];
				}
			} else {
				// Item processing failed or timed out
				error_log( '[PUNTWORK] [TIMEOUT] Item ' . $guid . ' processing failed: ' . $item_result['error'] );
				$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Skipped GUID: ' . $guid . ' - ' . $item_result['error'];
				$processed_count++;
			}

			// Clear cache periodically to prevent memory accumulation during large batch processing
			if ( $processed_count % 10 === 0 ) {
				$cache_clear_start = microtime( true );
				$result1 = execute_with_timeout( function() { if ( function_exists( 'wp_cache_flush' ) ) { wp_cache_flush(); } }, array(), 5 ); // 5 second timeout
				$result2 = execute_with_timeout( function() { \Puntwork\Utilities\CacheManager::clearGroup( \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS ); }, array(), 5 ); // 5 second timeout
				$cache_clear_time = microtime( true ) - $cache_clear_start;
				error_log( '[PUNTWORK] [MEMORY-MGMT] Cache cleared after processing ' . $processed_count . ' items in ' . number_format( $cache_clear_time, 4 ) . ' seconds' );
			}

			// Update intermediate status every N items to keep UI responsive
			if ( $processed_count % $intermediate_update_interval == 0 || $processed_count >= $total_to_process ) {
				$current_time = microtime( true );
				if ( $current_time - $last_intermediate_update >= 0.5 || $processed_count >= $total_to_process ) { // At least 0.5 seconds between updates
					$status_update_start = microtime( true );
					error_log( '[PUNTWORK] [UI-STATUS] About to call update_intermediate_batch_status: processed=' . $processed_count . ', total=' . $total_to_process . ', published=' . $published . ', updated=' . $updated . ', skipped=' . $skipped );
					$result = execute_with_timeout( 'update_intermediate_batch_status', array( $processed_count, $total_to_process, $published, $updated, $skipped, $logs ), 10 ); // 10 second timeout
					$status_update_time = microtime( true ) - $status_update_start;
					if ( $result === null ) {
						error_log( '[PUNTWORK] [TIMEOUT] Intermediate status update timed out after ' . number_format( $status_update_time, 2 ) . ' seconds' );
					} else {
						error_log( '[PUNTWORK] [UI-STATUS] Intermediate status update completed in ' . number_format( $status_update_time, 4 ) . ' seconds' );
					}
					$last_intermediate_update = $current_time;
				} else {
					error_log( '[PUNTWORK] [UI-STATUS] Skipping intermediate update - too soon since last update (' . round( $current_time - $last_intermediate_update, 2 ) . 's ago)' );
				}
			}

			if ( $processed_count % 5 == 0 ) {
				error_log( '[PUNTWORK] [ITEMS-DEBUG] Processed ' . $processed_count . ' items so far in batch' );
				if ( ob_get_level() > 0 ) {
					ob_flush();
					flush();
				}
			}

			// Unset the processed item AFTER processing is complete
			unset( $batch_items[ $guid ] );

			// Aggressive memory cleanup after each item
			if ( $item_counter % 3 === 0 ) { // Every 3 items
				if ( function_exists( 'gc_collect_cycles' ) ) {
					gc_collect_cycles();
				}
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
			}
		}
		error_log( '[PUNTWORK] [ITEMS-DEBUG] process_batch_items completed processing all ' . $total_to_process . ' items' );
		error_log( '[PUNTWORK] [ITEMS-DEBUG] Final counts: published=' . $published . ', updated=' . $updated . ', skipped=' . $skipped . ', processed_count=' . $processed_count );

		// Execute bulk ACF updates for all posts in chunks
		if ( ! empty( $all_acf_updates ) ) {
			error_log( '[PUNTWORK] [ITEMS-DEBUG] Executing bulk ACF updates for ' . count( $all_acf_updates ) . ' posts' );
			$chunk_size  = 10; // Process in chunks of 10 posts to avoid overly large queries
			$chunks      = array_chunk( $all_acf_updates, $chunk_size );
			$post_chunks = array_chunk( $posts_to_update, $chunk_size );

			$total_acf_start = microtime( true );
			foreach ( $chunks as $chunk_index => $chunk_updates ) {
				$chunk_posts = $post_chunks[ $chunk_index ];
				$chunk_start = microtime( true );
				error_log( '[PUNTWORK] [ITEMS-DEBUG] Processing ACF chunk ' . ( $chunk_index + 1 ) . '/' . count( $chunks ) . ' (' . count( $chunk_updates ) . ' posts)' );

				bulk_update_acf_fields( $chunk_posts, $chunk_updates );

				$chunk_time = microtime( true ) - $chunk_start;
				error_log( '[PUNTWORK] [ITEMS-DEBUG] ACF chunk ' . ( $chunk_index + 1 ) . ' completed in ' . number_format( $chunk_time, 4 ) . ' seconds' );
			}
			$total_acf_time = microtime( true ) - $total_acf_start;
			error_log( '[PUNTWORK] [ITEMS-DEBUG] Bulk ACF updates completed in ' . number_format( $total_acf_time, 4 ) . ' seconds total (' . number_format( $total_acf_time / count( $all_acf_updates ), 4 ) . ' seconds per post)' );
		}

		// Final cache clear after all processing to ensure clean state
		$final_cache_start = microtime( true );
		$result1 = execute_with_timeout( function() { if ( function_exists( 'wp_cache_flush' ) ) { wp_cache_flush(); } }, array(), 10 ); // 10 second timeout
		$result2 = execute_with_timeout( function() { \Puntwork\Utilities\CacheManager::clearGroup( \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS ); }, array(), 10 ); // 10 second timeout
		$final_cache_time = microtime( true ) - $final_cache_start;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [MEMORY-MGMT] Final cache clear completed in ' . number_format( $final_cache_time, 4 ) . ' seconds' );
		}
	}
}

/**
 * Process a single item with fork-based timeout protection
 */
function process_item_with_fork($guid, $batch_items, $post_ids_by_guid, $last_updates, $post_statuses, $all_hashes_by_post, $item_counter, $timeout_limit) {
	error_log('[PUNTWORK] [FORK-DEBUG] ===== STARTING process_item_with_fork for GUID: ' . $guid . ' =====');
	$result = array(
		'success'  => false,
		'error'    => '',
		'published' => 0,
		'updated'  => 0,
		'skipped'  => 0,
		'logs'     => array(),
		'acf_updates' => null,
		'post_id' => null,
	);

	if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid') || !function_exists('pcntl_signal')) {
		error_log('[PUNTWORK] [FORK-DEBUG] pcntl functions not available for GUID: ' . $guid);
		$result['error'] = 'pcntl functions not available';
		return $result;
	}

	// DISABLED: Forking causes issues with WordPress database connections in child processes
	// Always process directly to avoid fork-related errors
	error_log('[PUNTWORK] [FORK-DEBUG] Forking disabled, processing directly for GUID: ' . $guid);
	error_log('[PUNTWORK] [FORK-DEBUG] batch_items exists: ' . (isset($batch_items) ? 'yes' : 'no'));
	error_log('[PUNTWORK] [FORK-DEBUG] batch_items[' . $guid . '] exists: ' . (isset($batch_items[$guid]) ? 'yes' : 'no'));
	if (isset($batch_items[$guid])) {
		error_log('[PUNTWORK] [FORK-DEBUG] batch_items[' . $guid . '] keys: ' . implode(', ', array_keys($batch_items[$guid])));
	}
	try {
		$item_result = process_single_item($guid, $batch_items, $post_ids_by_guid, $last_updates, $post_statuses, $all_hashes_by_post, $item_counter);
		error_log('[PUNTWORK] [FORK-DEBUG] process_single_item returned for GUID: ' . $guid . ', success: ' . (isset($item_result['success']) ? $item_result['success'] : 'not set') . ', error: ' . (isset($item_result['error']) ? $item_result['error'] : 'none'));
		return $item_result;
	} catch (Exception $e) {
		error_log('[PUNTWORK] [FORK-DEBUG] Exception in process_single_item for GUID: ' . $guid . ': ' . $e->getMessage());
		$result['error'] = 'Exception: ' . $e->getMessage();
		return $result;
	} catch (Throwable $e) {
		error_log('[PUNTWORK] [FORK-DEBUG] Throwable in process_single_item for GUID: ' . $guid . ': ' . $e->getMessage());
		$result['error'] = 'Throwable: ' . $e->getMessage();
		return $result;
	}
}

/**
 * Process a single item (called in child process)
 */
function process_single_item($guid, $batch_items, $post_ids_by_guid, $last_updates, $post_statuses, $all_hashes_by_post, $item_counter) {
	error_log('[PUNTWORK] [ITEM-DEBUG] ===== STARTING process_single_item for GUID: ' . $guid . ' =====');
	error_log('[PUNTWORK] [ITEM-DEBUG] Function called with guid=' . $guid . ', batch_items count=' . count($batch_items));
	$result = array(
		'success'  => false,
		'error'    => '',
		'published' => 0,
		'updated'  => 0,
		'skipped'  => 0,
		'logs'     => array(),
		'acf_updates' => null,
		'post_id' => null,
	);

	try {
		error_log('[PUNTWORK] [ITEM-DEBUG] Checking if batch_items[' . $guid . '] exists: ' . (isset($batch_items[$guid]) ? 'yes' : 'no'));
		if (!isset($batch_items[$guid])) {
			$result['error'] = 'Batch item not found for GUID: ' . $guid;
			error_log('[PUNTWORK] [ITEM-DEBUG] ERROR: batch_items[' . $guid . '] does not exist');
			return $result;
		}

		error_log('[PUNTWORK] [ITEM-DEBUG] Checking if batch_items[' . $guid . '][\'item\'] exists: ' . (isset($batch_items[$guid]['item']) ? 'yes' : 'no'));
		if (!isset($batch_items[$guid]['item'])) {
			$result['error'] = 'Batch item data not found for GUID: ' . $guid;
			error_log('[PUNTWORK] [ITEM-DEBUG] ERROR: batch_items[' . $guid . '][\'item\'] does not exist');
			return $result;
		}

		$item = $batch_items[$guid]['item'];
		error_log('[PUNTWORK] [ITEM-DEBUG] Retrieved item data for GUID: ' . $guid . ', item keys: ' . implode(', ', array_keys($item)));

		// Validate required data before processing
		if (empty($item['functiontitle']) || empty($item['guid'])) {
			$result['error'] = 'Missing required fields: functiontitle or guid';
			error_log('[PUNTWORK] [VALIDATION-ERROR] Missing required fields for GUID: ' . $guid . ' - title: ' . ($item['functiontitle'] ?? 'missing') . ', guid: ' . ($item['guid'] ?? 'missing'));
			return $result;
		}

		// Check memory usage before heavy operations
		$current_memory = memory_get_usage(true);
		$memory_limit = get_memory_limit_bytes();
		if ($current_memory > $memory_limit * 0.8) {
			$result['error'] = 'Memory limit approaching - skipping item';
			error_log('[PUNTWORK] [MEMORY-WARNING] Memory usage high (' . $current_memory . ' bytes) for GUID: ' . $guid);
			return $result;
		}

		$xml_updated = isset($item['updated']) ? $item['updated'] : '';
		$xml_updated_ts = strtotime($xml_updated);
		$post_id = isset($post_ids_by_guid[$guid]) ? $post_ids_by_guid[$guid] : null;
		error_log('[PUNTWORK] [ITEM-DEBUG] Post ID for GUID ' . $guid . ': ' . ($post_id ?: 'null'));

		// If post exists, check if it needs updating
		if ($post_id) {
			error_log('[PUNTWORK] [ITEM-DEBUG] Processing existing post ID: ' . $post_id . ' for GUID: ' . $guid);
			// Check if content has changed
			$current_hash = $all_hashes_by_post[$post_id] ?? '';
			$item_hash = md5(json_encode($item));

			if ($current_hash === $item_hash) {
				$result['skipped'] = 1;
				$result['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Skipped ID: ' . $post_id . ' GUID: ' . $guid . ' - No changes';
				$result['success'] = true;
				error_log('[PUNTWORK] [ITEM-DEBUG] Skipped post ID: ' . $post_id . ' for GUID: ' . $guid . ' - no changes');
				return $result;
			}

			// Update existing post
			$xml_title = isset($item['functiontitle']) ? $item['functiontitle'] : '';
			$xml_validfrom = isset($item['validfrom']) ? $item['validfrom'] : '';
			$post_modified = $xml_updated ?: current_time('mysql');

			// Update metadata
			try {
				update_post_meta($post_id, '_last_import_update', $xml_updated);
				update_post_meta($post_id, '_import_hash', $item_hash);
				error_log('[PUNTWORK] [ITEM-DEBUG] Updated metadata for post ID: ' . $post_id . ' for GUID: ' . $guid);
			} catch (Exception $e) {
				error_log('[PUNTWORK] [ITEM-DEBUG] Exception updating metadata for existing post ID: ' . $post_id . ' for GUID: ' . $guid . ': ' . $e->getMessage());
				$result['error'] = 'Existing post metadata update exception: ' . $e->getMessage();
				return $result;
			} catch (Throwable $e) {
				error_log('[PUNTWORK] [ITEM-DEBUG] Throwable updating metadata for existing post ID: ' . $post_id . ' for GUID: ' . $guid . ': ' . $e->getMessage());
				$result['error'] = 'Existing post metadata update throwable: ' . $e->getMessage();
				return $result;
			}

			// Prepare ACF updates
			try {
				if (!function_exists('get_acf_fields') || !function_exists('update_field')) {
					error_log('[PUNTWORK] [ACF-WARNING] ACF functions not available for existing post ID: ' . $post_id . ' GUID: ' . $guid);
					$result['acf_updates'] = null; // Skip ACF updates
				} else {
					$acf_fields = get_acf_fields();
					$zero_empty_fields = get_zero_empty_fields();
					$acf_updates = array();
					foreach ($acf_fields as $field) {
						$value = $item[$field] ?? '';
						$is_special = in_array($field, $zero_empty_fields);
						$set_value = $is_special && $value == '0' ? '' : $value;
						$acf_updates[$field] = $set_value;
					}
					error_log('[PUNTWORK] [ACF-PREPARED] Prepared ' . count($acf_updates) . ' ACF fields for existing post ID: ' . $post_id . ' GUID: ' . $guid);
				}
			} catch (Exception $e) {
				error_log('[PUNTWORK] [ACF-EXCEPTION] Exception preparing ACF updates for existing post ID: ' . $post_id . ' GUID: ' . $guid . ': ' . $e->getMessage());
				$result['error'] = 'Existing post ACF preparation exception: ' . $e->getMessage();
				return $result;
			} catch (Throwable $e) {
				error_log('[PUNTWORK] [ACF-THROWABLE] Throwable preparing ACF updates for existing post ID: ' . $post_id . ' GUID: ' . $guid . ': ' . $e->getMessage());
				$result['error'] = 'Existing post ACF preparation throwable: ' . $e->getMessage();
				return $result;
			}

			$result['updated'] = 1;
			$result['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Updated ID: ' . $post_id . ' GUID: ' . $guid;
			$result['acf_updates'] = $acf_updates;
			$result['post_id'] = $post_id;
			$result['success'] = true;
			error_log('[PUNTWORK] [ITEM-DEBUG] Successfully prepared update for post ID: ' . $post_id . ' for GUID: ' . $guid);
		} else {
			error_log('[PUNTWORK] [ITEM-DEBUG] Creating new post for GUID: ' . $guid);
			// Create new post
			$xml_title = isset($item['functiontitle']) ? $item['functiontitle'] : '';
			$xml_validfrom = isset($item['validfrom']) ? $item['validfrom'] : current_time('mysql');
			$post_modified = $xml_updated ?: current_time('mysql');

			$user_id = get_current_user_id();
			if (!$user_id) {
				$user_id = 1; // Fallback to admin user
			}

			$post_data = array(
				'post_type' => 'job',
				'post_title' => $xml_title,
				'post_name' => sanitize_title($xml_title . '-' . $guid),
				'post_status' => 'publish',
				'post_date' => $xml_validfrom,
				'post_modified' => $post_modified,
				'comment_status' => 'closed',
				'post_author' => $user_id,
			);

			error_log('[PUNTWORK] [ITEM-DEBUG] About to call wp_insert_post for GUID: ' . $guid);
			try {
				$post_id = wp_insert_post($post_data);
				error_log('[PUNTWORK] [DB-INSERT] wp_insert_post result for GUID ' . $guid . ': ' . var_export($post_id, true));
				if (is_wp_error($post_id)) {
					$result['error'] = 'wp_insert_post failed: ' . $post_id->get_error_message();
					error_log('[PUNTWORK] [DB-ERROR] wp_insert_post error for GUID ' . $guid . ': ' . $post_id->get_error_message());
					return $result;
				}
				if (!$post_id || $post_id <= 0) {
					$result['error'] = 'wp_insert_post returned invalid ID: ' . $post_id;
					error_log('[PUNTWORK] [DB-ERROR] Invalid post ID returned for GUID ' . $guid . ': ' . $post_id);
					return $result;
				}
				error_log('[PUNTWORK] [DB-SUCCESS] Successfully created post ID ' . $post_id . ' for GUID: ' . $guid);
			} catch (Exception $e) {
				error_log('[PUNTWORK] [DB-EXCEPTION] Exception in wp_insert_post for GUID: ' . $guid . ': ' . $e->getMessage());
				$result['error'] = 'wp_insert_post exception: ' . $e->getMessage();
				return $result;
			} catch (Throwable $e) {
				error_log('[PUNTWORK] [DB-THROWABLE] Throwable in wp_insert_post for GUID: ' . $guid . ': ' . $e->getMessage());
				$result['error'] = 'wp_insert_post throwable: ' . $e->getMessage();
				return $result;
			}

			// Update metadata
			try {
				update_post_meta($post_id, '_last_import_update', $xml_updated);
				$item_hash = md5(json_encode($item));
				update_post_meta($post_id, '_import_hash', $item_hash);
				error_log('[PUNTWORK] [ITEM-DEBUG] Updated metadata for new post ID: ' . $post_id . ' for GUID: ' . $guid);
			} catch (Exception $e) {
				error_log('[PUNTWORK] [ITEM-DEBUG] Exception updating metadata for post ID: ' . $post_id . ' for GUID: ' . $guid . ': ' . $e->getMessage());
				$result['error'] = 'Metadata update exception: ' . $e->getMessage();
				return $result;
			} catch (Throwable $e) {
				error_log('[PUNTWORK] [ITEM-DEBUG] Throwable updating metadata for post ID: ' . $post_id . ' for GUID: ' . $guid . ': ' . $e->getMessage());
				$result['error'] = 'Metadata update throwable: ' . $e->getMessage();
				return $result;
			}

			// Prepare ACF updates with availability check
			try {
				if (!function_exists('get_acf_fields') || !function_exists('update_field')) {
					error_log('[PUNTWORK] [ACF-WARNING] ACF functions not available for post ID: ' . $post_id . ' GUID: ' . $guid);
					$result['acf_updates'] = null; // Skip ACF updates
				} else {
					$acf_fields = get_acf_fields();
					$zero_empty_fields = get_zero_empty_fields();
					$acf_updates = array();
					foreach ($acf_fields as $field) {
						$value = $item[$field] ?? '';
						$is_special = in_array($field, $zero_empty_fields);
						$set_value = $is_special && $value == '0' ? '' : $value;
						$acf_updates[$field] = $set_value;
					}
					error_log('[PUNTWORK] [ACF-PREPARED] Prepared ' . count($acf_updates) . ' ACF fields for post ID: ' . $post_id . ' GUID: ' . $guid);
				}
			} catch (Exception $e) {
				error_log('[PUNTWORK] [ACF-EXCEPTION] Exception preparing ACF updates for post ID: ' . $post_id . ' GUID: ' . $guid . ': ' . $e->getMessage());
				$result['error'] = 'ACF preparation exception: ' . $e->getMessage();
				return $result;
			} catch (Throwable $e) {
				error_log('[PUNTWORK] [ACF-THROWABLE] Throwable preparing ACF updates for post ID: ' . $post_id . ' GUID: ' . $guid . ': ' . $e->getMessage());
				$result['error'] = 'ACF preparation throwable: ' . $e->getMessage();
				return $result;
			}

			$result['published'] = 1;
			$result['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Published ID: ' . $post_id . ' GUID: ' . $guid;
			$result['acf_updates'] = $acf_updates;
			$result['post_id'] = $post_id;
			$result['success'] = true;
			error_log('[PUNTWORK] [ITEM-DEBUG] Successfully created new post ID: ' . $post_id . ' for GUID: ' . $guid);
		}

		error_log('[PUNTWORK] [ITEM-DEBUG] Completed process_single_item for GUID: ' . $guid . ', success: ' . $result['success']);
		return $result;
	} catch (Exception $e) {
		error_log('[PUNTWORK] [ITEM-DEBUG] Exception in process_single_item for GUID: ' . $guid . ': ' . $e->getMessage() . ' at line ' . $e->getLine());
		error_log('[PUNTWORK] [ITEM-DEBUG] Stack trace: ' . $e->getTraceAsString());
		$result['error'] = 'Exception: ' . $e->getMessage();
		return $result;
	} catch (Throwable $e) {
		error_log('[PUNTWORK] [ITEM-DEBUG] Throwable in process_single_item for GUID: ' . $guid . ': ' . $e->getMessage() . ' at line ' . $e->getLine());
		error_log('[PUNTWORK] [ITEM-DEBUG] Stack trace: ' . $e->getTraceAsString());
		$result['error'] = 'Throwable: ' . $e->getMessage();
		return $result;
	}
}
function execute_with_timeout( callable $function, array $args = array(), int $timeout_seconds = 30 ) {
	// Execute directly without timeout to avoid forking issues
	try {
		$result = call_user_func_array( $function, $args );
		return $result;
	} catch ( \Exception $e ) {
		error_log( '[PUNTWORK] [TIMEOUT] Exception during execution: ' . $e->getMessage() );
		return null;
	}
}
