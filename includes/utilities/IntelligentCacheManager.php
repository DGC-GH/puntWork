<?php

/**
 * Intelligent Cache Manager for predictive caching and performance optimization.
 *
 * @since      1.0.1
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Puntwork\Utilities\IntelligentCacheManager' ) ) {
	class IntelligentCacheManager {
		private static $instance = null;
		private static $cache_patterns = array();
		private static $predictive_cache = array();
		private static $cache_hits = array();
		private static $cache_misses = array();

		const CACHE_GROUP_CRITICAL = 'puntwork_critical';
		const CACHE_GROUP_FEEDS = 'puntwork_feeds';
		const CACHE_GROUP_ANALYTICS = 'puntwork_analytics';
		const CACHE_GROUP_TAXONOMY = 'puntwork_taxonomy';
		const CACHE_GROUP_POSTMETA = 'puntwork_postmeta';

		const PREDICTION_ACCURACY_THRESHOLD = 0.7;
		const MAX_PREDICTIVE_CACHE_SIZE = 100;

		/**
		 * Get singleton instance
		 */
		public static function getInstance() {
			if ( self::$instance === null ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Warm critical caches on system startup
		 */
		public static function warmCriticalCaches() {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [CACHE] Warming critical caches' );
			}

			self::warmFeedConfigurations();
			self::warmTaxonomyTerms();
			self::warmCommonPostMeta();
			self::warmSystemSettings();

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [CACHE] Critical cache warming completed' );
			}
		}

		/**
		 * Warm feed configuration caches
		 */
		private static function warmFeedConfigurations() {
			global $wpdb;

			try {
				// Cache feed configurations
				$feeds = $wpdb->get_results( "
					SELECT post_id, meta_key, meta_value
					FROM {$wpdb->postmeta}
					WHERE meta_key LIKE 'feed_%'
					AND meta_value != ''
					ORDER BY post_id
				", ARRAY_A );

				$feed_configs = array();
				foreach ( $feeds as $feed ) {
					$feed_configs[ $feed['post_id'] ][ $feed['meta_key'] ] = $feed['meta_value'];
				}

				foreach ( $feed_configs as $post_id => $config ) {
					wp_cache_set(
						"feed_config_{$post_id}",
						$config,
						self::CACHE_GROUP_FEEDS,
						3600 // 1 hour
					);
				}

			} catch ( Exception $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[PUNTWORK] [CACHE] Error warming feed configurations: ' . $e->getMessage() );
				}
			}
		}

		/**
		 * Warm taxonomy term caches
		 */
		private static function warmTaxonomyTerms() {
			$taxonomies = array( 'job_category', 'job_location', 'job_type' );

			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_terms( array(
					'taxonomy' => $taxonomy,
					'hide_empty' => false,
					'number' => 1000, // Reasonable limit
				) );

				if ( ! is_wp_error( $terms ) ) {
					wp_cache_set(
						"taxonomy_terms_{$taxonomy}",
						$terms,
						self::CACHE_GROUP_TAXONOMY,
						1800 // 30 minutes
					);
				}
			}
		}

		/**
		 * Warm commonly accessed post meta
		 */
		private static function warmCommonPostMeta() {
			global $wpdb;

			try {
				// Cache common meta keys that are frequently accessed
				$common_meta_keys = array(
					'_last_import_update',
					'_import_hash',
					'guid',
					'functiontitle',
					'companyname'
				);

				foreach ( $common_meta_keys as $meta_key ) {
					$meta_values = $wpdb->get_results( $wpdb->prepare( "
						SELECT post_id, meta_value
						FROM {$wpdb->postmeta}
						WHERE meta_key = %s
						AND meta_value != ''
						ORDER BY post_id DESC
						LIMIT 5000
					", $meta_key ), ARRAY_A );

					if ( ! empty( $meta_values ) ) {
						wp_cache_set(
							"postmeta_{$meta_key}",
							$meta_values,
							self::CACHE_GROUP_POSTMETA,
							3600 // 1 hour
						);
					}
				}

			} catch ( Exception $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[PUNTWORK] [CACHE] Error warming post meta: ' . $e->getMessage() );
				}
			}
		}

		/**
		 * Warm system settings
		 */
		private static function warmSystemSettings() {
			// Cache PuntWork settings
			$settings = array(
				'puntwork_import_settings',
				'puntwork_performance_settings',
				'puntwork_cache_settings'
			);

			foreach ( $settings as $setting_key ) {
				$value = get_option( $setting_key );
				if ( $value !== false ) {
					wp_cache_set(
						"setting_{$setting_key}",
						$value,
						self::CACHE_GROUP_CRITICAL,
						7200 // 2 hours
					);
				}
			}
		}

		/**
		 * Predictive cache loading based on operation patterns
		 *
		 * @param array $predicted_operations Array of predicted operations
		 */
		public static function predictiveCacheLoading( array $predicted_operations ) {
			foreach ( $predicted_operations as $operation ) {
				self::prepareCacheForOperation( $operation );
			}
		}

		/**
		 * Prepare cache for a specific operation
		 *
		 * @param array $operation Operation details
		 */
		private static function prepareCacheForOperation( array $operation ) {
			switch ( $operation['type'] ) {
				case 'feed_import':
					self::prepareFeedImportCache( $operation );
					break;

				case 'batch_process':
					self::prepareBatchProcessCache( $operation );
					break;

				case 'taxonomy_query':
					self::prepareTaxonomyCache( $operation );
					break;

				case 'post_meta_query':
					self::preparePostMetaCache( $operation );
					break;
			}
		}

		/**
		 * Prepare cache for feed import operations
		 */
		private static function prepareFeedImportCache( array $operation ) {
			if ( isset( $operation['feed_ids'] ) ) {
				foreach ( $operation['feed_ids'] as $feed_id ) {
					// Preload feed configuration
					get_post_meta( $feed_id, 'feed_url', true );
					get_post_meta( $feed_id, 'feed_format', true );
					get_post_meta( $feed_id, 'feed_mapping', true );
				}
			}
		}

		/**
		 * Prepare cache for batch processing operations
		 */
		private static function prepareBatchProcessCache( array $operation ) {
			if ( isset( $operation['taxonomy_terms'] ) ) {
				foreach ( $operation['taxonomy_terms'] as $taxonomy ) {
					wp_cache_get( "taxonomy_terms_{$taxonomy}", self::CACHE_GROUP_TAXONOMY );
				}
			}
		}

		/**
		 * Prepare taxonomy cache
		 */
		private static function prepareTaxonomyCache( array $operation ) {
			if ( isset( $operation['taxonomies'] ) ) {
				foreach ( $operation['taxonomies'] as $taxonomy ) {
					get_terms( array(
						'taxonomy' => $taxonomy,
						'hide_empty' => false,
					) );
				}
			}
		}

		/**
		 * Prepare post meta cache
		 */
		private static function preparePostMetaCache( array $operation ) {
			if ( isset( $operation['meta_keys'] ) && isset( $operation['post_ids'] ) ) {
				foreach ( $operation['post_ids'] as $post_id ) {
					foreach ( $operation['meta_keys'] as $meta_key ) {
						get_post_meta( $post_id, $meta_key, true );
					}
				}
			}
		}

		/**
		 * Record cache access patterns for predictive caching
		 *
		 * @param string $key Cache key
		 * @param string $group Cache group
		 * @param bool $hit Whether it was a cache hit
		 */
		public static function recordCacheAccess( string $key, string $group, bool $hit ) {
			$pattern_key = $group . '::' . $key;

			if ( $hit ) {
				if ( ! isset( self::$cache_hits[ $pattern_key ] ) ) {
					self::$cache_hits[ $pattern_key ] = 0;
				}
				self::$cache_hits[ $pattern_key ]++;
			} else {
				if ( ! isset( self::$cache_misses[ $pattern_key ] ) ) {
					self::$cache_misses[ $pattern_key ] = 0;
				}
				self::$cache_misses[ $pattern_key ]++;
			}

			// Maintain reasonable array sizes
			if ( count( self::$cache_hits ) > 1000 ) {
				array_shift( self::$cache_hits );
			}
			if ( count( self::$cache_misses ) > 1000 ) {
				array_shift( self::$cache_misses );
			}
		}

		/**
		 * Get cache performance statistics
		 */
		public static function getCacheStats() {
			$total_hits = array_sum( self::$cache_hits );
			$total_misses = array_sum( self::$cache_misses );
			$total_accesses = $total_hits + $total_misses;

			$hit_rate = $total_accesses > 0 ? $total_hits / $total_accesses : 0;

			return array(
				'total_hits' => $total_hits,
				'total_misses' => $total_misses,
				'hit_rate' => $hit_rate,
				'top_hit_keys' => array_slice( self::$cache_hits, 0, 10, true ),
				'top_miss_keys' => array_slice( self::$cache_misses, 0, 10, true ),
			);
		}

		/**
		 * Optimize cache based on access patterns
		 */
		public static function optimizeCache() {
			$stats = self::getCacheStats();

			// Increase TTL for frequently hit keys
			foreach ( $stats['top_hit_keys'] as $key => $hits ) {
				if ( $hits > 100 ) { // Frequently accessed
					// This would extend TTL in a real implementation
					// For now, just log the optimization opportunity
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( "[PUNTWORK] [CACHE] High-hit key: {$key} ({$hits} hits)" );
					}
				}
			}

			// Consider preloading frequently missed keys
			foreach ( $stats['top_miss_keys'] as $key => $misses ) {
				if ( $misses > 50 ) { // Frequently missed
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( "[PUNTWORK] [CACHE] High-miss key: {$key} ({$misses} misses)" );
					}
				}
			}
		}

		/**
		 * Smart cache invalidation based on operation type
		 *
		 * @param string $operation_type Type of operation performed
		 * @param array $affected_items Items affected by the operation
		 */
		public static function smartInvalidateCache( string $operation_type, array $affected_items = array() ) {
			switch ( $operation_type ) {
				case 'feed_updated':
					self::invalidateFeedCache( $affected_items );
					break;

				case 'taxonomy_updated':
					self::invalidateTaxonomyCache( $affected_items );
					break;

				case 'post_meta_updated':
					self::invalidatePostMetaCache( $affected_items );
					break;

				case 'bulk_import':
					self::invalidateBulkImportCache();
					break;
			}
		}

		/**
		 * Invalidate feed-related caches
		 */
		private static function invalidateFeedCache( array $feed_ids ) {
			foreach ( $feed_ids as $feed_id ) {
				wp_cache_delete( "feed_config_{$feed_id}", self::CACHE_GROUP_FEEDS );
			}
		}

		/**
		 * Invalidate taxonomy caches
		 */
		private static function invalidateTaxonomyCache( array $taxonomies ) {
			foreach ( $taxonomies as $taxonomy ) {
				wp_cache_delete( "taxonomy_terms_{$taxonomy}", self::CACHE_GROUP_TAXONOMY );
			}
		}

		/**
		 * Invalidate post meta caches
		 */
		private static function invalidatePostMetaCache( array $meta_updates ) {
			foreach ( $meta_updates as $update ) {
				if ( isset( $update['meta_key'] ) ) {
					wp_cache_delete( "postmeta_{$update['meta_key']}", self::CACHE_GROUP_POSTMETA );
				}
			}
		}

		/**
		 * Invalidate caches after bulk import
		 */
		private static function invalidateBulkImportCache() {
			// Clear analytics cache as data has changed
			wp_cache_delete( 'import_stats', self::CACHE_GROUP_ANALYTICS );
			wp_cache_delete( 'recent_imports', self::CACHE_GROUP_ANALYTICS );

			// Clear post meta caches as new data was added
			wp_cache_delete( 'postmeta__last_import_update', self::CACHE_GROUP_POSTMETA );
			wp_cache_delete( 'postmeta__import_hash', self::CACHE_GROUP_POSTMETA );
		}

		/**
		 * Get predictive cache recommendations
		 */
		public static function getPredictiveRecommendations() {
			$stats = self::getCacheStats();
			$recommendations = array();

			if ( $stats['hit_rate'] < 0.5 ) {
				$recommendations[] = 'Cache hit rate is low. Consider preloading frequently accessed data.';
			}

			if ( ! empty( $stats['top_miss_keys'] ) ) {
				$recommendations[] = 'Consider preloading these frequently missed cache keys: ' .
					implode( ', ', array_keys( array_slice( $stats['top_miss_keys'], 0, 5 ) ) );
			}

			return $recommendations;
		}
	}

	// Initialize the intelligent cache manager
	IntelligentCacheManager::getInstance();
}