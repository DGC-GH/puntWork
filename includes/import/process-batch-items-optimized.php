<?php

/**
 * Optimized batch item processing with streaming and bulk operations.
 *
 * @since      1.0.1
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure required utilities are loaded
require_once __DIR__ . '/../utilities/database-optimization.php';
require_once __DIR__ . '/../utilities/MemoryManager.php';

if ( ! function_exists( 'process_batch_items_streaming' ) ) {
	/**
	 * Process batch items using streaming approach to minimize memory usage.
	 *
	 * @param string $json_path Path to the JSONL file
	 * @param array $batch_guids Array of GUIDs to process
	 * @param array $last_updates Last update timestamps
	 * @param array $all_hashes_by_post Existing post hashes
	 * @param array $acf_fields ACF fields to update
	 * @param array $zero_empty_fields Fields that should be empty when value is '0'
	 * @param array $post_ids_by_guid Existing post IDs by GUID
	 * @param array &$logs Processing logs
	 * @param int &$updated Count of updated posts
	 * @param int &$published Count of published posts
	 * @param int &$skipped Count of skipped posts
	 * @param int &$processed_count Total processed count
	 */
	function process_batch_items_streaming( $json_path, $batch_guids, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, &$logs, &$updated, &$published, &$skipped, &$processed_count ) {
		$script_start_time = microtime( true );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [STREAMING] process_batch_items_streaming called with ' . count( $batch_guids ) . ' GUIDs' );
		}

		if ( empty( $batch_guids ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [STREAMING] process_batch_items_streaming called with empty batch_guids - no items to process' );
			}
			return;
		}

		$user_id = get_user_by( 'login', 'admin' ) ? get_user_by( 'login', 'admin' )->ID : get_current_user_id();

		// Preload post statuses and meta for existing posts
		$post_ids_for_status = array_values( $post_ids_by_guid );
		if ( ! empty( $post_ids_for_status ) ) {
			$post_statuses = bulk_get_post_statuses( $post_ids_for_status );
			$preloaded_meta = preload_post_meta_batch( $post_ids_for_status );
		}

		$total_to_process = count( $batch_guids );
		$chunk_size = \Puntwork\Utilities\MemoryManager::getOptimalChunkSize();
		$processed_in_chunk = 0;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [STREAMING] Using chunk size: ' . $chunk_size . ' for ' . $total_to_process . ' items' );
		}

		// DISABLE EXPENSIVE HOOKS AT BATCH LEVEL FOR MAXIMUM PERFORMANCE
		global $wp_filter;
		$expensive_hooks_backup = array();
		$expensive_hooks = array(
			'save_post', 'wp_insert_post', 'publish_post', 'transition_post_status',
			'added_post_meta', 'updated_post_meta', 'post_updated', 'pre_post_update',
		);
		foreach ( $expensive_hooks as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) ) {
				$expensive_hooks_backup[ $hook ] = $wp_filter[ $hook ];
				unset( $wp_filter[ $hook ] );
			}
		}

		// Disable ACF hooks
		$acf_hooks_disabled = false;
		if ( function_exists( 'acf' ) && function_exists( 'acf_save_post' ) ) {
			$acf_hooks_disabled = true;
			remove_action( 'save_post', 'acf_save_post', 10 );
			if ( function_exists( 'acf_disable_field_saving' ) ) {
				acf_disable_field_saving();
			}
		}

		// Process items in chunks
		$chunk_start_time = microtime( true );
		$chunk_guids = array_chunk( $batch_guids, $chunk_size );

		foreach ( $chunk_guids as $chunk_index => $guid_chunk ) {
			$chunk_processed = process_guid_chunk(
				$json_path, $guid_chunk, $last_updates, $all_hashes_by_post,
				$acf_fields, $zero_empty_fields, $post_ids_by_guid, $post_statuses,
				$user_id, $logs, $updated, $published, $skipped, $processed_count
			);

			$processed_in_chunk += $chunk_processed;

			// Memory management checkpoint
			if ( $processed_in_chunk % 100 === 0 ) {
				\Puntwork\Utilities\MemoryManager::checkMemoryUsage();
			}

			// Update progress every chunk
			update_intermediate_batch_status( $processed_count, $total_to_process, $published, $updated, $skipped, $logs );

			// Check for cancellation
			if ( get_transient( 'import_cancel' ) ) {
				$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Batch processing cancelled by user';
				break;
			}
		}

		// RESTORE EXPENSIVE HOOKS
		foreach ( $expensive_hooks_backup as $hook => $filters ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				$wp_filter[ $hook ] = $filters;
			} else {
				$wp_filter[ $hook ]->callbacks = array_merge( $wp_filter[ $hook ]->callbacks, $filters->callbacks );
			}
		}

		// Re-enable ACF hooks
		if ( $acf_hooks_disabled ) {
			add_action( 'save_post', 'acf_save_post', 10, 1 );
			if ( function_exists( 'acf_enable_field_saving' ) ) {
				acf_enable_field_saving();
			}
		}

		// Final cleanup
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		\Puntwork\Utilities\CacheManager::clearGroup( \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS );

		$total_time = microtime( true ) - $script_start_time;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[PUNTWORK] [STREAMING] Completed streaming processing in %.4f seconds', $total_time ) );
		}
	}
}

