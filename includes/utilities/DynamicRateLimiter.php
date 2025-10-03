<?php

namespace Puntwork;

/*
 * Dynamic Rate Limiting System
 *
 * Monitors system performance and automatically adjusts rate limits
 * based on server load, request patterns, and operational context.
 *
 * @since      1.0.15
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamic Rate Limiting class.
 */
class DynamicRateLimiter {

	/**
	 * Default configuration for dynamic rate limiting.
	 */
	private static $default_config = array(
		'enabled'                   => true,
		'memory_threshold_high'     => 80,
		'memory_threshold_low'      => 20,
		'cpu_threshold_high'        => 80,
		'cpu_threshold_low'         => 20,
		'response_time_threshold'   => 5,
		'error_rate_threshold'      => 10,
		'peak_hours_start'          => 9,
		'peak_hours_end'            => 17,
		'peak_hours_boost'          => 1.2,
		'off_peak_reduction'        => 0.8,
		'import_boost_factor'       => 1.5,
		'min_adjustment_percentage' => 20,
		'max_adjustment_percentage' => 300,
	);
	/**
	 * Performance metrics storage key.
	 */
	public const METRICS_KEY = 'puntwork_dynamic_rate_metrics';	/**
	 * Rate limit adjustments storage key.
	 */
	public const ADJUSTMENTS_KEY = 'puntwork_dynamic_rate_adjustments';

	/**
	 * Memory monitoring cache.
	 */
	private static $memory_cache = array(
		'limit_bytes'        => null,
		'limit_cached_at'    => 0,
		'usage_history'      => array(),
		'pressure_threshold' => 75, // Memory pressure threshold (%)
		'critical_threshold' => 90, // Critical memory threshold (%)
	);

	/**
	 * Memory cleanup thresholds.
	 */
	private static $cleanup_thresholds = array(
		'metrics_cleanup'   => 80, // Clean metrics when memory > 80%
		'cache_cleanup'     => 85,   // Clean caches when memory > 85%
		'emergency_cleanup' => 90, // Emergency cleanup when memory > 90%
	);

	/**
	 * Get dynamic rate limiting configuration.
	 *
	 * @return array Configuration array
	 */
	public static function getConfig(): array {
		$stored_config = get_option( 'puntwork_dynamic_rate_config', array() );

		return array_merge( self::$default_config, $stored_config );
	}

	/**
	 * Update dynamic rate limiting configuration.
	 *
	 * @param array $config New configuration
	 * @return bool Success
	 */
	public static function updateConfig( array $config ): bool {
		$current_config = self::getConfig();
		$updated_config = array_merge( $current_config, $config );

		return update_option( 'puntwork_dynamic_rate_config', $updated_config );
	}

	/**
	 * Record performance metrics with memory pressure monitoring.
	 *
	 * @param string $action    Action name
	 * @param array  $metrics   Performance metrics
	 */
	public static function recordMetrics( string $action, array $metrics ): void {
		$config = self::getConfig();
		if ( ! $config['enabled'] ) {
			return;
		}

		// Check memory pressure before recording metrics
		self::checkMemoryPressure();

		$timestamp      = time();
		$metric_key     = self::METRICS_KEY . '_' . $action;
		$stored_metrics = get_option( $metric_key, array() );

		// Clean old metrics (keep last 2 hours only)
		$cutoff_time    = $timestamp - 7200; // 2 hours
		$stored_metrics = array_filter(
			$stored_metrics,
			function ( $metric ) use ( $cutoff_time ) {
				return $metric['timestamp'] >= $cutoff_time;
			}
		);

		// Get detailed memory information
		$memory_info = self::getDetailedMemoryUsage();

		// Add new metrics
		$metric_data = array_merge(
			$metrics,
			array(
				'action'          => $action,
				'timestamp'       => $timestamp,
				'server_load'     => self::getServerLoad(),
				'memory_usage'    => $memory_info['current_percent'],
				'memory_pressure' => $memory_info['pressure_level'],
				'memory_trend'    => $memory_info['trend']['direction'],
				'cpu_usage'       => self::getCpuUsage(),
			)
		);

		$stored_metrics[] = $metric_data;

		// Adaptive metrics limit based on memory pressure
		$max_metrics = 100; // Default
		if ( $memory_info['pressure_level'] === 'high' ) {
			$max_metrics = 50; // Reduce during high memory pressure
		} elseif ( $memory_info['pressure_level'] === 'critical' ) {
			$max_metrics = 25; // Further reduce during critical pressure
		}

		// Keep only recent metrics to prevent bloat
		if ( count( $stored_metrics ) > $max_metrics ) {
			$stored_metrics = array_slice( $stored_metrics, -$max_metrics );
		}

		update_option( $metric_key, $stored_metrics );
	}

