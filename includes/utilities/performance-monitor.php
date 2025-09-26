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
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Performance monitoring class
 */
class PerformanceMonitor {

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
    public static function start(string $operation): string {
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
    public static function checkpoint(string $id, string $checkpoint, array $data = []): void {
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
    public static function end(string $id): array {
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
    public static function snapshot(): array {
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
    private static function get_memory_limit_bytes(): int {
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
    private static function store_performance_data(array $data): void {
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
    private static function create_performance_table(): void {
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
    public static function get_statistics(string $operation = '', int $days = 30): array {
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
    public static function cleanup_old_logs(int $days_retention = 90): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'puntwork_performance_logs';

        $wpdb->query($wpdb->prepare("
            DELETE FROM $table_name
            WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
        ", $days_retention));
    }
}

/**
 * Start performance monitoring for import operations
 *
 * @param string $operation Operation name
 * @return string Measurement ID
 */
function start_performance_monitoring(string $operation): string {
    return PerformanceMonitor::start($operation);
}

/**
 * Add checkpoint to performance monitoring
 *
 * @param string $id Measurement ID
 * @param string $checkpoint Checkpoint name
 * @param array $data Additional data
 */
function checkpoint_performance(string $id, string $checkpoint, array $data = []): void {
    PerformanceMonitor::checkpoint($id, $checkpoint, $data);
}

/**
 * End performance monitoring
 *
 * @param string $id Measurement ID
 * @return array Performance data
 */
function end_performance_monitoring(string $id): array {
    return PerformanceMonitor::end($id);
}

/**
 * Get current performance snapshot
 *
 * @return array Current performance data
 */
function get_performance_snapshot(): array {
    return PerformanceMonitor::snapshot();
}

/**
 * Get performance statistics
 *
 * @param string $operation Operation name (optional)
 * @param int $days Number of days to look back
 * @return array Performance statistics
 */
function get_performance_statistics(string $operation = '', int $days = 30): array {
    return PerformanceMonitor::get_statistics($operation, $days);
}