if ( ! function_exists( 'process_guid_chunk' ) ) {
	/**
	 * Process a chunk of GUIDs by streaming the JSONL file.
	 */
	function process_guid_chunk( $json_path, $guid_chunk, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, $post_statuses, $user_id, &$logs, &$updated, &$published, &$skipped, &$processed_count ) {
		$posts_to_insert = array();
		$posts_to_update = array();
		$acf_updates = array();
		$meta_updates = array();

		$guid_set = array_flip( $guid_chunk );
		$found_items = array();

		// Stream through JSONL file to find matching items
		$handle = fopen( $json_path, 'r' );
		if ( ! $handle ) {
			throw new Exception( 'Unable to open JSONL file for streaming: ' . $json_path );
		}

		while ( ( $line = fgets( $handle ) ) !== false ) {
			$item_data = json_decode( trim( $line ), true );
			if ( ! $item_data || ! isset( $item_data['guid'] ) ) {
				continue;
			}

			$guid = $item_data['guid'];
			if ( isset( $guid_set[ $guid ] ) ) {
				$found_items[ $guid ] = $item_data;
				unset( $guid_set[ $guid ] );

				// Break if we've found all items in this chunk
				if ( empty( $guid_set ) ) {
					break;
				}
			}
		}
		fclose( $handle );

		// Process found items
		foreach ( $guid_chunk as $guid ) {
			if ( ! isset( $found_items[ $guid ] ) ) {
				$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Warning: GUID not found in JSONL: ' . $guid;
				++$processed_count;
				continue;
			}

			try {
				$item = $found_items[ $guid ];
				$xml_updated = isset( $item['updated'] ) ? $item['updated'] : '';
				$xml_updated_ts = strtotime( $xml_updated );
				$post_id = isset( $post_ids_by_guid[ $guid ] ) ? $post_ids_by_guid[ $guid ] : null;

				// If post exists, check if it needs updating
				if ( $post_id ) {
					$current_post_status = $post_statuses[ $post_id ] ?? 'draft';
					if ( $current_post_status !== 'publish' ) {
						// Need to republish
						$posts_to_update[] = array(
							'ID' => $post_id,
							'post_status' => 'publish',
							'post_modified' => $xml_updated ?: current_time( 'mysql' ),
							'post_modified_gmt' => $xml_updated ? get_gmt_from_date( $xml_updated ) : current_time( 'mysql', true ),
						);
						$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Republished ID: ' . $post_id . ' GUID: ' . $guid;
					}

					$current_hash = $all_hashes_by_post[ $post_id ] ?? '';
					$item_hash = md5( json_encode( $item ) );

					// Skip if content hasn't changed
					if ( $current_hash === $item_hash ) {
						++$skipped;
						$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Skipped ID: ' . $post_id . ' GUID: ' . $guid . ' - No changes';
						++$processed_count;
						continue;
					}

					// Update existing post
					$xml_title = isset( $item['functiontitle'] ) ? $item['functiontitle'] : '';
					$post_modified = $xml_updated ?: current_time( 'mysql' );

					$posts_to_update[] = array(
						'ID' => $post_id,
						'post_title' => $xml_title,
						'post_name' => sanitize_title( $xml_title . '-' . $guid ),
						'post_status' => 'publish',
						'post_modified' => $post_modified,
						'post_modified_gmt' => $xml_updated ? get_gmt_from_date( $xml_updated ) : current_time( 'mysql', true ),
					);

					// Prepare ACF updates
					$acf_update_data = array();
					foreach ( $acf_fields as $field ) {
						$value = $item[ $field ] ?? '';
						$is_special = in_array( $field, $zero_empty_fields );
						$set_value = $is_special && $value == '0' ? '' : $value;
						$acf_update_data[ $field ] = $set_value;
					}
					$acf_updates[] = $acf_update_data;

					// Prepare meta updates
					$meta_updates[] = array(
						'post_id' => $post_id,
						'meta_key' => '_last_import_update',
						'meta_value' => $xml_updated,
					);
					$meta_updates[] = array(
						'post_id' => $post_id,
						'meta_key' => '_import_hash',
						'meta_value' => $item_hash,
					);

					++$updated;
					$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Updated ID: ' . $post_id . ' GUID: ' . $guid;
				} else {
					// Create new post
					$xml_title = isset( $item['functiontitle'] ) ? $item['functiontitle'] : '';
					$xml_validfrom = isset( $item['validfrom'] ) ? $item['validfrom'] : current_time( 'mysql' );
					$post_modified = $xml_updated ?: current_time( 'mysql' );

					$posts_to_insert[] = array(
						'post_type' => 'job',
						'post_title' => $xml_title,
						'post_name' => sanitize_title( $xml_title . '-' . $guid ),
						'post_status' => 'publish',
						'post_date' => $xml_validfrom,
						'post_date_gmt' => $xml_validfrom ? get_gmt_from_date( $xml_validfrom ) : current_time( 'mysql', true ),
						'post_modified' => $post_modified,
						'post_modified_gmt' => $xml_updated ? get_gmt_from_date( $xml_updated ) : current_time( 'mysql', true ),
						'comment_status' => 'closed',
						'ping_status' => 'closed',
						'post_author' => $user_id,
						'guid' => '', // Will be set after insert
					);

					// Prepare ACF updates for new post
					$acf_update_data = array();
					foreach ( $acf_fields as $field ) {
						$value = $item[ $field ] ?? '';
						$is_special = in_array( $field, $zero_empty_fields );
						$set_value = $is_special && $value == '0' ? '' : $value;
						$acf_update_data[ $field ] = $set_value;
					}
					$acf_updates[] = $acf_update_data;

					// Prepare meta updates for new post
					$item_hash = md5( json_encode( $item ) );
					$meta_updates[] = array(
						'post_id' => 'NEW_POST_PLACEHOLDER', // Will be replaced after insert
						'meta_key' => '_last_import_update',
						'meta_value' => $xml_updated,
					);
					$meta_updates[] = array(
						'post_id' => 'NEW_POST_PLACEHOLDER', // Will be replaced after insert
						'meta_key' => '_import_hash',
						'meta_value' => $item_hash,
					);
					$meta_updates[] = array(
						'post_id' => 'NEW_POST_PLACEHOLDER', // Will be replaced after insert
						'meta_key' => 'guid',
						'meta_value' => $guid,
					);

					++$published;
					$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Published GUID: ' . $guid;
				}

				++$processed_count;

			} catch ( \Exception $e ) {
				$error_msg = 'Error processing GUID ' . $guid . ': ' . $e->getMessage();
				$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . $error_msg;
				error_log( '[PUNTWORK] [STREAMING] EXCEPTION processing GUID ' . $guid . ': ' . $e->getMessage() );
				++$processed_count;
			}
		}

		// Execute bulk operations for this chunk
		execute_chunk_bulk_operations( $posts_to_insert, $posts_to_update, $meta_updates, $acf_updates, $logs );

		return count( $guid_chunk );
	}
}

