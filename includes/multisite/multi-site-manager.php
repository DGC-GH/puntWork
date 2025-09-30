<?php

/**
 * Multi-Site Support Manager.
 *
 * Handles network-wide job distribution and synchronization across WordPress multisite networks.
 *
 * @since      0.0.4
 */

namespace Puntwork\MultiSite;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-Site Manager for network-wide job distribution.
 */
class MultiSiteManager {

	/**
	 * Network sites cache.
	 */
	private static array $networkSites = array();

	/**
	 * Site capabilities cache.
	 */
	private static array $siteCapabilities = array();

	/**
	 * Job distribution strategies.
	 */
	public const STRATEGY_ROUND_ROBIN      = 'round_robin';
	public const STRATEGY_LOAD_BALANCED    = 'load_balanced';
	public const STRATEGY_CAPABILITY_BASED = 'capability_based';
	public const STRATEGY_GEOGRAPHIC       = 'geographic';

	/**
	 * Initialize multi-site support.
	 */
	public static function init(): void {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'init', array( self::class, 'setupMultisite' ) );
		add_action( 'wp_ajaxSyncNetworkJobs', array( self::class, 'ajaxSyncNetworkJobs' ) );
		add_action( 'wp_ajaxGetNetworkStats', array( self::class, 'ajaxGetNetworkStats' ) );
		add_action( 'wp_ajax_distributeJobsNetwork', array( self::class, 'ajaxDistributeJobsNetwork' ) );

