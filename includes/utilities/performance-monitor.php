<?php

/**
 * Performance monitoring and benchmarking utilities
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.9
 */

namespace Puntwork;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced caching utility with Redis support
 */
class CacheManager
{
    /**
     * Cache group for mappings
     */
    const GROUP_MAPPINGS = 'puntwork_mappings';

    /**
     * Cache group for analytics
     */
    const GROUP_ANALYTICS = 'puntwork_analytics';

    /**
     * Check if Redis/Object Cache is available
     *
     * @return bool True if Redis/Object Cache is available
     */
    public static function is_redis_available(): bool
    {
        return function_exists('wp_cache_get') && wp_cache_get('test_redis_connection', 'puntwork_test') === false;
    }

    /**
     * Get cached data with Redis support
     *
     * @param string $key Cache key
     * @param string $group Cache group
     * @return mixed Cached data or false
     */
    public static function get(string $key, string $group = '')
    {
        // Try Redis/Object Cache first
        if (self::is_redis_available()) {
            $cached = wp_cache_get($key, $group);
            if ($cached !== false) {
                return $cached;
            }
        }

        // Fallback to transients
        $transient_key = $group ? $group . '_' . $key : $key;
        return get_transient($transient_key);
    }

    /**
     * Set cached data with Redis support
     *
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param string $group Cache group
     * @param int $expiration Expiration time in seconds
     * @return bool True on success
     */
    public static function set(string $key, $data, string $group = '', int $expiration = 3600): bool
    {
        // Try Redis/Object Cache first
        if (self::is_redis_available()) {
            $result = wp_cache_set($key, $data, $group, $expiration);
            if ($result) {
                return true;
            }
        }

        // Fallback to transients
        $transient_key = $group ? $group . '_' . $key : $key;
        return set_transient($transient_key, $data, $expiration);
    }

    /**
     * Delete cached data
     *
     * @param string $key Cache key
     * @param string $group Cache group
     * @return bool True on success
     */
    public static function delete(string $key, string $group = ''): bool
    {
        // Try Redis/Object Cache first
        if (self::is_redis_available()) {
            wp_cache_delete($key, $group);
        }

        // Also clear transients
        $transient_key = $group ? $group . '_' . $key : $key;
        return delete_transient($transient_key);
    }

    /**
     * Clear all cache in a group
     *
     * @param string $group Cache group
     * @return bool True on success
     */
    public static function clear_group(string $group): bool
    {
        if (self::is_redis_available()) {
            // For Redis, we can't easily clear a group, so we'll flush the entire cache
            // This is a limitation of the WordPress object cache API
            wp_cache_flush();
        }

        // Clear transients with group prefix
        global $wpdb;
        $transient_prefix = '_transient_' . $group . '_';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $transient_prefix . '%'
        ));

        return true;
    }

    /**
     * Get cache stats
     *
     * @return array Cache statistics
     */
    public static function get_stats(): array
    {
        return [
            'redis_available' => self::is_redis_available(),
            'cache_groups' => [self::GROUP_MAPPINGS, self::GROUP_ANALYTICS],
            'wp_cache_supports_groups' => function_exists('wp_cache_supports') ? wp_cache_supports('groups') : false,
        ];
    }
}

/**
 * Performance monitoring class
 */
class PerformanceMonitor
{
    /**
     * Start time for current measurement
     */
    private static $start_time = null;

    /**
     * Memory usage at start
     */
    private static $start_memory = null;

    /**
     * Performance metrics storage
     */
    private static $metrics = [];

    /**
     * Start performance monitoring
     *
     * @param string $operation Operation name
     * @return string Measurement ID
     */
    public static function start(string $operation): string
    {
        $id = $operation . '_' . microtime(true);
        self::$start_time = microtime(true);
        self::$start_memory = memory_get_usage(true);

        self::$metrics[$id] = [
            'operation' => $operation,
            'start_time' => self::$start_time,
            'start_memory' => self::$start_memory,
            'start_peak_memory' => memory_get_peak_usage(true),
            'checkpoints' => []
        ];

        return $id;
    }