if ( ! function_exists( 'execute_chunk_bulk_operations' ) ) {
	/**
	 * Execute bulk operations for a chunk of processed items.
	 */
	function execute_chunk_bulk_operations( $posts_to_insert, $posts_to_update, $meta_updates, $acf_updates, &$logs ) {
		$bulk_start_time = microtime( true );

		// Bulk insert new posts
		$inserted_post_ids = array();
		if ( ! empty( $posts_to_insert ) ) {
			$inserted_post_ids = bulk_insert_posts( $posts_to_insert );

			// Update GUIDs for inserted posts and fix meta_updates placeholders
			foreach ( $inserted_post_ids as $index => $post_id ) {
				if ( isset( $posts_to_insert[ $index ] ) ) {
					$guid = $posts_to_insert[ $index ]['guid'] ?? '';
					if ( $guid ) {
						update_post_meta( $post_id, 'guid', $guid );
					}

					// Fix meta_updates placeholders
					foreach ( $meta_updates as &$meta_update ) {
						if ( $meta_update['post_id'] === 'NEW_POST_PLACEHOLDER' ) {
							$meta_update['post_id'] = $post_id;
							break; // Only fix one per new post
						}
					}
				}
			}
		}

		// Bulk update existing posts
		if ( ! empty( $posts_to_update ) ) {
			bulk_update_posts( $posts_to_update );
		}

		// Bulk update meta
		if ( ! empty( $meta_updates ) ) {
			bulk_insert_postmeta( $meta_updates );
		}

		// Bulk update ACF fields
		if ( ! empty( $acf_updates ) ) {
			$acf_post_ids = array_merge( $inserted_post_ids, array_column( $posts_to_update, 'ID' ) );
			bulk_update_acf_fields( $acf_post_ids, $acf_updates );
		}

		$bulk_time = microtime( true ) - $bulk_start_time;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[PUNTWORK] [BULK-OPS] Chunk bulk operations completed in %.4f seconds', $bulk_time ) );
		}
	}
}