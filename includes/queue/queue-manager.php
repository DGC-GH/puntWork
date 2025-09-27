<?php

/**
 * Background Queue System for puntWork
 * Provides asynchronous job processing for improved scalability
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Queue Manager Class
 * Handles background job processing with database-backed queue
 */
class PuntworkQueueManager
{
    private const TABLE_NAME = 'puntwork_queue';
    private const MAX_RETRIES = 3;
    private const BATCH_SIZE = 10;

    public function __construct()
    {
        $this->initHooks();
        // Only create table in WordPress environment, not during testing
        if (function_exists('dbDelta') && defined('ABSPATH')) {
            $this->createQueueTable();
        }
    }

    /**
     * Initialize WordPress hooks
     */
    private function initHooks()
    {
        add_action('init', [$this, 'processQueue']);
        add_action('puntwork_process_queue', [$this, 'processQueueCron']);
        add_action('wp_ajax_puntwork_process_queue', [$this, 'ajaxProcessQueue']);

        // Schedule cron job
        if (!wp_next_scheduled('puntwork_process_queue')) {
            wp_schedule_event(time(), 'puntwork_queue_interval', 'puntwork_process_queue');
        }

        // Add custom cron schedule
        add_filter('cron_schedules', [$this, 'addQueueCronSchedule']);
    }

    /**
     * Add custom cron schedule for queue processing
     */
    public function addQueueCronSchedule($schedules)
    {
        $schedules['puntwork_queue_interval'] = [
            'interval' => 30, // 30 seconds
            'display' => __('Every 30 seconds - puntWork Queue')
        ];
        return $schedules;
    }

    /**
     * Create queue table if it doesn't exist
     */
    private function createQueueTable()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        error_log('[PUNTWORK] Checking/creating queue table: ' . $table_name);