    /**
     * Add a checkpoint during monitoring
     *
     * @param string $id Measurement ID
     * @param string $checkpoint Checkpoint name
     * @param array $data Additional data
     */
    public static function checkpoint(string $id, string $checkpoint, array $data = []): void
    {
        if (!isset(self::$metrics[$id])) {
            return;
        }

        $current_time = microtime(true);
        $current_memory = memory_get_usage(true);
        $elapsed = $current_time - self::$metrics[$id]['start_time'];
        $memory_used = $current_memory - self::$metrics[$id]['start_memory'];

        self::$metrics[$id]['checkpoints'][] = [
            'name' => $checkpoint,
            'time' => $current_time,
            'elapsed' => $elapsed,
            'memory_current' => $current_memory,
            'memory_used' => $memory_used,
            'memory_peak' => memory_get_peak_usage(true),
            'data' => $data
        ];
    }

    /**
     * End performance monitoring
     *
     * @param string $id Measurement ID
     * @return array Performance data
     */
    public static function end(string $id): array
    {
        if (!isset(self::$metrics[$id])) {
            return [];
        }

        $end_time = microtime(true);
        $end_memory = memory_get_usage(true);
        $total_time = $end_time - self::$metrics[$id]['start_time'];
        $total_memory = $end_memory - self::$metrics[$id]['start_memory'];

        $result = self::$metrics[$id];
        $result['end_time'] = $end_time;
        $result['end_memory'] = $end_memory;
        $result['total_time'] = $total_time;
        $result['total_memory_used'] = $total_memory;
        $result['peak_memory'] = memory_get_peak_usage(true);
        $result['memory_limit'] = self::get_memory_limit_bytes();
        $result['php_version'] = PHP_VERSION;
        $result['wordpress_version'] = get_bloginfo('version');

        // Calculate rates if we have item counts
        if (isset($result['checkpoints']) && count($result['checkpoints']) > 0) {
            $last_checkpoint = end($result['checkpoints']);
            if (isset($last_checkpoint['data']['items_processed'])) {
                $result['items_per_second'] = $last_checkpoint['data']['items_processed'] / $total_time;
            }
        }

        // Store in database for historical tracking
        self::store_performance_data($result);

        // Clean up
        unset(self::$metrics[$id]);

        return $result;
    }

    /**
     * Get current performance snapshot
     *
     * @return array Current performance data
     */
    public static function snapshot(): array
    {
        return [
            'memory_current' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'memory_limit' => self::get_memory_limit_bytes(),
            'time' => microtime(true),
            'load_average' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version')
        ];
    }

    /**
     * Get memory limit in bytes
     *
     * @return int Memory limit in bytes
     */
    private static function get_memory_limit_bytes(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);

        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int) $limit;
        }
    }

    /**
     * Store performance data in database
     *
     * @param array $data Performance data
     */
    private static function store_performance_data(array $data): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'puntwork_performance_logs';

        // Create table if it doesn't exist
        self::create_performance_table();

        $wpdb->insert(
            $table_name,
            [
                'operation' => $data['operation'],
                'total_time' => $data['total_time'],
                'total_memory_used' => $data['total_memory_used'],
                'peak_memory' => $data['peak_memory'],
                'items_per_second' => $data['items_per_second'] ?? null,
                'checkpoints' => json_encode($data['checkpoints']),
                'metadata' => json_encode([
                    'php_version' => $data['php_version'],
                    'wordpress_version' => $data['wordpress_version'],
                    'memory_limit' => $data['memory_limit']
                ]),
                'created_at' => current_time('mysql')
            ],
            ['%s', '%f', '%d', '%d', '%f', '%s', '%s', '%s']
        );
    }

    /**
     * Create performance logs table
     */
    private static function create_performance_table(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'puntwork_performance_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            operation varchar(100) NOT NULL,
            total_time float NOT NULL,
            total_memory_used bigint(20) NOT NULL,
            peak_memory bigint(20) NOT NULL,
            items_per_second float DEFAULT NULL,
            checkpoints longtext,
            metadata longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY operation_time (operation, created_at),
            KEY total_time (total_time)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Get performance statistics
     *
     * @param string $operation Operation name (optional)
     * @param int $days Number of days to look back
     * @return array Performance statistics
     */
    public static function get_statistics(?string $operation = '', int $days = 30): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'puntwork_performance_logs';

        $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)";
        if ($operation) {
            $where .= $wpdb->prepare(" AND operation = %s", $operation);
        }

        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as total_runs,
                AVG(total_time) as avg_time,
                MIN(total_time) as min_time,
                MAX(total_time) as max_time,
                AVG(total_memory_used) as avg_memory,
                MAX(peak_memory) as max_peak_memory,
                AVG(items_per_second) as avg_items_per_second
            FROM $table_name
            $where
        "));

        if (!$stats) {
            return [];
        }

        return [
            'total_runs' => (int) $stats->total_runs,
            'avg_time_seconds' => round((float) $stats->avg_time, 3),
            'min_time_seconds' => round((float) $stats->min_time, 3),
            'max_time_seconds' => round((float) $stats->max_time, 3),
            'avg_memory_mb' => round((float) $stats->avg_memory / 1024 / 1024, 2),
            'max_peak_memory_mb' => round((float) $stats->max_peak_memory / 1024 / 1024, 2),
            'avg_items_per_second' => $stats->avg_items_per_second ? round((float) $stats->avg_items_per_second, 2) : null,
            'period_days' => $days
        ];
    }

    /**
     * Clean up old performance logs
     *
     * @param int $days_retention Days to keep logs
     */
    public static function cleanup_old_logs(int $days_retention = 90): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'puntwork_performance_logs';

        $wpdb->query($wpdb->prepare("
            DELETE FROM $table_name
            WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
        ", $days_retention));
    }
}

