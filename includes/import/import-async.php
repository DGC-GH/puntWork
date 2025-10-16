<?php
/**
 * Async Background Operations for Import System
 * Handles cleanup, finalization, and maintenance tasks asynchronously
 *
 * @package    Puntwork
 * @subpackage Import
 * @since      1.2.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Async operation types
 */
class AsyncOperationType {
    const CLEANUP_FINALIZATION = 'cleanup_finalization';
    const CLEANUP_OLD_DATA = 'cleanup_old_data';
    const DATA_MAINTENANCE = 'data_maintenance';
    const HEALTH_CHECK = 'health_check';
    const METRICS_AGGREGATION = 'metrics_aggregation';
    const MONITORING_UPDATE = 'monitoring_update';
}

/**
 * Async job status
 */
class AsyncJobStatus {
    const PENDING = 'pending';
    const RUNNING = 'running';
    const COMPLETED = 'completed';
    const FAILED = 'failed';
    const RETRY = 'retry';
}

/**
 * Background job operations queue
 */
class AsyncJobQueue {

    private $queue_key = 'puntwork_async_jobs';
    private $max_concurrent_jobs = 3;
    private $job_timeout = 300; // 5 minutes

    /**
     * Add job to queue
     */
    public function add_job($operation_type, $data = [], $priority = 'normal', $delay = 0) {
        $job = [
            'id' => uniqid('async_job_', true),
            'operation_type' => $operation_type,
            'data' => $data,
            'priority' => $priority,
            'status' => AsyncJobStatus::PENDING,
            'created_at' => time(),
            'scheduled_at' => time() + $delay,
            'started_at' => 0,
            'completed_at' => 0,
            'attempts' => 0,
            'max_attempts' => 3,
            'last_error' => '',
            'progress' => 0
        ];

        $queue = $this->get_queue();
        $queue[] = $job;

        // Sort by priority and scheduled time
        usort($queue, function($a, $b) {
            $priority_order = ['high' => 3, 'normal' => 2, 'low' => 1];
            $a_priority = $priority_order[$a['priority']] ?? 2;
            $b_priority = $priority_order[$b['priority']] ?? 2;

            if ($a_priority !== $b_priority) {
                return $b_priority - $a_priority; // Higher priority first
            }

            return $a['scheduled_at'] - $b['scheduled_at']; // Earlier scheduled first
        });

        $this->save_queue($queue);

        PuntWorkLogger::info('Async job added to queue', PuntWorkLogger::CONTEXT_IMPORT, [
            'job_id' => $job['id'],
            'operation_type' => $operation_type,
            'priority' => $priority,
            'delay' => $delay
        ]);

        // Immediately schedule if no delay
        if ($delay === 0) {
            $this->schedule_next_job();
        }

        return $job['id'];
    }

    /**
     * Process next pending job
     */
    public function process_next_job() {
        $queue = $this->get_queue();
        $now = time();

        // Find next runnable job
        $next_job_index = null;
        $running_count = 0;

        foreach ($queue as $index => $job) {
            if ($job['status'] === AsyncJobStatus::RUNNING) {
                $running_count++;

                // Check for stalled jobs
                if (($now - $job['started_at']) > $this->job_timeout) {
                    PuntWorkLogger::warn('Stalled async job detected, resetting', PuntWorkLogger::CONTEXT_IMPORT, [
                        'job_id' => $job['id'],
                        'operation_type' => $job['operation_type'],
                        'stalled_time' => $now - $job['started_at']
                    ]);

                    $queue[$index]['status'] = AsyncJobStatus::RETRY;
                    $queue[$index]['last_error'] = 'Job timeout - stalled';
                }
            }

            if ($job['status'] === AsyncJobStatus::PENDING &&
                $job['scheduled_at'] <= $now &&
                $running_count < $this->max_concurrent_jobs) {
                $next_job_index = $index;
                break;
            }
        }

        if ($next_job_index === null) {
            return false; // No jobs to process
        }

        $job = &$queue[$next_job_index];
        $job['status'] = AsyncJobStatus::RUNNING;
        $job['started_at'] = $now;
        $job['attempts']++;

        $this->save_queue($queue);

        PuntWorkLogger::info('Async job started processing', PuntWorkLogger::CONTEXT_IMPORT, [
            'job_id' => $job['id'],
            'operation_type' => $job['operation_type'],
            'attempt' => $job['attempts']
        ]);

        try {
            $result = $this->execute_job($job);

            // Update job status
            $job['status'] = AsyncJobStatus::COMPLETED;
            $job['completed_at'] = time();
            $job['progress'] = 100;

            PuntWorkLogger::info('Async job completed successfully', PuntWorkLogger::CONTEXT_IMPORT, [
                'job_id' => $job['id'],
                'operation_type' => $job['operation_type'],
                'duration' => time() - $now,
                'result' => $result
            ]);

        } catch (\Exception $e) {
            $job['last_error'] = $e->getMessage();
            $job['status'] = $this->handle_job_failure($job, $e);
            $job['completed_at'] = time();

            PuntWorkLogger::error('Async job failed', PuntWorkLogger::CONTEXT_IMPORT, [
                'job_id' => $job['id'],
                'operation_type' => $job['operation_type'],
                'attempt' => $job['attempts'],
                'error' => $e->getMessage(),
                'next_status' => $job['status']
            ]);
        }

        $this->save_queue($queue);

        // Schedule next job
        $this->schedule_next_job();

        return true;
    }