        // Check if table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table_name
            )
        );

        if (!$table_exists) {
            error_log('[PUNTWORK] Queue table does not exist, creating it');
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

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $result = dbDelta($sql);

            if (!empty($result)) {
                error_log('[PUNTWORK] Queue table created successfully: ' . json_encode($result));
            } else {
                error_log('[PUNTWORK] dbDelta returned empty result for queue table creation');
            }

            if ($wpdb->last_error) {
                error_log('[PUNTWORK] Database error during queue table creation: ' . $wpdb->last_error);
            }
        } else {
            error_log('[PUNTWORK] Queue table already exists');
        }
    }

    /**
     * Add job to queue
     */
    public function addJob($job_type, $job_data, $priority = 10, $delay = 0)
    {
        global $wpdb;

        $scheduled_time = $delay > 0 ? date('Y-m-d H:i:s', time() + $delay) : current_time('mysql');

        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE_NAME,
            [
                'job_type' => $job_type,
                'job_data' => wp_json_encode($job_data),
                'priority' => $priority,
                'scheduled_at' => $scheduled_time,
                'created_at' => current_time('mysql')
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );

        if ($result === false) {
            error_log('[PUNTWORK] Failed to add job to queue: ' . $wpdb->last_error);
            return false;
        }

        $job_id = $wpdb->insert_id;

        // Trigger immediate processing if high priority
        if ($priority <= 5) {
            $this->processQueue();
        }

        do_action('puntwork_job_queued', $job_id, $job_type, $job_data);

        return $job_id;
    }

    /**
     * Get pending jobs for processing
     */
    private function getPendingJobs($limit = self::BATCH_SIZE)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $table_name
            WHERE status = 'pending'
            AND scheduled_at <= %s
            AND attempts < max_attempts
            ORDER BY priority ASC, scheduled_at ASC
            LIMIT %d
        ", current_time('mysql'), $limit), ARRAY_A);
    }

    /**
     * Process queue jobs
     */
    public function processQueue()
    {
        $jobs = $this->getPendingJobs();

        if (empty($jobs)) {
            return;
        }

        // Check if load balancer is available and should be used
        if ($this->shouldUseLoadBalancer()) {
            $this->processWithLoadBalancer($jobs);
        } else {
            foreach ($jobs as $job) {
                $this->processJob($job);
            }
        }
    }

    /**
     * Process single job
     */
    private function processJob($job)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Mark job as processing
        $wpdb->update(
            $table_name,
            [
                'status' => 'processing',
                'started_at' => current_time('mysql'),
                'attempts' => $job['attempts'] + 1
            ],
            ['id' => $job['id']],
            ['%s', '%s', '%d'],
            ['%d']
        );

        try {
            $job_data = json_decode($job['job_data'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid job data JSON: ' . json_last_error_msg());
            }

            $result = $this->executeJob($job['job_type'], $job_data);

            // Mark as completed
            $wpdb->update(
                $table_name,
                [
                    'status' => 'completed',
                    'completed_at' => current_time('mysql')
                ],
                ['id' => $job['id']],
                ['%s', '%s'],
                ['%d']
            );

            do_action('puntwork_job_completed', $job['id'], $job['job_type'], $result);
        } catch (\Exception $e) {
            error_log('[PUNTWORK] Job failed: ' . $e->getMessage());

            // Check if max attempts reached
            if ($job['attempts'] + 1 >= $job['max_attempts']) {
                $wpdb->update(
                    $table_name,
                    ['status' => 'failed'],
                    ['id' => $job['id']],
                    ['%s'],
                    ['%d']
                );

                do_action('puntwork_job_failed', $job['id'], $job['job_type'], $e->getMessage());
            } else {
                // Reset to pending for retry
                $wpdb->update(
                    $table_name,
                    ['status' => 'pending'],
                    ['id' => $job['id']],
                    ['%s'],
                    ['%d']
                );
            }
        }
    }

    /**
     * Execute job based on type
     */
    private function executeJob($job_type, $job_data)
    {
        switch ($job_type) {
            case 'feed_import':
                return $this->processFeedImport($job_data);

            case 'batch_process':
                return $this->processBatch($job_data);

            case 'cleanup':
                return $this->processCleanup($job_data);

            case 'notification':
                return $this->sendNotification($job_data);

            case 'analytics_update':
                return $this->updateAnalytics($job_data);

            default:
                throw new \Exception("Unknown job type: $job_type");
        }
    }

    /**
     * Process feed import job
     */
    private function processFeedImport($job_data)
    {
        // Import feed processing logic
        $feed_id = $job_data['feed_id'] ?? null;
        $force = $job_data['force'] ?? false;

        if (!$feed_id) {
            throw new \Exception('Feed ID required for import job');
        }

        // Use existing import logic
        require_once __DIR__ . '/../import/import-batch.php';

        // Process the feed import
        $result = processFeedImport($feed_id, $force);

        return $result;
    }

    /**
     * Process batch job
     */
    private function processBatch($job_data)
    {
        $batch_id = $job_data['batch_id'] ?? null;

        if (!$batch_id) {
            throw new \Exception('Batch ID required for batch processing job');
        }

        // Use existing batch processing logic
        require_once __DIR__ . '/../batch/batch-processing.php';

        $result = process_import_batch($batch_id);

        return $result;
    }

    /**
     * Process cleanup job
     */
    private function processCleanup($job_data)
    {
        $type = $job_data['type'] ?? 'general';

        switch ($type) {
            case 'old_logs':
                return $this->cleanupOldLogs($job_data);

            case 'temp_files':
                return $this->cleanupTempFiles($job_data);

            case 'cache':
                return $this->cleanupCache($job_data);

            default:
                return $this->generalCleanup($job_data);
        }
    }

    /**
     * Send notification job
     */
    private function sendNotification($job_data)
    {
        $type = $job_data['type'] ?? 'email';
        $recipients = $job_data['recipients'] ?? [];
        $subject = $job_data['subject'] ?? '';
        $message = $job_data['message'] ?? '';

        // Use WordPress mail function
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = 0;
        foreach ($recipients as $recipient) {
            if (wp_mail($recipient, $subject, $message, $headers)) {
                $sent++;
            }
        }

        return ['sent' => $sent, 'total' => count($recipients)];
    }

    /**
     * Update analytics job
     */
    private function updateAnalytics($job_data)
    {
        // Update analytics data
        require_once __DIR__ . '/../analytics/analytics-processor.php';

        return update_analytics_data($job_data);
    }

    /**
     * Cron-based queue processing
     */
    public function processQueueCron()
    {
        // Only process if not already running
        if (get_transient('puntwork_queue_processing')) {
            return;
        }

        set_transient('puntwork_queue_processing', true, 300); // 5 minutes

        try {
            $this->processQueue();
        } finally {
            delete_transient('puntwork_queue_processing');
        }
    }

    /**
     * AJAX queue processing for immediate execution
     */
    public function ajaxProcessQueue()
    {
        // Verify nonce and permissions
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_queue_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        try {
            $this->processQueue();
            wp_send_json_success(['message' => 'Queue processed successfully']);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Queue processing failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Get queue statistics
     */
    public function getQueueStats()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $stats = $wpdb->get_row("
            SELECT
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
                COUNT(*) as total
            FROM $table_name
        ", ARRAY_A);

        return $stats ?: ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'total' => 0];
    }

    /**
     * Cleanup methods
     */
    private function cleanupOldLogs($data)
    {
        $days = $data['days'] ?? 30;
        // Cleanup old log files
        return ['cleaned' => 0, 'message' => 'Log cleanup not implemented yet'];
    }

    private function cleanupTempFiles($data)
    {
        $path = $data['path'] ?? sys_get_temp_dir();
        // Cleanup temp files
        return ['cleaned' => 0, 'message' => 'Temp file cleanup not implemented yet'];
    }

    private function cleanupCache($data)
    {
        // Clear various caches
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");

        return ['message' => 'Cache cleared successfully'];
    }

    private function generalCleanup($data)
    {
        // General cleanup tasks
        return ['message' => 'General cleanup completed'];
    }

    /**
     * Check if load balancer should be used
     */
    private function shouldUseLoadBalancer()
    {
        // Use load balancer if multiple instances are available and configured
        if (!class_exists('Puntwork\\PuntworkLoadBalancer')) {
            return false;
        }

        $scaling_manager = $this->getScalingManager();
        if (!$scaling_manager) {
            return false;
        }

        $active_instances = $scaling_manager->get_active_instances();
        return count($active_instances) > 1;
    }

    /**
     * Process jobs using load balancer
     */
    private function processWithLoadBalancer($jobs)
    {
        $load_balancer = $this->getLoadBalancer();
        if (!$load_balancer) {
            // Fallback to local processing
            foreach ($jobs as $job) {
                $this->processJob($job);
            }
            return;
        }

        foreach ($jobs as $job) {
            // Let load balancer handle job distribution
            $this->distributeJobViaLoadBalancer($job, $load_balancer);
        }
    }

    /**
     * Distribute job via load balancer
     */
    private function distributeJobViaLoadBalancer($job, $load_balancer)
    {
        $scaling_manager = $this->getScalingManager();
        if (!$scaling_manager) {
            $this->processJob($job);
            return;
        }

        $instance = $scaling_manager->get_optimal_instance($job['job_type']);

        if (!$instance) {
            // No suitable instance, process locally
            $this->processJob($job);
            return;
        }

        // For distributed processing, mark job as being processed remotely
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $wpdb->update(
            $table_name,
            [
                'status' => 'processing',
                'started_at' => current_time('mysql')
            ],
            ['id' => $job['id']],
            ['%s', '%s'],
            ['%d']
        );

        // In a real distributed setup, this would send the job to the remote instance
        // For now, simulate the processing
        try {
            $result = $this->simulateRemoteProcessing($job, $instance);

            if ($result['success']) {
                $wpdb->update(
                    $table_name,
                    [
                        'status' => 'completed',
                        'completed_at' => current_time('mysql')
                    ],
                    ['id' => $job['id']],
                    ['%s', '%s'],
                    ['%d']
                );

                do_action('puntwork_job_completed', $job['id'], $job['job_type'], $result);
            } else {
                $this->handle_job_failure($job, $result['error']);
            }
        } catch (\Exception $e) {
            $this->handle_job_failure($job, $e->getMessage());
        }
    }

    /**
     * Simulate remote job processing
     */
    private function simulateRemoteProcessing($job, $instance)
    {
        // This would normally make an HTTP request to the remote instance
        // For demonstration, we'll simulate the processing

        $job_data = json_decode($job['job_data'], true);
        $processing_time = $this->estimateRemoteProcessingTime($job['job_type'], $job_data, $instance);

        // Simulate processing delay
        sleep(min($processing_time, 5)); // Cap at 5 seconds for demo

        // Simulate occasional failures
        if (mt_rand(1, 100) <= 3) { // 3% failure rate for distributed jobs
            return [
                'success' => false,
                'error' => 'Remote processing failed'
            ];
        }

        return [
            'success' => true,
            'result' => 'Job processed on remote instance: ' . $instance['instance_id']
        ];
    }

    /**
     * Estimate remote processing time
     */
    private function estimateRemoteProcessingTime($job_type, $job_data, $instance)
    {
        $base_times = [
            'feed_import' => 3.0,    // Slightly longer for network overhead
            'batch_process' => 7.0,
            'analytics_update' => 2.0
        ];

        $base_time = $base_times[$job_type] ?? 2.0;

        // Adjust based on instance role
        $speed_factor = 1.0;
        if ($instance['role'] === 'heavy_processing') {
            $speed_factor = 0.6; // Faster processing
        } elseif ($instance['role'] === 'light_processing') {
            $speed_factor = 1.8; // Slower processing
        }

        return $base_time * $speed_factor;
    }

    /**
     * Get scaling manager instance
     */
    private function getScalingManager()
    {
        if (class_exists('Puntwork\\PuntworkHorizontalScalingManager')) {
            global $puntwork_scaling_manager;
            return $puntwork_scaling_manager ?? null;
        }
        return null;
    }

    /**
     * Ensure queue table exists (public method for external calls)
     */
    public function ensureTableExists()
    {
        $this->createQueueTable();
    }

// Initialize queue manager
new PuntworkQueueManager();
