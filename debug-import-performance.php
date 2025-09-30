<?php
/**
 * Debug Import Performance Analysis Tool
 * Analyzes import performance from debug logs
 */

class DebugImportPerformance {
    private $log_file = '/wp-content/debug.log';
    private $metrics = [];

    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/debug.log';
    }

    public function analyze_performance() {
        if (!file_exists($this->log_file)) {
            return ['error' => 'Debug log file not found'];
        }

        $lines = file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->metrics = [
            'total_time' => 0,
            'batch_times' => [],
            'memory_usage' => [],
            'query_times' => [],
            'errors' => 0,
            'warnings' => 0
        ];

        foreach ($lines as $line) {
            $this->parse_log_line($line);
        }

        return $this->generate_report();
    }

    private function parse_log_line($line) {
        // Parse PERF-MONITOR lines
        if (strpos($line, '[PERF-MONITOR]') !== false) {
            if (preg_match('/Duration: ([0-9.]+) seconds/', $line, $matches)) {
                $this->metrics['batch_times'][] = (float)$matches[1];
            }
        }

        // Parse MEMORY lines
        if (strpos($line, '[MEMORY') !== false) {
            if (preg_match('/([0-9]+)MB/', $line, $matches)) {
                $this->metrics['memory_usage'][] = (int)$matches[1];
            }
        }

        // Parse DB-PERF lines
        if (strpos($line, '[DB-PERF]') !== false) {
            if (preg_match('/([0-9.]+) seconds/', $line, $matches)) {
                $this->metrics['query_times'][] = (float)$matches[1];
            }
        }

        // Count errors and warnings
        if (strpos($line, 'ERROR') !== false) {
            $this->metrics['errors']++;
        }
        if (strpos($line, 'WARNING') !== false) {
            $this->metrics['warnings']++;
        }
    }

    private function generate_report() {
        $report = [
            'summary' => [
                'total_batches' => count($this->metrics['batch_times']),
                'total_errors' => $this->metrics['errors'],
                'total_warnings' => $this->metrics['warnings'],
                'average_batch_time' => count($this->metrics['batch_times']) > 0 ?
                    array_sum($this->metrics['batch_times']) / count($this->metrics['batch_times']) : 0,
                'max_memory_usage' => !empty($this->metrics['memory_usage']) ? max($this->metrics['memory_usage']) : 0,
                'average_query_time' => count($this->metrics['query_times']) > 0 ?
                    array_sum($this->metrics['query_times']) / count($this->metrics['query_times']) : 0,
            ],
            'performance_bottlenecks' => $this->identify_bottlenecks(),
            'recommendations' => $this->generate_recommendations()
        ];

        return $report;
    }

    private function identify_bottlenecks() {
        $bottlenecks = [];

        // Check for slow batches
        if (!empty($this->metrics['batch_times'])) {
            $avg_time = array_sum($this->metrics['batch_times']) / count($this->metrics['batch_times']);
            $slow_batches = array_filter($this->metrics['batch_times'], function($time) use ($avg_time) {
                return $time > $avg_time * 1.5;
            });
            if (count($slow_batches) > 0) {
                $bottlenecks[] = count($slow_batches) . ' batches significantly slower than average';
            }
        }

        // Check memory usage
        if (!empty($this->metrics['memory_usage'])) {
            $max_memory = max($this->metrics['memory_usage']);
            if ($max_memory > 400) { // Assuming 512MB limit
                $bottlenecks[] = 'High memory usage detected: ' . $max_memory . 'MB';
            }
        }

        // Check query performance
        if (!empty($this->metrics['query_times'])) {
            $avg_query = array_sum($this->metrics['query_times']) / count($this->metrics['query_times']);
            if ($avg_query > 0.1) { // 100ms threshold
                $bottlenecks[] = 'Slow database queries detected: ' . number_format($avg_query, 3) . 's average';
            }
        }

        return $bottlenecks;
    }

    private function generate_recommendations() {
        $recommendations = [];

        if ($this->metrics['errors'] > 10) {
            $recommendations[] = 'High error rate detected. Review error logs for patterns.';
        }

        if (!empty($this->metrics['batch_times'])) {
            $avg_time = array_sum($this->metrics['batch_times']) / count($this->metrics['batch_times']);
            if ($avg_time > 30) {
                $recommendations[] = 'Consider reducing batch size to improve processing speed.';
            }
        }

        if (!empty($this->metrics['memory_usage'])) {
            $max_memory = max($this->metrics['memory_usage']);
            if ($max_memory > 300) {
                $recommendations[] = 'Memory usage is high. Consider increasing PHP memory limit or optimizing data structures.';
            }
        }

        return $recommendations;
    }
}

// Usage
if (defined('WP_ADMIN') && WP_ADMIN) {
    $analyzer = new DebugImportPerformance();
    $report = $analyzer->analyze_performance();

    echo "<h2>Import Performance Analysis</h2>";
    echo "<pre>" . json_encode($report, JSON_PRETTY_PRINT) . "</pre>";
}