/**
 * Database query performance monitor
 */
class DatabasePerformanceMonitor
{
    private static array $query_log = [];
    private static float $start_time = 0.0;

    /**
     * Start monitoring database queries
     */
    public static function start(): void
    {
        self::$query_log = [];
        self::$start_time = microtime(true);

        // Hook into WordPress database queries
        add_filter('query', [__CLASS__, 'log_query']);
        add_filter('get_col', [__CLASS__, 'log_query']);
        add_filter('get_row', [__CLASS__, 'log_query']);
        add_filter('get_results', [__CLASS__, 'log_query']);
    }

    /**
     * Log a database query
     *
     * @param string $query The SQL query
     * @return string The query (unchanged)
     */
    public static function log_query(string $query): string
    {
        $query_start = microtime(true);
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

        // Store query info
        self::$query_log[] = [
            'query' => $query,
            'start_time' => $query_start,
            'backtrace' => $backtrace,
            'query_type' => self::get_query_type($query)
        ];

        return $query;
    }

    /**
     * End monitoring and return statistics
     *
     * @return array Query performance statistics
     */
    public static function end(): array
    {
        $end_time = microtime(true);
        $total_time = $end_time - self::$start_time;

        // Remove hooks
        remove_filter('query', [__CLASS__, 'log_query']);
        remove_filter('get_col', [__CLASS__, 'log_query']);
        remove_filter('get_row', [__CLASS__, 'log_query']);
        remove_filter('get_results', [__CLASS__, 'log_query']);

        $query_count = count(self::$query_log);
        $slow_queries = array_filter(self::$query_log, fn($q) => ($q['start_time'] ?? 0) > 0.1); // Queries > 100ms

        return [
            'total_queries' => $query_count,
            'total_time' => round($total_time, 4),
            'avg_query_time' => $query_count > 0 ? round($total_time / $query_count, 4) : 0,
            'slow_queries_count' => count($slow_queries),
            'slow_queries' => array_slice($slow_queries, 0, 10), // Top 10 slow queries
            'query_types' => self::analyze_query_types()
        ];
    }

    /**
     * Get query type from SQL
     */
    private static function get_query_type(string $query): string
    {
        $query = strtoupper(trim($query));
        if (strpos($query, 'SELECT') === 0) return 'SELECT';
        if (strpos($query, 'INSERT') === 0) return 'INSERT';
        if (strpos($query, 'UPDATE') === 0) return 'UPDATE';
        if (strpos($query, 'DELETE') === 0) return 'DELETE';
        return 'OTHER';
    }

    /**
     * Analyze query types distribution
     */
    private static function analyze_query_types(): array
    {
        $types = [];
        foreach (self::$query_log as $query) {
            $type = $query['query_type'];
            $types[$type] = ($types[$type] ?? 0) + 1;
        }
        return $types;
    }
}

/**
 * Memory management utilities for large imports
 */
class MemoryManager
{
    private static int $gc_threshold = 100; // Run GC every 100 items
    private static int $processed_count = 0;
    private static int $last_gc_run = 0;

