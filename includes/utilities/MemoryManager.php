<?php

/**
 * Memory management utilities for large imports.
 *
 * @since      1.0.9
 */

namespace Puntwork\Utilities;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Memory management utilities for large imports.
 */
class MemoryManager {

	private static int $gc_threshold    = 100; // Run GC every 100 items
	private static int $processed_count = 0;
	private static int $last_gc_run     = 0;

	/**
	 * Check and manage memory usage during batch processing.
	 *
	 * @param  int   $current_index Current processing index
	 * @param  float $threshold     Memory threshold (0-1)
	 * @return array Memory management actions taken
	 */
	public static function checkMemoryUsage( int $current_index, float $threshold = 0.8 ): array {
		$actions      = array();
		$memory_usage = memory_get_usage( true );
		$memory_limit = self::getMemoryLimitBytes();
		$memory_ratio = $memory_usage / $memory_limit;

		++self::$processed_count;

		// Force garbage collection periodically
		if ( self::$processed_count - self::$last_gc_run >= self::$gc_threshold ) {
			gc_collect_cycles();
			self::$last_gc_run = self::$processed_count;
			$actions[]         = 'garbage_collection';
		}

		// Memory pressure detected
		if ( $memory_ratio > $threshold ) {
			// Aggressive cleanup
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
				$actions[] = 'cache_flush';
			}

			// Force immediate GC
			gc_collect_cycles();
			$actions[] = 'forced_gc';

			// Clear any large static caches if they exist
			if ( isset( $GLOBALS['wp_object_cache'] ) && method_exists( $GLOBALS['wp_object_cache'], 'flush' ) ) {
				$GLOBALS['wp_object_cache']->flush();
				$actions[] = 'object_cache_flush';
			}
		}

		return array(
			'memory_usage_mb' => round( $memory_usage / 1024 / 1024, 2 ),
			'memory_limit_mb' => round( $memory_limit / 1024 / 1024, 2 ),
			'memory_ratio'    => round( $memory_ratio, 3 ),
			'actions_taken'   => $actions,
		);
	}

	/**
	 * Optimize memory for large batch operations.
	 */
	public static function optimizeForLargeBatch(): void {
		// Increase GC threshold to reduce collection frequency
		gc_mem_caches();

		// Disable some WordPress features that consume memory
		if ( ! defined( 'WP_DISABLE_FATAL_ERROR_HANDLER' ) ) {
			define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
		}

		// Reduce autoload overhead for known classes
		if ( function_exists( 'spl_autoload_register' ) ) {
			// Preload critical classes if needed
		}
	}

	/**
	 * Get memory limit in bytes.
	 */
	protected static function getMemoryLimitBytes(): int {
		$limit = ini_get( 'memory_limit' );
		if ( preg_match( '/^(\d+)(.)$/', $limit, $matches ) ) {
			$value = (int) $matches[1];
			$unit  = strtoupper( $matches[2] );
			switch ( $unit ) {
				case 'G':
					return $value * 1024 * 1024 * 1024;
				case 'M':
					return $value * 1024 * 1024;
				case 'K':
					return $value * 1024;
				default:
					return $value;
			}
		}

		return 128 * 1024 * 1024; // Default 128MB
	}

	/**
	 * Get optimal chunk size based on available memory.
	 *
	 * @return int Optimal chunk size for processing
	 */
	public static function getOptimalChunkSize(): int {
		$memory_limit = self::getMemoryLimitBytes();
		$current_usage = memory_get_usage( true );

		// Reserve 20% of memory for overhead
		$available_memory = $memory_limit - $current_usage;
		$safe_memory = $available_memory * 0.8;

		// Estimate ~2KB per item (rough estimate for job data)
		$estimated_item_size = 2048; // 2KB per item
		$optimal_chunk_size = (int) floor( $safe_memory / $estimated_item_size );

		// Set reasonable bounds
		$optimal_chunk_size = max( 10, min( 100, $optimal_chunk_size ) );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[PUNTWORK] [MEMORY] Optimal chunk size: %d (memory_limit: %.1fMB, current_usage: %.1fMB, available: %.1fMB)',
				$optimal_chunk_size,
				$memory_limit / 1024 / 1024,
				$current_usage / 1024 / 1024,
				$available_memory / 1024 / 1024
			) );
		}

		return $optimal_chunk_size;
	}

}
