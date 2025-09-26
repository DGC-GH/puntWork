<?php
/**
 * System Monitoring Dashboard
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      2.1.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * System monitoring dashboard page
 */
function system_monitoring_page() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Enqueue monitoring scripts and styles
    wp_enqueue_style('puntwork-monitoring', PUNTWORK_URL . 'assets/css/admin-modern.css', [], PUNTWORK_VERSION);
    wp_enqueue_script('puntwork-monitoring', PUNTWORK_URL . 'assets/js/puntwork-logger.js', ['jquery'], PUNTWORK_VERSION, true);
    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.0', true);

    wp_localize_script('puntwork-monitoring', 'puntworkMonitoring', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('puntwork_monitoring_nonce'),
        'refresh_interval' => 30000, // 30 seconds
    ]);

    ?>
    <div class="wrap">
        <div class="puntwork-header">
            <h1><?php _e('System Monitoring & Observability', 'puntwork'); ?></h1>
            <p><?php _e('Real-time monitoring of system health, performance metrics, and operational status.', 'puntwork'); ?></p>
        </div>

        <!-- System Status Overview -->
        <div class="puntwork-card">
            <h2><?php _e('System Status Overview', 'puntwork'); ?></h2>
            <div class="status-grid">
                <div class="status-item">
                    <div class="status-indicator" id="system-status"></div>
                    <div class="status-info">
                        <h3><?php _e('Overall System', 'puntwork'); ?></h3>
                        <span id="system-status-text"><?php _e('Checking...', 'puntwork'); ?></span>
                    </div>
                </div>
                <div class="status-item">
                    <div class="status-indicator" id="database-status"></div>
                    <div class="status-info">
                        <h3><?php _e('Database', 'puntwork'); ?></h3>
                        <span id="database-status-text"><?php _e('Checking...', 'puntwork'); ?></span>
                    </div>
                </div>
                <div class="status-item">
                    <div class="status-indicator" id="cache-status"></div>
                    <div class="status-info">
                        <h3><?php _e('Cache System', 'puntwork'); ?></h3>
                        <span id="cache-status-text"><?php _e('Checking...', 'puntwork'); ?></span>
                    </div>
                </div>
                <div class="status-item">
                    <div class="status-indicator" id="queue-status"></div>
                    <div class="status-info">
                        <h3><?php _e('Queue System', 'puntwork'); ?></h3>
                        <span id="queue-status-text"><?php _e('Checking...', 'puntwork'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Metrics -->
        <div class="puntwork-card">
            <h2><?php _e('Performance Metrics', 'puntwork'); ?></h2>
            <div class="metrics-grid">
                <div class="metric-item">
                    <h3><?php _e('Import Speed', 'puntwork'); ?></h3>
                    <div class="metric-value" id="import-speed">0</div>
                    <span class="metric-unit"><?php _e('jobs/min', 'puntwork'); ?></span>
                </div>
                <div class="metric-item">
                    <h3><?php _e('Memory Usage', 'puntwork'); ?></h3>
                    <div class="metric-value" id="memory-usage">0</div>
                    <span class="metric-unit"><?php _e('MB', 'puntwork'); ?></span>
                </div>
                <div class="metric-item">
                    <h3><?php _e('Active Feeds', 'puntwork'); ?></h3>
                    <div class="metric-value" id="active-feeds">0</div>
                    <span class="metric-unit"><?php _e('feeds', 'puntwork'); ?></span>
                </div>
                <div class="metric-item">
                    <h3><?php _e('Queue Depth', 'puntwork'); ?></h3>
                    <div class="metric-value" id="queue-depth">0</div>
                    <span class="metric-unit"><?php _e('jobs', 'puntwork'); ?></span>
                </div>
            </div>
        </div>

        <!-- Real-time Charts -->
        <div class="chart-container">
            <div class="puntwork-card">
                <h2><?php _e('Import Performance (Last 24 Hours)', 'puntwork'); ?></h2>
                <canvas id="performance-chart" width="400" height="200"></canvas>
            </div>
            <div class="puntwork-card">
                <h2><?php _e('System Resources', 'puntwork'); ?></h2>
                <canvas id="resources-chart" width="400" height="200"></canvas>
            </div>
        </div>

        <!-- Recent Activity Log -->
        <div class="puntwork-card">
            <h2><?php _e('Recent Activity', 'puntwork'); ?></h2>
            <div id="activity-log" class="activity-log">
                <div class="log-entry loading">
                    <span><?php _e('Loading recent activity...', 'puntwork'); ?></span>
                </div>
            </div>
            <div class="log-controls">
                <button id="refresh-activity" class="button"><?php _e('Refresh', 'puntwork'); ?></button>
                <button id="clear-logs" class="button button-secondary"><?php _e('Clear Old Logs', 'puntwork'); ?></button>
            </div>
        </div>

        <!-- Alert Configuration -->
        <div class="puntwork-card">
            <h2><?php _e('Alert Configuration', 'puntwork'); ?></h2>
            <form id="alert-settings-form">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="alert-email"><?php _e('Alert Email', 'puntwork'); ?></label>
                        <input type="email" id="alert-email" name="alert_email" value="<?php echo esc_attr(get_option('puntwork_alert_email', '')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="alert-threshold"><?php _e('Error Threshold', 'puntwork'); ?></label>
                        <input type="number" id="alert-threshold" name="alert_threshold" value="<?php echo esc_attr(get_option('puntwork_alert_threshold', 5)); ?>" min="1" max="100">
                        <span class="description"><?php _e('Consecutive errors before alerting', 'puntwork'); ?></span>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button button-primary"><?php _e('Save Settings', 'puntwork'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .status-item {
            display: flex;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 12px;
            background: #6c757d;
        }

        .status-indicator.healthy { background: #28a745; }
        .status-indicator.warning { background: #ffc107; }
        .status-indicator.error { background: #dc3545; }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .metric-item {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .metric-value {
            font-size: 2.5em;
            font-weight: bold;
            margin: 10px 0;
        }

        .metric-unit {
            font-size: 0.9em;
            opacity: 0.9;
        }

        .chart-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }

        .activity-log {
            max-height: 300px;
            overflow-y: auto;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
        }

        .log-entry {
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .log-entry:last-child {
            border-bottom: none;
        }

        .log-entry.error { color: #dc3545; }
        .log-entry.warning { color: #ffc107; }
        .log-entry.info { color: #007bff; }
        .log-entry.success { color: #28a745; }

        .log-timestamp {
            font-size: 0.85em;
            color: #6c757d;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 600;
        }

        .form-group input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .description {
            font-size: 0.85em;
            color: #6c757d;
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .chart-container {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <script>
        jQuery(document).ready(function($) {
            const monitoring = {
                init: function() {
                    this.bindEvents();
                    this.loadInitialData();
                    this.startAutoRefresh();
                },

                bindEvents: function() {
                    $('#refresh-activity').on('click', () => this.loadActivityLog());
                    $('#clear-logs').on('click', () => this.clearOldLogs());
                    $('#alert-settings-form').on('submit', (e) => this.saveAlertSettings(e));
                },

                loadInitialData: function() {
                    this.checkSystemStatus();
                    this.loadPerformanceMetrics();
                    this.loadActivityLog();
                    this.initializeCharts();
                },

                startAutoRefresh: function() {
                    setInterval(() => {
                        this.checkSystemStatus();
                        this.loadPerformanceMetrics();
                    }, puntworkMonitoring.refresh_interval);
                },

                checkSystemStatus: function() {
                    $.ajax({
                        url: puntworkMonitoring.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'puntwork_get_system_metrics',
                            nonce: puntworkMonitoring.nonce
                        },
                        success: (response) => {
                            if (response.success) {
                                this.updateSystemStatus(response.data);
                            }
                        }
                    });
                },

                updateSystemStatus: function(data) {
                    // Update status indicators based on metrics
                    const memoryPercent = data.memory_usage && data.memory_usage.limit ?
                        (data.memory_usage.current / this.parseSize(data.memory_usage.limit)) * 100 : 0;

                    const diskPercent = data.disk_usage ? data.disk_usage.percentage : 0;

                    // System status based on memory and disk usage
                    let systemStatus = 'healthy';
                    if (memoryPercent > 80 || diskPercent > 90) {
                        systemStatus = 'warning';
                    }
                    if (memoryPercent > 95 || diskPercent > 95) {
                        systemStatus = 'error';
                    }

                    $('#system-status').attr('class', 'status-indicator ' + systemStatus);
                    $('#system-status-text').text(systemStatus.charAt(0).toUpperCase() + systemStatus.slice(1));

                    // Database status based on connections
                    let dbStatus = 'healthy';
                    if (data.database_connections && data.database_connections.active > 80) {
                        dbStatus = 'warning';
                    }
                    $('#database-status').attr('class', 'status-indicator ' + dbStatus);
                    $('#database-status-text').text(dbStatus.charAt(0).toUpperCase() + dbStatus.slice(1));

                    // Cache status (simplified)
                    $('#cache-status').attr('class', 'status-indicator healthy');
                    $('#cache-status-text').text('Healthy');

                    // Queue status
                    let queueStatus = 'healthy';
                    if (data.queue_status && data.queue_status.failed > 0) {
                        queueStatus = 'warning';
                    }
                    $('#queue-status').attr('class', 'status-indicator ' + queueStatus);
                    $('#queue-status-text').text(queueStatus.charAt(0).toUpperCase() + queueStatus.slice(1));
                },

                parseSize: function(size) {
                    if (typeof size === 'string') {
                        const units = { 'K': 1024, 'M': 1024*1024, 'G': 1024*1024*1024 };
                        const match = size.match(/^(\d+)([KMG]?)$/);
                        return match ? parseInt(match[1]) * (units[match[2]] || 1) : 0;
                    }
                    return size;
                },

                loadPerformanceMetrics: function() {
                    $.ajax({
                        url: puntworkMonitoring.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'puntwork_get_performance_metrics',
                            nonce: puntworkMonitoring.nonce,
                            time_range: '1h'
                        },
                        success: (response) => {
                            if (response.success) {
                                this.updatePerformanceMetrics(response.data);
                            }
                        }
                    });
                },

                updatePerformanceMetrics: function(data) {
                    // Update metrics display
                    const memoryMB = data.memory_usage && data.memory_usage.current ?
                        Math.round(data.memory_usage.current / (1024 * 1024)) : 0;
                    $('#memory-usage').text(memoryMB);

                    // Update other metrics (placeholders for now)
                    $('#import-speed').text('25'); // Placeholder
                    $('#active-feeds').text('12'); // Placeholder
                    $('#queue-depth').text('5'); // Placeholder

                    // Update charts if data available
                    if (data.page_load_times && this.performanceChart) {
                        this.updatePerformanceChart(data.page_load_times);
                    }
                },

                updatePerformanceChart: function(data) {
                    const labels = data.map(point => new Date(point.time * 1000).toLocaleTimeString());
                    const values = data.map(point => point.value);

                    this.performanceChart.data.labels = labels;
                    this.performanceChart.data.datasets[0].data = values;
                    this.performanceChart.update();
                },

                loadActivityLog: function() {
                    $.ajax({
                        url: puntworkMonitoring.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'puntwork_get_error_logs',
                            nonce: puntworkMonitoring.nonce,
                            limit: 20
                        },
                        success: (response) => {
                            if (response.success) {
                                this.updateActivityLog(response.data.logs);
                            }
                        }
                    });
                },

                updateActivityLog: function(entries) {
                    const $log = $('#activity-log');
                    $log.empty();

                    if (!entries || entries.length === 0) {
                        $log.append('<div class="log-entry"><span><?php _e('No recent activity', 'puntwork'); ?></span></div>');
                        return;
                    }

                    entries.forEach(entry => {
                        const $entry = $('<div class="log-entry ' + (entry.level || 'info') + '"></div>');
                        $entry.append('<span>' + (entry.message || 'Unknown event') + '</span>');
                        $entry.append('<span class="log-timestamp">' + new Date(entry.timestamp * 1000).toLocaleString() + '</span>');
                        $log.append($entry);
                    });
                },

                clearOldLogs: function() {
                    if (confirm('<?php _e('Are you sure you want to clear old logs?', 'puntwork'); ?>')) {
                        $.ajax({
                            url: puntworkMonitoring.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'puntwork_clear_old_logs',
                                nonce: puntworkMonitoring.nonce
                            },
                            success: (response) => {
                                if (response.success) {
                                    this.loadActivityLog();
                                    alert('<?php _e('Old logs cleared successfully', 'puntwork'); ?>');
                                }
                            }
                        });
                    }
                },

                saveAlertSettings: function(e) {
                    e.preventDefault();

                    const formData = $(e.target).serialize();

                    $.ajax({
                        url: puntworkMonitoring.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'puntwork_save_alert_settings',
                            nonce: puntworkMonitoring.nonce,
                            ...Object.fromEntries(new URLSearchParams(formData))
                        },
                        success: (response) => {
                            if (response.success) {
                                alert('<?php _e('Alert settings saved successfully', 'puntwork'); ?>');
                            } else {
                                alert('<?php _e('Error saving alert settings', 'puntwork'); ?>');
                            }
                        }
                    });
                },

                initializeCharts: function() {
                    // Initialize Chart.js charts
                    this.performanceChart = new Chart(
                        document.getElementById('performance-chart').getContext('2d'),
                        {
                            type: 'line',
                            data: {
                                labels: [],
                                datasets: [{
                                    label: '<?php _e('Import Speed', 'puntwork'); ?>',
                                    data: [],
                                    borderColor: '#007bff',
                                    backgroundColor: 'rgba(0,123,255,0.1)',
                                    tension: 0.4
                                }]
                            },
                            options: {
                                responsive: true,
                                scales: {
                                    y: {
                                        beginAtZero: true
                                    }
                                }
                            }
                        }
                    );

                    this.resourcesChart = new Chart(
                        document.getElementById('resources-chart').getContext('2d'),
                        {
                            type: 'doughnut',
                            data: {
                                labels: ['<?php _e('Used', 'puntwork'); ?>', '<?php _e('Available', 'puntwork'); ?>'],
                                datasets: [{
                                    data: [65, 35],
                                    backgroundColor: ['#dc3545', '#28a745']
                                }]
                            },
                            options: {
                                responsive: true
                            }
                        }
                    );
                }
            };

            monitoring.init();
        });
    </script>
    <?php
}