	/**
	 * Get current server load average.
	 *
	 * @return float Load average (1-minute)
	 */
	private static function getServerLoad(): float {
		if ( function_exists( 'sys_getloadavg' ) ) {
			$load = sys_getloadavg();

			return $load[0] ?? 0.0;
		}

		// Fallback: estimate based on active processes
		$active_processes = shell_exec( 'ps aux | wc -l' );
		$active_processes = intval( trim( $active_processes ) );

		return min( $active_processes / 100, 10.0 ); // Rough estimation
	}

	/**
	 * Get current memory usage with advanced monitoring.
	 *
	 * @return array Memory usage information
	 */
	public static function getDetailedMemoryUsage(): array {
		$current_usage = memory_get_usage( true );
		$peak_usage    = memory_get_peak_usage( true );
		$limit_bytes   = self::getMemoryLimitBytes();

		$current_percent = $limit_bytes > 0 ? ( $current_usage / $limit_bytes ) * 100 : 0;
		$peak_percent    = $limit_bytes > 0 ? ( $peak_usage / $limit_bytes ) * 100 : 0;

		// Track memory usage history for trend analysis
		$timestamp                             = microtime( true );
		self::$memory_cache['usage_history'][] = array(
			'timestamp' => $timestamp,
			'current'   => $current_usage,
			'peak'      => $peak_usage,
			'percent'   => $current_percent,
		);

		// Keep only last 10 measurements (last ~5 minutes at normal monitoring intervals)
		if ( count( self::$memory_cache['usage_history'] ) > 10 ) {
			self::$memory_cache['usage_history'] = array_slice( self::$memory_cache['usage_history'], -10 );
		}

		// Calculate memory pressure trend
		$trend = self::calculateMemoryTrend();

		// Determine memory pressure level
		$pressure_level = 'normal';
		if ( $current_percent >= self::$memory_cache['critical_threshold'] ) {
			$pressure_level = 'critical';
		} elseif ( $current_percent >= self::$memory_cache['pressure_threshold'] ) {
			$pressure_level = 'high';
		}

		return array(
			'current_bytes'     => $current_usage,
			'peak_bytes'        => $peak_usage,
			'limit_bytes'       => $limit_bytes,
			'current_percent'   => round( $current_percent, 2 ),
			'peak_percent'      => round( $peak_percent, 2 ),
			'available_bytes'   => $limit_bytes - $current_usage,
			'available_percent' => round( 100 - $current_percent, 2 ),
			'pressure_level'    => $pressure_level,
			'trend'             => $trend,
			'history_count'     => count( self::$memory_cache['usage_history'] ),
		);
	}

	/**
	 * Get current memory usage percentage (backward compatibility).
	 *
	 * @return float Memory usage percentage
	 */
	private static function getMemoryUsage(): float {
		$details = self::getDetailedMemoryUsage();

		return $details['current_percent'];
	}

	/**
	 * Calculate memory usage trend.
	 *
	 * @return array Trend information
	 */
	private static function calculateMemoryTrend(): array {
		$history = self::$memory_cache['usage_history'];

		if ( count( $history ) < 3 ) {
			return array(
				'direction'  => 'unknown',
				'rate'       => 0,
				'confidence' => 0,
			);
		}

		// Calculate trend using linear regression on recent measurements
		$n     = count( $history );
		$sum_x = $sum_y = $sum_xy = $sum_x2 = 0;

		foreach ( $history as $i => $point ) {
			$x       = $i; // Time index
			$y       = $point['current']; // Memory usage
			$sum_x  += $x;
			$sum_y  += $y;
			$sum_xy += $x * $y;
			$sum_x2 += $x * $x;
		}

		$slope = ( $n * $sum_xy - $sum_x * $sum_y ) / ( $n * $sum_x2 - $sum_x * $sum_x );

		// Determine trend direction and rate
		$direction = 'stable';
		$rate      = 0;

		if ( abs( $slope ) > 1000 ) { // Significant change threshold
			$direction = $slope > 0 ? 'increasing' : 'decreasing';
			$rate      = $slope / 1024 / 1024; // Convert to MB per measurement
		}

		return array(
			'direction'  => $direction,
			'rate'       => round( $rate, 2 ),
			'confidence' => min( 100, $n * 10 ), // Simple confidence based on sample size
		);
	}

