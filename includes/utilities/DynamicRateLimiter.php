<?php

namespace Puntwork;

/**
 * Dynamic Rate Limiting System
 *
 * Monitors system performance and automatically adjusts rate limits
 * based on server load, request patterns, and operational context.
 *
 * @since      1.0.15
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dynamic Rate Limiting class.
 */
class DynamicRateLimiter
{
    /**
     * Performance metrics storage key
     */
    const METRICS_KEY = 'puntwork_dynamic_rate_metrics';

    /**
     * Rate limit adjustments storage key
     */
    const ADJUSTMENTS_KEY = 'puntwork_dynamic_rate_adjustments';

    /**
     * Default configuration for dynamic rate limiting
     */
    private static $default_config = [
        'enabled' => true,
        'monitoring_interval' => 60, // seconds
        'adjustment_interval' => 300, // 5 minutes
        'max_adjustment_percentage' => 200, // Maximum 200% increase
        'min_adjustment_percentage' => 25, // Minimum 25% of base limit
        'cpu_threshold_high' => 80, // CPU usage percentage
        'cpu_threshold_low' => 30,
        'memory_threshold_high' => 85, // Memory usage percentage
        'memory_threshold_low' => 50,
        'response_time_threshold' => 2.0, // seconds
        'error_rate_threshold' => 10, // percentage
        'import_boost_factor' => 1.5, // Boost during imports
        'peak_hours_boost' => 1.2, // Boost during peak hours
        'off_peak_reduction' => 0.8, // Reduce during off-peak
        'peak_hours_start' => 9, // 9 AM
        'peak_hours_end' => 17, // 5 PM
    ];

    /**
     * Get dynamic rate limiting configuration
     *
     * @return array Configuration array
     */
    public static function getConfig(): array
    {
        $stored_config = get_option('puntwork_dynamic_rate_config', []);
        return array_merge(self::$default_config, $stored_config);
    }

    /**
     * Update dynamic rate limiting configuration
     *
     * @param array $config New configuration
     * @return bool Success
     */
    public static function updateConfig(array $config): bool
    {
        $current_config = self::getConfig();
        $updated_config = array_merge($current_config, $config);
        return update_option('puntwork_dynamic_rate_config', $updated_config);
    }

    /**
     * Record performance metrics
     *
     * @param string $action    Action name
     * @param array  $metrics   Performance metrics
     */
    public static function recordMetrics(string $action, array $metrics): void
    {
        $config = self::getConfig();
        if (!$config['enabled']) {
            return;
        }

        $timestamp = time();
        $stored_metrics = get_option(self::METRICS_KEY, []);

        // Clean old metrics (keep last 24 hours)
        $cutoff_time = $timestamp - 86400;
        $stored_metrics = array_filter($stored_metrics, function($metric) use ($cutoff_time) {
            return $metric['timestamp'] >= $cutoff_time;
        });

        // Add new metrics
        $metric_data = array_merge($metrics, [
            'action' => $action,
            'timestamp' => $timestamp,
            'server_load' => self::getServerLoad(),
            'memory_usage' => self::getMemoryUsage(),
            'cpu_usage' => self::getCpuUsage(),
        ]);

        $stored_metrics[] = $metric_data;

        // Keep only last 1000 metrics to prevent bloat
        if (count($stored_metrics) > 1000) {
            $stored_metrics = array_slice($stored_metrics, -1000);
        }

        update_option(self::METRICS_KEY, $stored_metrics);
    }