    /**
     * Check and manage memory usage during batch processing
     *
     * @param int $current_index Current processing index
     * @param float $threshold Memory threshold (0-1)
     * @return array Memory management actions taken
     */
    public static function check_memory_usage(int $current_index, float $threshold = 0.8): array
    {
        $actions = [];
        $memory_usage = memory_get_usage(true);
        $memory_limit = self::get_memory_limit_bytes();
        $memory_ratio = $memory_usage / $memory_limit;

        self::$processed_count++;

        // Force garbage collection periodically
        if (self::$processed_count - self::$last_gc_run >= self::$gc_threshold) {
            gc_collect_cycles();
            self::$last_gc_run = self::$processed_count;
            $actions[] = 'garbage_collection';
        }

        // Memory pressure detected
        if ($memory_ratio > $threshold) {
            // Aggressive cleanup
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
                $actions[] = 'cache_flush';
            }

            // Force immediate GC
            gc_collect_cycles();
            $actions[] = 'forced_gc';

            // Clear any large static caches if they exist
            if (isset($GLOBALS['wp_object_cache']) && method_exists($GLOBALS['wp_object_cache'], 'flush')) {
                $GLOBALS['wp_object_cache']->flush();
                $actions[] = 'object_cache_flush';
            }
        }

        return [
            'memory_usage_mb' => round($memory_usage / 1024 / 1024, 2),
            'memory_limit_mb' => round($memory_limit / 1024 / 1024, 2),
            'memory_ratio' => round($memory_ratio, 3),
            'actions_taken' => $actions
        ];
    }

    /**
     * Optimize memory for large batch operations
     */
    public static function optimize_for_large_batch(): void
    {
        // Increase GC threshold to reduce collection frequency
        gc_mem_caches();

        // Disable some WordPress features that consume memory
        if (!defined('WP_DISABLE_FATAL_ERROR_HANDLER')) {
            define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
        }

        // Reduce autoload overhead for known classes
        if (function_exists('spl_autoload_register')) {
            // Preload critical classes if needed
        }
    }

    /**
     * Get memory limit in bytes
     */
    private static function get_memory_limit_bytes(): int
    {
        $limit = ini_get('memory_limit');
        if (preg_match('/^(\d+)(.)$/', $limit, $matches)) {
            $value = (int) $matches[1];
            $unit = strtoupper($matches[2]);
            switch ($unit) {
                case 'G': return $value * 1024 * 1024 * 1024;
                case 'M': return $value * 1024 * 1024;
                case 'K': return $value * 1024;
                default: return $value;
            }
        }
        return 128 * 1024 * 1024; // Default 128MB
    }

    /**
     * Reset memory manager state
     */
    public static function reset(): void
    {
        self::$processed_count = 0;
        self::$last_gc_run = 0;
    }
}

/**
 * Circuit breaker pattern for feed processing reliability
 */
class CircuitBreaker
{
    private static array $circuits = [];
    const STATE_CLOSED = 'closed';     // Normal operation
    const STATE_OPEN = 'open';         // Failing, reject requests
    const STATE_HALF_OPEN = 'half_open'; // Testing if service recovered

    /**
     * Check if circuit is closed (allow request)
     *
     * @param string $circuit_name Circuit identifier
     * @return bool True if request should proceed
     */
    public static function can_proceed(string $circuit_name): bool
    {
        $circuit = self::get_circuit_state($circuit_name);

        switch ($circuit['state']) {
            case self::STATE_CLOSED:
                return true;
            case self::STATE_OPEN:
                // Check if timeout has passed
                if (time() - $circuit['last_failure'] > $circuit['timeout']) {
                    self::$circuits[$circuit_name]['state'] = self::STATE_HALF_OPEN;
                    return true; // Allow one test request
                }
                return false;
            case self::STATE_HALF_OPEN:
                return true; // Allow test request
            default:
                return true;
        }
    }

    /**
     * Record successful operation
     *
     * @param string $circuit_name Circuit identifier
     */
    public static function record_success(string $circuit_name): void
    {
        if (!isset(self::$circuits[$circuit_name])) {
            self::init_circuit($circuit_name);
        }

        $circuit = &self::$circuits[$circuit_name];

        if ($circuit['state'] === self::STATE_HALF_OPEN) {
            // Service recovered, close circuit
            $circuit['state'] = self::STATE_CLOSED;
            $circuit['failure_count'] = 0;
        }
    }

    /**
     * Record failed operation
     *
     * @param string $circuit_name Circuit identifier
     */
    public static function record_failure(string $circuit_name): void
    {
        if (!isset(self::$circuits[$circuit_name])) {
            self::init_circuit($circuit_name);
        }

        $circuit = &self::$circuits[$circuit_name];
        $circuit['failure_count']++;
        $circuit['last_failure'] = time();

        // Open circuit if failure threshold reached
        if ($circuit['failure_count'] >= $circuit['failure_threshold']) {
            $circuit['state'] = self::STATE_OPEN;
        }
    }