	/**
	 * Get memory limit in bytes with caching.
	 *
	 * @return int Memory limit in bytes
	 */
	private static function getMemoryLimitBytes(): int {
		$cache_time = 300; // Cache for 5 minutes

		if ( self::$memory_cache['limit_bytes'] === null ||
			( time() - self::$memory_cache['limit_cached_at'] ) > $cache_time ) {
			$memory_limit = ini_get( 'memory_limit' );

			if ( preg_match( '/^(\d+)(.)$/', $memory_limit, $matches ) ) {
				$value = (int) $matches[1];
				$unit  = strtolower( $matches[2] );

				switch ( $unit ) {
					case 'g':
						$value *= 1024 * 1024 * 1024;

						break;
					case 'm':
						$value *= 1024 * 1024;

						break;
					case 'k':
						$value *= 1024;

						break;
				}

				self::$memory_cache['limit_bytes'] = $value;
			} else {
				self::$memory_cache['limit_bytes'] = 128 * 1024 * 1024; // Default 128MB
			}

			self::$memory_cache['limit_cached_at'] = time();
		}

		return self::$memory_cache['limit_bytes'];
	}

	/**
	 * Check if memory usage is approaching critical levels and trigger cleanup.
	 *
	 * @return bool True if cleanup was triggered
	 */
	public static function checkMemoryPressure(): bool {
		$memory_info     = self::getDetailedMemoryUsage();
		$current_percent = $memory_info['current_percent'];

		// Emergency cleanup at critical threshold
		if ( $current_percent >= self::$cleanup_thresholds['emergency_cleanup'] ) {
			self::performEmergencyCleanup();

			return true;
		}

		// Cache cleanup at high threshold
		if ( $current_percent >= self::$cleanup_thresholds['cache_cleanup'] ) {
			self::performCacheCleanup();

			return true;
		}

		// Metrics cleanup at medium threshold
		if ( $current_percent >= self::$cleanup_thresholds['metrics_cleanup'] ) {
			self::performMetricsCleanup();

			return true;
		}

		return false;
	}

	/**
	 * Perform emergency memory cleanup.
	 */
	private static function performEmergencyCleanup(): void {
		PuntWorkLogger::warning(
			'Emergency memory cleanup triggered',
			PuntWorkLogger::CONTEXT_SECURITY,
			array( 'memory_usage' => self::getDetailedMemoryUsage() )
		);

		// Clear all caches
		wp_cache_flush();

		// Clear transients
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'" );

		// Force garbage collection if available
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		// Reset internal caches
		self::$memory_cache['usage_history'] = array();
	}

