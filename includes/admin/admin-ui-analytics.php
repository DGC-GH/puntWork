<?php
/**
 * Admin UI for Import Analytics Dashboard
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      1.0.12
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import Analytics Dashboard Admin Page
 */
function import_analytics_page() {
    // Handle CSV export
    if (isset($_POST['export_csv']) && check_admin_referer('analytics_export_nonce')) {
        $period = sanitize_text_field($_POST['export_period'] ?? '30days');
        $csv_content = ImportAnalytics::export_analytics_csv($period);

        if ($csv_content) {
            $filename = 'puntwork-analytics-' . $period . '-' . date('Y-m-d') . '.csv';
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($csv_content));
            echo $csv_content;
            exit;
        } else {
            add_settings_error('analytics_export', 'no_data', 'No analytics data available for export.', 'error');
        }
    }

    // Get analytics data
    $period = sanitize_text_field($_GET['period'] ?? '30days');
    $analytics_data = ImportAnalytics::get_analytics_data($period);

    ?>
    <div class="wrap">
        <h1><?php _e('Import Analytics Dashboard', 'puntwork'); ?></h1>

        <?php settings_errors('analytics_export'); ?>

        <!-- Period Selector -->
        <div class="analytics-period-selector">
            <form method="get" style="display: inline;">
                <input type="hidden" name="page" value="puntwork-analytics">
                <label for="period-select"><?php _e('Time Period:', 'puntwork'); ?></label>
                <select name="period" id="period-select" onchange="this.form.submit()">
                    <option value="7days" <?php selected($period, '7days'); ?>><?php _e('Last 7 Days', 'puntwork'); ?></option>
                    <option value="30days" <?php selected($period, '30days'); ?>><?php _e('Last 30 Days', 'puntwork'); ?></option>
                    <option value="90days" <?php selected($period, '90days'); ?>><?php _e('Last 90 Days', 'puntwork'); ?></option>
                </select>
            </form>

            <!-- Export Button -->
            <form method="post" style="display: inline; margin-left: 20px;">
                <?php wp_nonce_field('analytics_export_nonce'); ?>
                <input type="hidden" name="export_period" value="<?php echo esc_attr($period); ?>">
                <button type="submit" name="export_csv" class="button button-secondary">
                    <?php _e('Export CSV', 'puntwork'); ?>
                </button>
            </form>
        </div>

        <div class="analytics-dashboard">
            <!-- Overview Metrics -->
            <div class="analytics-section">
                <h2><?php _e('Overview', 'puntwork'); ?></h2>
                <div class="metrics-grid">
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($analytics_data['overview']['total_imports']); ?></div>
                        <div class="metric-label"><?php _e('Total Imports', 'puntwork'); ?></div>
                    </div>

                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($analytics_data['overview']['total_processed']); ?></div>
                        <div class="metric-label"><?php _e('Jobs Processed', 'puntwork'); ?></div>
                    </div>

                    <div class="metric-card">
                        <div class="metric-value"><?php echo $analytics_data['overview']['avg_success_rate']; ?>%</div>
                        <div class="metric-label"><?php _e('Avg Success Rate', 'puntwork'); ?></div>
                    </div>

                    <div class="metric-card">
                        <div class="metric-value"><?php echo $analytics_data['overview']['avg_duration']; ?>s</div>
                        <div class="metric-label"><?php _e('Avg Duration', 'puntwork'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Performance Breakdown -->
            <div class="analytics-section">
                <h2><?php _e('Performance by Trigger Type', 'puntwork'); ?></h2>
                <div class="performance-breakdown">
                    <?php foreach ($analytics_data['performance'] as $trigger_type => $stats): ?>
                        <div class="performance-card">
                            <h3><?php echo esc_html(ucfirst($trigger_type)); ?> Imports</h3>
                            <div class="performance-stats">
                                <div class="stat">
                                    <span class="stat-label"><?php _e('Count:', 'puntwork'); ?></span>
                                    <span class="stat-value"><?php echo number_format($stats['count']); ?></span>
                                </div>
                                <div class="stat">
                                    <span class="stat-label"><?php _e('Avg Duration:', 'puntwork'); ?></span>
                                    <span class="stat-value"><?php echo $stats['avg_duration']; ?>s</span>
                                </div>
                                <div class="stat">
                                    <span class="stat-label"><?php _e('Success Rate:', 'puntwork'); ?></span>
                                    <span class="stat-value"><?php echo $stats['avg_success_rate']; ?>%</span>
                                </div>
                                <div class="stat">
                                    <span class="stat-label"><?php _e('Jobs Processed:', 'puntwork'); ?></span>
                                    <span class="stat-value"><?php echo number_format($stats['total_processed']); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Trends Chart -->
            <div class="analytics-section">
                <h2><?php _e('Import Trends', 'puntwork'); ?></h2>
                <div class="chart-container">
                    <canvas id="trends-chart" width="400" height="200"></canvas>
                </div>
            </div>

            <!-- Feed Statistics -->
            <div class="analytics-section">
                <h2><?php _e('Feed Performance', 'puntwork'); ?></h2>
                <div class="feed-stats-grid">
                    <div class="feed-stat-card">
                        <div class="stat-value"><?php echo $analytics_data['feed_stats']['avg_feeds_processed']; ?></div>
                        <div class="stat-label"><?php _e('Avg Feeds Processed', 'puntwork'); ?></div>
                    </div>

                    <div class="feed-stat-card">
                        <div class="stat-value"><?php echo $analytics_data['feed_stats']['avg_feeds_successful']; ?></div>
                        <div class="stat-label"><?php _e('Avg Feeds Successful', 'puntwork'); ?></div>
                    </div>

                    <div class="feed-stat-card">
                        <div class="stat-value"><?php echo $analytics_data['feed_stats']['avg_feeds_failed']; ?></div>
                        <div class="stat-label"><?php _e('Avg Feeds Failed', 'puntwork'); ?></div>
                    </div>

                    <div class="feed-stat-card">
                        <div class="stat-value"><?php echo $analytics_data['feed_stats']['avg_response_time']; ?>s</div>
                        <div class="stat-label"><?php _e('Avg Response Time', 'puntwork'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Job Statistics -->
            <div class="analytics-section">
                <h2><?php _e('Job Processing Statistics', 'puntwork'); ?></h2>
                <div class="job-stats-breakdown">
                    <div class="job-stat-item">
                        <span class="job-stat-label"><?php _e('Published:', 'puntwork'); ?></span>
                        <span class="job-stat-value"><?php echo number_format($analytics_data['overview']['total_published']); ?></span>
                        <div class="job-stat-bar">
                            <div class="job-stat-fill published" style="width: <?php echo $analytics_data['overview']['total_processed'] > 0 ? ($analytics_data['overview']['total_published'] / $analytics_data['overview']['total_processed'] * 100) : 0; ?>%"></div>
                        </div>
                    </div>

                    <div class="job-stat-item">
                        <span class="job-stat-label"><?php _e('Updated:', 'puntwork'); ?></span>
                        <span class="job-stat-value"><?php echo number_format($analytics_data['overview']['total_updated']); ?></span>
                        <div class="job-stat-bar">
                            <div class="job-stat-fill updated" style="width: <?php echo $analytics_data['overview']['total_processed'] > 0 ? ($analytics_data['overview']['total_updated'] / $analytics_data['overview']['total_processed'] * 100) : 0; ?>%"></div>
                        </div>
                    </div>

                    <div class="job-stat-item">
                        <span class="job-stat-label"><?php _e('Duplicates:', 'puntwork'); ?></span>
                        <span class="job-stat-value"><?php echo number_format($analytics_data['overview']['total_duplicates']); ?></span>
                        <div class="job-stat-bar">
                            <div class="job-stat-fill duplicates" style="width: <?php echo $analytics_data['overview']['total_processed'] > 0 ? ($analytics_data['overview']['total_duplicates'] / $analytics_data['overview']['total_processed'] * 100) : 0; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Error Summary -->
            <?php if ($analytics_data['errors']['total_errors'] > 0): ?>
            <div class="analytics-section">
                <h2><?php _e('Error Summary', 'puntwork'); ?></h2>
                <div class="error-summary">
                    <div class="error-count">
                        <span class="error-number"><?php echo number_format($analytics_data['errors']['total_errors']); ?></span>
                        <span class="error-label"><?php _e('imports had errors', 'puntwork'); ?></span>
                    </div>
                    <?php if ($analytics_data['errors']['error_messages']): ?>
                        <div class="error-messages">
                            <strong><?php _e('Common Error Messages:', 'puntwork'); ?></strong>
                            <p><?php echo esc_html($analytics_data['errors']['error_messages']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Hourly Distribution -->
            <div class="analytics-section">
                <h2><?php _e('Import Activity by Hour', 'puntwork'); ?></h2>
                <div class="hourly-chart-container">
                    <canvas id="hourly-chart" width="400" height="150"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart.js for visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Trends Chart
            const trendsData = <?php echo json_encode($analytics_data['trends']['daily']); ?>;
            if (trendsData.length > 0) {
                const trendsCtx = document.getElementById('trends-chart').getContext('2d');
                new Chart(trendsCtx, {
                    type: 'line',
                    data: {
                        labels: trendsData.map(item => item.date),
                        datasets: [{
                            label: 'Imports',
                            data: trendsData.map(item => item.imports_count),
                            borderColor: '#007cba',
                            backgroundColor: 'rgba(0, 124, 186, 0.1)',
                            tension: 0.4
                        }, {
                            label: 'Jobs Processed',
                            data: trendsData.map(item => item.jobs_processed),
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            tension: 0.4,
                            yAxisID: 'y1'
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Imports'
                                }
                            },
                            y1: {
                                beginAtZero: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Jobs Processed'
                                },
                                grid: {
                                    drawOnChartArea: false
                                }
                            }
                        }
                    }
                });
            }

            // Hourly Distribution Chart
            const hourlyData = <?php echo json_encode($analytics_data['trends']['hourly']); ?>;
            if (hourlyData.length > 0) {
                const hourlyCtx = document.getElementById('hourly-chart').getContext('2d');
                new Chart(hourlyCtx, {
                    type: 'bar',
                    data: {
                        labels: hourlyData.map(item => item.hour + ':00'),
                        datasets: [{
                            label: 'Imports',
                            data: hourlyData.map(item => item.count),
                            backgroundColor: 'rgba(0, 124, 186, 0.6)',
                            borderColor: '#007cba',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Imports'
                                }
                            }
                        }
                    }
                });
            }
        });
    </script>

    <style>
        .analytics-dashboard {
            max-width: none;
        }

        .analytics-period-selector {
            background: #fff;
            padding: 15px 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .analytics-section {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .analytics-section h2 {
            margin-top: 0;
            margin-bottom: 20px;
            color: #23282d;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .metric-card {
            text-align: center;
            padding: 20px;
            border: 1px solid #e1e1e1;
            border-radius: 8px;
            background: #f8f9fa;
        }

        .metric-value {
            font-size: 2em;
            font-weight: bold;
            color: #007cba;
            display: block;
            margin-bottom: 5px;
        }

        .metric-label {
            color: #666;
            font-size: 0.9em;
        }

        .performance-breakdown {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .performance-card {
            border: 1px solid #e1e1e1;
            border-radius: 8px;
            padding: 20px;
            background: #f8f9fa;
        }

        .performance-card h3 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #23282d;
        }

        .performance-stats .stat {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .stat-label { font-weight: 500; }
        .stat-value { font-weight: 600; }

        .chart-container {
            height: 300px;
            position: relative;
        }

        .feed-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .feed-stat-card {
            text-align: center;
            padding: 15px;
            border: 1px solid #e1e1e1;
            border-radius: 6px;
            background: #f8f9fa;
        }

        .feed-stat-card .stat-value {
            font-size: 1.5em;
            font-weight: bold;
            color: #28a745;
            display: block;
            margin-bottom: 5px;
        }

        .feed-stat-card .stat-label {
            color: #666;
            font-size: 0.8em;
        }

        .job-stats-breakdown {
            max-width: 600px;
        }

        .job-stat-item {
            margin-bottom: 15px;
        }

        .job-stat-label {
            font-weight: 500;
            display: inline-block;
            width: 100px;
        }

        .job-stat-value {
            font-weight: 600;
            margin-left: 10px;
        }

        .job-stat-bar {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            margin-top: 5px;
            overflow: hidden;
        }

        .job-stat-fill {
            height: 100%;
            border-radius: 4px;
        }

        .job-stat-fill.published { background: #28a745; }
        .job-stat-fill.updated { background: #007cba; }
        .job-stat-fill.duplicates { background: #ffc107; }

        .error-summary {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 8px;
            padding: 20px;
        }

        .error-count {
            text-align: center;
            margin-bottom: 15px;
        }

        .error-number {
            font-size: 2em;
            font-weight: bold;
            color: #721c24;
            display: block;
        }

        .error-label {
            color: #721c24;
        }

        .error-messages {
            background: #fff;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #f5c6cb;
        }

        .hourly-chart-container {
            height: 200px;
            position: relative;
        }
    </style>
    <?php
}