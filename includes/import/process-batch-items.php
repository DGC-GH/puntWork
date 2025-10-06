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

if ( ! function_exists( 'process_batch_items' ) ) {
	function process_batch_items( $batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, &$logs, &$updated, &$published, &$skipped, &$processed_count, &$processed_guids = array() ) {
		if ( empty( $batch_guids ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$user_id = 1; // Fallback to admin user
		}

		foreach ( $batch_guids as $guid ) {
			// Check for import cancellation
			if ( get_transient( 'import_cancel' ) ) {
				break;
			}

			if ( ! isset( $batch_items[ $guid ] ) ) {
				continue;
			}

			$item = $batch_items[ $guid ]['item'];

			// Validate required data
			if ( empty( $item['functiontitle'] ) || empty( $item['guid'] ) ) {
				$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Skipped GUID: ' . $guid . ' - Missing required fields';
				$processed_count++;
				continue;
			}

			$xml_updated = $item['updated'] ?? '';
			$post_id = $post_ids_by_guid[ $guid ] ?? null;

			// If post exists, check if it needs updating
			if ( $post_id ) {
				// Check if content has changed
				$current_hash = $all_hashes_by_post[ $post_id ] ?? '';
				$item_hash = md5( json_encode( $item ) );

				if ( $current_hash === $item_hash ) {
					$skipped++;
					$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Skipped ID: ' . $post_id . ' GUID: ' . $guid . ' - No changes';
					$processed_count++;
					$processed_guids[] = $guid;
					continue;
				}

				// Update existing post
				update_post_meta( $post_id, '_last_import_update', $xml_updated );
				update_post_meta( $post_id, '_import_hash', $item_hash );

				// Update ACF fields if available
				if ( function_exists( 'update_field' ) ) {
					foreach ( $acf_fields as $field ) {
						$value = $item[ $field ] ?? '';
						$is_special = in_array( $field, $zero_empty_fields );
						$set_value = $is_special && $value == '0' ? '' : $value;
						update_field( $field, $set_value, $post_id );
					}
				}

				$updated++;
				$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Updated ID: ' . $post_id . ' GUID: ' . $guid;
			} else {
				// Create new post
				$xml_title = $item['functiontitle'] ?? 'Untitled Job';
				$xml_validfrom = $item['validfrom'] ?? current_time( 'mysql' );
				$post_modified = $xml_updated ?: current_time( 'mysql' );

				$post_data = array(
					'post_type'     => 'job',
					'post_title'    => $xml_title,
					'post_name'     => sanitize_title( $xml_title . '-' . $guid ),
					'post_status'   => 'publish',
					'post_date'     => $xml_validfrom,
					'post_modified' => $post_modified,
					'comment_status' => 'closed',
					'post_author'   => $user_id,
				);

				$post_id = wp_insert_post( $post_data );

				if ( is_wp_error( $post_id ) ) {
					$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Failed to create post for GUID: ' . $guid . ' - ' . $post_id->get_error_message();
					$processed_count++;
					continue;
				}

				// Update metadata
				update_post_meta( $post_id, '_last_import_update', $xml_updated );
				$item_hash = md5( json_encode( $item ) );
				update_post_meta( $post_id, '_import_hash', $item_hash );

				// Update ACF fields if available
				if ( function_exists( 'update_field' ) ) {
					foreach ( $acf_fields as $field ) {
						$value = $item[ $field ] ?? '';
						$is_special = in_array( $field, $zero_empty_fields );
						$set_value = $is_special && $value == '0' ? '' : $value;
						update_field( $field, $set_value, $post_id );
					}
				}

				$published++;
				$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Published ID: ' . $post_id . ' GUID: ' . $guid;
			}

			$processed_count++;
			$processed_guids[] = $guid;
		}
	}
}