	/**
	 * Perform cache cleanup.
	 */
	private static function performCacheCleanup(): void {
		PuntWorkLogger::info(
			'Cache cleanup triggered due to memory pressure',
			PuntWorkLogger::CONTEXT_SECURITY,
			array( 'memory_usage' => self::getDetailedMemoryUsage() )
		);

		// Clear expired transients
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %s",
				'_transient_%',
				time()
			)
		);

		// Clear puntWork specific caches
		delete_option( 'puntwork_feed_cache' );
		delete_option( 'puntwork_analytics_cache' );
		delete_option( 'puntwork_performance_cache' );

		// Clear any cached feed data
		$feed_cache_keys = get_option( 'puntwork_feed_cache_keys', array() );
		foreach ( $feed_cache_keys as $key ) {
			delete_transient( $key );
		}
		delete_option( 'puntwork_feed_cache_keys' );
	}

	/**
	 * Perform metrics cleanup.
	 */
	private static function performMetricsCleanup(): void {
		PuntWorkLogger::info(
			'Metrics cleanup triggered due to memory pressure',
			PuntWorkLogger::CONTEXT_SECURITY,
			array( 'memory_usage' => self::getDetailedMemoryUsage() )
		);

		global $wpdb;

		// Get all metric keys
		$metric_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				self::METRICS_KEY . '_%'
			)
		);

		$cutoff_time   = time() - 3600; // Keep only last hour during cleanup
		$total_removed = 0;

		foreach ( $metric_keys as $key ) {
			$metrics        = get_option( $key, array() );
			$original_count = count( $metrics );

			$cleaned_metrics = array_filter(
				$metrics,
				function ( $metric ) use ( $cutoff_time ) {
					return $metric['timestamp'] >= $cutoff_time;
				}
			);

			if ( count( $cleaned_metrics ) !== $original_count ) {
				update_option( $key, $cleaned_metrics );
				$total_removed += ( $original_count - count( $cleaned_metrics ) );
			}
		}

		PuntWorkLogger::info(
			'Metrics cleanup completed',
			PuntWorkLogger::CONTEXT_SECURITY,
			array( 'removed' => $total_removed )
		);
	}

	/**
	 * Get current CPU usage percentage.
	 *
	 * @return float CPU usage percentage
	 */
	private static function getCpuUsage(): float {
		static $last_cpu_info = null;
		static $last_time     = null;

		$current_time = microtime( true );

		if ( PHP_OS_FAMILY === 'Linux' ) {
			$cpu_info = self::getLinuxCpuInfo();

			if ( $last_cpu_info !== null && $last_time !== null ) {
				$time_diff = $current_time - $last_time;
				if ( $time_diff > 0 ) {
					$cpu_diff  = $cpu_info['total'] - $last_cpu_info['total'];
					$idle_diff = $cpu_info['idle'] - $last_cpu_info['idle'];

					if ( $cpu_diff > 0 ) {
						$cpu_usage = 100 * ( $cpu_diff - $idle_diff ) / $cpu_diff;

						return max( 0, min( 100, $cpu_usage ) );
					}
				}
			}

			$last_cpu_info = $cpu_info;
			$last_time     = $current_time;
		}

		// Fallback: return 0 or estimate based on load
		return self::getServerLoad() * 10; // Rough estimation
	}

	/**
	 * Get Linux CPU information from /proc/stat.
	 *
	 * @return array CPU statistics
	 */
	private static function getLinuxCpuInfo(): array {
		$cpu_line = shell_exec( 'head -n 1 /proc/stat 2>/dev/null' );
		if ( ! $cpu_line ) {
			return array(
				'total' => 0,
				'idle'  => 0,
			);
		}

		$cpu_stats = preg_split( '/\s+/', trim( $cpu_line ) );
		if ( count( $cpu_stats ) < 8 ) {
			return array(
				'total' => 0,
				'idle'  => 0,
			);
		}

		// Remove 'cpu' label
		array_shift( $cpu_stats );

		$user    = (int) $cpu_stats[0];
		$nice    = (int) $cpu_stats[1];
		$system  = (int) $cpu_stats[2];
		$idle    = (int) $cpu_stats[3];
		$iowait  = (int) $cpu_stats[4];
		$irq     = (int) $cpu_stats[5];
		$softirq = (int) $cpu_stats[6];

		$total = $user + $nice + $system + $idle + $iowait + $irq + $softirq;

		return array(
			'total' => $total,
			'idle'  => $idle,
		);
	}

	/**
	 * Calculate dynamic rate limit adjustments with enhanced memory monitoring.
	 *
	 * @param string $action Action name
	 * @return array Adjustment factors
	 */
	public static function calculateAdjustments( string $action ): array {
		$config = self::getConfig();
		if ( ! $config['enabled'] ) {
			return array(
				'multiplier' => 1.0,
				'reason'     => 'disabled',
			);
		}

		$metrics = self::getRecentMetrics( $action, 300 ); // Last 5 minutes
		if ( empty( $metrics ) ) {
			return array(
				'multiplier' => 1.0,
				'reason'     => 'no_metrics',
			);
		}

		$multiplier = 1.0;
		$reasons    = array();

		// Get detailed memory information
		$memory_info = self::getDetailedMemoryUsage();

		// Server performance factors
		$avg_cpu    = array_sum( array_column( $metrics, 'cpu_usage' ) ) / count( $metrics );
		$avg_memory = array_sum( array_column( $metrics, 'memory_usage' ) ) / count( $metrics );
		$avg_load   = array_sum( array_column( $metrics, 'server_load' ) ) / count( $metrics );

		// Enhanced memory-based adjustment with pressure levels
		$memory_pressure_multiplier = 1.0;

		if ( $memory_info['pressure_level'] === 'critical' ) {
			// Critical memory pressure - aggressive reduction
			$memory_pressure_multiplier = 0.3;
			$reasons[]                  = 'critical_memory_pressure';
		} elseif ( $memory_info['pressure_level'] === 'high' ) {
			// High memory pressure - moderate reduction
			$memory_pressure_multiplier = 0.6;
			$reasons[]                  = 'high_memory_pressure';
		} elseif ( $avg_memory > $config['memory_threshold_high'] ) {
			// Standard high memory threshold
			$memory_factor              = max( 0.4, 1.0 - ( ( $avg_memory - $config['memory_threshold_high'] ) / 35 ) );
			$memory_pressure_multiplier = $memory_factor;
			$reasons[]                  = "high_memory_{$avg_memory}%";
		} elseif ( $avg_memory < $config['memory_threshold_low'] ) {
			// Low memory usage - can increase limits
			$memory_factor              = min( 1.8, 1.0 + ( ( $config['memory_threshold_low'] - $avg_memory ) / 40 ) );
			$memory_pressure_multiplier = $memory_factor;
			$reasons[]                  = "low_memory_{$avg_memory}%";
		}

		// Apply memory pressure multiplier
		$multiplier *= $memory_pressure_multiplier;

		// Memory trend-based adjustment
		if ( $memory_info['trend']['direction'] === 'increasing' &&
			$memory_info['trend']['rate'] > 5 ) { // More than 5MB increase per measurement
			$trend_factor = max( 0.7, 1.0 - ( $memory_info['trend']['rate'] / 20 ) );
			$multiplier  *= $trend_factor;
			$reasons[]    = 'memory_trend_increasing';
		}

		// CPU-based adjustment
		if ( $avg_cpu > $config['cpu_threshold_high'] ) {
			$cpu_factor  = max( 0.5, 1.0 - ( ( $avg_cpu - $config['cpu_threshold_high'] ) / 50 ) );
			$multiplier *= $cpu_factor;
			$reasons[]   = "high_cpu_{$avg_cpu}%";
		} elseif ( $avg_cpu < $config['cpu_threshold_low'] ) {
			$cpu_factor  = min( 2.0, 1.0 + ( ( $config['cpu_threshold_low'] - $avg_cpu ) / 50 ) );
			$multiplier *= $cpu_factor;
			$reasons[]   = "low_cpu_{$avg_cpu}%";
		}

		// Load-based adjustment
		if ( $avg_load > 5.0 ) {
			$load_factor = max( 0.6, 1.0 - ( ( $avg_load - 5.0 ) / 10 ) );
			$multiplier *= $load_factor;
			$reasons[]   = "high_load_{$avg_load}";
		} elseif ( $avg_load < 1.0 ) {
			$load_factor = min( 1.5, 1.0 + ( ( 1.0 - $avg_load ) / 2 ) );
			$multiplier *= $load_factor;
			$reasons[]   = "low_load_{$avg_load}";
		}

		// Response time factors
		if ( isset( $metrics[0]['response_time'] ) ) {
			$avg_response_time = array_sum( array_column( $metrics, 'response_time' ) ) / count( $metrics );
			if ( $avg_response_time > $config['response_time_threshold'] ) {
				$response_factor = max( 0.7, 1.0 - ( ( $avg_response_time - $config['response_time_threshold'] ) / 2 ) );
				$multiplier     *= $response_factor;
				$reasons[]       = "slow_response_{$avg_response_time}s";
			}
		}

		// Error rate factors
		$error_count = count(
			array_filter(
				$metrics,
				function ( $m ) {
					return isset( $m['is_error'] ) && $m['is_error'];
				}
			)
		);
		$error_rate  = ( count( $metrics ) > 0 ) ? ( $error_count / count( $metrics ) ) * 100 : 0;

		if ( $error_rate > $config['error_rate_threshold'] ) {
			$error_factor = max( 0.6, 1.0 - ( ( $error_rate - $config['error_rate_threshold'] ) / 20 ) );
			$multiplier  *= $error_factor;
			$reasons[]    = "high_errors_{$error_rate}%";
		}

		// Time-based factors
		$current_hour  = (int) date( 'H' );
		$is_peak_hours = $current_hour >= $config['peak_hours_start'] && $current_hour <= $config['peak_hours_end'];

		if ( $is_peak_hours ) {
			$multiplier *= $config['peak_hours_boost'];
			$reasons[]   = 'peak_hours';
		} else {
			$multiplier *= $config['off_peak_reduction'];
			$reasons[]   = 'off_peak';
		}

		// Import operation boost (but reduce if memory pressure is high)
		if ( self::isImportOperation( $action ) ) {
			$import_multiplier = $config['import_boost_factor'];
			if ( $memory_info['pressure_level'] === 'high' ) {
				$import_multiplier *= 0.8; // Reduce import boost during high memory pressure
			} elseif ( $memory_info['pressure_level'] === 'critical' ) {
				$import_multiplier *= 0.5; // Further reduce during critical pressure
			}
			$multiplier *= $import_multiplier;
			$reasons[]   = 'import_operation';
		}

		// Cleanup operation specific adjustments
		if ( self::isCleanupOperation( $action ) ) {
			$cleanup_metrics = self::getCleanupPerformanceMetrics();

			// Adjust based on batch processing performance
			if ( $cleanup_metrics['avg_batch_time'] > 8.0 ) {
				// Slow batch processing - reduce frequency
				$cleanup_factor = max( 0.5, 1.0 - ( ( $cleanup_metrics['avg_batch_time'] - 8.0 ) / 10 ) );
				$multiplier    *= $cleanup_factor;
				$reasons[]      = 'slow_cleanup_batches';
			} elseif ( $cleanup_metrics['avg_batch_time'] < 2.0 ) {
				// Fast batch processing - can increase frequency
				$cleanup_factor = min( 1.8, 1.0 + ( ( 2.0 - $cleanup_metrics['avg_batch_time'] ) / 2 ) );
				$multiplier    *= $cleanup_factor;
				$reasons[]      = 'fast_cleanup_batches';
			}

			// Adjust based on items processed per batch
			if ( $cleanup_metrics['avg_items_per_batch'] > 30 ) {
				// Processing many items - slightly reduce frequency to prevent memory spikes
				$multiplier *= 0.9;
				$reasons[]   = 'high_volume_cleanup';
			} elseif ( $cleanup_metrics['avg_items_per_batch'] < 5 ) {
				// Processing few items - can increase frequency
				$multiplier *= 1.2;
				$reasons[]   = 'low_volume_cleanup';
			}

			// Be more conservative with cleanup during peak memory usage
			if ( $memory_info['current_percent'] > 75 ) {
				$multiplier *= 0.7;
				$reasons[]   = 'cleanup_memory_conservative';
			}
		}

		// Apply bounds with memory-aware minimums
		$min_multiplier = $config['min_adjustment_percentage'] / 100;
		if ( $memory_info['pressure_level'] === 'critical' ) {
			$min_multiplier = max( 0.1, $min_multiplier * 0.5 ); // Allow more aggressive reduction
		}

		$multiplier = max(
			$min_multiplier,
			min( $config['max_adjustment_percentage'] / 100, $multiplier )
		);

		return array(
			'multiplier' => $multiplier,
			'reason'     => implode( ',', $reasons ),
			'metrics'    => array(
				'avg_cpu'                  => round( $avg_cpu, 1 ),
				'avg_memory'               => round( $avg_memory, 1 ),
				'avg_load'                 => round( $avg_load, 2 ),
				'error_rate'               => round( $error_rate, 1 ),
				'sample_count'             => count( $metrics ),
				'memory_pressure'          => $memory_info['pressure_level'],
				'memory_trend'             => $memory_info['trend']['direction'],
				'available_memory_percent' => $memory_info['available_percent'],
			),
		);
	}

	/**
	 * Check if action is related to cleanup operations.
	 *
	 * @param string $action Action name
	 * @return bool True if cleanup operation
	 */
	private static function isCleanupOperation( string $action ): bool {
		$cleanup_actions = array(
			'job_import_cleanup_duplicates',
			'job_import_cleanup_continue',
		);

		return in_array( $action, $cleanup_actions ) ||
				strpos( $action, 'cleanup' ) !== false ||
				strpos( $action, 'purge' ) !== false;
	}

	/**
	 * Get cleanup operation performance metrics.
	 *
	 * @return array Cleanup performance metrics
	 */
	private static function getCleanupPerformanceMetrics(): array {
		// Get recent cleanup progress data
		$progress = get_option( 'job_import_cleanup_progress', array() );

		$metrics = array(
			'avg_batch_time'       => 3.0, // Default 3 seconds
			'avg_items_per_batch'  => 15,  // Default 15 items
			'total_batches'        => 0,
			'last_batch_time'      => 0,
			'last_batch_items'     => 0,
		);

		if ( ! empty( $progress ) ) {
			$metrics['last_batch_time'] = $progress['last_batch_time'] ?? 0;
			$metrics['last_batch_items'] = $progress['batch_size'] ?? 0;

			// Calculate averages from recent performance
			if ( isset( $progress['total_processed'] ) && isset( $progress['batch_size'] ) && $progress['batch_size'] > 0 ) {
				$estimated_batches = max( 1, round( $progress['total_processed'] / $progress['batch_size'] ) );
				$metrics['total_batches'] = $estimated_batches;

				// Estimate average batch time (rough calculation)
				if ( isset( $progress['start_time'] ) && isset( $progress['last_batch_time'] ) ) {
					$total_time = microtime( true ) - $progress['start_time'];
					if ( $estimated_batches > 0 ) {
						$metrics['avg_batch_time'] = round( $total_time / $estimated_batches, 2 );
					}
				}

				$metrics['avg_items_per_batch'] = $progress['batch_size'];
			}
		}

		return $metrics;
	}

	/**
	 * Get recent metrics for an action.
	 *
	 * @param string $action     Action name
	 * @param int    $time_range Time range in seconds
	 * @return array Recent metrics
	 */
	private static function getRecentMetrics( string $action, int $time_range = 300 ): array {
		$metric_key     = self::METRICS_KEY . '_' . $action;
		$stored_metrics = get_option( $metric_key, array() );
		$cutoff_time    = time() - $time_range;

		return array_filter(
			$stored_metrics,
			function ( $metric ) use ( $cutoff_time ) {
				return $metric['timestamp'] >= $cutoff_time;
			}
		);
	}

	/**
	 * Apply dynamic rate limiting to a request.
	 *
	 * @param string $action Action name
	 * @return array|WP_Error Rate limit result or error
	 */
	public static function applyDynamicRateLimit( string $action ) {
		$config = self::getConfig();
		if ( ! $config['enabled'] ) {
			// Fall back to static rate limiting
			return \Puntwork\SecurityUtils::checkStaticRateLimit( $action );
		}

		// Get base configuration
		$base_config = SecurityUtils::getRateLimitConfig( $action );

		// Calculate dynamic adjustments
		$adjustments = self::calculateAdjustments( $action );

		// Apply adjustments to base limits
		$dynamic_max_requests = (int) round( $base_config['max_requests'] * $adjustments['multiplier'] );
		$dynamic_time_window  = $base_config['time_window']; // Keep time window static

		// Ensure minimum limits
		$dynamic_max_requests = max( 1, $dynamic_max_requests );

		// Log adjustments for monitoring
		if ( $adjustments['multiplier'] != 1.0 ) {
			PuntWorkLogger::debug(
				"Dynamic rate limit adjustment for {$action}",
				PuntWorkLogger::CONTEXT_SECURITY,
				array(
					'base_requests'    => $base_config['max_requests'],
					'dynamic_requests' => $dynamic_max_requests,
					'multiplier'       => $adjustments['multiplier'],
					'reason'           => $adjustments['reason'],
					'metrics'          => $adjustments['metrics'],
				)
			);
		}

		// Check rate limit with dynamic values
		$result = SecurityUtils::checkRateLimit( $action, $dynamic_max_requests, $dynamic_time_window );

		// Record metrics for this request
		$is_error = is_wp_error( $result );
		self::recordMetrics(
			$action,
			array(
				'is_error'      => $is_error,
				'response_time' => 0, // Will be set by caller if available
				'dynamic_limit' => $dynamic_max_requests,
				'base_limit'    => $base_config['max_requests'],
				'multiplier'    => $adjustments['multiplier'],
			)
		);

		return $result;
	}

	/**
	 * Get dynamic rate limiting status with enhanced memory information.
	 *
	 * @return array Status information
	 */
	public static function getStatus(): array {
		$config      = self::getConfig();
		$memory_info = self::getDetailedMemoryUsage();

		// Get all metric keys (per-action) and count metrics efficiently
		global $wpdb;
		$metric_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				self::METRICS_KEY . '_%'
			)
		);

		$total_metrics  = 0;
		$recent_metrics = 0;
		$cutoff_time    = time() - 3600; // Last hour

		foreach ( $metric_keys as $key ) {
			$metrics        = get_option( $key, array() );
			$total_metrics += count( $metrics );
			// Count recent metrics without filtering all
			$recent_count = 0;
			foreach ( $metrics as $metric ) {
				if ( $metric['timestamp'] >= $cutoff_time ) {
					++$recent_count;
				} else {
					// Since metrics are stored in chronological order, we can break early
					break;
				}
			}
			$recent_metrics += $recent_count;
		}

		return array(
			'enabled'               => $config['enabled'],
			'config'                => $config,
			'total_metrics'         => $total_metrics,
			'recent_metrics'        => $recent_metrics,
			'current_load'          => self::getServerLoad(),
			'current_memory'        => $memory_info,
			'current_cpu'           => self::getCpuUsage(),
			'memory_pressure_level' => $memory_info['pressure_level'],
			'memory_trend'          => $memory_info['trend'],
			'cleanup_thresholds'    => self::$cleanup_thresholds,
			'last_updated'          => time(),
		);
	}

	/**
	 * Reset dynamic rate limiting data.
	 *
	 * @return bool Success
	 */
	public static function reset(): bool {
		global $wpdb;

		// Delete all metric options
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				self::METRICS_KEY . '_%'
			)
		);

		delete_option( self::ADJUSTMENTS_KEY );

		return true;
	}

	/**
	 * Initialize dynamic rate limiting system.
	 */
	public static function init(): void {
		// Schedule cleanup of old metrics - DISABLED: Background processing disabled
		// if ( ! wp_next_scheduled( 'puntwork_cleanup_dynamic_rate_metrics' ) ) {
		// 	wp_schedule_event( time(), 'daily', 'puntwork_cleanup_dynamic_rate_metrics' );
		// }

		add_action( 'puntwork_cleanup_dynamic_rate_metrics', array( self::class, 'cleanupOldMetrics' ) );
	}

	/**
	 * Clean up old metrics data.
	 */
	public static function cleanupOldMetrics(): void {
		global $wpdb;
		$cutoff_time = time() - ( 7 * 24 * 3600 ); // Keep 7 days

		// Get all metric keys
		$metric_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				self::METRICS_KEY . '_%'
			)
		);

		$total_removed = 0;
		foreach ( $metric_keys as $key ) {
			$metrics        = get_option( $key, array() );
			$original_count = count( $metrics );

			$cleaned_metrics = array_filter(
				$metrics,
				function ( $metric ) use ( $cutoff_time ) {
					return $metric['timestamp'] >= $cutoff_time;
				}
			);

			if ( count( $cleaned_metrics ) !== $original_count ) {
				update_option( $key, $cleaned_metrics );
				$total_removed += ( $original_count - count( $cleaned_metrics ) );
			}
		}

		PuntWorkLogger::info(
			'Cleaned up dynamic rate limiting metrics',
			PuntWorkLogger::CONTEXT_SECURITY,
			array( 'removed' => $total_removed )
		);
	}
}

// Initialize the dynamic rate limiter
DynamicRateLimiter::init();