    /**
     * Execute a specific job
     */
    private function execute_job(&$job) {
        $operation_type = $job['operation_type'];
        $data = $job['data'];

        switch ($operation_type) {
            case AsyncOperationType::CLEANUP_FINALIZATION:
                return $this->execute_cleanup_finalization($data, $job);

            case AsyncOperationType::CLEANUP_OLD_DATA:
                return $this->execute_cleanup_old_data($data, $job);

            case AsyncOperationType::DATA_MAINTENANCE:
                return $this->execute_data_maintenance($data, $job);

            case AsyncOperationType::HEALTH_CHECK:
                return $this->execute_health_check($data, $job);

            case AsyncOperationType::METRICS_AGGREGATION:
                return $this->execute_metrics_aggregation($data, $job);

            case AsyncOperationType::MONITORING_UPDATE:
                return $this->execute_monitoring_update($data, $job);

            default:
                throw new \Exception("Unknown operation type: {$operation_type}");
        }
    }

    /**
     * Execute cleanup finalization job
     */
    private function execute_cleanup_finalization($data, &$job) {
        $cleanup_type = $data['cleanup_type'] ?? 'standard';
        $import_id = $data['import_id'] ?? null;
        $force_cleanup = $data['force_cleanup'] ?? false;

        if ($cleanup_type === 'finalization') {
            // Run full finalization cleanup
            $result = cleanup_old_job_posts(time());

            // Update import status
            if ($import_id) {
                $status = get_import_status([]);
                $status['cleanup_completed'] = true;
                $status['cleanup_result'] = $result;
                set_import_status($status);
            }

            return $result;
        }

        if ($cleanup_type === 'transient_cleanup') {
            // Clean up import transients
            cleanup_import_data();
            return ['action' => 'transient_cleanup', 'completed' => true];
        }

        throw new \Exception("Unknown cleanup type: {$cleanup_type}");
    }

    /**
     * Execute old data cleanup job
     */
    private function execute_cleanup_old_data($data, &$job) {
        $cleanup_strategy = $data['strategy'] ?? 'smart_retention';
        $retention_days = $data['retention_days'] ?? 90;

        if ($cleanup_strategy === 'smart_retention') {
            return smart_cleanup_expired_jobs();
        } elseif ($cleanup_strategy === 'force_delete') {
            return legacy_cleanup_old_posts();
        }

        throw new \Exception("Unknown cleanup strategy: {$cleanup_strategy}");
    }

