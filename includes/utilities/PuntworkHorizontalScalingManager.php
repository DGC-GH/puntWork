<?php

/**
 * Horizontal Scaling Manager for puntWork
 * Provides distributed processing capabilities across multiple instances.
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Horizontal Scaling Manager Class
 * Manages distributed processing across multiple server instances.
 */
class PuntworkHorizontalScalingManager {

	private const INSTANCE_TABLE        = 'puntwork_instances';
	private const HEALTH_CHECK_INTERVAL = 30; // seconds
	private const INSTANCE_TIMEOUT      = 300; // 5 minutes
	private const MAX_INSTANCES         = 10;

	private static $table_checked = false;

	private $instance_id;
	private $instance_role;
	private $last_health_check;

	public function __construct() {
		$this->instance_id       = $this->generateInstanceId();
		$this->instance_role     = $this->determineInstanceRole();
		$this->last_health_check = time();

		// Only initialize database operations if WordPress is properly loaded
		if ( $this->isWordpressEnvironment() ) {
			$this->initHooks();
			$this->createInstanceTable();
			$this->registerInstance();
		}
	}

	/**
	 * Initialize WordPress hooks.
	 */
	private function initHooks() {
		add_action( 'init', array( $this, 'healthCheck' ) );
		add_action( 'wp_ajax_puntwork_scaling_health', array( $this, 'ajaxHealthCheck' ) );
		add_action( 'wp_ajax_nopriv_puntwork_scaling_health', array( $this, 'ajaxHealthCheck' ) );
		add_action( 'puntwork_cleanup_instances', array( $this, 'cleanupDeadInstances' ) );

		// Schedule cleanup
		if ( ! wp_next_scheduled( 'puntwork_cleanup_instances' ) ) {
			wp_schedule_event( time(), 'hourly', 'puntwork_cleanup_instances' );
		}

		// Register shutdown function for cleanup
		add_action( 'shutdown', array( $this, 'unregisterInstance' ) );
	}

	/**
	 * Check if we're in a proper WordPress environment.
	 */
	private function isWordpressEnvironment() {
		global $wpdb;

		return isset( $wpdb ) && $wpdb instanceof \wpdb && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' );
	}

	/**
	 * Generate unique instance ID.
	 */
	private function generateInstanceId() {
		$server_id  = gethostname() ?: 'unknown';
		$process_id = getmypid() ?: rand( 1000, 9999 );
		$timestamp  = time();

		return sprintf( '%s-%d-%d', $server_id, $process_id, $timestamp );
	}

	/**
	 * Determine instance role based on server capabilities.
	 */
	private function determineInstanceRole() {
		// Check server capabilities to determine role
		$memory_limit = ini_get( 'memory_limit' );
		$memory_bytes = $this->parseSize( $memory_limit );

		$cpu_count = $this->getCpuCount();

		// Determine role based on resources
		if ( $memory_bytes >= 512 * 1024 * 1024 && $cpu_count >= 4 ) {
			return 'heavy_processing'; // Can handle large imports
		} elseif ( $memory_bytes >= 256 * 1024 * 1024 && $cpu_count >= 2 ) {
			return 'standard_processing'; // Standard processing
		} elseif ( $memory_bytes >= 128 * 1024 * 1024 ) {
			return 'light_processing'; // Light processing only
		} else {
			return 'coordinator_only'; // Coordination and API only
		}
	}

	/**
	 * Parse size string to bytes.
	 */
	private function parseSize( $size ) {
		$unit  = strtolower( substr( $size, -1 ) );
		$value = (int) substr( $size, 0, -1 );

		switch ( $unit ) {
			case 'g':
				return $value * 1024 * 1024 * 1024;
			case 'm':
				return $value * 1024 * 1024;
			case 'k':
				return $value * 1024;
			default:
				return $value;
		}
	}

	/**
	 * Get CPU count.
	 */
	private function getCpuCount() {
		if ( function_exists( 'shell_exec' ) ) {
			$cpu_count = shell_exec( 'nproc 2>/dev/null' ) ?: shell_exec( 'sysctl -n hw.ncpu 2>/dev/null' );
			if ( $cpu_count ) {
				return (int) trim( $cpu_count );
			}
		}

		// Fallback: estimate based on memory
		$memory_limit = ini_get( 'memory_limit' );
		$memory_bytes = $this->parseSize( $memory_limit );

		if ( $memory_bytes >= 1024 * 1024 * 1024 ) { // 1GB+
			return 4;
		} elseif ( $memory_bytes >= 512 * 1024 * 1024 ) { // 512MB+
			return 2;
		} else {
			return 1;
		}
	}

