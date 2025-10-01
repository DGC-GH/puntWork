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
	function process_batch_items( $batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, &$logs, &$updated, &$published, &$skipped, &$processed_count ) {
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

		foreach ( $batch_guids as $guid ) {
			// Check for cancellation at the start of each item
			if ( get_transient( 'import_cancel' ) ) {
				$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Batch processing cancelled by user';
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[PUNTWORK] [ITEMS-DEBUG] Batch processing cancelled by user at item ' . $item_counter );
				}

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

			try {
				$item           = $batch_items[ $guid ]['item'];
				$xml_updated    = isset( $item['updated'] ) ? $item['updated'] : '';
				$xml_updated_ts = strtotime( $xml_updated );
				$post_id        = isset( $post_ids_by_guid[ $guid ] ) ? $post_ids_by_guid[ $guid ] : null;

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[PUNTWORK] [ITEMS-DEBUG] Item data extracted: post_id=' . ( $post_id ?? 'null' ) . ', xml_updated="' . $xml_updated . '", xml_updated_ts=' . $xml_updated_ts );
					error_log( '[PUNTWORK] [ITEMS-DEBUG] Item title: "' . ( isset( $item['functiontitle'] ) ? $item['functiontitle'] : 'MISSING' ) . '"' );
					error_log( '[PUNTWORK] [ITEMS-DEBUG] Item company: "' . ( isset( $item['company'] ) ? $item['company'] : 'MISSING' ) . '"' );
				}

				// If post exists, check if it needs updating
				if ( $post_id ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[PUNTWORK] [ITEMS-DEBUG] Post exists for GUID ' . $guid . ' (ID: ' . $post_id . '), checking if update needed' );
					}

					// First, ensure the job is published if it's in the feed
					$current_post_status = $post_statuses[ $post_id ] ?? 'draft';
					error_log( '[PUNTWORK] [ITEMS-DEBUG] Current post status: ' . $current_post_status );

					if ( $current_post_status !== 'publish' ) {
						error_log( '[PUNTWORK] [ITEMS-DEBUG] Republishing post ' . $post_id . ' for GUID ' . $guid . ' (was ' . $current_post_status . ')' );
						wp_update_post(
							array(
								'ID'          => $post_id,
								'post_status' => 'publish',
							)
						);
						$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Republished ID: ' . $post_id . ' GUID: ' . $guid . ' - Found in active feed';
						error_log( '[PUNTWORK] [ITEMS-DEBUG] Successfully republished post ' . $post_id );
					} else {
						error_log( '[PUNTWORK] [ITEMS-DEBUG] Post ' . $post_id . ' already published, no republish needed' );
					}

					$current_last_update = isset( $last_updates[ $post_id ] ) ? $last_updates[ $post_id ]->meta_value : '';
					$current_last_ts     = $current_last_update ? strtotime( $current_last_update ) : 0;

					error_log( '[PUNTWORK] [ITEMS-DEBUG] GUID ' . $guid . ' timestamp comparison:' );
					error_log( '[PUNTWORK] [ITEMS-DEBUG]   - xml_updated: "' . $xml_updated . '" -> timestamp: ' . $xml_updated_ts . ' (' . date( 'Y-m-d H:i:s', $xml_updated_ts ) . ')' );
					error_log( '[PUNTWORK] [ITEMS-DEBUG]   - current_last_update: "' . $current_last_update . '" -> timestamp: ' . $current_last_ts . ' (' . date( 'Y-m-d H:i:s', $current_last_ts ) . ')' );

					// Skip if no update timestamp or if current version is newer/equal
					// if ($xml_updated_ts && $current_last_ts >= $xml_updated_ts ) {
					// error_log('[PUNTWORK] [ITEMS-DEBUG] SKIPPING: GUID ' . $guid . ' - Not updated (current version is newer or equal)');
					// error_log('[PUNTWORK] [ITEMS-DEBUG]   - Reason: current_ts (' . $current_last_ts . ') >= xml_ts (' . $xml_updated_ts . ')');
					// ++$skipped;
					// $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Skipped ID: ' . $post_id . ' GUID: ' . $guid . ' - Not updated (current: ' . date('Y-m-d H:i:s', $current_last_ts) . ', xml: ' . date('Y-m-d H:i:s', $xml_updated_ts) . ')';
					// ++$processed_count;
					// error_log('[PUNTWORK] [ITEMS-DEBUG] ==== COMPLETED ITEM ' . $item_counter . ' - SKIPPED (NOT UPDATED) ===');
					// continue;
					// }

					$current_hash = $all_hashes_by_post[ $post_id ] ?? '';
					$item_hash    = md5( json_encode( $item ) );

					error_log( '[PUNTWORK] [ITEMS-DEBUG] GUID ' . $guid . ' hash comparison:' );
					error_log( '[PUNTWORK] [ITEMS-DEBUG]   - current_hash: ' . substr( $current_hash, 0, 8 ) . '...' );
					error_log( '[PUNTWORK] [ITEMS-DEBUG]   - item_hash: ' . substr( $item_hash, 0, 8 ) . '...' );
					error_log( '[PUNTWORK] [ITEMS-DEBUG]   - hash_match: ' . ( $current_hash === $item_hash ? 'true' : 'false' ) );

					// Skip if content hasn't changed
					if ( $current_hash === $item_hash ) {
						error_log( '[PUNTWORK] [ITEMS-DEBUG] SKIPPING: GUID ' . $guid . ' - No changes (content hash identical)' );
						++$skipped;
						$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Skipped ID: ' . $post_id . ' GUID: ' . $guid . ' - No changes';
						++$processed_count;
						error_log( '[PUNTWORK] [ITEMS-DEBUG] ==== COMPLETED ITEM ' . $item_counter . ' - SKIPPED (NO CHANGES) ===' );

						continue;
					}

					error_log( '[PUNTWORK] [ITEMS-DEBUG] UPDATING existing post ' . $post_id . ' for GUID ' . $guid );
					// Update existing post
					$xml_title     = isset( $item['functiontitle'] ) ? $item['functiontitle'] : '';
					$xml_validfrom = isset( $item['validfrom'] ) ? $item['validfrom'] : '';
					$post_modified = $xml_updated ?: current_time( 'mysql' );

					error_log( '[PUNTWORK] [ITEMS-DEBUG] Update details: title="' . $xml_title . '", validfrom="' . $xml_validfrom . '", modified="' . $post_modified . '"' );

					// Temporarily disable ACF hooks to prevent hanging during wp_update_post
					$acf_hooks_disabled = false;
					if ( function_exists( 'acf' ) ) {
						$acf_hooks_disabled = true;
						error_log( '[PUNTWORK] [ITEMS-DEBUG] Temporarily disabling ACF hooks for wp_update_post' );
						
						// Remove common ACF hooks that might cause issues
						remove_action( 'save_post', 'acf_save_post', 10 );
						remove_action( 'wp_insert_post_data', 'acf_wp_insert_post_data', 10 );
						remove_action( 'pre_post_update', 'acf_pre_post_update', 10 );
						
						// Disable ACF field saving temporarily
						if ( function_exists( 'acf_disable_field_saving' ) ) {
							acf_disable_field_saving();
						}
					}

					$post_update_start = microtime( true );
					wp_update_post(
						array(
							'ID'            => $post_id,
							'post_title'    => $xml_title,
							'post_name'     => sanitize_title( $xml_title . '-' . $guid ),
							'post_status'   => 'publish', // Ensure updated posts are published
							'post_date'     => $xml_validfrom,
							'post_modified' => $post_modified,
						)
					);
					$post_update_time = microtime( true ) - $post_update_start;
					error_log( '[PUNTWORK] [ITEMS-DEBUG] Post update completed in ' . number_format( $post_update_time, 4 ) . ' seconds' );

					// Re-enable ACF hooks after wp_update_post
					if ( $acf_hooks_disabled ) {
						error_log( '[PUNTWORK] [ITEMS-DEBUG] Re-enabling ACF hooks after wp_update_post' );
						
						// Re-add ACF hooks
						add_action( 'save_post', 'acf_save_post', 10, 1 );
						// Note: acf_wp_insert_post_data function does not exist, so not re-adding
						add_action( 'pre_post_update', 'acf_pre_post_update', 10, 2 );
						
						// Re-enable ACF field saving
						if ( function_exists( 'acf_enable_field_saving' ) ) {
							acf_enable_field_saving();
						}
					}

					// Check for database errors after wp_update_post
					global $wpdb;
					if ( ! empty( $wpdb->last_error ) ) {
						error_log( '[PUNTWORK] [DB-ERROR] Database error after wp_update_post for post ' . $post_id . ': ' . $wpdb->last_error );
					}

					error_log( '[PUNTWORK] [ITEMS-DEBUG] Post updated successfully, now updating metadata' );

					update_post_meta( $post_id, '_last_import_update', $xml_updated );
					update_post_meta( $post_id, '_import_hash', $item_hash );

					// Prepare ACF field updates for bulk operation
					$acf_updates = array();
					foreach ( $acf_fields as $field ) {
						$value      = $item[ $field ] ?? '';
						$is_special = in_array( $field, $zero_empty_fields );
						$set_value  = $is_special && $value == '0' ? '' : $value;

						// Ensure values are strings for logging
						$value_str     = is_array( $value ) ? json_encode( $value ) : (string) $value;
						$set_value_str = is_array( $set_value ) ? json_encode( $set_value ) : (string) $set_value;

						$acf_updates[ $field ] = $set_value;
						if ( $item_counter % 100 == 0 ) {
							error_log( '[PUNTWORK] [ITEMS-DEBUG] ACF field ' . $field . ': "' . substr( $value_str, 0, 50 ) . '" -> "' . substr( $set_value_str, 0, 50 ) . '"' );
						}
					}

					// Collect ACF updates for batch processing
					$all_acf_updates[] = $acf_updates;
					$posts_to_update[] = $post_id;

					++$updated;
					$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Updated ID: ' . $post_id . ' GUID: ' . $guid;
					error_log( '[PUNTWORK] [ITEMS-DEBUG] ==== COMPLETED ITEM ' . $item_counter . ' - UPDATED ===' );
				} else {
					error_log( '[PUNTWORK] [ITEMS-DEBUG] No existing post found for GUID ' . $guid . ', creating new post' );
					// Create new post only if it doesn't exist
					$xml_title     = isset( $item['functiontitle'] ) ? $item['functiontitle'] : '';
					$xml_validfrom = isset( $item['validfrom'] ) ? $item['validfrom'] : current_time( 'mysql' );
					$post_modified = $xml_updated ?: current_time( 'mysql' );

					error_log( '[PUNTWORK] [ITEMS-DEBUG] Creating new post with: title="' . $xml_title . '", validfrom="' . $xml_validfrom . '", modified="' . $post_modified . '"' );

					// Temporarily disable ACF hooks to prevent hanging during wp_insert_post
					$acf_hooks_disabled = false;
					if ( function_exists( 'acf' ) ) {
						$acf_hooks_disabled = true;
						error_log( '[PUNTWORK] [ITEMS-DEBUG] Temporarily disabling ACF hooks for wp_insert_post' );
						
						// Remove common ACF hooks that might cause issues
						remove_action( 'save_post', 'acf_save_post', 10 );
						remove_action( 'wp_insert_post_data', 'acf_wp_insert_post_data', 10 );
						remove_action( 'pre_post_update', 'acf_pre_post_update', 10 );
						
						// Disable ACF field saving temporarily
						if ( function_exists( 'acf_disable_field_saving' ) ) {
							acf_disable_field_saving();
						}
					}

					$post_data = array(
						'post_type'      => 'job',  // Use existing CPT created with ACF
						'post_title'     => $xml_title,
						'post_name'      => sanitize_title( $xml_title . '-' . $guid ),
						'post_status'    => 'publish',
						'post_date'      => $xml_validfrom,
						'post_modified'  => $post_modified,
						'comment_status' => 'closed',
						'post_author'    => $user_id,
					);

					$post_insert_start = microtime( true );
					$post_id           = wp_insert_post( $post_data );
					$post_insert_time  = microtime( true ) - $post_insert_start;
					error_log( '[PUNTWORK] [ITEMS-DEBUG] Post insert completed in ' . number_format( $post_insert_time, 4 ) . ' seconds' );
					
					// Re-enable ACF hooks after wp_insert_post
					if ( $acf_hooks_disabled ) {
						error_log( '[PUNTWORK] [ITEMS-DEBUG] Re-enabling ACF hooks after wp_insert_post' );
						
						// Re-add ACF hooks
						add_action( 'save_post', 'acf_save_post', 10, 1 );
						// Note: acf_wp_insert_post_data function does not exist, so not re-adding
						add_action( 'pre_post_update', 'acf_pre_post_update', 10, 2 );
						
						// Re-enable ACF field saving
						if ( function_exists( 'acf_enable_field_saving' ) ) {
							acf_enable_field_saving();
						}
					}
					if ( is_wp_error( $post_id ) ) {
						$error_msg = 'Create failed GUID: ' . $guid . ' - ' . $post_id->get_error_message();
						$logs[]    = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . $error_msg;
						error_log( '[PUNTWORK] [ITEMS-DEBUG] ERROR: Post creation failed for GUID ' . $guid . ': ' . $post_id->get_error_message() );
						++$processed_count;
						error_log( '[PUNTWORK] [ITEMS-DEBUG] ==== COMPLETED ITEM ' . $item_counter . ' - CREATE FAILED ===' );

						continue;
					}

					error_log( '[PUNTWORK] [ITEMS-DEBUG] Successfully created post ID: ' . $post_id . ' for GUID: ' . $guid );
					++$published;
					update_post_meta( $post_id, '_last_import_update', $xml_updated );
					$item_hash = md5( json_encode( $item ) );
					update_post_meta( $post_id, '_import_hash', $item_hash );

					// Prepare ACF field updates for bulk operation
					$acf_updates = array();
					foreach ( $acf_fields as $field ) {
						$value      = $item[ $field ] ?? '';
						$is_special = in_array( $field, $zero_empty_fields );
						$set_value  = $is_special && $value == '0' ? '' : $value;

						// Ensure values are strings for logging
						$value_str     = is_array( $value ) ? json_encode( $value ) : (string) $value;
						$set_value_str = is_array( $set_value ) ? json_encode( $set_value ) : (string) $set_value;

						$acf_updates[ $field ] = $set_value;
						if ( $item_counter % 100 == 0 ) {
							error_log( '[PUNTWORK] [ITEMS-DEBUG] ACF field ' . $field . ': "' . substr( $value_str, 0, 50 ) . '" -> "' . substr( $set_value_str, 0, 50 ) . '"' );
						}
					}

					// Collect ACF updates for batch processing
					$all_acf_updates[] = $acf_updates;
					$posts_to_update[] = $post_id;

					$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Published ID: ' . $post_id . ' GUID: ' . $guid;
					error_log( '[PUNTWORK] [ITEMS-DEBUG] ==== COMPLETED ITEM ' . $item_counter . ' - PUBLISHED ===' );
				}

				++$processed_count;
				unset( $batch_items[ $guid ] );

				// Clear cache periodically to prevent memory accumulation during large batch processing
				if ( $processed_count % 10 === 0 ) {
					if ( function_exists( 'wp_cache_flush' ) ) {
						wp_cache_flush();
					}
					\Puntwork\Utilities\CacheManager::clearGroup( \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS );
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[PUNTWORK] [MEMORY-MGMT] Cache cleared after processing ' . $processed_count . ' items' );
					}
				}

				// Update intermediate status every N items to keep UI responsive
				if ( $processed_count % $intermediate_update_interval == 0 || $processed_count >= $total_to_process ) {
					$current_time = microtime( true );
					if ( $current_time - $last_intermediate_update >= 0.5 || $processed_count >= $total_to_process ) { // At least 0.5 seconds between updates
						error_log( '[PUNTWORK] [UI-STATUS] About to call update_intermediate_batch_status: processed=' . $processed_count . ', total=' . $total_to_process . ', published=' . $published . ', updated=' . $updated . ', skipped=' . $skipped );
						update_intermediate_batch_status( $processed_count, $total_to_process, $published, $updated, $skipped, $logs );
						$last_intermediate_update = $current_time;
						error_log( '[PUNTWORK] [UI-STATUS] Intermediate status update completed at ' . $processed_count . '/' . $total_to_process . ' items' );
					} else {
						error_log( '[PUNTWORK] [UI-STATUS] Skipping intermediate update - too soon since last update (' . round( $current_time - $last_intermediate_update, 2 ) . 's ago)' );
					}
				}

				if ( $processed_count % 5 == 0 ) {
					error_log( '[PUNTWORK] [ITEMS-DEBUG] Processed ' . $processed_count . ' items so far in batch' );
					ob_flush();
					flush();
				}
			} catch ( \Exception $e ) {
				$error_msg = 'Error processing GUID ' . $guid . ': ' . $e->getMessage();
				$logs[]    = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . $error_msg;
				error_log( '[PUNTWORK] [ITEMS-DEBUG] EXCEPTION processing GUID ' . $guid . ': ' . $e->getMessage() );
				error_log( '[PUNTWORK] [ITEMS-DEBUG] Stack trace: ' . $e->getTraceAsString() );
				// Continue to next item instead of failing the whole batch
				++$processed_count;
				error_log( '[PUNTWORK] [ITEMS-DEBUG] ==== COMPLETED ITEM ' . $item_counter . ' - EXCEPTION ===' );
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
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		\Puntwork\Utilities\CacheManager::clearGroup( \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [MEMORY-MGMT] Final cache clear after batch processing completion' );
		}
	}
}