    /**
     * Execute data maintenance job
     */
    private function execute_data_maintenance($data, &$job) {
        $maintenance_type = $data['maintenance_type'] ?? 'full';

        $results = [];

        // Update job progress
        $job['progress'] = 10;
        $this->update_job_progress($job);

        // Clean old monitoring data
        cleanup_monitoring_data();
        $results['monitoring_cleanup'] = 'completed';

        $job['progress'] = 30;
        $this->update_job_progress($job);

        // Trim import history
        $history = get_option('puntwork_import_history', []);
        if (count($history) > 25) {
            $history = array_slice($history, 0, 25);
            update_option('puntwork_import_history', $history);
            $results['history_trimmed'] = count($history);
        }

        $job['progress'] = 60;
        $this->update_job_progress($job);

        // Clean health alerts
        $alerts = get_option('puntwork_import_health_alerts', []);
        if (count($alerts) > 50) {
            $alerts = array_slice($alerts, 0, 50);
            update_option('puntwork_import_health_alerts', $alerts);
            $results['alerts_trimmed'] = count($alerts);
        }

        $job['progress'] = 80;
        $this->update_job_progress($job);

        // Optimize options table
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        $job['progress'] = 100;
        $this->update_job_progress($job);

        return ['action' => 'data_maintenance', 'results' => $results];
    }

    /**
     * Execute health check job
     */
    private function execute_health_check($data, &$job) {
        $health_status = check_system_health_status();

        // Store health check result
        update_option('puntwork_last_health_check', [
            'timestamp' => time(),
            'status' => $health_status
        ]);

        // Send alerts for critical issues
        if ($health_status['overall_status'] === 'critical') {
            $admin_email = get_option('admin_email');
            $subject = '[PuntWork] Critical System Health Alert';

            $message = "PuntWork Import System Health Critical\n\n";
            $message .= "Overall Status: {$health_status['overall_status']}\n";
            $message .= "Critical Alerts (24h): {$health_status['critical_alerts_24h']}\n";
            $message .= "Performance Trend: {$health_status['performance_trend']}\n";
            $message .= "Avg Import Time: {$health_status['avg_import_time']}s\n\n";

            $message .= "Recommendations:\n";
            foreach ($health_status['recommendations'] as $rec) {
                $message .= "- {$rec}\n";
            }

            wp_mail($admin_email, $subject, $message);
        }

        return $health_status;
    }

    /**
     * Execute metrics aggregation job
     */
    private function execute_metrics_aggregation($data, &$job) {
        $period_days = $data['period_days'] ?? 30;
        $analytics = get_import_performance_analytics($period_days);

        // Store aggregated metrics
        update_option('puntwork_metrics_aggregated', [
            'timestamp' => time(),
            'period_days' => $period_days,
            'data' => $analytics
        ]);

        // Update predictive baseline
        $baseline = get_performance_baseline();
        update_option('puntwork_performance_baseline', $baseline);

        return $analytics;
    }