	/**
	 * Create instances table.
	 */
	private function createInstanceTable() {
		if ( self::$table_checked || ! $this->isWordpressEnvironment() ) {
			return;
		}

		self::$table_checked = true;

		// Check if we've already verified the table exists in this session
		$table_verified = get_transient( 'puntwork_instances_table_verified' );
		if ( $table_verified ) {
			return; // Table already verified, skip
		}

		global $wpdb;

		$table_name      = $wpdb->prefix . self::INSTANCE_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// Check if table exists and has correct structure
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		if ( $table_exists ) {
			// Check if primary key exists - try information_schema first, fallback to SHOW INDEXES
			\Puntwork\PuntWorkLogger::debug(
				'Checking primary key existence for instances table',
				\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
				array(
					'table' => $table_name,
				)
			);

			try {
				$start_time         = microtime( true );
				$primary_key_exists = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = %s
                     AND CONSTRAINT_NAME = "PRIMARY"',
						$table_name
					)
				);
				$duration           = microtime( true ) - $start_time;
				\Puntwork\PuntWorkLogger::debug(
					'Successfully checked primary key using information_schema',
					\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
					array(
						'primary_key_exists' => $primary_key_exists,
						'duration'           => round( $duration, 4 ),
					)
				);
			} catch ( \Exception $e ) {
				// Fallback: Use SHOW INDEXES to check for PRIMARY key
				\Puntwork\PuntWorkLogger::warn(
					'information_schema access denied for primary key check, using SHOW INDEXES fallback',
					\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
					array(
						'error' => $e->getMessage(),
						'table' => $table_name,
					)
				);

				try {
					$start_time         = microtime( true );
					$primary_key_exists = $wpdb->get_var(
						$wpdb->prepare(
							"SHOW INDEXES FROM %s WHERE Key_name = 'PRIMARY'",
							$table_name
						)
					);
					$duration           = microtime( true ) - $start_time;
					$primary_key_exists = $primary_key_exists ? 1 : 0;
					\Puntwork\PuntWorkLogger::debug(
						'Successfully checked primary key using SHOW INDEXES fallback',
						\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
						array(
							'primary_key_exists' => $primary_key_exists,
							'duration'           => round( $duration, 4 ),
						)
					);
				} catch ( \Exception $fallback_e ) {
					// If we can't check, assume table needs recreation
					$primary_key_exists = 0;
					\Puntwork\PuntWorkLogger::error(
						'Failed to check primary key existence, assuming table needs recreation',
						\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
						array(
							'error' => $fallback_e->getMessage(),
							'table' => $table_name,
						)
					);
				}
			}

			if ( $primary_key_exists > 0 ) {
				// Table exists and is properly structured - mark as verified for 24 hours
				set_transient( 'puntwork_instances_table_verified', true, 86400 ); // 24 hours

				return;
			}

			// Table exists but is malformed - recreate it
			$sql  = "CREATE TABLE $table_name (";
			$sql .= 'instance_id varchar(100) NOT NULL,';
			$sql .= 'server_name varchar(100) NOT NULL,';
			$sql .= 'ip_address varchar(45) NOT NULL,';
			$sql .= 'role varchar(50) NOT NULL,';
			$sql .= "status enum('active','inactive','maintenance') DEFAULT 'active',";
			$sql .= 'cpu_count int(11) DEFAULT 1,';
			$sql .= 'memory_limit bigint(20) DEFAULT 0,';
			$sql .= 'last_seen datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,';
			$sql .= 'created_at datetime DEFAULT CURRENT_TIMESTAMP,';
			$sql .= 'PRIMARY KEY (instance_id),';
			$sql .= 'KEY server_name (server_name),';
			$sql .= 'KEY status_last_seen (status, last_seen)';
			$sql .= ") $charset_collate;";

