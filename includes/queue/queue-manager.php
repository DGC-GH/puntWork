<?php

/**
 * Background Queue System for puntWork
 * Provides asynchronous job processing for improved scalability
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue Manager Class
 * Handles background job processing with database-backed queue
 */
class PuntworkQueueManager {

	private const TABLE_NAME  = 'puntwork_queue';
	private const MAX_RETRIES = 3;
	private const BATCH_SIZE  = 10;

	public function __construct() {
		$this->initHooks();
		// Only create table in WordPress environment, not during testing
		if ( function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) ) {
			$this->createQueueTable();
		}
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function initHooks() {
		add_action( 'init', array( $this, 'processQueue' ) );
		add_action( 'puntwork_process_queue', array( $this, 'processQueueCron' ) );
		add_action( 'wp_ajax_puntwork_process_queue', array( $this, 'ajaxProcessQueue' ) );

		// Schedule cron job
		if ( ! wp_next_scheduled( 'puntwork_process_queue' ) ) {
			wp_schedule_event( time(), 'puntwork_queue_interval', 'puntwork_process_queue' );
		}

		// Add custom cron schedule
		add_filter( 'cron_schedules', array( $this, 'addQueueCronSchedule' ) );
	}

	/**
	 * Add custom cron schedule for queue processing
	 */
	public function addQueueCronSchedule( $schedules ) {
		$schedules['puntwork_queue_interval'] = array(
			'interval' => 30, // 30 seconds
			'display'  => __( 'Every 30 seconds - puntWork Queue' ),
		);
		return $schedules;
	}

	/**
	 * Create queue table if it doesn't exist
	 */
	private function createQueueTable() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		error_log( '[PUNTWORK] Checking/creating queue table: ' . $table_name );

