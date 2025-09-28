<?php

/**
 * Enhanced Cache Manager with advanced features
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.9
 */

namespace Puntwork\Utilities;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enhanced Cache Manager with advanced features
 */
class EnhancedCacheManager extends CacheManager {

	/**
	 * Cache warming for frequently accessed data
	 */
	public static function warmCommonCaches(): void {
		// Warm up ACF fields cache
		if ( function_exists( 'get_acf_fields' ) ) {
			$acf_fields = get_acf_fields();
			self::set( 'acf_fields_warmed', $acf_fields, self::GROUP_MAPPINGS, HOUR_IN_SECONDS );
		}

		// Warm up field mappings
		if ( function_exists( 'get_field_mappings' ) ) {
			$mappings = get_field_mappings();
			self::set( 'field_mappings_warmed', $mappings, self::GROUP_MAPPINGS, HOUR_IN_SECONDS );
		}

		// Warm up geographic mappings
		if ( function_exists( 'get_geographic_mappings' ) ) {
			$geo_mappings = get_geographic_mappings();
			self::set( 'geographic_mappings_warmed', $geo_mappings, self::GROUP_MAPPINGS, HOUR_IN_SECONDS );
		}
	}

	/**
	 * Get cached data with automatic cache warming
	 */
	public static function getWithWarmup(
		string $key,
		string $group = '',
		?callable $fallback = null,
		int $warmup_threshold = 300
	) {
		$cached = self::get( $key, $group );

		if ( $cached === false && $fallback ) {
			// Cache miss - execute fallback and cache result
			$cached = $fallback();
			self::set( $key, $cached, $group, $warmup_threshold );
		}

		return $cached;
	}

	/**
	 * Batch cache operations for better performance
	 */
	public static function getMultiple( array $keys, string $group = '' ): array {
		$results      = array();
		$missing_keys = array();

		// First pass - get from cache
		foreach ( $keys as $key ) {
			$cached = self::get( $key, $group );
			if ( $cached !== false ) {
				$results[ $key ] = $cached;
			} else {
				$missing_keys[] = $key;
			}
		}

		return array( $results, $missing_keys );
	}

	/**
	 * Set multiple cache entries at once
	 */
	public static function setMultiple( array $data, string $group = '', int $expiration = 3600 ): bool {
		$success = true;
		foreach ( $data as $key => $value ) {
			if ( ! self::set( $key, $value, $group, $expiration ) ) {
				$success = false;
			}
		}
		return $success;
	}

	/**
	 * Intelligent cache invalidation based on patterns
	 */
	public static function invalidatePattern( string $pattern, string $group = '' ): int {
		global $wpdb;

		$invalidated = 0;

		// For transients (fallback)
		$transient_pattern = '_transient_' . ( $group ? $group . '_' : '' ) . $pattern;
		$transients        = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$transient_pattern . '%'
			)
		);

		foreach ( $transients as $transient ) {
			$key = str_replace( '_transient_', '', $transient );
			if ( $group ) {
				$key = str_replace( $group . '_', '', $key );
			}
			delete_transient( $key );
			++$invalidated;
		}

		// For Redis/Object Cache - we can't pattern match, so we clear the group
		if ( self::isRedisAvailable() ) {
			self::clearGroup( $group );
		}

		return $invalidated;
	}

	/**
	 * Cache analytics and hit/miss tracking
	 */
	private static $cache_stats = array(
		'hits'   => 0,
		'misses' => 0,
		'sets'   => 0,
	);

	public static function getAnalytics(): array {
		return self::$cache_stats;
	}

	public static function resetAnalytics(): void {
		self::$cache_stats = array(
			'hits'   => 0,
			'misses' => 0,
			'sets'   => 0,
		);
	}

	/**
	 * Override get method to track analytics
	 */
	public static function get( string $key, string $group = '' ) {
		$result = parent::get( $key, $group );

		if ( $result !== false ) {
			++self::$cache_stats['hits'];
		} else {
			++self::$cache_stats['misses'];
		}

		return $result;
	}

	/**
	 * Override set method to track analytics
	 */
	public static function set( string $key, $data, string $group = '', int $expiration = 3600 ): bool {
		$result = parent::set( $key, $data, $group, $expiration );

		if ( $result ) {
			++self::$cache_stats['sets'];
		}

		return $result;
	}
}
