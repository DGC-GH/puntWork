<?php

/**
 * Optimized batch item processing with bulk operations.
 *
 * @since      1.0.1
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure required utilities are loaded
require_once __DIR__ . '/../utilities/database-optimization.php';

if ( ! function_exists( 'process_batch_items_optimized' ) ) {
	function process_batch_items_optimized( $batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, &$logs, &$updated, &$published, &$skipped, &$processed_count ) {
		$script_start_time = microtime( true );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [ITEMS-DEBUG] process_batch_items_optimized called with ' . count( $batch_guids ) . ' GUIDs' );
		}
		if ( empty( $batch_guids ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [ITEMS-DEBUG] process_batch_items_optimized called with empty batch_guids - no items to process' );
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

		// DISABLE EXPENSIVE HOOKS AT BATCH LEVEL FOR MAXIMUM PERFORMANCE
		global $wp_filter;
		$expensive_hooks_backup = array();
		$expensive_hooks = array(
			'save_post',           // Many plugins hook here for indexing/notifications
			'wp_insert_post',      // Post insertion hooks
			'publish_post',        // Publishing hooks
			'transition_post_status', // Status change hooks
			'added_post_meta',     // Meta addition hooks
			'updated_post_meta',   // Meta update hooks
			'post_updated',        // Post update hooks
			'pre_post_update',     // Pre post update hooks
		);
		foreach ( $expensive_hooks as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) ) {
				$expensive_hooks_backup[ $hook ] = $wp_filter[ $hook ];
				unset( $wp_filter[ $hook ] );
			}
		}

		// Disable ACF hooks for the entire batch
		$acf_hooks_disabled = false;
		if ( function_exists( 'acf' ) && function_exists( 'acf_save_post' ) ) {
			$acf_hooks_disabled = true;
			remove_action( 'save_post', 'acf_save_post', 10 );
			if ( function_exists( 'acf_disable_field_saving' ) ) {
				acf_disable_field_saving();
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [PERFORMANCE] Disabled expensive hooks for batch processing' );
		}

		// Collect data for bulk operations
		$posts_to_insert = array();
		$posts_to_update = array();
		$acf_updates = array();
		$meta_updates = array(); // For _last_import_update and _import_hash

		$item_counter = 0;
		$intermediate_update_interval = 5; // Update status every 5 items for better UI responsiveness
		$last_intermediate_update = 0;

		foreach ( $batch_guids as $guid ) {
			++$item_counter;

			try {
				$item = $batch_items[ $guid ]['item'];
				$xml_updated = isset( $item['updated'] ) ? $item['updated'] : '';
				$xml_updated_ts = strtotime( $xml_updated );
				$post_id = isset( $post_ids_by_guid[ $guid ] ) ? $post_ids_by_guid[ $guid ] : null;

				// Check for cancellation at the start of each item
				if ( get_transient( 'import_cancel' ) ) {
					$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Batch processing cancelled by user';
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[PUNTWORK] [ITEMS-DEBUG] Batch processing cancelled by user at item ' . $item_counter );
					}
					break;
				}

				// If post exists, check if it needs updating
				if ( $post_id ) {
					$current_post_status = $post_statuses[ $post_id ] ?? 'draft';
					if ( $current_post_status !== 'publish' ) {
						// Need to republish - add to update queue
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

				// Update intermediate status every N items
				if ( $processed_count % $intermediate_update_interval == 0 || $processed_count >= $total_to_process ) {
					$current_time = microtime( true );
					if ( $current_time - $last_intermediate_update >= 0.5 || $processed_count >= $total_to_process ) {
						update_intermediate_batch_status( $processed_count, $total_to_process, $published, $updated, $skipped, $logs );
						$last_intermediate_update = $current_time;
					}
				}

			} catch ( \Exception $e ) {
				$error_msg = 'Error processing GUID ' . $guid . ': ' . $e->getMessage();
				$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . $error_msg;
				error_log( '[PUNTWORK] [ITEMS-DEBUG] EXCEPTION processing GUID ' . $guid . ': ' . $e->getMessage() );
				++$processed_count;
			}
		}

		// EXECUTE BULK OPERATIONS
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [BULK-OPS] Executing bulk operations: ' . count( $posts_to_insert ) . ' inserts, ' . count( $posts_to_update ) . ' updates' );
		}

		$bulk_start_time = microtime( true );

		// Bulk insert new posts
		$inserted_post_ids = array();
		if ( ! empty( $posts_to_insert ) ) {
			$inserted_post_ids = bulk_insert_posts( $posts_to_insert );

			// Update GUIDs for inserted posts and fix meta_updates placeholders
			foreach ( $inserted_post_ids as $index => $post_id ) {
				if ( isset( $batch_guids[ $index ] ) ) {
					$guid = $batch_guids[ $index ];
					update_post_meta( $post_id, 'guid', $guid );

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

		// Bulk update meta (including ACF fields)
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
			error_log( '[PUNTWORK] [BULK-OPS] Bulk operations completed in ' . number_format( $bulk_time, 4 ) . ' seconds' );
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

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [PERFORMANCE] Restored expensive hooks after batch processing' );
			error_log( '[PUNTWORK] [ITEMS-DEBUG] process_batch_items_optimized completed processing all ' . $total_to_process . ' items' );
			error_log( '[PUNTWORK] [ITEMS-DEBUG] Final counts: published=' . $published . ', updated=' . $updated . ', skipped=' . $skipped . ', processed_count=' . $processed_count );
		}

		// Final cache clear after all processing
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		\Puntwork\Utilities\CacheManager::clearGroup( \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [MEMORY-MGMT] Final cache clear after batch processing completion' );
		}
	}
}