    /**
     * Get circuit state
     *
     * @param string $circuit_name Circuit identifier
     * @return array Circuit state data
     */
    private static function get_circuit_state(string $circuit_name): array
    {
        if (!isset(self::$circuits[$circuit_name])) {
            self::init_circuit($circuit_name);
        }
        return self::$circuits[$circuit_name];
    }

    /**
     * Initialize circuit state
     *
     * @param string $circuit_name Circuit identifier
     */
    private static function init_circuit(string $circuit_name): void
    {
        self::$circuits[$circuit_name] = [
            'state' => self::STATE_CLOSED,
            'failure_count' => 0,
            'failure_threshold' => 5, // Open after 5 failures
            'timeout' => 300, // 5 minutes timeout
            'last_failure' => 0
        ];
    }

    /**
     * Get all circuit states (for monitoring)
     *
     * @return array All circuit states
     */
    public static function get_all_states(): array
    {
        return self::$circuits;
    }
}

/**
 * Check if feed processing can proceed
 *
 * @param string $feed_url Feed URL
 * @return bool True if processing should proceed
 */
function can_process_feed(string $feed_url): bool
{
    $circuit_name = 'feed_' . md5($feed_url);
    return CircuitBreaker::can_proceed($circuit_name);
}

/**
 * Record feed processing success
 *
 * @param string $feed_url Feed URL
 */
function record_feed_success(string $feed_url): void
{
    $circuit_name = 'feed_' . md5($feed_url);
    CircuitBreaker::record_success($circuit_name);
}

/**
 * Record feed processing failure
 *
 * @param string $feed_url Feed URL
 */
function record_feed_failure(string $feed_url): void
{
    $circuit_name = 'feed_' . md5($feed_url);
    CircuitBreaker::record_failure($circuit_name);
}

/**
 * Get circuit breaker status for monitoring
 *
 * @return array Circuit states
 */
function get_circuit_breaker_status(): array
{
    return CircuitBreaker::get_all_states();
}

/**
 * Start performance monitoring for import operations
 *
 * @param string $operation Operation name
 * @return string Measurement ID
 */
function start_performance_monitoring(string $operation): string
{
    return PerformanceMonitor::start($operation);
}

/**
 * Add checkpoint to performance monitoring
 *
 * @param string $id Measurement ID
 * @param string $checkpoint Checkpoint name
 * @param array $data Additional data
 */
function checkpoint_performance(string $id, string $checkpoint, array $data = []): void
{
    PerformanceMonitor::checkpoint($id, $checkpoint, $data);
}

/**
 * End performance monitoring
 *
 * @param string $id Measurement ID
 * @return array Performance data
 */
function end_performance_monitoring(string $id): array
{
    return PerformanceMonitor::end($id);
}

/**
 * Get current performance snapshot
 *
 * @return array Current performance data
 */
function get_performance_snapshot(): array
{
    return PerformanceMonitor::snapshot();
}

/**
 * Get performance statistics
 *
 * @param string $operation Operation name (optional)
 * @param int $days Number of days to look back
 * @return array Performance statistics
 */
function get_performance_statistics(?string $operation = '', int $days = 30): array
{
    return PerformanceMonitor::get_statistics($operation ?? '', $days);
}

/**
 * Start database performance monitoring
 */
function start_db_performance_monitoring(): void
{
    DatabasePerformanceMonitor::start();
}

/**
 * End database performance monitoring
 *
 * @return array Database performance statistics
 */
function end_db_performance_monitoring(): array
{
    return DatabasePerformanceMonitor::end();
}

/**
 * Check memory usage during batch processing
 *
 * @param int $current_index Current processing index
 * @param float $threshold Memory threshold
 * @return array Memory status
 */
function check_batch_memory_usage(int $current_index, float $threshold = 0.8): array
{
    return MemoryManager::check_memory_usage($current_index, $threshold);
}

/**
 * Optimize memory for large batch operations
 */
function optimize_memory_for_batch(): void
{
    MemoryManager::optimize_for_large_batch();
}

/**
 * Reset memory manager
 */
function reset_memory_manager(): void
{
    MemoryManager::reset();
}