    /**
     * Get current server load average
     *
     * @return float Load average (1-minute)
     */
    private static function getServerLoad(): float
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return $load[0] ?? 0.0;
        }

        // Fallback: estimate based on active processes
        $active_processes = shell_exec('ps aux | wc -l');
        $active_processes = intval(trim($active_processes));
        return min($active_processes / 100, 10.0); // Rough estimation
    }

    /**
     * Get current memory usage percentage
     *
     * @return float Memory usage percentage
     */
    private static function getMemoryUsage(): float
    {
        $memory_limit = self::getMemoryLimitBytes();
        $current_usage = memory_get_peak_usage(true);

        if ($memory_limit > 0) {
            return ($current_usage / $memory_limit) * 100;
        }

        return 0.0;
    }

    /**
     * Get memory limit in bytes
     *
     * @return int Memory limit in bytes
     */
    private static function getMemoryLimitBytes(): int
    {
        $memory_limit = ini_get('memory_limit');

        if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
            $value = (int)$matches[1];
            $unit = strtolower($matches[2]);

            switch ($unit) {
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

            return $value;
        }

        return 128 * 1024 * 1024; // Default 128MB
    }

    /**
     * Get current CPU usage percentage
     *
     * @return float CPU usage percentage
     */
    private static function getCpuUsage(): float
    {
        static $last_cpu_info = null;
        static $last_time = null;

        $current_time = microtime(true);

        if (PHP_OS_FAMILY === 'Linux') {
            $cpu_info = self::getLinuxCpuInfo();

            if ($last_cpu_info !== null && $last_time !== null) {
                $time_diff = $current_time - $last_time;
                if ($time_diff > 0) {
                    $cpu_diff = $cpu_info['total'] - $last_cpu_info['total'];
                    $idle_diff = $cpu_info['idle'] - $last_cpu_info['idle'];

                    if ($cpu_diff > 0) {
                        $cpu_usage = 100 * ($cpu_diff - $idle_diff) / $cpu_diff;
                        return max(0, min(100, $cpu_usage));
                    }
                }
            }

            $last_cpu_info = $cpu_info;
            $last_time = $current_time;
        }

        // Fallback: return 0 or estimate based on load
        return self::getServerLoad() * 10; // Rough estimation
    }

    /**
     * Get Linux CPU information from /proc/stat
     *
     * @return array CPU statistics
     */
    private static function getLinuxCpuInfo(): array
    {
        $cpu_line = shell_exec('head -n 1 /proc/stat 2>/dev/null');
        if (!$cpu_line) {
            return ['total' => 0, 'idle' => 0];
        }

        $cpu_stats = preg_split('/\s+/', trim($cpu_line));
        if (count($cpu_stats) < 8) {
            return ['total' => 0, 'idle' => 0];
        }

        // Remove 'cpu' label
        array_shift($cpu_stats);

        $user = (int)$cpu_stats[0];
        $nice = (int)$cpu_stats[1];
        $system = (int)$cpu_stats[2];
        $idle = (int)$cpu_stats[3];
        $iowait = (int)$cpu_stats[4];
        $irq = (int)$cpu_stats[5];
        $softirq = (int)$cpu_stats[6];

        $total = $user + $nice + $system + $idle + $iowait + $irq + $softirq;

        return [
            'total' => $total,
            'idle' => $idle,
        ];
    }

    /**
     * Calculate dynamic rate limit adjustments
     *
     * @param string $action Action name
     * @return array Adjustment factors
     */
    public static function calculateAdjustments(string $action): array
    {
        $config = self::getConfig();
        if (!$config['enabled']) {
            return ['multiplier' => 1.0, 'reason' => 'disabled'];
        }

        $metrics = self::getRecentMetrics($action, 300); // Last 5 minutes
        if (empty($metrics)) {
            return ['multiplier' => 1.0, 'reason' => 'no_metrics'];
        }

        $multiplier = 1.0;
        $reasons = [];

        // Server performance factors
        $avg_cpu = array_sum(array_column($metrics, 'cpu_usage')) / count($metrics);
        $avg_memory = array_sum(array_column($metrics, 'memory_usage')) / count($metrics);
        $avg_load = array_sum(array_column($metrics, 'server_load')) / count($metrics);

        // CPU-based adjustment
        if ($avg_cpu > $config['cpu_threshold_high']) {
            $cpu_factor = max(0.5, 1.0 - (($avg_cpu - $config['cpu_threshold_high']) / 50));
            $multiplier *= $cpu_factor;
            $reasons[] = "high_cpu_{$avg_cpu}%";
        } elseif ($avg_cpu < $config['cpu_threshold_low']) {
            $cpu_factor = min(2.0, 1.0 + (($config['cpu_threshold_low'] - $avg_cpu) / 50));
            $multiplier *= $cpu_factor;
            $reasons[] = "low_cpu_{$avg_cpu}%";
        }

        // Memory-based adjustment
        if ($avg_memory > $config['memory_threshold_high']) {
            $memory_factor = max(0.5, 1.0 - (($avg_memory - $config['memory_threshold_high']) / 35));
            $multiplier *= $memory_factor;
            $reasons[] = "high_memory_{$avg_memory}%";
        } elseif ($avg_memory < $config['memory_threshold_low']) {
            $memory_factor = min(2.0, 1.0 + (($config['memory_threshold_low'] - $avg_memory) / 40));
            $multiplier *= $memory_factor;
            $reasons[] = "low_memory_{$avg_memory}%";
        }

        // Load-based adjustment
        if ($avg_load > 5.0) {
            $load_factor = max(0.6, 1.0 - (($avg_load - 5.0) / 10));
            $multiplier *= $load_factor;
            $reasons[] = "high_load_{$avg_load}";
        } elseif ($avg_load < 1.0) {
            $load_factor = min(1.5, 1.0 + ((1.0 - $avg_load) / 2));
            $multiplier *= $load_factor;
            $reasons[] = "low_load_{$avg_load}";
        }

        // Response time factors
        if (isset($metrics[0]['response_time'])) {
            $avg_response_time = array_sum(array_column($metrics, 'response_time')) / count($metrics);
            if ($avg_response_time > $config['response_time_threshold']) {
                $response_factor = max(0.7, 1.0 - (($avg_response_time - $config['response_time_threshold']) / 2));
                $multiplier *= $response_factor;
                $reasons[] = "slow_response_{$avg_response_time}s";
            }
        }

        // Error rate factors
        $error_count = count(array_filter($metrics, function($m) { return isset($m['is_error']) && $m['is_error']; }));
        $error_rate = (count($metrics) > 0) ? ($error_count / count($metrics)) * 100 : 0;

        if ($error_rate > $config['error_rate_threshold']) {
            $error_factor = max(0.6, 1.0 - (($error_rate - $config['error_rate_threshold']) / 20));
            $multiplier *= $error_factor;
            $reasons[] = "high_errors_{$error_rate}%";
        }

        // Time-based factors
        $current_hour = (int)date('H');
        $is_peak_hours = $current_hour >= $config['peak_hours_start'] && $current_hour <= $config['peak_hours_end'];

        if ($is_peak_hours) {
            $multiplier *= $config['peak_hours_boost'];
            $reasons[] = 'peak_hours';
        } else {
            $multiplier *= $config['off_peak_reduction'];
            $reasons[] = 'off_peak';
        }

        // Import operation boost
        if (self::isImportOperation($action)) {
            $multiplier *= $config['import_boost_factor'];
            $reasons[] = 'import_operation';
        }

        // Apply bounds
        $multiplier = max(
            $config['min_adjustment_percentage'] / 100,
            min($config['max_adjustment_percentage'] / 100, $multiplier)
        );

        return [
            'multiplier' => $multiplier,
            'reason' => implode(',', $reasons),
            'metrics' => [
                'avg_cpu' => round($avg_cpu, 1),
                'avg_memory' => round($avg_memory, 1),
                'avg_load' => round($avg_load, 2),
                'error_rate' => round($error_rate, 1),
                'sample_count' => count($metrics),
            ]
        ];
    }

    /**
     * Check if action is related to import operations
     *
     * @param string $action Action name
     * @return bool True if import operation
     */
    private static function isImportOperation(string $action): bool
    {
        $import_actions = [
            'run_job_import_batch',
            'process_feed',
            'combine_jsonl',
            'get_job_import_status',
        ];

        return in_array($action, $import_actions) ||
               strpos($action, 'import') !== false ||
               strpos($action, 'feed') !== false;
    }

    /**
     * Get recent metrics for an action
     *
     * @param string $action     Action name
     * @param int    $time_range Time range in seconds
     * @return array Recent metrics
     */
    private static function getRecentMetrics(string $action, int $time_range = 300): array
    {
        $stored_metrics = get_option(self::METRICS_KEY, []);
        $cutoff_time = time() - $time_range;

        return array_filter($stored_metrics, function($metric) use ($action, $cutoff_time) {
            return $metric['action'] === $action && $metric['timestamp'] >= $cutoff_time;
        });
    }

    /**
     * Apply dynamic rate limiting to a request
     *
     * @param string $action Action name
     * @return array|WP_Error Rate limit result or error
     */
    public static function applyDynamicRateLimit(string $action)
    {
        $config = self::getConfig();
        if (!$config['enabled']) {
            // Fall back to static rate limiting
            return SecurityUtils::checkRateLimit($action);
        }

        // Get base configuration
        $base_config = SecurityUtils::getRateLimitConfig($action);

        // Calculate dynamic adjustments
        $adjustments = self::calculateAdjustments($action);

        // Apply adjustments to base limits
        $dynamic_max_requests = (int)round($base_config['max_requests'] * $adjustments['multiplier']);
        $dynamic_time_window = $base_config['time_window']; // Keep time window static

        // Ensure minimum limits
        $dynamic_max_requests = max(1, $dynamic_max_requests);

        // Log adjustments for monitoring
        if ($adjustments['multiplier'] != 1.0) {
            PuntWorkLogger::debug(
                "Dynamic rate limit adjustment for {$action}",
                PuntWorkLogger::CONTEXT_SECURITY,
                [
                    'base_requests' => $base_config['max_requests'],
                    'dynamic_requests' => $dynamic_max_requests,
                    'multiplier' => $adjustments['multiplier'],
                    'reason' => $adjustments['reason'],
                    'metrics' => $adjustments['metrics'],
                ]
            );
        }

        // Check rate limit with dynamic values
        $result = SecurityUtils::checkRateLimit($action, $dynamic_max_requests, $dynamic_time_window);

        // Record metrics for this request
        $is_error = is_wp_error($result);
        self::recordMetrics($action, [
            'is_error' => $is_error,
            'response_time' => 0, // Will be set by caller if available
            'dynamic_limit' => $dynamic_max_requests,
            'base_limit' => $base_config['max_requests'],
            'multiplier' => $adjustments['multiplier'],
        ]);

        return $result;
    }

    /**
     * Get dynamic rate limiting status
     *
     * @return array Status information
     */
    public static function getStatus(): array
    {
        $config = self::getConfig();
        $metrics = get_option(self::METRICS_KEY, []);
        $adjustments = get_option(self::ADJUSTMENTS_KEY, []);

        $recent_metrics = array_filter($metrics, function($m) {
            return $m['timestamp'] >= (time() - 3600); // Last hour
        });

        return [
            'enabled' => $config['enabled'],
            'config' => $config,
            'total_metrics' => count($metrics),
            'recent_metrics' => count($recent_metrics),
            'current_load' => self::getServerLoad(),
            'current_memory' => self::getMemoryUsage(),
            'current_cpu' => self::getCpuUsage(),
            'last_updated' => time(),
        ];
    }

    /**
     * Reset dynamic rate limiting data
     *
     * @return bool Success
     */
    public static function reset(): bool
    {
        delete_option(self::METRICS_KEY);
        delete_option(self::ADJUSTMENTS_KEY);

        return true;
    }

    /**
     * Initialize dynamic rate limiting system
     */
    public static function init(): void
    {
        // Schedule cleanup of old metrics
        if (!wp_next_scheduled('puntwork_cleanup_dynamic_rate_metrics')) {
            wp_schedule_event(time(), 'daily', 'puntwork_cleanup_dynamic_rate_metrics');
        }

        add_action('puntwork_cleanup_dynamic_rate_metrics', [self::class, 'cleanupOldMetrics']);
    }

    /**
     * Clean up old metrics data
     */
    public static function cleanupOldMetrics(): void
    {
        $metrics = get_option(self::METRICS_KEY, []);
        $cutoff_time = time() - (7 * 24 * 3600); // Keep 7 days

        $cleaned_metrics = array_filter($metrics, function($metric) use ($cutoff_time) {
            return $metric['timestamp'] >= $cutoff_time;
        });

        update_option(self::METRICS_KEY, $cleaned_metrics);

        PuntWorkLogger::info(
            'Cleaned up dynamic rate limiting metrics',
            PuntWorkLogger::CONTEXT_SECURITY,
            ['removed' => count($metrics) - count($cleaned_metrics)]
        );
    }
}

// Initialize the dynamic rate limiter
DynamicRateLimiter::init();