			include_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );

			// Mark table as verified after creation
			set_transient( 'puntwork_instances_table_verified', true, 86400 );
		}
	} // <-- Add this closing brace to end createInstanceTable()

	/**
	 * Register this instance.
	 */
	private function registerInstance() {
		if ( ! $this->isWordpressEnvironment() ) {
			return;
		}

		global $wpdb;

		$table_name = $wpdb->prefix . self::INSTANCE_TABLE;

		$data = array(
			'instance_id'  => $this->instance_id,
			'server_name'  => gethostname() ?: 'unknown',
			'ip_address'   => $this->getServerIp(),
			'role'         => $this->instance_role,
			'status'       => 'active',
			'cpu_count'    => $this->getCpuCount(),
			'memory_limit' => $this->parseSize( ini_get( 'memory_limit' ) ),
			'last_seen'    => current_time( 'mysql' ),
		);

		$wpdb->replace( $table_name, $data );
	}

	/**
	 * Get server IP address.
	 */
	private function getServerIp() {
		$server_ip = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '127.0.0.1';

		// Try to get public IP if behind load balancer
		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded_ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$server_ip     = trim( $forwarded_ips[0] );
		} elseif ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$server_ip = $_SERVER['HTTP_X_REAL_IP'];
		}

		return $server_ip;
	}

	/**
	 * Health check for this instance.
	 */
	public function healthCheck() {
		if ( ! $this->isWordpressEnvironment() ) {
			return;
		}

		$now = time();

		// Only run health check every 30 seconds
		if ( $now - $this->last_health_check < self::HEALTH_CHECK_INTERVAL ) {
			return;
		}

		$this->last_health_check = $now;

		// Update last seen timestamp
		global $wpdb;
		$table_name = $wpdb->prefix . self::INSTANCE_TABLE;

		$wpdb->update(
			$table_name,
			array( 'last_seen' => current_time( 'mysql' ) ),
			array( 'instance_id' => $this->instance_id ),
			array( '%s' ),
			array( '%s' )
		);

		// Check system resources
		$health_status = $this->checkSystemHealth();

		if ( ! $health_status['healthy'] ) {
			// Mark instance as unhealthy
			$wpdb->update(
				$table_name,
				array( 'status' => 'maintenance' ),
				array( 'instance_id' => $this->instance_id ),
				array( '%s' ),
				array( '%s' )
			);

			error_log( '[PUNTWORK] Instance unhealthy: ' . implode( ', ', $health_status['issues'] ) );
		}
	}

	/**
	 * Check system health.
	 */
	private function checkSystemHealth() {
		$issues  = array();
		$healthy = true;

		// Check memory usage
		$memory_usage = memory_get_peak_usage( true );
		$memory_limit = $this->parseSize( ini_get( 'memory_limit' ) );

		if ( $memory_usage / $memory_limit > 0.9 ) { // 90% memory usage
			$issues[] = 'High memory usage';
			$healthy  = false;
		}

		// Check disk space
		$disk_free  = disk_free_space( __DIR__ );
		$disk_total = disk_total_space( __DIR__ );

		if ( $disk_free / $disk_total < 0.1 ) { // Less than 10% free space
			$issues[] = 'Low disk space';
			$healthy  = false;
		}

		// Check database connectivity (only if WordPress environment)
		if ( $this->isWordpressEnvironment() ) {
			global $wpdb;
			if ( ! $wpdb->check_connection() ) {
				$issues[] = 'Database connection failed';
				$healthy  = false;
			}
		}

		return array(
			'healthy' => $healthy,
			'issues'  => $issues,
		);
	}

	/**
	 * AJAX health check endpoint.
	 */
	public function ajaxHealthCheck() {
		$health_status = $this->checkSystemHealth();

		if ( $health_status['healthy'] ) {
			wp_send_json_success(
				array(
					'status'      => 'healthy',
					'instance_id' => $this->instance_id,
					'role'        => $this->instance_role,
					'timestamp'   => current_time( 'mysql' ),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'status'      => 'unhealthy',
					'issues'      => $health_status['issues'],
					'instance_id' => $this->instance_id,
					'timestamp'   => current_time( 'mysql' ),
				),
				503
			);
		}
	}

	/**
	 * Cleanup dead instances.
	 */
	public function cleanupDeadInstances() {
		if ( ! $this->isWordpressEnvironment() ) {
			return;
		}

		global $wpdb;

		$table_name   = $wpdb->prefix . self::INSTANCE_TABLE;
		$timeout_time = date( 'Y-m-d H:i:s', time() - self::INSTANCE_TIMEOUT );

		$wpdb->query(
			$wpdb->prepare(
				"
            UPDATE $table_name
            SET status = 'inactive'
            WHERE last_seen < %s AND status = 'active'
        ",
				$timeout_time
			)
		);
	}

	/**
	 * Unregister instance on shutdown.
	 */
	public function unregisterInstance() {
		if ( ! $this->isWordpressEnvironment() ) {
			return;
		}

		global $wpdb;

		$table_name = $wpdb->prefix . self::INSTANCE_TABLE;

		$wpdb->flush();

		$wpdb->update(
			$table_name,
			array( 'status' => 'inactive' ),
			array( 'instance_id' => $this->instance_id ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Get active instances.
	 */
	public function getActiveInstances( $role = null ) {
		if ( ! $this->isWordpressEnvironment() ) {
			return array();
		}

		global $wpdb;

		$table_name = $wpdb->prefix . self::INSTANCE_TABLE;

		$where  = "status = 'active'";
		$params = array();

		if ( $role ) {
			$where   .= ' AND role = %s';
			$params[] = $role;
		}

		$query = "SELECT * FROM $table_name WHERE $where ORDER BY last_seen DESC";

		if ( ! empty( $params ) ) {
			$query = $wpdb->prepare( $query, $params );
		}

		return $wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Get instance statistics.
	 */
	public function getInstanceStats() {
		if ( ! $this->isWordpressEnvironment() ) {
			return array(
				'active'      => 0,
				'inactive'    => 0,
				'maintenance' => 0,
				'total'       => 0,
			);
		}

		global $wpdb;

		$table_name = $wpdb->prefix . self::INSTANCE_TABLE;

		$stats = $wpdb->get_row(
			"
            SELECT
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active,
                COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive,
                COUNT(CASE WHEN status = 'maintenance' THEN 1 END) as maintenance,
                COUNT(*) as total
            FROM $table_name
        ",
			ARRAY_A
		);

		return $stats ?: array(
			'active'      => 0,
			'inactive'    => 0,
			'maintenance' => 0,
			'total'       => 0,
		);
	}

	/**
	 * Get current instance info.
	 */
	public function getCurrentInstance() {
		return array(
			'instance_id'  => $this->instance_id,
			'role'         => $this->instance_role,
			'server_name'  => gethostname() ?: 'unknown',
			'ip_address'   => $this->getServerIp(),
			'cpu_count'    => $this->getCpuCount(),
			'memory_limit' => $this->parseSize( ini_get( 'memory_limit' ) ),
		);
	}

	/**
	 * Check if this instance can handle a specific job type.
	 */
	public function canHandleJob( $job_type ) {
		$role_capabilities = array(
			'coordinator_only'    => array( 'notification', 'analytics_update', 'cleanup' ),
			'light_processing'    => array( 'notification', 'analytics_update', 'cleanup', 'feed_import' ),
			'standard_processing' => array( 'notification', 'analytics_update', 'cleanup', 'feed_import', 'batch_process' ),
			'heavy_processing'    => array( 'notification', 'analytics_update', 'cleanup', 'feed_import', 'batch_process' ),
		);

		return in_array( $job_type, $role_capabilities[ $this->instance_role ] ?? array() );
	}

	/**
	 * Get optimal instance for job type.
	 */
	public function getOptimalInstance( $job_type ) {
		$instances = $this->getActiveInstances();

		$capable_instances = array_filter(
			$instances,
			function ( $instance ) use ( $job_type ) {
				$role_capabilities = array(
					'coordinator_only'    => array( 'notification', 'analytics_update', 'cleanup' ),
					'light_processing'    => array( 'notification', 'analytics_update', 'cleanup', 'feed_import' ),
					'standard_processing' => array( 'notification', 'analytics_update', 'cleanup', 'feed_import', 'batch_process' ),
					'heavy_processing'    => array( 'notification', 'analytics_update', 'cleanup', 'feed_import', 'batch_process' ),
				);

				return in_array( $job_type, $role_capabilities[ $instance['role'] ] ?? array() );
			}
		);

		if ( empty( $capable_instances ) ) {
			return null;
		}

		// Prefer heavy processing instances for heavy jobs
		if ( $job_type == 'batch_process' ) {
			$heavy_instances = array_filter(
				$capable_instances,
				function ( $instance ) {
					return $instance['role'] == 'heavy_processing';
				}
			);

			if ( ! empty( $heavy_instances ) ) {
				return reset( $heavy_instances );
			}
		}

		// Return first available instance
		return reset( $capable_instances );
	}
}

// Initialize horizontal scaling manager
new PuntworkHorizontalScalingManager();
