<?php
/**
 * Debug Memory Usage Analysis Tool
 * Monitors and analyzes memory usage patterns during imports
 */

class DebugMemoryUsage {
    private $memory_snapshots = [];
    private $peak_memory = 0;
    private $memory_trend = [];
    private $memory_leaks = [];

    public function __construct() {
        // Hook into import process to take snapshots
        add_action('puntwork_import_start', [$this, 'start_memory_monitoring']);
        add_action('puntwork_batch_start', [$this, 'snapshot_memory']);
        add_action('puntwork_batch_end', [$this, 'snapshot_memory']);
        add_action('puntwork_import_end', [$this, 'end_memory_monitoring']);
    }

    public function start_memory_monitoring() {
        $this->memory_snapshots = [];
        $this->peak_memory = 0;
        $this->memory_trend = [];
        $this->memory_leaks = [];

        error_log('[PUNTWORK] [MEMORY-MONITOR] Started memory monitoring');

        // Initial snapshot
        $this->snapshot_memory('import_start');
    }

    public function snapshot_memory($context = 'unknown') {
        $current_memory = memory_get_usage(true);
        $current_memory_mb = round($current_memory / 1024 / 1024, 2);

        $snapshot = [
            'timestamp' => microtime(true),
            'memory_bytes' => $current_memory,
            'memory_mb' => $current_memory_mb,
            'context' => $context,
            'peak_memory' => memory_get_peak_usage(true),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'limit' => ini_get('memory_limit'),
            'limit_bytes' => $this->convert_to_bytes(ini_get('memory_limit'))
        ];

        $this->memory_snapshots[] = $snapshot;
        $this->memory_trend[] = $current_memory_mb;

        if ($current_memory > $this->peak_memory) {
            $this->peak_memory = $current_memory;
        }

        // Log significant memory changes
        if (count($this->memory_trend) > 1) {
            $previous = $this->memory_trend[count($this->memory_trend) - 2];
            $change = $current_memory_mb - $previous;

            if (abs($change) > 10) { // 10MB change threshold
                error_log(sprintf(
                    '[PUNTWORK] [MEMORY-CHANGE] %s: %.2fMB (%.2fMB change) - Context: %s',
                    $change > 0 ? 'Increased' : 'Decreased',
                    $current_memory_mb,
                    abs($change),
                    $context
                ));
            }
        }

        // Check for memory limit warnings
        $limit_bytes = $snapshot['limit_bytes'];
        if ($limit_bytes > 0 && $current_memory > $limit_bytes * 0.8) {
            error_log(sprintf(
                '[PUNTWORK] [MEMORY-WARNING] Memory usage at %.1f%% of limit (%s/%s) - Context: %s',
                ($current_memory / $limit_bytes) * 100,
                $this->format_bytes($current_memory),
                ini_get('memory_limit'),
                $context
            ));
        }
    }

    public function end_memory_monitoring() {
        $this->snapshot_memory('import_end');

        $report = $this->generate_memory_report();
        $this->log_memory_report($report);

        // Detect potential memory leaks
        $this->detect_memory_leaks();
    }

    private function generate_memory_report() {
        if (empty($this->memory_snapshots)) {
            return ['error' => 'No memory snapshots collected'];
        }

        $first = $this->memory_snapshots[0];
        $last = end($this->memory_snapshots);

        $report = [
            'duration' => $last['timestamp'] - $first['timestamp'],
            'start_memory' => $first['memory_mb'],
            'end_memory' => $last['memory_mb'],
            'peak_memory' => round($this->peak_memory / 1024 / 1024, 2),
            'memory_growth' => $last['memory_mb'] - $first['memory_mb'],
            'average_memory' => array_sum($this->memory_trend) / count($this->memory_trend),
            'memory_limit' => ini_get('memory_limit'),
            'snapshots_count' => count($this->memory_snapshots),
            'memory_efficiency' => $this->calculate_memory_efficiency(),
            'recommendations' => $this->generate_memory_recommendations()
        ];

        return $report;
    }

    private function calculate_memory_efficiency() {
        if (empty($this->memory_trend)) {
            return 0;
        }

        // Calculate variance in memory usage (lower is better)
        $mean = array_sum($this->memory_trend) / count($this->memory_trend);
        $variance = 0;

        foreach ($this->memory_trend as $value) {
            $variance += pow($value - $mean, 2);
        }

        $variance = $variance / count($this->memory_trend);
        $std_dev = sqrt($variance);

        // Efficiency score (0-100, higher is better)
        $max_reasonable_std_dev = 50; // 50MB standard deviation considered high
        $efficiency = max(0, 100 - ($std_dev / $max_reasonable_std_dev) * 100);

        return round($efficiency, 1);
    }

