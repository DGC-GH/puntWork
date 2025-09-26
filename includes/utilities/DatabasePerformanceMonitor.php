<?php

/**
 * Database query performance monitor
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.9
 */

namespace Puntwork\Utilities;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
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
    public static function logQuery(string $query): string
    {
        $query_start = microtime(true);
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

        // Store query info
        self::$query_log[] = [
            'query' => $query,
            'start_time' => $query_start,
            'backtrace' => $backtrace,
            'query_type' => self::getQueryType($query)
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
            'query_types' => self::analyzeQueryTypes()
        ];
    }

    /**
     * Get query type from SQL
     */
    private static function getQueryType(string $query): string
    {
        $query = strtoupper(trim($query));
        if (strpos($query, 'SELECT') === 0) {
            return 'SELECT';
        }
        if (strpos($query, 'INSERT') === 0) {
            return 'INSERT';
        }
        if (strpos($query, 'UPDATE') === 0) {
            return 'UPDATE';
        }
        if (strpos($query, 'DELETE') === 0) {
            return 'DELETE';
        }
        return 'OTHER';
    }

    /**
     * Analyze query types distribution
     */
    private static function analyzeQueryTypes(): array
    {
        $types = [];
        foreach (self::$query_log as $query) {
            $type = $query['query_type'];
            $types[$type] = ($types[$type] ?? 0) + 1;
        }
        return $types;
    }
}
