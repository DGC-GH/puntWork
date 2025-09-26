<?php
/**
 * Admin UI for Performance Metrics Dashboard
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      2.0.0
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Performance Metrics Dashboard Admin Page
 */
function performance_metrics_page() {
    // Handle AJAX actions
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'clear_performance_logs':
                check_admin_referer('performance_metrics_nonce');
                PerformanceMonitor::cleanup_old_logs(7); // Keep only 7 days
                add_settings_error('performance_metrics', 'logs_cleared', 'Performance logs cleared successfully.', 'success');
                break;
        }
    }

    // Get performance data
    $period = sanitize_text_field($_GET['period'] ?? '7days');
    $operation = sanitize_text_field($_GET['operation'] ?? '');

    $performance_stats = get_performance_statistics($operation, $period === '7days' ? 7 : ($period === '30days' ? 30 : 90));
    $current_snapshot = get_performance_snapshot();

    // Get recent performance logs
    global $wpdb;
    $table_name = $wpdb->prefix . 'puntwork_performance_logs';

    $where_clause = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL " . ($period === '7days' ? 7 : ($period === '30days' ? 30 : 90)) . " DAY)";
    if ($operation) {
        $where_clause .= $wpdb->prepare(" AND operation = %s", $operation);
    }

    $recent_logs = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM $table_name
        $where_clause
        ORDER BY created_at DESC
        LIMIT 50
    "));

    // Get operation types for filter
    $operation_types = $wpdb->get_col("
        SELECT DISTINCT operation FROM $table_name
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY operation
    ");

    ?>
    <div class="wrap">
        <h1><?php _e('Performance Metrics Dashboard', 'puntwork'); ?></h1>

        <?php settings_errors('performance_metrics'); ?>

        <!-- Filters -->
        <div class="performance-filters">
            <form method="get" style="display: inline;">
                <input type="hidden" name="page" value="puntwork-performance">
                <label for="period-select"><?php _e('Time Period:', 'puntwork'); ?></label>
                <select name="period" id="period-select" onchange="this.form.submit()">
                    <option value="7days" <?php selected($period, '7days'); ?>><?php _e('Last 7 Days', 'puntwork'); ?></option>
                    <option value="30days" <?php selected($period, '30days'); ?>><?php _e('Last 30 Days', 'puntwork'); ?></option>
                    <option value="90days" <?php selected($period, '90days'); ?>><?php _e('Last 90 Days', 'puntwork'); ?></option>
                </select>

                <label for="operation-select" style="margin-left: 20px;"><?php _e('Operation:', 'puntwork'); ?></label>
                <select name="operation" id="operation-select" onchange="this.form.submit()">
                    <option value=""><?php _e('All Operations', 'puntwork'); ?></option>
                    <?php foreach ($operation_types as $op): ?>
                        <option value="<?php echo esc_attr($op); ?>" <?php selected($operation, $op); ?>><?php echo esc_html($op); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>

            <!-- Clear Logs Button -->
            <form method="post" style="display: inline; margin-left: 20px;">
                <?php wp_nonce_field('performance_metrics_nonce'); ?>
                <input type="hidden" name="action" value="clear_performance_logs">
                <button type="submit" class="button button-secondary" onclick="return confirm('<?php _e('Are you sure you want to clear old performance logs?', 'puntwork'); ?>')">
                    <?php _e('Clear Old Logs', 'puntwork'); ?>
                </button>
            </form>
        </div>

        <div class="performance-dashboard">
            <!-- Current System Status -->
            <div class="performance-section">
                <h2><?php _e('Current System Status', 'puntwork'); ?></h2>
                <div class="system-status-grid">
                    <div class="status-card">
                        <div class="status-value"><?php echo size_format($current_snapshot['memory_current']); ?></div>
                        <div class="status-label"><?php _e('Current Memory', 'puntwork'); ?></div>
                    </div>

                    <div class="status-card">
                        <div class="status-value"><?php echo size_format($current_snapshot['memory_peak']); ?></div>
                        <div class="status-label"><?php _e('Peak Memory', 'puntwork'); ?></div>
                    </div>

                    <div class="status-card">
                        <div class="status-value"><?php echo size_format($current_snapshot['memory_limit']); ?></div>
                        <div class="status-label"><?php _e('Memory Limit', 'puntwork'); ?></div>
                    </div>

                    <div class="status-card">
                        <div class="status-value"><?php echo $current_snapshot['php_version']; ?></div>
                        <div class="status-label"><?php _e('PHP Version', 'puntwork'); ?></div>
                    </div>

                    <div class="status-card">
                        <div class="status-value"><?php echo $current_snapshot['wordpress_version']; ?></div>
                        <div class="status-label"><?php _e('WordPress Version', 'puntwork'); ?></div>
                    </div>

                    <?php if ($current_snapshot['load_average']): ?>
                    <div class="status-card">
                        <div class="status-value"><?php echo number_format($current_snapshot['load_average'][0], 2); ?></div>
                        <div class="status-label"><?php _e('Load Average (1m)', 'puntwork'); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Performance Overview -->
            <?php if (!empty($performance_stats)): ?>
            <div class="performance-section">
                <h2><?php _e('Performance Overview', 'puntwork'); ?></h2>
                <div class="performance-overview-grid">
                    <div class="overview-card">
                        <div class="overview-value"><?php echo number_format($performance_stats['total_runs']); ?></div>
                        <div class="overview-label"><?php _e('Total Operations', 'puntwork'); ?></div>
                    </div>

                    <div class="overview-card">
                        <div class="overview-value"><?php echo $performance_stats['avg_time_seconds']; ?>s</div>
                        <div class="overview-label"><?php _e('Avg Duration', 'puntwork'); ?></div>
                        <div class="overview-subtext">
                            Min: <?php echo $performance_stats['min_time_seconds']; ?>s |
                            Max: <?php echo $performance_stats['max_time_seconds']; ?>s
                        </div>
                    </div>

                    <div class="overview-card">
                        <div class="overview-value"><?php echo $performance_stats['avg_memory_mb']; ?> MB</div>
                        <div class="overview-label"><?php _e('Avg Memory Usage', 'puntwork'); ?></div>
                        <div class="overview-subtext">
                            Peak: <?php echo $performance_stats['max_peak_memory_mb']; ?> MB
                        </div>
                    </div>

                    <?php if ($performance_stats['avg_items_per_second']): ?>
                    <div class="overview-card">
                        <div class="overview-value"><?php echo $performance_stats['avg_items_per_second']; ?>/s</div>
                        <div class="overview-label"><?php _e('Avg Processing Rate', 'puntwork'); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Performance Charts -->
            <div class="performance-section">
                <h2><?php _e('Performance Trends', 'puntwork'); ?></h2>
                <div class="charts-container">
                    <div class="chart-wrapper">
                        <h3><?php _e('Execution Time Trend', 'puntwork'); ?></h3>
                        <canvas id="time-trend-chart" width="400" height="200"></canvas>
                    </div>

                    <div class="chart-wrapper">
                        <h3><?php _e('Memory Usage Trend', 'puntwork'); ?></h3>
                        <canvas id="memory-trend-chart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Performance Logs -->
            <div class="performance-section">
                <h2><?php _e('Recent Performance Logs', 'puntwork'); ?></h2>

                <?php if (empty($recent_logs)): ?>
                    <div class="notice notice-info">
                        <p><?php _e('No performance logs found for the selected period.', 'puntwork'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="performance-logs-table-container">
                        <table class="wp-list-table widefat fixed striped performance-logs-table">
                            <thead>
                                <tr>
                                    <th><?php _e('Time', 'puntwork'); ?></th>
                                    <th><?php _e('Operation', 'puntwork'); ?></th>
                                    <th><?php _e('Duration', 'puntwork'); ?></th>
                                    <th><?php _e('Memory Used', 'puntwork'); ?></th>
                                    <th><?php _e('Peak Memory', 'puntwork'); ?></th>
                                    <th><?php _e('Processing Rate', 'puntwork'); ?></th>
                                    <th><?php _e('Details', 'puntwork'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_logs as $log): ?>
                                    <tr>
                                        <td><?php echo wp_date('M j, H:i:s', strtotime($log->created_at)); ?></td>
                                        <td><?php echo esc_html($log->operation); ?></td>
                                        <td><?php echo number_format($log->total_time, 3); ?>s</td>
                                        <td><?php echo size_format($log->total_memory_used); ?></td>
                                        <td><?php echo size_format($log->peak_memory); ?></td>
                                        <td><?php echo $log->items_per_second ? number_format($log->items_per_second, 1) . '/s' : '—'; ?></td>
                                        <td>
                                            <button class="button button-small view-details" data-log-id="<?php echo $log->id; ?>" data-checkpoints='<?php echo esc_attr($log->checkpoints); ?>' data-metadata='<?php echo esc_attr($log->metadata); ?>'>
                                                <?php _e('View Details', 'puntwork'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Cache Performance -->
            <div class="performance-section">
                <h2><?php _e('Cache Performance', 'puntwork'); ?></h2>
                <?php $cache_stats = CacheManager::get_stats(); ?>
                <div class="cache-stats-grid">
                    <div class="cache-stat-card">
                        <div class="cache-stat-value"><?php echo $cache_stats['redis_available'] ? '✅' : '❌'; ?></div>
                        <div class="cache-stat-label"><?php _e('Redis Available', 'puntwork'); ?></div>
                    </div>

                    <div class="cache-stat-card">
                        <div class="cache-stat-value"><?php echo count($cache_stats['cache_groups']); ?></div>
                        <div class="cache-stat-label"><?php _e('Cache Groups', 'puntwork'); ?></div>
                    </div>

                    <div class="cache-stat-card">
                        <div class="cache-stat-value"><?php echo $cache_stats['wp_cache_supports_groups'] ? '✅' : '❌'; ?></div>
                        <div class="cache-stat-label"><?php _e('Groups Support', 'puntwork'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Performance Details Modal -->
    <div id="performance-details-modal" class="performance-modal" style="display: none;">
        <div class="performance-modal-content">
            <div class="performance-modal-header">
                <h3><?php _e('Performance Details', 'puntwork'); ?></h3>
                <button class="performance-modal-close">&times;</button>
            </div>
            <div class="performance-modal-body">
                <div id="performance-details-content"></div>
            </div>
        </div>
    </div>

    <!-- Chart.js for visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Get chart data from PHP
            const chartData = <?php echo json_encode(get_performance_chart_data($period, $operation)); ?>;

            // Time Trend Chart
            if (chartData.time_trend && chartData.time_trend.labels.length > 0) {
                const timeCtx = document.getElementById('time-trend-chart').getContext('2d');
                new Chart(timeCtx, {
                    type: 'line',
                    data: {
                        labels: chartData.time_trend.labels,
                        datasets: [{
                            label: 'Average Duration (seconds)',
                            data: chartData.time_trend.data,
                            borderColor: '#007cba',
                            backgroundColor: 'rgba(0, 124, 186, 0.1)',
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Duration (seconds)'
                                }
                            }
                        }
                    }
                });
            }

            // Memory Trend Chart
            if (chartData.memory_trend && chartData.memory_trend.labels.length > 0) {
                const memoryCtx = document.getElementById('memory-trend-chart').getContext('2d');
                new Chart(memoryCtx, {
                    type: 'line',
                    data: {
                        labels: chartData.memory_trend.labels,
                        datasets: [{
                            label: 'Average Memory Usage (MB)',
                            data: chartData.memory_trend.data,
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Memory (MB)'
                                }
                            }
                        }
                    }
                });
            }

            // Modal functionality
            const modal = document.getElementById('performance-details-modal');
            const modalClose = document.querySelector('.performance-modal-close');
            const viewDetailsButtons = document.querySelectorAll('.view-details');

            viewDetailsButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const checkpoints = JSON.parse(this.getAttribute('data-checkpoints') || '[]');
                    const metadata = JSON.parse(this.getAttribute('data-metadata') || '{}');

                    let content = '<div class="performance-details">';

                    if (checkpoints.length > 0) {
                        content += '<h4>Checkpoints:</h4><ul>';
                        checkpoints.forEach(checkpoint => {
                            content += `<li><strong>${checkpoint.name}</strong>: ${checkpoint.elapsed.toFixed(3)}s elapsed, ${formatBytes(checkpoint.memory_used)} memory used`;
                            if (checkpoint.data && Object.keys(checkpoint.data).length > 0) {
                                content += ` (${JSON.stringify(checkpoint.data)})`;
                            }
                            content += '</li>';
                        });
                        content += '</ul>';
                    }

                    if (Object.keys(metadata).length > 0) {
                        content += '<h4>System Info:</h4><ul>';
                        Object.entries(metadata).forEach(([key, value]) => {
                            if (key === 'memory_limit') {
                                content += `<li><strong>${key}</strong>: ${formatBytes(value)}</li>`;
                            } else {
                                content += `<li><strong>${key}</strong>: ${value}</li>`;
                            }
                        });
                        content += '</ul>';
                    }

                    content += '</div>';

                    document.getElementById('performance-details-content').innerHTML = content;
                    modal.style.display = 'block';
                });
            });

            modalClose.addEventListener('click', function() {
                modal.style.display = 'none';
            });

            window.addEventListener('click', function(event) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        });

        function formatBytes(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    </script>

    <style>
        .performance-dashboard {
            max-width: none;
        }

        .performance-filters {
            background: #fff;
            padding: 15px 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .performance-section {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .performance-section h2 {
            margin-top: 0;
            margin-bottom: 20px;
            color: #23282d;
        }

        .system-status-grid, .performance-overview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .status-card, .overview-card {
            text-align: center;
            padding: 20px;
            border: 1px solid #e1e1e1;
            border-radius: 8px;
            background: #f8f9fa;
        }

        .status-value, .overview-value {
            font-size: 1.8em;
            font-weight: bold;
            color: #007cba;
            display: block;
            margin-bottom: 5px;
        }

        .status-label, .overview-label {
            color: #666;
            font-size: 0.9em;
        }

        .overview-subtext {
            font-size: 0.8em;
            color: #888;
            margin-top: 5px;
        }

        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
        }

        .chart-wrapper {
            background: #f8f9fa;
            border: 1px solid #e1e1e1;
            border-radius: 8px;
            padding: 20px;
        }

        .chart-wrapper h3 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #23282d;
            font-size: 16px;
        }

        .performance-logs-table-container {
            overflow-x: auto;
        }

        .performance-logs-table th,
        .performance-logs-table td {
            padding: 8px 12px;
            text-align: left;
        }

        .performance-logs-table .view-details {
            padding: 2px 8px;
            font-size: 12px;
        }

        .cache-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .cache-stat-card {
            text-align: center;
            padding: 15px;
            border: 1px solid #e1e1e1;
            border-radius: 6px;
            background: #f8f9fa;
        }

        .cache-stat-value {
            font-size: 1.5em;
            font-weight: bold;
            color: #28a745;
            display: block;
            margin-bottom: 5px;
        }

        .cache-stat-label {
            color: #666;
            font-size: 0.8em;
        }

        .performance-modal {
            position: fixed;
            z-index: 100000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .performance-modal-content {
            background-color: #fff;
            margin: 5% auto;
            padding: 0;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .performance-modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .performance-modal-header h3 {
            margin: 0;
        }

        .performance-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }

        .performance-modal-body {
            padding: 20px;
            max-height: 400px;
            overflow-y: auto;
        }

        .performance-details h4 {
            margin-top: 20px;
            margin-bottom: 10px;
            color: #23282d;
        }

        .performance-details ul {
            margin: 0;
            padding-left: 20px;
        }

        .performance-details li {
            margin-bottom: 5px;
            line-height: 1.4;
        }
    </style>
    <?php
}

/**
 * Get performance chart data for the dashboard
 *
 * @param string $period Time period
 * @param string $operation Operation filter
 * @return array Chart data
 */
function get_performance_chart_data(string $period, string $operation = ''): array {
    global $wpdb;

    $table_name = $wpdb->prefix . 'puntwork_performance_logs';
    $days = $period === '7days' ? 7 : ($period === '30days' ? 30 : 90);

    $where_clause = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)";
    if ($operation) {
        $where_clause .= $wpdb->prepare(" AND operation = %s", $operation);
    }

    // Time trend data (daily averages)
    $time_trend = $wpdb->get_results($wpdb->prepare("
        SELECT
            DATE(created_at) as date,
            AVG(total_time) as avg_time
        FROM $table_name
        $where_clause
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    "));

    // Memory trend data (daily averages)
    $memory_trend = $wpdb->get_results($wpdb->prepare("
        SELECT
            DATE(created_at) as date,
            AVG(total_memory_used) as avg_memory
        FROM $table_name
        $where_clause
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    "));

    return [
        'time_trend' => [
            'labels' => array_map(function($row) {
                return wp_date('M j', strtotime($row->date));
            }, $time_trend),
            'data' => array_map(function($row) {
                return round((float) $row->avg_time, 3);
            }, $time_trend)
        ],
        'memory_trend' => [
            'labels' => array_map(function($row) {
                return wp_date('M j', strtotime($row->date));
            }, $memory_trend),
            'data' => array_map(function($row) {
                return round((float) $row->avg_memory / 1024 / 1024, 2); // Convert to MB
            }, $memory_trend)
        ]
    ];
}