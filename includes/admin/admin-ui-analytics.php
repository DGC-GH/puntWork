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
function import_analytics_page()
{
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

    // Get analytics data with caching
    $period = sanitize_text_field($_GET['period'] ?? '30days');

    // Localize script with translations
    wp_localize_script('puntwork-analytics', 'puntworkAnalyticsL10n', array(
        'loading' => __('Loading analytics data...', 'puntwork'),
        'errorLoading' => __('Error loading analytics data. Please try again.', 'puntwork'),
        'retry' => __('Retry', 'puntwork'),
        'overview' => __('Overview', 'puntwork'),
        'totalImports' => __('Total Imports', 'puntwork'),
        'jobsProcessed' => __('Jobs Processed', 'puntwork'),
        'avgSuccessRate' => __('Avg Success Rate', 'puntwork'),
        'avgDuration' => __('Avg Duration', 'puntwork'),
        'performanceByTrigger' => __('Performance by Trigger Type', 'puntwork'),
        'imports' => __('Imports', 'puntwork'),
        'count' => __('Count:', 'puntwork'),
        'avgDurationShort' => __('Avg Duration:', 'puntwork'),
        'successRate' => __('Success Rate:', 'puntwork'),
        'jobsProcessedShort' => __('Jobs Processed:', 'puntwork'),
        'importTrends' => __('Import Trends', 'puntwork'),
        'feedPerformance' => __('Feed Performance', 'puntwork'),
        'avgFeedsProcessed' => __('Avg Feeds Processed', 'puntwork'),
        'avgFeedsSuccessful' => __('Avg Feeds Successful', 'puntwork'),
        'avgFeedsFailed' => __('Avg Feeds Failed', 'puntwork'),
        'avgResponseTime' => __('Avg Response Time', 'puntwork'),
        'jobProcessingStats' => __('Job Processing Statistics', 'puntwork'),
        'published' => __('Published:', 'puntwork'),
        'updated' => __('Updated:', 'puntwork'),
        'duplicates' => __('Duplicates:', 'puntwork'),
        'errorSummary' => __('Error Summary', 'puntwork'),
        'importsHadErrors' => __('imports had errors', 'puntwork'),
        'commonErrorMessages' => __('Common Error Messages:', 'puntwork'),
        'importActivityByHour' => __('Import Activity by Hour', 'puntwork'),
        'numberOfImports' => __('Number of Imports', 'puntwork'),
        'jobsProcessedLabel' => __('Jobs Processed', 'puntwork')
    ));

    // Check if this is an AJAX request for lazy loading
    if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
        $analytics_data = ImportAnalytics::get_analytics_data($period);
        wp_send_json([
            'success' => true,
            'data' => $analytics_data,
            'period' => $period
        ]);
    }

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

        <!-- Analytics Dashboard Container -->
        <div id="analytics-dashboard" class="analytics-dashboard">
            <!-- Loading State -->
            <div id="analytics-loading" class="analytics-loading">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                    <div><?php _e('Loading analytics data...', 'puntwork'); ?></div>
                </div>
            </div>

            <!-- Analytics Content (loaded via AJAX) -->
            <div id="analytics-content" style="display: none;">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Chart.js for visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Analytics Lazy Loading
        let currentAnalyticsPeriod = '<?php echo esc_js($period); ?>';

        function loadAnalyticsData(period) {
            const dashboard = document.getElementById('analytics-dashboard');
            const loading = document.getElementById('analytics-loading');
            const content = document.getElementById('analytics-content');

            // Show loading state
            loading.style.display = 'block';
            content.style.display = 'none';

            // Fetch analytics data
            fetch(window.location.pathname + '?page=puntwork-analytics&period=' + period + '&ajax=1')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderAnalyticsContent(data.data, period);
                        loading.style.display = 'none';
                        content.style.display = 'block';
                        currentAnalyticsPeriod = period;
                    } else {
                        throw new Error(data.message || 'Failed to load analytics data');
                    }
                })
                .catch(error => {
                    console.error('Error loading analytics:', error);
                    loading.innerHTML = `
                        <div class="loading-spinner error">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div>${puntworkAnalyticsL10n.errorLoading}</div>
                            <button onclick="loadAnalyticsData('${period}')" class="button button-secondary" style="margin-top: 10px;">${puntworkAnalyticsL10n.retry}</button>
                        </div>
                    `;
                });
        }

        function renderAnalyticsContent(analytics_data, period) {
            const content = document.getElementById('analytics-content');

            content.innerHTML = `
                <!-- Overview Metrics -->
                <div class="analytics-section">
                    <h2>${puntworkAnalyticsL10n.overview}</h2>
                    <div class="metrics-grid">
                        <div class="metric-card">
                            <div class="metric-value">${number_format(analytics_data.overview.total_imports)}</div>
                            <div class="metric-label">${puntworkAnalyticsL10n.totalImports}</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value">${number_format(analytics_data.overview.total_processed)}</div>
                            <div class="metric-label">${puntworkAnalyticsL10n.jobsProcessed}</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value">${analytics_data.overview.avg_success_rate}%</div>
                            <div class="metric-label">${puntworkAnalyticsL10n.avgSuccessRate}</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value">${analytics_data.overview.avg_duration}s</div>
                            <div class="metric-label">${puntworkAnalyticsL10n.avgDuration}</div>
                        </div>
                    </div>
                </div>

                <!-- Performance Breakdown -->
                <div class="analytics-section">
                    <h2>${puntworkAnalyticsL10n.performanceByTrigger}</h2>
                    <div class="performance-breakdown">
                        ${Object.entries(analytics_data.performance).map(([trigger_type, stats]) => `
                            <div class="performance-card">
                                <h3>${trigger_type.charAt(0).toUpperCase() + trigger_type.slice(1)} ${puntworkAnalyticsL10n.imports}</h3>
                                <div class="performance-stats">
                                    <div class="stat">
                                        <span class="stat-label">${puntworkAnalyticsL10n.count}</span>
                                        <span class="stat-value">${number_format(stats.count)}</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-label">${puntworkAnalyticsL10n.avgDurationShort}</span>
                                        <span class="stat-value">${stats.avg_duration}s</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-label">${puntworkAnalyticsL10n.successRate}</span>
                                        <span class="stat-value">${stats.avg_success_rate}%</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-label">${puntworkAnalyticsL10n.jobsProcessedShort}</span>
                                        <span class="stat-value">${number_format(stats.total_processed)}</span>
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>

                <!-- Trends Chart -->
                <div class="analytics-section">
                    <h2>${puntworkAnalyticsL10n.importTrends}</h2>
                    <div class="chart-container">
                        <canvas id="trends-chart" width="400" height="200"></canvas>
                    </div>
                </div>

                <!-- Feed Statistics -->
                <div class="analytics-section">
                    <h2>${puntworkAnalyticsL10n.feedPerformance}</h2>
                    <div class="feed-stats-grid">
                        <div class="feed-stat-card">
                            <div class="stat-value">${analytics_data.feed_stats.avg_feeds_processed}</div>
                            <div class="stat-label">${puntworkAnalyticsL10n.avgFeedsProcessed}</div>
                        </div>
                        <div class="feed-stat-card">
                            <div class="stat-value">${analytics_data.feed_stats.avg_feeds_successful}</div>
                            <div class="stat-label">${puntworkAnalyticsL10n.avgFeedsSuccessful}</div>
                        </div>
                        <div class="feed-stat-card">
                            <div class="stat-value">${analytics_data.feed_stats.avg_feeds_failed}</div>
                            <div class="stat-label">${puntworkAnalyticsL10n.avgFeedsFailed}</div>
                        </div>
                        <div class="feed-stat-card">
                            <div class="stat-value">${analytics_data.feed_stats.avg_response_time}s</div>
                            <div class="stat-label">${puntworkAnalyticsL10n.avgResponseTime}</div>
                        </div>
                    </div>
                </div>

                <!-- Job Statistics -->
                <div class="analytics-section">
                    <h2>${puntworkAnalyticsL10n.jobProcessingStats}</h2>
                    <div class="job-stats-breakdown">
                        <div class="job-stat-item">
                            <span class="job-stat-label">${puntworkAnalyticsL10n.published}</span>
                            <span class="job-stat-value">${number_format(analytics_data.overview.total_published)}</span>
                            <div class="job-stat-bar">
                                <div class="job-stat-fill published" style="width: ${analytics_data.overview.total_processed > 0 ? (analytics_data.overview.total_published / analytics_data.overview.total_processed * 100) : 0}%;"></div>
                            </div>
                        </div>
                        <div class="job-stat-item">
                            <span class="job-stat-label">${puntworkAnalyticsL10n.updated}</span>
                            <span class="job-stat-value">${number_format(analytics_data.overview.total_updated)}</span>
                            <div class="job-stat-bar">
                                <div class="job-stat-fill updated" style="width: ${analytics_data.overview.total_processed > 0 ? (analytics_data.overview.total_updated / analytics_data.overview.total_processed * 100) : 0}%;"></div>
                            </div>
                        </div>
                        <div class="job-stat-item">
                            <span class="job-stat-label">${puntworkAnalyticsL10n.duplicates}</span>
                            <span class="job-stat-value">${number_format(analytics_data.overview.total_duplicates)}</span>
                            <div class="job-stat-bar">
                                <div class="job-stat-fill duplicates" style="width: ${analytics_data.overview.total_processed > 0 ? (analytics_data.overview.total_duplicates / analytics_data.overview.total_processed * 100) : 0}%;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                ${analytics_data.errors.total_errors > 0 ? `
                <!-- Error Summary -->
                <div class="analytics-section">
                    <h2>${puntworkAnalyticsL10n.errorSummary}</h2>
                    <div class="error-summary">
                        <div class="error-count">
                            <span class="error-number">${number_format(analytics_data.errors.total_errors)}</span>
                            <span class="error-label">${puntworkAnalyticsL10n.importsHadErrors}</span>
                        </div>
                        ${analytics_data.errors.error_messages ? `
                            <div class="error-messages">
                                <strong>${puntworkAnalyticsL10n.commonErrorMessages}</strong>
                                <p>${analytics_data.errors.error_messages}</p>
                            </div>
                        ` : ''}
                    </div>
                </div>
                ` : ''}

                <!-- Hourly Distribution -->
                <div class="analytics-section">
                    <h2>${puntworkAnalyticsL10n.importActivityByHour}</h2>
                    <div class="hourly-chart-container">
                        <canvas id="hourly-chart" width="400" height="150"></canvas>
                    </div>
                </div>
            `;

            // Initialize charts after content is rendered
            setTimeout(() => {
                initializeCharts(analytics_data);
            }, 100);
        }

        function initializeCharts(analytics_data) {
            // Trends Chart
            const trendsData = analytics_data.trends.daily;
            if (trendsData && trendsData.length > 0) {
                const trendsCtx = document.getElementById('trends-chart').getContext('2d');
                new Chart(trendsCtx, {
                    type: 'line',
                    data: {
                        labels: trendsData.map(item => item.date),
                        datasets: [{
                            label: puntworkAnalyticsL10n.imports,
                            data: trendsData.map(item => item.imports_count),
                            borderColor: '#007cba',
                            backgroundColor: 'rgba(0, 124, 186, 0.1)',
                            tension: 0.4
                        }, {
                            label: puntworkAnalyticsL10n.jobsProcessedLabel,
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
                                    text: puntworkAnalyticsL10n.numberOfImports
                                }
                            },
                            y1: {
                                beginAtZero: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: puntworkAnalyticsL10n.jobsProcessedLabel
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
            const hourlyData = analytics_data.trends.hourly;
            if (hourlyData && hourlyData.length > 0) {
                const hourlyCtx = document.getElementById('hourly-chart').getContext('2d');
                new Chart(hourlyCtx, {
                    type: 'bar',
                    data: {
                        labels: hourlyData.map(item => item.hour + ':00'),
                        datasets: [{
                            label: puntworkAnalyticsL10n.imports,
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
                                    text: puntworkAnalyticsL10n.numberOfImports
                                }
                            }
                        }
                    }
                });
            }
        }

        function number_format(number) {
            return new Intl.NumberFormat().format(number);
        }

        // Initialize analytics on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadAnalyticsData(currentAnalyticsPeriod);

            // Period selector change handler
            document.getElementById('period-select').addEventListener('change', function() {
                const newPeriod = this.value;
                // Update URL without page reload
                const url = new URL(window.location);
                url.searchParams.set('period', newPeriod);
                window.history.pushState({}, '', url);
                loadAnalyticsData(newPeriod);
            });
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

    <style>
        .analytics-loading {
            text-align: center;
            padding: 60px 20px;
        }

        .loading-spinner {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
        }

        .loading-spinner i {
            font-size: 48px;
            color: #007cba;
        }

        .loading-spinner.error i {
            color: #dc3545;
        }

        .loading-spinner div {
            font-size: 16px;
            color: #6c757d;
        }
    </style>
    <?php
}