		// Schedule network sync
		if ( ! wp_next_scheduled( 'puntwork_network_sync' ) ) {
			wp_schedule_event( time(), 'hourly', 'puntwork_network_sync' );
		}
		add_action( 'puntwork_network_sync', array( self::class, 'syncNetworkData' ) );
	}

	/**
	 * Setup multi-site functionality.
	 */
	public static function setupMultisite(): void {
		self::createNetworkTables();
		self::registerNetworkSettings();
	}

	/**
	 * Create network-wide tables.
	 */
	private static function createNetworkTables(): void {
		global $wpdb;

		if ( ! is_multisite() ) {
			return;
		}

		$network_table   = $wpdb->base_prefix . 'puntwork_network_jobs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $network_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id varchar(255) NOT NULL,
            site_id bigint(20) unsigned NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            priority int(11) NOT NULL DEFAULT 0,
            data longtext NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY job_site (job_id, site_id),
            KEY site_id (site_id),
            KEY status (status),
            KEY priority (priority)
        ) $charset_collate;";

		include_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Register network settings.
	 */
	private static function registerNetworkSettings(): void {
		register_setting(
			'puntwork_network',
			'puntwork_network_distribution_strategy',
			array(
				'type'              => 'string',
				'default'           => self::STRATEGY_LOAD_BALANCED,
				'sanitize_callback' => array( self::class, 'sanitizeDistributionStrategy' ),
			)
		);

		register_setting(
			'puntwork_network',
			'puntwork_network_sync_enabled',
			array(
				'type'    => 'boolean',
				'default' => true,
			)
		);

		register_setting(
			'puntwork_network',
			'puntwork_network_max_sites',
			array(
				'type'    => 'integer',
				'default' => 10,
			)
		);
	}

	/**
	 * Sanitize distribution strategy.
	 */
	public static function sanitizeDistributionStrategy( string $strategy ): string {
		$valid_strategies = array(
			self::STRATEGY_ROUND_ROBIN,
			self::STRATEGY_LOAD_BALANCED,
			self::STRATEGY_CAPABILITY_BASED,
			self::STRATEGY_GEOGRAPHIC,
		);

		return in_array( $strategy, $valid_strategies ) ? $strategy : self::STRATEGY_LOAD_BALANCED;
	}

	/**
	 * Get all network sites with puntwork capability.
	 */
	public static function getNetworkSites(): array {
		if ( ! empty( self::$networkSites ) ) {
			return self::$networkSites;
		}

		if ( ! is_multisite() ) {
			return array();
		}

		$sites = get_sites(
			array(
				'number' => get_option( 'puntwork_network_max_sites', 10 ),
				'public' => 1,
			)
		);

		$capable_sites = array();
		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );

			if ( self::siteHasPuntworkCapability( $site->blog_id ) ) {
				$capable_sites[] = array(
					'id'           => $site->blog_id,
					'name'         => get_bloginfo( 'name' ),
					'url'          => get_bloginfo( 'url' ),
					'capabilities' => self::getSiteCapabilities( $site->blog_id ),
					'stats'        => self::getSiteStats( $site->blog_id ),
				);
			}

			restore_current_blog();
		}

		self::$networkSites = $capable_sites;

		return $capable_sites;
	}

	/**
	 * Check if site has puntwork capability.
	 */
	private static function siteHasPuntworkCapability( int $site_id ): bool {
		// Check if puntwork plugin is active
		if (
			! is_plugin_active_for_network( 'puntwork/puntwork.php' )
			&& ! is_plugin_active( 'puntwork/puntwork.php' )
		) {
			return false;
		}

		// Check if site has required capabilities
		return current_user_can( 'manage_options' ) ||
		get_option( 'puntwork_network_enabled', false );
	}

	/**
	 * Get site capabilities.
	 */
	private static function getSiteCapabilities( int $site_id ): array {
		if ( isset( self::$siteCapabilities[ $site_id ] ) ) {
			return self::$siteCapabilities[ $site_id ];
		}

		$capabilities = array(
			'max_jobs_per_hour' => get_option( 'puntwork_max_jobs_per_hour', 1000 ),
			'supported_formats' => get_option( 'puntwork_supported_formats', array( 'json', 'xml', 'csv' ) ),
			'geographic_region' => get_option( 'puntwork_geographic_region', 'global' ),
			'processing_power'  => get_option( 'puntwork_processing_power', 'standard' ),
			'storage_capacity'  => get_option( 'puntwork_storage_capacity', 'standard' ),
		);

		self::$siteCapabilities[ $site_id ] = $capabilities;

		return $capabilities;
	}

	/**
	 * Get site statistics.
	 */
	private static function getSiteStats( int $site_id ): array {
		global $wpdb;

		$stats = array(
			'total_jobs'   => 0,
			'active_feeds' => 0,
			'success_rate' => 0,
			'last_sync'    => null,
			'current_load' => 0,
		);

		try {
			// Get job counts
			$job_count           = wp_count_posts( 'job' );
			$stats['total_jobs'] = $job_count->publish ?? 0;

			// Get active feeds
			$feed_count            = wp_count_posts( 'job-feed' );
			$stats['active_feeds'] = $feed_count->publish ?? 0;

			// Get success rate from analytics
			$analytics_table       = $wpdb->prefix . 'puntwork_import_analytics';
			$success_rate          = $wpdb->get_var(
				"
                SELECT AVG(success_rate)
                FROM $analytics_table
                WHERE end_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            "
			);
			$stats['success_rate'] = round( ( $success_rate ?? 0 ) * 100, 1 );

			// Get last sync time
			$stats['last_sync'] = get_option( 'puntwork_last_network_sync' );

			// Calculate current load (simplified)
			$stats['current_load'] = self::calculateSiteLoad( $site_id );
		} catch ( \Exception $e ) {
			\Puntwork\PuntWorkLogger::error( 'Failed to get site stats for site ' . $site_id . ': ' . $e->getMessage() );
		}

		return $stats;
	}

	/**
	 * Calculate site load.
	 */
	private static function calculateSiteLoad( int $site_id ): float {
		// Simplified load calculation based on recent activity
		global $wpdb;

		$analytics_table = $wpdb->prefix . 'puntwork_import_analytics';
		$recent_jobs     = $wpdb->get_var(
			"
            SELECT COUNT(*)
            FROM $analytics_table
            WHERE end_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        "
		);

		// Normalize to 0-100 scale
		return min( 100, ( $recent_jobs ?? 0 ) * 10 );
	}

	/**
	 * Distribute jobs across network using specified strategy.
	 */
	public static function distributeJobsNetwork( array $jobs, ?string $strategy = null ): array {
		if ( ! $strategy ) {
			$strategy = get_option( 'puntwork_network_distribution_strategy', self::STRATEGY_LOAD_BALANCED );
		}

		$sites = self::getNetworkSites();
		if ( empty( $sites ) ) {
			return array(
				'distributed' => array(),
				'errors'      => array( 'No capable sites found in network' ),
			);
		}

		$distributed = array();
		$errors      = array();

		switch ( $strategy ) {
			case self::STRATEGY_ROUND_ROBIN:
				$distributed = self::distributeRoundRobin( $jobs, $sites );

				break;
			case self::STRATEGY_LOAD_BALANCED:
				$distributed = self::distributeLoadBalanced( $jobs, $sites );

				break;
			case self::STRATEGY_CAPABILITY_BASED:
				$distributed = self::distributeCapabilityBased( $jobs, $sites );

				break;
			case self::STRATEGY_GEOGRAPHIC:
				$distributed = self::distributeGeographic( $jobs, $sites );

				break;
			default:
				$errors[] = 'Unknown distribution strategy: ' . $strategy;
		}

		return array(
			'distributed' => $distributed,
			'errors'      => $errors,
		);
	}

	/**
	 * Round-robin distribution.
	 */
	private static function distributeRoundRobin( array $jobs, array $sites ): array {
		$distributed = array();
		$site_count  = count( $sites );
		$site_index  = 0;

		foreach ( $jobs as $job ) {
			$site                         = $sites[ $site_index % $site_count ];
			$distributed[ $site['id'] ][] = $job;
			++$site_index;
		}

		return $distributed;
	}

	/**
	 * Load-balanced distribution.
	 */
	private static function distributeLoadBalanced( array $jobs, array $sites ): array {
		$distributed = array();

		// Sort sites by current load (ascending)
		usort(
			$sites,
			function ( $a, $b ) {
				return $a['stats']['current_load'] <=> $b['stats']['current_load'];
			}
		);

		foreach ( $jobs as $job ) {
			// Find least loaded site
			$target_site = null;
			$min_load    = PHP_FLOAT_MAX;

			foreach ( $sites as $site ) {
				if ( $site['stats']['current_load'] < $min_load ) {
					$min_load    = $site['stats']['current_load'];
					$target_site = $site;
				}
			}

			if ( $target_site ) {
				$distributed[ $target_site['id'] ][] = $job;
				// Simulate load increase
				$target_site['stats']['current_load'] += 1;
			}
		}

		return $distributed;
	}

	/**
	 * Capability-based distribution.
	 */
	private static function distributeCapabilityBased( array $jobs, array $sites ): array {
		$distributed = array();

		foreach ( $jobs as $job ) {
			$best_site  = null;
			$best_score = -1;

			foreach ( $sites as $site ) {
				$score = self::calculateCapabilityScore( $job, $site );
				if ( $score > $best_score ) {
					$best_score = $score;
					$best_site  = $site;
				}
			}

			if ( $best_site ) {
				$distributed[ $best_site['id'] ][] = $job;
			}
		}

		return $distributed;
	}

	/**
	 * Geographic distribution.
	 */
	private static function distributeGeographic( array $jobs, array $sites ): array {
		$distributed = array();

		// Group sites by region
		$sites_by_region = array();
		foreach ( $sites as $site ) {
			$region                       = $site['capabilities']['geographic_region'] ?? 'global';
			$sites_by_region[ $region ][] = $site;
		}

		foreach ( $jobs as $job ) {
			$job_region   = $job['region'] ?? 'global';
			$region_sites = $sites_by_region[ $job_region ] ?? $sites_by_region['global'] ?? $sites;

			if ( ! empty( $region_sites ) ) {
				// Use round-robin within region
				static $region_index = array();
				if ( ! isset( $region_index[ $job_region ] ) ) {
					$region_index[ $job_region ] = 0;
				}

				$site                         = $region_sites[ $region_index[ $job_region ] % count( $region_sites ) ];
				$distributed[ $site['id'] ][] = $job;
				++$region_index[ $job_region ];
			}
		}

		return $distributed;
	}

	/**
	 * Calculate capability score for job-site matching.
	 */
	private static function calculateCapabilityScore( array $job, array $site ): float {
		$score = 0;

		// Format compatibility
		$job_format = $job['format'] ?? 'json';
		if ( in_array( $job_format, $site['capabilities']['supported_formats'] ) ) {
			$score += 50;
		}

		// Processing power match
		$job_complexity = $job['complexity'] ?? 'standard';
		if ( $site['capabilities']['processing_power'] === $job_complexity ) {
			$score += 30;
		}

		// Current load (inverse relationship)
		$load_penalty = $site['stats']['current_load'] / 10; // 0-10 scale
		$score       -= $load_penalty;

		// Success rate bonus
		$success_bonus = $site['stats']['success_rate'] / 10; // 0-10 scale
		$score        += $success_bonus;

		return max( 0, $score );
	}

	/**
	 * Sync network data.
	 */
	public static function syncNetworkData(): void {
		if ( ! is_multisite() || ! get_option( 'puntwork_network_sync_enabled', true ) ) {
			return;
		}

		$sites     = self::getNetworkSites();
		$sync_data = array();

		foreach ( $sites as $site ) {
			try {
				switch_to_blog( $site['id'] );

				// Collect sync data
				$site_data = array(
					'site_id'           => $site['id'],
					'job_templates'     => self::getSiteJobTemplates(),
					'feed_configs'      => self::getSiteFeedConfigs(),
					'analytics_summary' => self::getSiteAnalyticsSummary(),
					'last_updated'      => current_time( 'timestamp' ),
				);

				$sync_data[] = $site_data;

				restore_current_blog();
			} catch ( \Exception $e ) {
				restore_current_blog();
				\Puntwork\PuntWorkLogger::error( 'Network sync failed for site ' . $site['id'] . ': ' . $e->getMessage() );
			}
		}

		// Store sync data
		update_option( 'puntwork_network_sync_data', $sync_data );
		update_option( 'puntwork_last_network_sync', current_time( 'timestamp' ) );

		\Puntwork\PuntWorkLogger::info( 'Network sync completed for ' . count( $sync_data ) . ' sites' );
	}

	/**
	 * Get site job templates for network sharing.
	 */
	private static function getSiteJobTemplates(): array {
		$templates = array();

		$template_posts = get_posts(
			array(
				'post_type'      => 'job-template',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		foreach ( $template_posts as $template ) {
			$templates[] = array(
				'id'      => $template->ID,
				'title'   => $template->post_title,
				'content' => $template->post_content,
				'meta'    => get_post_meta( $template->ID ),
			);
		}

		return $templates;
	}

	/**
	 * Get site feed configurations.
	 */
	private static function getSiteFeedConfigs(): array {
		$configs = array();

		$feed_posts = get_posts(
			array(
				'post_type'      => 'job-feed',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		foreach ( $feed_posts as $feed ) {
			$configs[] = array(
				'id'       => $feed->ID,
				'title'    => $feed->post_title,
				'url'      => get_post_meta( $feed->ID, 'feed_url', true ),
				'format'   => get_post_meta( $feed->ID, 'feed_format', true ),
				'settings' => get_post_meta( $feed->ID ),
			);
		}

		return $configs;
	}

	/**
	 * Get site analytics summary.
	 */
	private static function getSiteAnalyticsSummary(): array {
		global $wpdb;

		$analytics_table = $wpdb->prefix . 'puntwork_import_analytics';

		return $wpdb->get_row(
			"
            SELECT
                COUNT(*) as total_imports,
                AVG(success_rate) as avg_success_rate,
                AVG(avg_response_time) as avg_response_time,
                SUM(processed_jobs) as total_jobs_processed,
                MAX(end_time) as last_import
            FROM $analytics_table
            WHERE end_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ",
			ARRAY_A
		) ?: array();
	}

	/**
	 * AJAX handler for syncing network jobs.
	 */
	public static function ajaxSyncNetworkJobs(): void {
		try {
			if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'puntwork_network_sync' ) ) {
				wp_send_json_error( 'Security check failed' );

				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Insufficient permissions' );

				return;
			}

			self::syncNetworkData();

			wp_send_json_success(
				array(
					'message'   => 'Network sync completed successfully',
					'last_sync' => current_time( 'timestamp' ),
				)
			);
		} catch ( \Exception $e ) {
			\Puntwork\PuntWorkLogger::error( 'Network sync failed: ' . $e->getMessage() );
			wp_send_json_error( 'Network sync failed: ' . $e->getMessage() );
		}
	}

	/**
	 * AJAX handler for getting network stats.
	 */
	public static function ajaxGetNetworkStats(): void {
		try {
			if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'puntwork_network_stats' ) ) {
				wp_send_json_error( 'Security check failed' );

				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Insufficient permissions' );

				return;
			}

			$sites         = self::getNetworkSites();
			$network_stats = array(
				'total_sites'      => count( $sites ),
				'active_sites'     => count( array_filter( $sites, fn ( $s ) => $s['stats']['active_feeds'] > 0 ) ),
				'total_jobs'       => array_sum( array_column( array_column( $sites, 'stats' ), 'total_jobs' ) ),
				'avg_success_rate' => round( array_sum( array_column( array_column( $sites, 'stats' ), 'success_rate' ) ) / count( $sites ), 1 ),
				'sites'            => $sites,
			);

			wp_send_json_success( $network_stats );
		} catch ( \Exception $e ) {
			wp_send_json_error( 'Failed to get network stats: ' . $e->getMessage() );
		}
	}

	/**
	 * AJAX handler for distributing jobs across network.
	 */
	public static function ajaxDistributeJobsNetwork(): void {
		try {
			if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'puntwork_network_distribute' ) ) {
				wp_send_json_error( 'Security check failed' );

				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Insufficient permissions' );

				return;
			}

			$jobs     = json_decode( stripslashes( $_POST['jobs'] ?? '[]' ), true );
			$strategy = sanitize_text_field( $_POST['strategy'] ?? '' );

			if ( empty( $jobs ) ) {
				wp_send_json_error( 'No jobs provided for distribution' );

				return;
			}

			$result = self::distributeJobsNetwork( $jobs, $strategy );

			wp_send_json_success(
				array(
					'message'      => 'Jobs distributed successfully',
					'distribution' => $result,
				)
			);
		} catch ( \Exception $e ) {
			\Puntwork\PuntWorkLogger::error( 'Network job distribution failed: ' . $e->getMessage() );
			wp_send_json_error( 'Network distribution failed: ' . $e->getMessage() );
		}
	}
}
