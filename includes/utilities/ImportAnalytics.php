<?php

/**
 * Import Analytics and Reporting Dashboard.
 *
 * @since      1.0.12
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Puntwork\Utilities\CacheManager;

/**
 * ImportAnalytics Class
 * Provides comprehensive analytics and reporting for import operations.
 */
class ImportAnalytics {

	public const TABLE_NAME        = 'puntwork_import_analytics';
	public const METRICS_TRANSIENT = 'puntwork_import_metrics';

	/**
	 * Initialize the analytics system.
	 */
	public static function init() {
		self::createAnalyticsTable();
		add_action( 'puntwork_import_completed', array( __CLASS__, 'recordImportMetrics' ), 10, 1 );
		add_action( 'admin_init', array( __CLASS__, 'scheduleAnalyticsCleanup' ) );
	}

	/**
	 * Create the analytics database table.
	 */
	private static function createAnalyticsTable() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            import_id varchar(64) NOT NULL,
            start_time datetime NOT NULL,
            end_time datetime DEFAULT NULL,
            duration int DEFAULT NULL,
            trigger_type varchar(20) DEFAULT 'manual',
            total_jobs int DEFAULT 0,
            processed_jobs int DEFAULT 0,
            published_jobs int DEFAULT 0,
            updated_jobs int DEFAULT 0,
            skipped_jobs int DEFAULT 0,
            duplicate_jobs int DEFAULT 0,
            failed_jobs int DEFAULT 0,
            memory_peak int DEFAULT NULL,
            feeds_processed int DEFAULT 0,
            feeds_successful int DEFAULT 0,
            feeds_failed int DEFAULT 0,
            avg_response_time float DEFAULT NULL,
            success_rate float DEFAULT NULL,
            error_message text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY import_id (import_id),
            KEY start_time (start_time),
            KEY trigger_type (trigger_type),
            KEY success_rate (success_rate)
        ) $charset_collate;";

		include_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Schedule analytics cleanup - DISABLED: Background processing disabled
	 */
	public static function scheduleAnalyticsCleanup() {
		// if ( ! wp_next_scheduled( 'puntwork_analytics_cleanup' ) ) {
		// 	// Run cleanup weekly
		// 	wp_schedule_event( time(), 'weekly', 'puntwork_analytics_cleanup' );
		// }
	}	/**
	 * Record import metrics when an import completes.
	 */
	public static function recordImportMetrics( $import_data ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// Generate unique import ID
		$import_id = wp_generate_uuid4();

		// Calculate metrics
		$start_time = isset( $import_data['start_time'] ) ? $import_data['start_time'] : null;
		$end_time   = isset( $import_data['end_time'] ) ? $import_data['end_time'] : microtime( true );

		$duration = null;
		if ( $start_time && $end_time ) {
			$duration = round( $end_time - $start_time, 2 );
		}

		$total_jobs     = $import_data['total'] ?? 0;
		$processed_jobs = $import_data['processed'] ?? 0;
		$published_jobs = $import_data['published'] ?? 0;
		$updated_jobs   = $import_data['updated'] ?? 0;
		$skipped_jobs   = $import_data['skipped'] ?? 0;
		$duplicate_jobs = $import_data['duplicates_drafted'] ?? 0;

		$success_rate = null;
		if ( $total_jobs > 0 ) {
			$success_rate = round( ( $processed_jobs / $total_jobs ) * 100, 2 );
		}

		// Get memory usage
		$memory_peak = memory_get_peak_usage( true );

		// Get feed processing stats
		$feed_stats = self::getFeedProcessingStats();

		$wpdb->insert(
			$table_name,
			array(
				'import_id'         => $import_id,
				'start_time'        => $start_time ? date( 'Y-m-d H:i:s', $start_time ) : null,
				'end_time'          => date( 'Y-m-d H:i:s', $end_time ),
				'duration'          => $duration,
				'trigger_type'      => $import_data['trigger_type'] ?? 'manual',
				'total_jobs'        => $total_jobs,
				'processed_jobs'    => $processed_jobs,
				'published_jobs'    => $published_jobs,
				'updated_jobs'      => $updated_jobs,
				'skipped_jobs'      => $skipped_jobs,
				'duplicate_jobs'    => $duplicate_jobs,
				'failed_jobs'       => $total_jobs - $processed_jobs,
				'memory_peak'       => $memory_peak,
				'feeds_processed'   => $feed_stats['processed'],
				'feeds_successful'  => $feed_stats['successful'],
				'feeds_failed'      => $feed_stats['failed'],
				'avg_response_time' => $feed_stats['avg_response_time'],
				'success_rate'      => $success_rate,
				'error_message'     => $import_data['error_message'] ?? null,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%f', '%f', '%s' )
		);

		// Clear cached metrics
		delete_transient( self::METRICS_TRANSIENT );

		PuntWorkLogger::info(
			'Import analytics recorded',
			PuntWorkLogger::CONTEXT_ANALYTICS,
			array(
				'import_id'      => $import_id,
				'duration'       => $duration,
				'success_rate'   => $success_rate,
				'processed_jobs' => $processed_jobs,
			)
		);
	}

	/**
	 * Get feed processing statistics for the current import.
	 */
	private static function getFeedProcessingStats() {
		$feeds               = get_feeds();
		$processed           = count( $feeds );
		$successful          = 0;
		$failed              = 0;
		$total_response_time = 0;

		// Get recent health data to estimate response times
		$health_status = FeedHealthMonitor::get_feed_health_status();

		foreach ( $feeds as $feed_key => $feed_url ) {
			if ( isset( $health_status[ $feed_key ] ) ) {
				$status = $health_status[ $feed_key ];
				if (
					$status['status'] === FeedHealthMonitor::STATUS_HEALTHY
					|| $status['status'] === FeedHealthMonitor::STATUS_WARNING
				) {
					++$successful;
				} else {
					++$failed;
				}

				if ( $status['response_time'] ) {
					$total_response_time += $status['response_time'];
				}
			} else {
				// Assume successful if no health data (first run)
				++$successful;
			}
		}

		$avg_response_time = $processed > 0 ? round( $total_response_time / $processed, 2 ) : null;

		return array(
			'processed'         => $processed,
			'successful'        => $successful,
			'failed'            => $failed,
			'avg_response_time' => $avg_response_time,
		);
	}

	/**
	 * Get comprehensive analytics data.
	 */
	public static function getAnalyticsData( $period = '30days' ) {
		$cache_key   = 'analytics_data_' . $period;
		$cached_data = CacheManager::get( $cache_key, CacheManager::GROUP_ANALYTICS );

		if ( $cached_data !== false ) {
			return $cached_data;
		}

		$data = array(
			'overview'    => self::getOverviewMetrics( $period ),
			'performance' => self::getPerformanceMetrics( $period ),
			'trends'      => self::getTrendsData( $period ),
			'feed_stats'  => self::getFeedStatistics( $period ),
			'errors'      => self::getErrorSummary( $period ),
			'predictions' => self::getPredictiveAnalytics( $period ),
		);

		// Cache for 1 hour
		CacheManager::set( $cache_key, $data, CacheManager::GROUP_ANALYTICS, HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Get overview metrics.
	 */
	private static function getOverviewMetrics( $period ) {
		global $wpdb;

		$table_name  = $wpdb->prefix . self::TABLE_NAME;
		$date_filter = self::getDateFilter( $period );

		$sql = $wpdb->prepare(
			"
            SELECT
                COUNT(*) as total_imports,
                AVG(duration) as avg_duration,
                AVG(success_rate) as avg_success_rate,
                SUM(processed_jobs) as total_processed,
                SUM(published_jobs) as total_published,
                SUM(updated_jobs) as total_updated,
                SUM(duplicate_jobs) as total_duplicates,
                MAX(end_time) as last_import
            FROM $table_name
            WHERE end_time >= %s
        ",
			$date_filter
		);

		$result = $wpdb->get_row( $sql, ARRAY_A );

		return array(
			'total_imports'    => (int) ( $result['total_imports'] ?? 0 ),
			'avg_duration'     => round( $result['avg_duration'] ?? 0, 2 ),
			'avg_success_rate' => round( $result['avg_success_rate'] ?? 0, 2 ),
			'total_processed'  => (int) ( $result['total_processed'] ?? 0 ),
			'total_published'  => (int) ( $result['total_published'] ?? 0 ),
			'total_updated'    => (int) ( $result['total_updated'] ?? 0 ),
			'total_duplicates' => (int) ( $result['total_duplicates'] ?? 0 ),
			'last_import'      => $result['last_import'] ?? null,
		);
	}

	/**
	 * Get performance metrics.
	 */
	private static function getPerformanceMetrics( $period ) {
		global $wpdb;

		$table_name  = $wpdb->prefix . self::TABLE_NAME;
		$date_filter = self::getDateFilter( $period );

		$sql = $wpdb->prepare(
			"
            SELECT
                trigger_type,
                COUNT(*) as count,
                AVG(duration) as avg_duration,
                AVG(success_rate) as avg_success_rate,
                SUM(processed_jobs) as total_processed
            FROM $table_name
            WHERE end_time >= %s
            GROUP BY trigger_type
            ORDER BY count DESC
        ",
			$date_filter
		);

		$results = $wpdb->get_results( $sql, ARRAY_A );

		$performance = array();
		foreach ( $results as $row ) {
			$performance[ $row['trigger_type'] ] = array(
				'count'            => (int) $row['count'],
				'avg_duration'     => round( $row['avg_duration'] ?? 0, 2 ),
				'avg_success_rate' => round( $row['avg_success_rate'] ?? 0, 2 ),
				'total_processed'  => (int) $row['total_processed'],
			);
		}

		return $performance;
	}

	/**
	 * Get trends data for charts.
	 */
	private static function getTrendsData( $period ) {
		global $wpdb;

		$table_name  = $wpdb->prefix . self::TABLE_NAME;
		$date_filter = self::getDateFilter( $period );

		// Daily trends
		$sql = $wpdb->prepare(
			"
            SELECT
                DATE(end_time) as date,
                COUNT(*) as imports_count,
                AVG(duration) as avg_duration,
                AVG(success_rate) as avg_success_rate,
                SUM(processed_jobs) as jobs_processed
            FROM $table_name
            WHERE end_time >= %s
            GROUP BY DATE(end_time)
            ORDER BY date ASC
        ",
			$date_filter
		);

		$daily_trends = $wpdb->get_results( $sql, ARRAY_A );

		// Hourly distribution
		$hourly_sql = $wpdb->prepare(
			"
            SELECT
                HOUR(end_time) as hour,
                COUNT(*) as count,
                AVG(success_rate) as avg_success_rate
            FROM $table_name
            WHERE end_time >= %s
            GROUP BY HOUR(end_time)
            ORDER BY hour ASC
        ",
			$date_filter
		);

		$hourly_distribution = $wpdb->get_results( $hourly_sql, ARRAY_A );

		return array(
			'daily'  => $daily_trends,
			'hourly' => $hourly_distribution,
		);
	}

	/**
	 * Get feed statistics.
	 */
	private static function getFeedStatistics( $period ) {
		global $wpdb;

		$table_name  = $wpdb->prefix . self::TABLE_NAME;
		$date_filter = self::getDateFilter( $period );

		$sql = $wpdb->prepare(
			"
            SELECT
                AVG(feeds_processed) as avg_feeds_processed,
                AVG(feeds_successful) as avg_feeds_successful,
                AVG(feeds_failed) as avg_feeds_failed,
                AVG(avg_response_time) as avg_response_time
            FROM $table_name
            WHERE end_time >= %s
        ",
			$date_filter
		);

		$result = $wpdb->get_row( $sql, ARRAY_A );

		return array(
			'avg_feeds_processed'  => round( $result['avg_feeds_processed'] ?? 0, 1 ),
			'avg_feeds_successful' => round( $result['avg_feeds_successful'] ?? 0, 1 ),
			'avg_feeds_failed'     => round( $result['avg_feeds_failed'] ?? 0, 1 ),
			'avg_response_time'    => round( $result['avg_response_time'] ?? 0, 2 ),
		);
	}

	/**
	 * Get error summary.
	 */
	private static function getErrorSummary( $period ) {
		global $wpdb;

		$table_name  = $wpdb->prefix . self::TABLE_NAME;
		$date_filter = self::getDateFilter( $period );

		$sql = $wpdb->prepare(
			"
            SELECT
                COUNT(*) as total_errors,
                GROUP_CONCAT(DISTINCT error_message SEPARATOR '; ') as error_messages
            FROM $table_name
            WHERE end_time >= %s AND success_rate < 100
        ",
			$date_filter
		);

		$result = $wpdb->get_row( $sql, ARRAY_A );

		return array(
			'total_errors'   => (int) ( $result['total_errors'] ?? 0 ),
			'error_messages' => $result['error_messages'] ?? '',
		);
	}

	/**
	 * Get predictive analytics data.
	 */
	private static function getPredictiveAnalytics( $period ) {
		// Import the PredictiveAnalytics class
		if ( ! class_exists( '\Puntwork\AI\PredictiveAnalytics' ) ) {
			include_once plugin_dir_path( dirname( __DIR__, 1 ) ) . 'includes/ai/predictive-analytics.php';
		}

		$predictions = array();

		try {
			// Get import volume predictions
			$predictions['import_volume'] = AI\PredictiveAnalytics::predictImportVolume(
				AI\PredictiveAnalytics::PERIOD_DAY
			);

			// Get content quality predictions
			$predictions['content_quality'] = AI\PredictiveAnalytics::predictContentQualityTrends(
				self::getPeriodDays( $period )
			);

			// Get duplicate pattern predictions
			$predictions['duplicate_patterns'] = AI\PredictiveAnalytics::predictDuplicatePatterns(
				self::getPeriodDays( $period )
			);

			// Get feed reliability predictions for top feeds
			$predictions['feed_reliability'] = self::getFeedReliabilityPredictions();
		} catch ( \Exception $e ) {
			PuntWorkLogger::error(
				'Error generating predictive analytics',
				PuntWorkLogger::CONTEXT_ANALYTICS,
				array(
					'error' => $e->getMessage(),
				)
			);

			$predictions = array(
				'error' => 'Unable to generate predictions: ' . $e->getMessage(),
			);
		}

		return $predictions;
	}

	/**
	 * Get feed reliability predictions for active feeds.
	 */
	private static function getFeedReliabilityPredictions() {
		$feeds       = get_feeds();
		$predictions = array();

		if ( ! class_exists( 'AI\PredictiveAnalytics' ) ) {
			include_once plugin_dir_path( dirname( __DIR__, 1 ) ) . 'includes/ai/predictive-analytics.php';
		}

		foreach ( array_keys( $feeds ) as $feed_key ) {
			try {
				$predictions[ $feed_key ] = AI\PredictiveAnalytics::predictFeedReliability( $feed_key );
			} catch ( \Exception $e ) {
				// Skip feeds with prediction errors
				continue;
			}
		}

		return $predictions;
	}

	/**
	 * Convert period string to days for predictive analytics.
	 */
	private static function getPeriodDays( $period ) {
		switch ( $period ) {
			case '7days':
				return 7;
			case '30days':
				return 30;
			case '90days':
				return 90;
			default:
				return 30;
		}
	}

	/**
	 * Get date filter for SQL queries.
	 */
	private static function getDateFilter( $period ) {
		$now = current_time( 'timestamp' );

		switch ( $period ) {
			case '7days':
				return date( 'Y-m-d H:i:s', strtotime( '-7 days', $now ) );
			case '30days':
				return date( 'Y-m-d H:i:s', strtotime( '-30 days', $now ) );
			case '90days':
				return date( 'Y-m-d H:i:s', strtotime( '-90 days', $now ) );
			default:
				return date( 'Y-m-d H:i:s', strtotime( '-30 days', $now ) );
		}
	}

	/**
	 * Clean up old analytics data (keep last 90 days).
	 */
	public static function cleanupOldData() {
		global $wpdb;

		$table_name  = $wpdb->prefix . self::TABLE_NAME;
		$cutoff_date = date( 'Y-m-d H:i:s', strtotime( '-90 days' ) );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE end_time < %s",
				$cutoff_date
			)
		);

		PuntWorkLogger::info(
			'Analytics cleanup completed',
			PuntWorkLogger::CONTEXT_ANALYTICS,
			array(
				'records_deleted' => $deleted,
				'cutoff_date'     => $cutoff_date,
			)
		);

		// Clear cached analytics data
		CacheManager::clear_group( CacheManager::GROUP_ANALYTICS );
	}

	/**
	 * Export analytics data as CSV.
	 */
	public static function exportAnalyticsCsv( $period = '30days' ) {
		global $wpdb;

		$table_name  = $wpdb->prefix . self::TABLE_NAME;
		$date_filter = self::getDateFilter( $period );

		$sql = $wpdb->prepare(
			"
            SELECT * FROM $table_name
            WHERE end_time >= %s
            ORDER BY end_time DESC
        ",
			$date_filter
		);

		$results = $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $results ) ) {
			return false;
		}

		// Create CSV content
		$csv_content = "Import ID,Start Time,End Time,Duration,Trigger Type,Total Jobs,Processed Jobs,Published Jobs,Updated Jobs,Skipped Jobs,Duplicate Jobs,Failed Jobs,Memory Peak,Feeds Processed,Feeds Successful,Feeds Failed,Avg Response Time,Success Rate,Error Message\n";

		foreach ( $results as $row ) {
			$csv_content .= sprintf(
				"%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,\"%s\"\n",
				$row['import_id'],
				$row['start_time'],
				$row['end_time'],
				$row['duration'],
				$row['trigger_type'],
				$row['total_jobs'],
				$row['processed_jobs'],
				$row['published_jobs'],
				$row['updated_jobs'],
				$row['skipped_jobs'],
				$row['duplicate_jobs'],
				$row['failed_jobs'],
				$row['memory_peak'],
				$row['feeds_processed'],
				$row['feeds_successful'],
				$row['feeds_failed'],
				$row['avg_response_time'],
				$row['success_rate'],
				str_replace( '"', '""', $row['error_message'] ?? '' )
			);
		}

		return $csv_content;
	}

	/**
	 * Record batch-level metrics asynchronously.
	 *
	 * @param array $batch_data Batch processing data
	 */
	public static function recordBatchMetrics( array $batch_data ): void {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// Only record if we have meaningful data
		if ( empty( $batch_data['processed'] ) || $batch_data['processed'] <= 0 ) {
			return;
		}

		$data = array(
			'import_id'         => $batch_data['import_id'] ?? wp_generate_uuid4(),
			'start_time'        => date( 'Y-m-d H:i:s', $batch_data['start_time'] ?? time() ),
			'end_time'          => date( 'Y-m-d H:i:s', $batch_data['end_time'] ?? time() ),
			'duration'          => $batch_data['batch_time'] ?? 0,
			'trigger_type'      => 'batch',
			'total_jobs'        => $batch_data['total'] ?? 0,
			'processed_jobs'    => $batch_data['processed'] ?? 0,
			'published_jobs'    => $batch_data['published'] ?? 0,
			'updated_jobs'      => $batch_data['updated'] ?? 0,
			'skipped_jobs'      => $batch_data['skipped'] ?? 0,
			'duplicate_jobs'    => $batch_data['duplicates_drafted'] ?? 0,
			'failed_jobs'       => 0, // Batches don't track failures separately
			'memory_peak'       => $batch_data['performance']['memory_peak'] ?? null,
			'feeds_processed'   => 1, // Batch level
			'feeds_successful'  => 1,
			'feeds_failed'      => 0,
			'avg_response_time' => null,
			'success_rate'      => $batch_data['processed'] > 0 ? 100.0 : 0.0,
			'error_message'     => $batch_data['message'] ?? null,
		);

		$wpdb->insert( $table_name, $data );

		// Update transient cache
		$metrics = get_transient( self::METRICS_TRANSIENT );
		if ( $metrics == false ) {
			$metrics = array();
		}

		$metrics[] = $data;
		// Keep only last 100 batch metrics
		if ( count( $metrics ) > 100 ) {
			$metrics = array_slice( $metrics, -100 );
		}

		set_transient( self::METRICS_TRANSIENT, $metrics, HOUR_IN_SECONDS );
	}
}
