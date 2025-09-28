<?php

/**
 * Enhanced batch processing utilities
 *
 * @package    Puntwork
 * @subpackage Batch
 * @since      1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enhanced batch processing with advanced caching and memory management
 */
function process_batch_enhanced( array $batch_guids, array $batch_items, array &$logs, int &$published, int &$updated, int &$skipped, int &$duplicates_drafted ): array {
	$start_time = microtime( true );

	// Initialize enhanced monitoring
	$monitor_id = start_performance_monitoring( 'enhanced_batch_processing' );

	try {
		// Warm up caches for better performance
		\Puntwork\Utilities\EnhancedCacheManager::warmCommonCaches();

		// Get existing posts with enhanced caching
		$existing_by_guid = get_posts_by_guids_with_status_enhanced( $batch_guids );

		$post_ids_by_guid = array();

		// Handle duplicates with circuit breaker protection
		handle_batch_duplicates_enhanced( $batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid );

		// Prepare batch metadata with advanced caching
		$batch_metadata = prepare_batch_metadata_enhanced( $post_ids_by_guid );

		// Process items with memory management
		$processed_count = process_batch_items_with_memory_management( $batch_guids, $batch_items, $batch_metadata, $post_ids_by_guid, $logs, $updated, $published, $skipped );

		$processing_time = microtime( true ) - $start_time;

		checkpoint_performance(
			$monitor_id,
			'batch_completed',
			array(
				'processed_count' => $processed_count,
				'processing_time' => $processing_time,
				'memory_peak'     => memory_get_peak_usage( true ),
			)
		);

		end_performance_monitoring( $monitor_id );

		return array(
			'processed_count' => $processed_count,
			'processing_time' => $processing_time,
		);
	} catch ( \Exception $e ) {
		end_performance_monitoring( $monitor_id );
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Enhanced batch processing failed: ' . $e->getMessage();
		throw $e;
	}
}

/**
 * Get posts by GUIDs with enhanced caching
 */
function get_posts_by_guids_with_status_enhanced( array $guids ): array {
	if ( empty( $guids ) ) {
		return array();
	}

	// Use enhanced caching with batch operations
	$cache_key = 'posts_by_guid_' . md5( implode( ',', $guids ) );
	$cached    = \Puntwork\Utilities\EnhancedCacheManager::getWithWarmup(
		$cache_key,
		\Puntwork\Utilities\CacheManager::GROUP_ANALYTICS,
		function () use ( $guids ) {
			return get_posts_by_guids_with_status( $guids );
		},
		10 * MINUTE_IN_SECONDS
	);

	return $cached;
}

/**
 * Handle duplicates with circuit breaker protection
 */
function handle_batch_duplicates_enhanced( array $batch_guids, array $existing_by_guid, array &$logs, int &$duplicates_drafted, array &$post_ids_by_guid ): void {
	// Check circuit breaker for duplicate processing
	if ( ! can_process_feed( 'duplicate_processing' ) ) {
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Duplicate processing circuit breaker open, skipping advanced deduplication';
		handle_duplicates( $batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid );
		return;
	}

	try {
		handle_batch_duplicates( $batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid );
		record_feed_success( 'duplicate_processing' );
	} catch ( \Exception $e ) {
		record_feed_failure( 'duplicate_processing' );
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Advanced deduplication failed, falling back to basic: ' . $e->getMessage();
		handle_duplicates( $batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid );
	}
}

/**
 * Prepare batch metadata with advanced caching strategies
 */
function prepare_batch_metadata_enhanced( array $post_ids_by_guid ): array {
	global $wpdb;

	$post_ids = array_values( $post_ids_by_guid );
	if ( empty( $post_ids ) ) {
		return array(
			'last_updates'   => array(),
			'hashes_by_post' => array(),
		);
	}

	// Use larger chunks for better performance
	$max_chunk_size = 100; // Increased from 50
	$post_id_chunks = array_chunk( $post_ids, $max_chunk_size );

	// Get last updates with enhanced caching
	$last_updates = get_cached_last_updates_enhanced( $post_ids, $post_id_chunks );

	// Get import hashes with enhanced caching
	$hashes_by_post = get_cached_import_hashes_enhanced( $post_ids, $post_id_chunks );

	return array(
		'last_updates'   => $last_updates,
		'hashes_by_post' => $hashes_by_post,
	);
}

/**
 * Enhanced cached last updates with batch operations
 */