    private function detect_memory_leaks() {
        if (count($this->memory_trend) < 3) {
            return;
        }

        // Simple leak detection: consistent upward trend in last 3 snapshots
        $recent = array_slice($this->memory_trend, -3);
        $increasing = true;

        for ($i = 1; $i < count($recent); $i++) {
            if ($recent[$i] <= $recent[$i-1]) {
                $increasing = false;
                break;
            }
        }

        if ($increasing && ($recent[2] - $recent[0]) > 20) { // 20MB increase
            $this->memory_leaks[] = [
                'type' => 'potential_leak',
                'description' => 'Memory consistently increasing in recent snapshots',
                'increase' => $recent[2] - $recent[0],
                'snapshots' => $recent
            ];

            error_log('[PUNTWORK] [MEMORY-LEAK] Potential memory leak detected: ' .
                     ($recent[2] - $recent[0]) . 'MB increase in last 3 snapshots');
        }
    }

    private function generate_memory_recommendations() {
        $recommendations = [];

        $report = $this->generate_memory_report();

        if ($report['memory_growth'] > 100) {
            $recommendations[] = 'High memory growth detected. Consider processing in smaller batches.';
        }

        if ($report['peak_memory'] > 400) {
            $recommendations[] = 'Peak memory usage is high. Consider increasing PHP memory limit or optimizing data structures.';
        }

        if ($report['memory_efficiency'] < 70) {
            $recommendations[] = 'Memory usage is unstable. Review code for proper cleanup and garbage collection.';
        }

        if (!empty($this->memory_leaks)) {
            $recommendations[] = 'Potential memory leaks detected. Review object cleanup and circular references.';
        }

        return $recommendations;
    }

    private function log_memory_report($report) {
        error_log(sprintf(
            '[PUNTWORK] [MEMORY-REPORT] Import completed - Duration: %.2fs, Peak: %sMB, Growth: %.2fMB, Efficiency: %.1f%%',
            $report['duration'],
            $report['peak_memory'],
            $report['memory_growth'],
            $report['memory_efficiency']
        ));

        if (!empty($report['recommendations'])) {
            foreach ($report['recommendations'] as $rec) {
                error_log('[PUNTWORK] [MEMORY-REC] ' . $rec);
            }
        }
    }

    private function convert_to_bytes($size_str) {
        if (!$size_str) return 0;

        $unit = strtolower(substr($size_str, -1));
        $size = (int)$size_str;

        switch ($unit) {
            case 'g': $size *= 1024;
            case 'm': $size *= 1024;
            case 'k': $size *= 1024;
        }

        return $size;
    }

    private function format_bytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . $units[$i];
    }

    public function get_memory_report() {
        return $this->generate_memory_report();
    }
}

// Global instance for access
global $debug_memory_usage;
$debug_memory_usage = new DebugMemoryUsage();

// Admin page to view memory report
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Memory Usage Debug',
        'Memory Debug',
        'manage_options',
        'memory-usage-debug',
        function() {
            global $debug_memory_usage;
            $report = $debug_memory_usage->get_memory_report();

            echo '<h1>Memory Usage Analysis Report</h1>';
            echo '<div class="wrap">';

            if (isset($report['error'])) {
                echo '<p>Error: ' . esc_html($report['error']) . '</p>';
                echo '</div>';
                return;
            }

            echo '<h2>Summary</h2>';
            echo '<table class="widefat">';
            echo '<tr><th>Metric</th><th>Value</th></tr>';
            echo '<tr><td>Duration</td><td>' . number_format($report['duration'], 2) . ' seconds</td></tr>';
            echo '<tr><td>Start Memory</td><td>' . $report['start_memory'] . ' MB</td></tr>';
            echo '<tr><td>End Memory</td><td>' . $report['end_memory'] . ' MB</td></tr>';
            echo '<tr><td>Peak Memory</td><td>' . $report['peak_memory'] . ' MB</td></tr>';
            echo '<tr><td>Memory Growth</td><td>' . number_format($report['memory_growth'], 2) . ' MB</td></tr>';
            echo '<tr><td>Average Memory</td><td>' . number_format($report['average_memory'], 2) . ' MB</td></tr>';
            echo '<tr><td>Memory Efficiency</td><td>' . $report['memory_efficiency'] . '%</td></tr>';
            echo '</table>';

            if (!empty($report['recommendations'])) {
                echo '<h2>Recommendations</h2>';
                echo '<ul>';
                foreach ($report['recommendations'] as $rec) {
                    echo '<li>' . esc_html($rec) . '</li>';
                }
                echo '</ul>';
            }

            echo '<h2>Memory Trend</h2>';
            echo '<pre>' . json_encode(array_slice($report, 0, -1), JSON_PRETTY_PRINT) . '</pre>';

            echo '</div>';
        }
    );
});