    /**
     * Execute monitoring update job
     */
    private function execute_monitoring_update($data, &$job) {
        // Update circuit breaker status in monitoring
        $cb_status = get_circuit_breaker_status();
        update_option('puntwork_circuit_breaker_monitoring', $cb_status);

        // Aggregate recent performance metrics
        $recent_metrics = [];
        $start_time = time() - 3600; // Last hour

        global $wpdb;
        $metrics_data = $wpdb->get_results($wpdb->prepare("
            SELECT option_name, option_value
            FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_puntwork_import_monitoring_%'
            AND option_value LIKE '%%start_time%%'
            ORDER BY option_id DESC
            LIMIT 10
        "));

        foreach ($metrics_data as $metric) {
            $key = str_replace('_transient_', '', $metric->option_name);
            $data = get_transient($key);

            if ($data && isset($data['start_time']) && $data['start_time'] >= $start_time) {
                $recent_metrics[] = [
                    'import_id' => $data['import_id'] ?? 'unknown',
                    'start_time' => $data['start_time'],
                    'health_status' => $data['health_status'] ?? 'unknown',
                    'processed' => $data['metrics']['items_processed'] ?? 0,
                    'errors' => count($data['metrics']['errors'] ?? []),
                    'circuit_breaker_events' => $data['metrics']['circuit_breaker_events'] ?? []
                ];
            }
        }

        // Store monitoring summary
        update_option('puntwork_recent_monitoring_summary', [
            'timestamp' => time(),
            'period_hours' => 1,
            'metrics' => $recent_metrics,
            'circuit_breaker' => $cb_status
        ]);

        return [
            'period_hours' => 1,
            'metric_count' => count($recent_metrics),
            'circuit_breaker_state' => $cb_status['state']
        ];
    }

    /**
     * Handle job failure and determine retry strategy
     */
    private function handle_job_failure($job, \Exception $e) {
        if ($job['attempts'] >= $job['max_attempts']) {
            // Max attempts reached - mark as failed
            return AsyncJobStatus::FAILED;
        } else {
            // Schedule retry with exponential backoff
            $backoff_delay = min(300, pow(2, $job['attempts'] - 1) * 60); // 1min, 2min, 4min, max 5min
            $job['scheduled_at'] = time() + $backoff_delay;

            PuntWorkLogger::info('Async job scheduled for retry', PuntWorkLogger::CONTEXT_IMPORT, [
                'job_id' => $job['id'],
                'attempt' => $job['attempts'],
                'next_attempt_seconds' => $backoff_delay
            ]);

            return AsyncJobStatus::RETRY;
        }
    }

    /**
     * Update job progress
     */
    private function update_job_progress($job) {
        $queue = $this->get_queue();

        foreach ($queue as &$queue_job) {
            if ($queue_job['id'] === $job['id']) {
                $queue_job['progress'] = $job['progress'];
                break;
            }
        }

        $this->save_queue($queue);
    }

    /**
     * Schedule next job processing
     */
    private function schedule_next_job() {
        $queue = $this->get_queue();
        $next_job = null;

        foreach ($queue as $job) {
            if ($job['status'] === AsyncJobStatus::PENDING &&
                (!$next_job || $job['scheduled_at'] < $next_job['scheduled_at'])) {
                $next_job = $job;
            }
        }

        if ($next_job) {
            $delay = max(0, $next_job['scheduled_at'] - time());

            if (!wp_next_scheduled('puntwork_process_async_job')) {
                wp_schedule_single_event(time() + $delay, 'puntwork_process_async_job');
            }
        }
    }

    /**
     * Get the job queue
     */
    private function get_queue() {
        $queue = get_option($this->queue_key, []);
        return is_array($queue) ? $queue : [];
    }

    /**
     * Save the job queue
     */
    private function save_queue($queue) {
        // Clean up old completed jobs (keep last 100)
        $queue = array_filter($queue, function($job) {
            if (in_array($job['status'], [AsyncJobStatus::COMPLETED, AsyncJobStatus::FAILED])) {
                return (time() - $job['completed_at']) < 86400; // Keep for 24 hours
            }
            return true;
        });

        // Sort to keep recent jobs at the top
        usort($queue, function($a, $b) {
            return ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0);
        });

        if (count($queue) > 100) {
            $queue = array_slice($queue, 0, 100);
        }

        update_option($this->queue_key, $queue);
    }

    /**
     * Get queue status
     */
    public function get_status() {
        $queue = $this->get_queue();
        $status_counts = array_count_values(array_column($queue, 'status'));

        return [
            'total_jobs' => count($queue),
            'pending' => $status_counts[AsyncJobStatus::PENDING] ?? 0,
            'running' => $status_counts[AsyncJobStatus::RUNNING] ?? 0,
            'completed' => $status_counts[AsyncJobStatus::COMPLETED] ?? 0,
            'failed' => $status_counts[AsyncJobStatus::FAILED] ?? 0,
            'retry' => $status_counts[AsyncJobStatus::RETRY] ?? 0,
            'next_scheduled' => wp_next_scheduled('puntwork_process_async_job')
        ];
    }

    /**
     * Force cleanup of stuck jobs
     */
    public function cleanup_stuck_jobs() {
        $queue = $this->get_queue();
        $now = time();
        $cleaned = 0;

        foreach ($queue as &$job) {
            if ($job['status'] === AsyncJobStatus::RUNNING &&
                ($now - $job['started_at']) > ($this->job_timeout * 2)) {
                $job['status'] = AsyncJobStatus::FAILED;
                $job['last_error'] = 'Force cleaned - stuck running';
                $job['completed_at'] = $now;
                $cleaned++;

                PuntWorkLogger::warn('Force cleaned stuck async job', PuntWorkLogger::CONTEXT_IMPORT, [
                    'job_id' => $job['id'],
                    'operation_type' => $job['operation_type'],
                    'stuck_time' => $now - $job['started_at']
                ]);
            }
        }

        if ($cleaned > 0) {
            $this->save_queue($queue);
        }

        return $cleaned;
    }
}

/**
 * Global async job queue instance
 */
function get_async_job_queue() {
    static $queue = null;

    if ($queue === null) {
        $queue = new AsyncJobQueue();
    }

    return $queue;
}

/**
 * Schedule async cleanup after import completion
 */
function schedule_async_cleanup($import_id, $cleanup_type = 'finalization') {
    $queue = get_async_job_queue();

    $job_data = [
        'import_id' => $import_id,
        'cleanup_type' => $cleanup_type,
        'force_cleanup' => false
    ];

    // Delay cleanup by 5 seconds to ensure import completion
    $job_id = $queue->add_job(AsyncOperationType::CLEANUP_FINALIZATION, $job_data, 'high', 5);

    PuntWorkLogger::info('Scheduled async cleanup after import', PuntWorkLogger::CONTEXT_IMPORT, [
        'import_id' => $import_id,
        'cleanup_type' => $cleanup_type,
        'job_id' => $job_id
    ]);

    return $job_id;
}

/**
 * Schedule periodic maintenance tasks
 */
function schedule_maintenance_tasks() {
    $queue = get_async_job_queue();

    // Schedule health check (every 6 hours)
    if (!wp_next_scheduled('puntwork_hourly_maintenance')) {
        wp_schedule_event(time(), 'sixhours', 'puntwork_hourly_maintenance');
    }

    // Schedule metrics aggregation (daily)
    if (!wp_next_scheduled('puntwork_daily_maintenance')) {
        wp_schedule_event(time(), 'daily', 'puntwork_daily_maintenance');
    }
}

/**
 * WordPress action hooks for async processing
 */
add_action('puntwork_process_async_job', function() {
    $queue = get_async_job_queue();
    $queue->process_next_job();
});

add_action('puntwork_hourly_maintenance', function() {
    $queue = get_async_job_queue();

    // Schedule health check
    $queue->add_job(AsyncOperationType::HEALTH_CHECK, [], 'low');

    // Schedule monitoring update
    $queue->add_job(AsyncOperationType::MONITORING_UPDATE, [], 'low');
});

add_action('puntwork_daily_maintenance', function() {
    $queue = get_async_job_queue();

    // Schedule metrics aggregation
    $queue->add_job(AsyncOperationType::METRICS_AGGREGATION, ['period_days' => 7], 'low');

    // Schedule data maintenance
    $queue->add_job(AsyncOperationType::DATA_MAINTENANCE, ['maintenance_type' => 'routine'], 'low');
});

/**
 * Process a batch of jobs (for cron hook)
 */
function process_async_jobs_batch() {
    $queue = get_async_job_queue();
    $processed = 0;
    $max_batch = 5; // Process up to 5 jobs per batch

    for ($i = 0; $i < $max_batch; $i++) {
        if (!$queue->process_next_job()) {
            break; // No more jobs to process
        }
        $processed++;
    }

    if ($processed > 0) {
        PuntWorkLogger::info('Batch processed async jobs', PuntWorkLogger::CONTEXT_IMPORT, [
            'processed_count' => $processed
        ]);
    }
}

/**
 * Get async operations status
 */
function get_async_operations_status() {
    $queue = get_async_job_queue();
    $status = $queue->get_status();

    // Add additional status information
    $status['last_health_check'] = get_option('puntwork_last_health_check');
    $status['recent_monitoring'] = get_option('puntwork_recent_monitoring_summary');
    $status['circuit_breaker'] = get_option('puntwork_circuit_breaker_monitoring');

    return $status;
}

/**
 * Force run cleanup (admin function)
 */
function force_async_cleanup($cleanup_type = 'finalization', $import_id = null) {
    $queue = get_async_job_queue();

    $data = [
        'import_id' => $import_id,
        'cleanup_type' => $cleanup_type,
        'force_cleanup' => true
    ];

    $job_id = $queue->add_job(AsyncOperationType::CLEANUP_FINALIZATION, $data, 'high');

    PuntWorkLogger::info('Force scheduled async cleanup', PuntWorkLogger::CONTEXT_IMPORT, [
        'cleanup_type' => $cleanup_type,
        'import_id' => $import_id,
        'job_id' => $job_id
    ]);

    return $job_id;
}