function get_cached_last_updates_enhanced( array $post_ids, array $post_id_chunks ): array {
	sort( $post_ids );
	$cache_key = 'batch_last_updates_enhanced_' . md5( implode( ',', $post_ids ) );

	$cached = \Puntwork\Utilities\EnhancedCacheManager::get( $cache_key, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS );
	if ( $cached !== false ) {
		return $cached;
	}

	$last_updates = array();
	foreach ( $post_id_chunks as $chunk ) {
		if ( empty( $chunk ) ) {
			continue;
		}
		$placeholders  = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
		$chunk_last    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_last_import_update' AND post_id IN ($placeholders)",
				$chunk
			),
			OBJECT_K
		);
		$last_updates += (array) $chunk_last;
	}

	// Cache for longer period with compression for large datasets
	if ( count( $last_updates ) > 1000 ) {
		\Puntwork\Utilities\EnhancedCacheManager::setCompressed( $cache_key, $last_updates, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS, 10 * MINUTE_IN_SECONDS );
	} else {
		\Puntwork\Utilities\EnhancedCacheManager::set( $cache_key, $last_updates, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS, 10 * MINUTE_IN_SECONDS );
	}

	return $last_updates;
}

/**
 * Enhanced cached import hashes with batch operations
 */
function get_cached_import_hashes_enhanced( array $post_ids, array $post_id_chunks ): array {
	$cache_key = 'batch_import_hashes_enhanced_' . md5( implode( ',', $post_ids ) );

	$cached = \Puntwork\Utilities\EnhancedCacheManager::get( $cache_key, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS );
	if ( $cached !== false ) {
		return $cached;
	}

	$hashes_by_post = array();
	foreach ( $post_id_chunks as $chunk ) {
		if ( empty( $chunk ) ) {
			continue;
		}
		$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
		$chunk_hashes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_import_hash' AND post_id IN ($placeholders)",
				$chunk
			),
			OBJECT_K
		);
		foreach ( $chunk_hashes as $id => $obj ) {
			$hashes_by_post[ $id ] = $obj->meta_value;
		}
	}

	// Cache for longer period with compression for large datasets
	if ( count( $hashes_by_post ) > 1000 ) {
		\Puntwork\Utilities\EnhancedCacheManager::setCompressed( $cache_key, $hashes_by_post, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS, 10 * MINUTE_IN_SECONDS );
	} else {
		\Puntwork\Utilities\EnhancedCacheManager::set( $cache_key, $hashes_by_post, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS, 10 * MINUTE_IN_SECONDS );
	}

	return $hashes_by_post;
}

/**
 * Process batch items with advanced memory management
 */
function process_batch_items_with_memory_management( array $batch_guids, array $batch_items, array $batch_metadata, array $post_ids_by_guid, array &$logs, int &$updated, int &$published, int &$skipped ): int {
	$processed_count = 0;
	$batch_size      = count( $batch_guids );

	// Predict memory usage and adjust batch size if needed
	$memory_prediction = \Puntwork\Utilities\AdvancedMemoryManager::predictMemoryUsage( $batch_size );

	if ( $memory_prediction['will_exceed_limit'] ) {
		$recommended_size = $memory_prediction['recommended_batch_size'];
		$logs[]           = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Predicted memory exceedance, adjusting batch size from {$batch_size} to {$recommended_size}";

		// Process in smaller chunks
		$chunks          = array_chunk( $batch_guids, $recommended_size, true );
		$total_processed = 0;

		foreach ( $chunks as $chunk_guids ) {
			$chunk_items    = array_intersect_key( $batch_items, array_flip( $chunk_guids ) );
			$chunk_post_ids = array_intersect_key( $post_ids_by_guid, array_flip( $chunk_guids ) );

			$chunk_processed  = process_batch_chunk( $chunk_guids, $chunk_items, $batch_metadata, $chunk_post_ids, $logs, $updated, $published, $skipped );
			$total_processed += $chunk_processed;

			// Memory cleanup between chunks
			\Puntwork\Utilities\AdvancedMemoryManager::checkAndCleanup();
		}

		return $total_processed;
	}

	// Process normally with memory monitoring
	return process_batch_chunk( $batch_guids, $batch_items, $batch_metadata, $post_ids_by_guid, $logs, $updated, $published, $skipped );
}

/**
 * Process a chunk of batch items
 */
function process_batch_chunk( array $batch_guids, array $batch_items, array $batch_metadata, array $post_ids_by_guid, array &$logs, int &$updated, int &$published, int &$skipped ): int {
	$processed_count   = 0;
	$acf_fields        = get_acf_fields();
	$zero_empty_fields = get_zero_empty_fields();

	process_batch_items( $batch_guids, $batch_items, $batch_metadata['last_updates'], $batch_metadata['hashes_by_post'], $acf_fields, $zero_empty_fields, $post_ids_by_guid, $logs, $updated, $published, $skipped, $processed_count );

	return $processed_count;
}