		// Check if table exists
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		if ( ! $table_exists ) {
			error_log( '[PUNTWORK] Queue table does not exist, creating it' );
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE $table_name (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                job_type varchar(100) NOT NULL,
                job_data longtext NOT NULL,
                priority int(11) DEFAULT 10,
                status enum('pending','processing','completed','failed') DEFAULT 'pending',
                attempts int(11) DEFAULT 0,
                max_attempts int(11) DEFAULT " . self::MAX_RETRIES . ",
                scheduled_at datetime DEFAULT CURRENT_TIMESTAMP,
                started_at datetime NULL,
                completed_at datetime NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY job_type_status (job_type, status),
                KEY priority_scheduled (priority, scheduled_at),
                KEY status_updated (status, updated_at)
            ) $charset_collate;";

			include_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$result = dbDelta( $sql );

			if ( ! empty( $result ) ) {
				error_log( '[PUNTWORK] Queue table created successfully: ' . json_encode( $result ) );
			} else {
				error_log( '[PUNTWORK] dbDelta returned empty result for queue table creation' );
			}

			if ( $wpdb->last_error ) {
				error_log( '[PUNTWORK] Database error during queue table creation: ' . $wpdb->last_error );
			}
		} else {
			error_log( '[PUNTWORK] Queue table already exists' );
		}
	}

	/**
	 * Add job to queue
	 */
	public function addJob( $job_type, $job_data, $priority = 10, $delay = 0 ) {
		global $wpdb;

		$scheduled_time = $delay > 0 ? date( 'Y-m-d H:i:s', time() + $delay ) : current_time( 'mysql' );

		$result = $wpdb->insert(
			$wpdb->prefix . self::TABLE_NAME,
			array(
				'job_type'     => $job_type,
				'job_data'     => wp_json_encode( $job_data ),
				'priority'     => $priority,
				'scheduled_at' => $scheduled_time,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		if ( $result == false ) {
			error_log( '[PUNTWORK] Failed to add job to queue: ' . $wpdb->last_error );
			return false;
		}

		$job_id = $wpdb->insert_id;

		// Trigger immediate processing if high priority
		if ( $priority <= 5 ) {
			$this->processQueue();
		}

		do_action( 'puntwork_job_queued', $job_id, $job_type, $job_data );

		return $job_id;
	}

	/**
	 * Get pending jobs for processing
	 */
	private function getPendingJobs( $limit = self::BATCH_SIZE ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		return $wpdb->get_results(
			$wpdb->prepare(
				"
            SELECT * FROM $table_name
            WHERE status = 'pending'
            AND scheduled_at <= %s
            AND attempts < max_attempts
            ORDER BY priority ASC, scheduled_at ASC
            LIMIT %d
        ",
				current_time( 'mysql' ),
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Process queue jobs
	 */
	public function processQueue() {
		$jobs = $this->getPendingJobs();

		if ( empty( $jobs ) ) {
			return;
		}

		// Check if load balancer is available and should be used
		if ( $this->shouldUseLoadBalancer() ) {
			$this->processWithLoadBalancer( $jobs );
		} else {
			foreach ( $jobs as $job ) {
				$this->processJob( $job );
			}
		}
	}

	/**
	 * Process single job
	 */
	private function processJob( $job ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// Mark job as processing
		$wpdb->update(
			$table_name,
			array(
				'status'     => 'processing',
				'started_at' => current_time( 'mysql' ),
				'attempts'   => $job['attempts'] + 1,
			),
			array( 'id' => $job['id'] ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);

		try {
			$job_data = json_decode( $job['job_data'], true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				throw new \Exception( 'Invalid job data JSON: ' . json_last_error_msg() );
			}

			$result = $this->executeJob( $job['job_type'], $job_data );

			// Mark as completed
			$wpdb->update(
				$table_name,
				array(
					'status'       => 'completed',
					'completed_at' => current_time( 'mysql' ),
				),
				array( 'id' => $job['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			do_action( 'puntwork_job_completed', $job['id'], $job['job_type'], $result );
		} catch ( \Exception $e ) {
			error_log( '[PUNTWORK] Job failed: ' . $e->getMessage() );

			$this->handleJobFailure( $job, $e );
		}
	}

	/**
	 * Handle job failure with enhanced retry logic
	 */
	private function handleJobFailure( $job, \Exception $e ) {
		global $wpdb;

		$table_name    = $wpdb->prefix . self::TABLE_NAME;
		$error_message = $e->getMessage();

		// Determine if error is retryable
		$is_retryable = $this->isRetryableError( $error_message );

		// Check if max attempts reached or error is not retryable
		if ( $job['attempts'] + 1 >= $job['max_attempts'] || ! $is_retryable ) {
			$wpdb->update(
				$table_name,
				array( 'status' => 'failed' ),
				array( 'id' => $job['id'] ),
				array( '%s' ),
				array( '%d' )
			);

			do_action( 'puntwork_job_failed', $job['id'], $job['job_type'], $error_message );
		} else {
			// Calculate exponential backoff delay
			$delay = $this->calculateRetryDelay( $job['attempts'] + 1 );

			// Reset to pending for retry with delay
			$wpdb->update(
				$table_name,
				array(
					'status'       => 'pending',
					'scheduled_at' => date( 'Y-m-d H:i:s', time() + $delay ),
				),
				array( 'id' => $job['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			do_action( 'puntwork_job_retry_scheduled', $job['id'], $job['job_type'], $delay, $error_message );
		}
	}

	/**
	 * Determine if an error is retryable
	 */
	private function isRetryableError( $error_message ) {
		$retryable_patterns = array(
			'timeout',
			'connection',
			'network',
			'temporary',
			'server error',
			'503',
			'502',
			'504',
			'database connection',
			'lock wait',
			'deadlock',
		);

		$error_lower = strtolower( $error_message );

		foreach ( $retryable_patterns as $pattern ) {
			if ( strpos( $error_lower, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Calculate exponential backoff delay for retries
	 */
	private function calculateRetryDelay( $attempt ) {
		// Exponential backoff: 30s, 1m, 2m, 4m, 8m, 16m (max 16 minutes)
		$base_delay = 30; // 30 seconds
		$delay      = $base_delay * pow( 2, $attempt - 1 );

		// Cap at 16 minutes
		return min( $delay, 960 );
	}

	/**
	 * Execute job based on type
	 */
	private function executeJob( $job_type, $job_data ) {
		switch ( $job_type ) {
			case 'feed_import':
				return $this->processFeedImport( $job_data );

			case 'job_import':
				return $this->processJobImport( $job_data );

			case 'batch_process':
				return $this->processBatch( $job_data );

			case 'cleanup':
				return $this->processCleanup( $job_data );

			case 'notification':
				return $this->sendNotification( $job_data );

			case 'analytics_update':
				return $this->updateAnalytics( $job_data );

			default:
				throw new \Exception( "Unknown job type: $job_type" );
		}
	}

	/**
	 * Process feed import job
	 */
	private function processFeedImport( $job_data ) {
		// Import feed processing logic
		$feed_id = $job_data['feed_id'] ?? null;
		$force   = $job_data['force'] ?? false;

		if ( ! $feed_id ) {
			throw new \Exception( 'Feed ID required for import job' );
		}

		// Use existing import logic
		include_once __DIR__ . '/../import/import-batch.php';

		// Process the feed import
		$result = processFeedImport( $feed_id, $force );

		return $result;
	}

	/**
	 * Process individual job import
	 */
	private function processJobImport( $job_data ) {
		$guid             = $job_data['guid'] ?? null;
		$item             = $job_data['job_data'] ?? null;
		$existing_post_id = $job_data['post_id'] ?? null;

		if ( ! $guid || ! $item ) {
			throw new \Exception( 'GUID and job data required for job import' );
		}

		// Check if required functions exist
		if ( ! function_exists( 'get_acf_fields' ) || ! function_exists( 'get_zero_empty_fields' ) || ! function_exists( 'update_field' ) ) {
			throw new \Exception( 'Required ACF functions not available' );
		}

		$acf_fields        = get_acf_fields();
		$zero_empty_fields = get_zero_empty_fields();
		$user_id           = get_user_by( 'login', 'admin' ) ? get_user_by( 'login', 'admin' )->ID : get_current_user_id();

		$xml_updated    = isset( $item['updated'] ) ? $item['updated'] : '';
		$xml_updated_ts = strtotime( $xml_updated );

		// Check if post exists
		if ( $existing_post_id ) {
			// Update existing post
			$current_last_update = get_post_meta( $existing_post_id, '_last_import_update', true );
			$current_last_ts     = $current_last_update ? strtotime( $current_last_update ) : 0;

			// Skip if no update needed
			if ( $xml_updated_ts && $current_last_ts >= $xml_updated_ts ) {
				return array(
					'status' => 'skipped',
					'reason' => 'not_updated',
				);
			}

			$current_hash = get_post_meta( $existing_post_id, '_import_hash', true );
			$item_hash    = md5( json_encode( $item ) );

			// Skip if content hasn't changed
			if ( $current_hash === $item_hash ) {
				return array(
					'status' => 'skipped',
					'reason' => 'no_changes',
				);
			}

			// Update post
			$xml_title     = isset( $item['functiontitle'] ) ? $item['functiontitle'] : '';
			$xml_validfrom = isset( $item['validfrom'] ) ? $item['validfrom'] : '';
			$post_modified = $xml_updated ?: current_time( 'mysql' );

			wp_update_post(
				array(
					'ID'            => $existing_post_id,
					'post_title'    => $xml_title,
					'post_name'     => sanitize_title( $xml_title . '-' . $guid ),
					'post_status'   => 'publish',
					'post_date'     => $xml_validfrom,
					'post_modified' => $post_modified,
				)
			);

			update_post_meta( $existing_post_id, '_last_import_update', $xml_updated );
			update_post_meta( $existing_post_id, '_import_hash', $item_hash );

			// Update ACF fields
			foreach ( $acf_fields as $field ) {
				$value      = $item[ $field ] ?? '';
				$is_special = in_array( $field, $zero_empty_fields );
				$set_value  = $is_special && $value == '0' ? '' : $value;
				update_field( $field, $set_value, $existing_post_id );
			}

			return array(
				'status'  => 'updated',
				'post_id' => $existing_post_id,
			);
		} else {
			// Create new post
			$xml_title     = isset( $item['functiontitle'] ) ? $item['functiontitle'] : '';
			$xml_validfrom = isset( $item['validfrom'] ) ? $item['validfrom'] : current_time( 'mysql' );
			$post_modified = $xml_updated ?: current_time( 'mysql' );

			$post_data = array(
				'post_type'      => 'job',
				'post_title'     => $xml_title,
				'post_name'      => sanitize_title( $xml_title . '-' . $guid ),
				'post_status'    => 'publish',
				'post_date'      => $xml_validfrom,
				'post_modified'  => $post_modified,
				'comment_status' => 'closed',
				'post_author'    => $user_id,
			);

			$post_id = wp_insert_post( $post_data );
			if ( is_wp_error( $post_id ) ) {
				throw new \Exception( 'Failed to create post: ' . $post_id->get_error_message() );
			}

			update_post_meta( $post_id, '_last_import_update', $xml_updated );
			$item_hash = md5( json_encode( $item ) );
			update_post_meta( $post_id, '_import_hash', $item_hash );

			// Update ACF fields
			foreach ( $acf_fields as $field ) {
				$value      = $item[ $field ] ?? '';
				$is_special = in_array( $field, $zero_empty_fields );
				$set_value  = $is_special && $value == '0' ? '' : $value;
				update_field( $field, $set_value, $post_id );
			}

			return array(
				'status'  => 'published',
				'post_id' => $post_id,
			);
		}
	}

	/**
	 * Process batch job
	 */
	private function processBatch( $job_data ) {
		$batch_id = $job_data['batch_id'] ?? null;

		if ( ! $batch_id ) {
			throw new \Exception( 'Batch ID required for batch processing job' );
		}

		// Use existing batch processing logic
		include_once __DIR__ . '/../batch/batch-processing.php';

		$result = process_import_batch( $batch_id );

		return $result;
	}

	/**
	 * Process cleanup job
	 */
	private function processCleanup( $job_data ) {
		$type = $job_data['type'] ?? 'general';

		switch ( $type ) {
			case 'old_logs':
				return $this->cleanupOldLogs( $job_data );

			case 'temp_files':
				return $this->cleanupTempFiles( $job_data );

			case 'cache':
				return $this->cleanupCache( $job_data );

			default:
				return $this->generalCleanup( $job_data );
		}
	}

	/**
	 * Send notification job
	 */
	private function sendNotification( $job_data ) {
		$type       = $job_data['type'] ?? 'email';
		$recipients = $job_data['recipients'] ?? array();
		$subject    = $job_data['subject'] ?? '';
		$message    = $job_data['message'] ?? '';

		// Use WordPress mail function
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sent = 0;
		foreach ( $recipients as $recipient ) {
			if ( wp_mail( $recipient, $subject, $message, $headers ) ) {
				++$sent;
			}
		}

		return array(
			'sent'  => $sent,
			'total' => count( $recipients ),
		);
	}

	/**
	 * Update analytics job
	 */
	private function updateAnalytics( $job_data ) {
		// Update analytics data
		include_once __DIR__ . '/../analytics/analytics-processor.php';

		return update_analytics_data( $job_data );
	}

	/**
	 * Cron-based queue processing
	 */
	public function processQueueCron() {
		// Only process if not already running
		if ( get_transient( 'puntwork_queue_processing' ) ) {
			return;
		}

		set_transient( 'puntwork_queue_processing', true, 300 ); // 5 minutes

		try {
			$this->processQueue();
		} finally {
			delete_transient( 'puntwork_queue_processing' );
		}
	}

	/**
	 * AJAX queue processing for immediate execution
	 */
	public function ajaxProcessQueue() {
		// Verify nonce and permissions
		if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'puntwork_queue_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		try {
			$this->processQueue();
			wp_send_json_success( array( 'message' => 'Queue processed successfully' ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => 'Queue processing failed: ' . $e->getMessage() ) );
		}
	}

	/**
	 * Get queue statistics
	 */
	public function getQueueStats() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$stats = $wpdb->get_row(
			"
            SELECT
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
                COUNT(*) as total
            FROM $table_name
        ",
			ARRAY_A
		);

		return $stats ?: array(
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
			'total'      => 0,
		);
	}

	/**
	 * Get queue health metrics
	 */
	public function getQueueHealth() {
		$stats  = $this->getQueueStats();
		$health = array(
			'status'  => 'healthy',
			'issues'  => array(),
			'metrics' => $stats,
		);

		// Check for issues
		if ( $stats['failed'] > 10 ) {
			$health['status']   = 'warning';
			$health['issues'][] = 'High number of failed jobs (' . $stats['failed'] . ')';
		}

		if ( $stats['pending'] > 1000 ) {
			$health['status']   = 'warning';
			$health['issues'][] = 'Large pending queue (' . $stats['pending'] . ' jobs)';
		}

		if ( $stats['processing'] > 50 ) {
			$health['status']   = 'warning';
			$health['issues'][] = 'Many jobs stuck in processing (' . $stats['processing'] . ')';
		}

		// Check for stuck jobs (processing for more than 10 minutes)
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$stuck_jobs = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE status = 'processing' AND started_at < %s",
				date( 'Y-m-d H:i:s', time() - 600 ) // 10 minutes ago
			)
		);

		if ( $stuck_jobs > 0 ) {
			$health['status']   = 'error';
			$health['issues'][] = $stuck_jobs . ' jobs stuck in processing for more than 10 minutes';
		}

		// Performance metrics
		$recent_completed = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE status = 'completed' AND completed_at > %s",
				date( 'Y-m-d H:i:s', time() - 3600 ) // Last hour
			)
		);

		$health['metrics']['throughput_per_hour'] = $recent_completed;
		$health['metrics']['avg_processing_time'] = $this->getAverageProcessingTime();

		return $health;
	}

	/**
	 * Get average processing time for completed jobs
	 */
	private function getAverageProcessingTime() {
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$result = $wpdb->get_row(
			"
            SELECT
                AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_time
            FROM $table_name
            WHERE status = 'completed'
            AND started_at IS NOT NULL
            AND completed_at IS NOT NULL
            AND completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ",
			ARRAY_A
		);

		return $result ? round( $result['avg_time'], 2 ) : 0;
	}

	/**
	 * Cleanup methods
	 */
	private function cleanupOldLogs( $data ) {
		$days = $data['days'] ?? 30;
		// Cleanup old log files
		return array(
			'cleaned' => 0,
			'message' => 'Log cleanup not implemented yet',
		);
	}

	private function cleanupTempFiles( $data ) {
		$path = $data['path'] ?? sys_get_temp_dir();
		// Cleanup temp files
		return array(
			'cleaned' => 0,
			'message' => 'Temp file cleanup not implemented yet',
		);
	}

	private function cleanupCache( $data ) {
		// Clear various caches
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" );

		return array( 'message' => 'Cache cleared successfully' );
	}

	private function generalCleanup( $data ) {
		// General cleanup tasks
		return array( 'message' => 'General cleanup completed' );
	}

	/**
	 * Check if load balancer should be used
	 */
	private function shouldUseLoadBalancer() {
		// Use load balancer if multiple instances are available and configured
		if ( ! class_exists( 'Puntwork\\PuntworkLoadBalancer' ) ) {
			return false;
		}

		$scaling_manager = $this->getScalingManager();
		if ( ! $scaling_manager ) {
			return false;
		}

		$active_instances = $scaling_manager->get_active_instances();
		return count( $active_instances ) > 1;
	}

	/**
	 * Process jobs using load balancer
	 */
	private function processWithLoadBalancer( $jobs ) {
		$load_balancer = $this->getLoadBalancer();
		if ( ! $load_balancer ) {
			// Fallback to local processing
			foreach ( $jobs as $job ) {
				$this->processJob( $job );
			}
			return;
		}

		foreach ( $jobs as $job ) {
			// Let load balancer handle job distribution
			$this->distributeJobViaLoadBalancer( $job, $load_balancer );
		}
	}

	/**
	 * Distribute job via load balancer
	 */
	private function distributeJobViaLoadBalancer( $job, $load_balancer ) {
		$scaling_manager = $this->getScalingManager();
		if ( ! $scaling_manager ) {
			$this->processJob( $job );
			return;
		}

		$instance = $scaling_manager->get_optimal_instance( $job['job_type'] );

		if ( ! $instance ) {
			// No suitable instance, process locally
			$this->processJob( $job );
			return;
		}

		// For distributed processing, mark job as being processed remotely
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$wpdb->update(
			$table_name,
			array(
				'status'     => 'processing',
				'started_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// In a real distributed setup, this would send the job to the remote instance
		// For now, simulate the processing
		try {
			$result = $this->simulateRemoteProcessing( $job, $instance );

			if ( $result['success'] ) {
				$wpdb->update(
					$table_name,
					array(
						'status'       => 'completed',
						'completed_at' => current_time( 'mysql' ),
					),
					array( 'id' => $job['id'] ),
					array( '%s', '%s' ),
					array( '%d' )
				);

				do_action( 'puntwork_job_completed', $job['id'], $job['job_type'], $result );
			} else {
				$this->handle_job_failure( $job, $result['error'] );
			}
		} catch ( \Exception $e ) {
			$this->handle_job_failure( $job, $e->getMessage() );
		}
	}

	/**
	 * Simulate remote job processing
	 */
	private function simulateRemoteProcessing( $job, $instance ) {
		// This would normally make an HTTP request to the remote instance
		// For demonstration, we'll simulate the processing

		$job_data        = json_decode( $job['job_data'], true );
		$processing_time = $this->estimateRemoteProcessingTime( $job['job_type'], $job_data, $instance );

		// Simulate processing delay
		sleep( min( $processing_time, 5 ) ); // Cap at 5 seconds for demo

		// Simulate occasional failures
		if ( mt_rand( 1, 100 ) <= 3 ) { // 3% failure rate for distributed jobs
			return array(
				'success' => false,
				'error'   => 'Remote processing failed',
			);
		}

		return array(
			'success' => true,
			'result'  => 'Job processed on remote instance: ' . $instance['instance_id'],
		);
	}

	/**
	 * Estimate remote processing time
	 */
	private function estimateRemoteProcessingTime( $job_type, $job_data, $instance ) {
		$base_times = array(
			'feed_import'      => 3.0,    // Slightly longer for network overhead
			'batch_process'    => 7.0,
			'analytics_update' => 2.0,
		);

		$base_time = $base_times[ $job_type ] ?? 2.0;

		// Adjust based on instance role
		$speed_factor = 1.0;
		if ( $instance['role'] == 'heavy_processing' ) {
			$speed_factor = 0.6; // Faster processing
		} elseif ( $instance['role'] == 'light_processing' ) {
			$speed_factor = 1.8; // Slower processing
		}

		return $base_time * $speed_factor;
	}

	/**
	 * Get scaling manager instance
	 */
	private function getScalingManager() {
		if ( class_exists( 'Puntwork\\PuntworkHorizontalScalingManager' ) ) {
			global $puntwork_scaling_manager;
			return $puntwork_scaling_manager ?? null;
		}
		return null;
	}

	/**
	 * Ensure queue table exists (public method for external calls)
	 */
	public function ensureTableExists() {
		$this->createQueueTable();
	}
}

// Initialize queue manager
global $puntwork_queue_manager;
$puntwork_queue_manager = new PuntworkQueueManager();
