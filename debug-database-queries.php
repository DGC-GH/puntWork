<?php
/**
 * Debug Database Queries Profiling Tool
 * Analyzes database query performance during imports
 */

class DebugDatabaseQueries {
    private $queries = [];
    private $query_times = [];
    private $slow_queries = [];

    public function __construct() {
        // Hook into WordPress database queries
        add_filter('query', [$this, 'log_query']);
        add_filter('get_meta_sql', [$this, 'log_meta_query']);
    }

    public function log_query($query) {
        $start_time = microtime(true);

        // Execute the query and measure time
        global $wpdb;
        $result = $wpdb->query($query);

        $end_time = microtime(true);
        $execution_time = $end_time - $start_time;

        $this->queries[] = [
            'query' => $query,
            'time' => $execution_time,
            'timestamp' => current_time('mysql'),
            'result_count' => $wpdb->num_rows ?? 0
        ];

        $this->query_times[] = $execution_time;

        // Log slow queries (>100ms)
        if ($execution_time > 0.1) {
            $this->slow_queries[] = [
                'query' => $query,
                'time' => $execution_time,
                'timestamp' => current_time('mysql')
            ];

            error_log(sprintf(
                '[PUNTWORK] [SLOW-QUERY] Query took %.3f seconds: %s',
                $execution_time,
                substr($query, 0, 200) . (strlen($query) > 200 ? '...' : '')
            ));
        }

        return $result;
    }

    public function log_meta_query($sql) {
        // Log meta queries separately
        error_log(sprintf(
            '[PUNTWORK] [META-QUERY] Meta SQL: %s',
            json_encode($sql)
        ));

        return $sql;
    }

    public function get_query_report() {
        $report = [
            'total_queries' => count($this->queries),
            'total_time' => array_sum($this->query_times),
            'average_time' => count($this->query_times) > 0 ? array_sum($this->query_times) / count($this->query_times) : 0,
            'slow_queries_count' => count($this->slow_queries),
            'slow_queries' => array_slice($this->slow_queries, 0, 10), // Top 10 slow queries
            'query_types' => $this->analyze_query_types(),
            'recommendations' => $this->generate_db_recommendations()
        ];

        return $report;
    }

    private function analyze_query_types() {
        $types = [
            'SELECT' => 0,
            'INSERT' => 0,
            'UPDATE' => 0,
            'DELETE' => 0,
            'meta_queries' => 0
        ];

        foreach ($this->queries as $query) {
            $sql = strtoupper($query['query']);
            if (strpos($sql, 'SELECT') === 0) {
                $types['SELECT']++;
            } elseif (strpos($sql, 'INSERT') === 0) {
                $types['INSERT']++;
            } elseif (strpos($sql, 'UPDATE') === 0) {
                $types['UPDATE']++;
            } elseif (strpos($sql, 'DELETE') === 0) {
                $types['DELETE']++;
            }
        }

        return $types;
    }

    private function generate_db_recommendations() {
        $recommendations = [];

        $avg_time = count($this->query_times) > 0 ? array_sum($this->query_times) / count($this->query_times) : 0;

        if ($avg_time > 0.05) { // 50ms average
            $recommendations[] = 'Average query time is high. Consider database optimization.';
        }

        if (count($this->slow_queries) > 5) {
            $recommendations[] = 'Multiple slow queries detected. Review query structure and add appropriate indexes.';
        }

        // Check for N+1 query patterns
        $select_count = 0;
        foreach ($this->queries as $query) {
            if (strpos(strtoupper($query['query']), 'SELECT') === 0) {
                $select_count++;
            }
        }

        if ($select_count > 100) {
            $recommendations[] = 'High number of SELECT queries. Consider using JOINs or caching to reduce database load.';
        }

        return $recommendations;
    }

    public function export_report($format = 'json') {
        $report = $this->get_query_report();

        if ($format === 'json') {
            header('Content-Type: application/json');
            echo json_encode($report, JSON_PRETTY_PRINT);
        } elseif ($format === 'csv') {
            header('Content-Type: text/csv');
            echo "Query,Time,Timestamp,Result Count\n";
            foreach ($this->queries as $query) {
                echo '"' . str_replace('"', '""', $query['query']) . '",' .
                     $query['time'] . ',' .
                     $query['timestamp'] . ',' .
                     ($query['result_count'] ?? 0) . "\n";
            }
        }
    }
}

// Enable query logging for debugging
if (defined('PUNTWORK_QUERY_LOGGING') && PUNTWORK_QUERY_LOGGING) {
    global $debug_db_queries;
    $debug_db_queries = new DebugDatabaseQueries();
}

// Admin page to view query report
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Database Query Debug',
        'DB Query Debug',
        'manage_options',
        'db-query-debug',
        function() {
            global $debug_db_queries;
            if (!$debug_db_queries) {
                echo '<p>Query logging not enabled. Add define(\'PUNTWORK_QUERY_LOGGING\', true); to wp-config.php</p>';
                return;
            }

            $report = $debug_db_queries->get_query_report();

            echo '<h1>Database Query Performance Report</h1>';
            echo '<div class="wrap">';
            echo '<h2>Summary</h2>';
            echo '<ul>';
            echo '<li>Total Queries: ' . $report['total_queries'] . '</li>';
            echo '<li>Total Time: ' . number_format($report['total_time'], 3) . ' seconds</li>';
            echo '<li>Average Time: ' . number_format($report['average_time'], 3) . ' seconds</li>';
            echo '<li>Slow Queries (>100ms): ' . $report['slow_queries_count'] . '</li>';
            echo '</ul>';

            if (!empty($report['recommendations'])) {
                echo '<h2>Recommendations</h2>';
                echo '<ul>';
                foreach ($report['recommendations'] as $rec) {
                    echo '<li>' . esc_html($rec) . '</li>';
                }
                echo '</ul>';
            }

            echo '<h2>Query Types</h2>';
            echo '<pre>' . json_encode($report['query_types'], JSON_PRETTY_PRINT) . '</pre>';

            echo '<p><a href="?page=db-query-debug&export=json" class="button">Export JSON</a> ';
            echo '<a href="?page=db-query-debug&export=csv" class="button">Export CSV</a></p>';

            echo '</div>';
        }
    );
});