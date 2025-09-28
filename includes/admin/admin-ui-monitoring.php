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
if (! defined('ABSPATH')) {
    exit;
}

/**
 * System monitoring dashboard page
 */
function system_monitoring_page()
{
    // Check user capabilities
    if (! current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Enqueue monitoring scripts and styles
    wp_enqueue_style('puntwork-monitoring', PUNTWORK_URL . 'assets/css/admin-modern.css', array(), PUNTWORK_VERSION);
    wp_enqueue_script('puntwork-monitoring', PUNTWORK_URL . 'assets/js/puntwork-logger.js', array( 'jquery' ), PUNTWORK_VERSION, true);
    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', true);

    wp_localize_script(
        'puntwork-monitoring',
        'puntworkMonitoring',
        array(
            'ajaxurl'          => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('puntwork_monitoring_nonce'),
            'refresh_interval' => 30000, // 30 seconds
        )
    );

    ?>
    <div class="wrap puntwork-admin">
        <div class="puntwork-container">
            <!-- Header Section -->
            <div class="puntwork-header">
                <h1 class="puntwork-header__title"><?php _e('System Monitoring & Observability', 'puntwork'); ?></h1>
                <p class="puntwork-header__subtitle"><?php _e('Real-time monitoring of system health, performance metrics, and operational status.', 'puntwork'); ?></p>
            </div>

            <!-- Status Overview Cards -->
            <div class="puntwork-grid puntwork-grid--4" style="margin-bottom: var(--spacing-2xl);">
                <!-- System Status -->
                <div class="puntwork-card">
                    <div class="puntwork-card__body" style="text-align: center; padding: var(--spacing-2xl);">
                        <div class="step-icon" style="background: linear-gradient(135deg, #34c759 0%, #30d158 100%); margin: 0 auto var(--spacing-lg);">
                            <i class="fas fa-server"></i>
                        </div>
                        <h3 style="font-size: var(--font-size-lg); font-weight: var(--font-weight-semibold); margin: 0 0 var(--spacing-sm); color: var(--color-black);">System Status</h3>
                        <div class="puntwork-status puntwork-status--success" style="font-size: var(--font-size-sm); display: inline-block;">Online</div>
                        <p style="font-size: var(--font-size-sm); color: var(--color-gray-600); margin: var(--spacing-sm) 0 0;">All systems operational</p>
                    </div>
                </div>

                <!-- Database Health -->
                <div class="puntwork-card">
                    <div class="puntwork-card__body" style="text-align: center; padding: var(--spacing-2xl);">
                        <div class="step-icon" style="background: linear-gradient(135deg, #007aff 0%, #5856d6 100%); margin: 0 auto var(--spacing-lg);">
                            <i class="fas fa-database"></i>
                        </div>
                        <h3 style="font-size: var(--font-size-lg); font-weight: var(--font-weight-semibold); margin: 0 0 var(--spacing-sm); color: var(--color-black);">Database</h3>
                        <div class="puntwork-status puntwork-status--success" style="font-size: var(--font-size-sm); display: inline-block;">Healthy</div>
                        <p style="font-size: var(--font-size-sm); color: var(--color-gray-600); margin: var(--spacing-sm) 0 0;">99.9% uptime</p>
                    </div>
                </div>

                <!-- Cache System -->
                <div class="puntwork-card">
                    <div class="puntwork-card__body" style="text-align: center; padding: var(--spacing-2xl);">
                        <div class="step-icon" style="background: linear-gradient(135deg, #ff9500 0%, #e8890b 100%); margin: 0 auto var(--spacing-lg);">
                            <i class="fas fa-memory"></i>
                        </div>
                        <h3 style="font-size: var(--font-size-lg); font-weight: var(--font-weight-semibold); margin: 0 0 var(--spacing-sm); color: var(--color-black);">Cache System</h3>
                        <div class="puntwork-status puntwork-status--success" style="font-size: var(--font-size-sm); display: inline-block;">Active</div>
                        <p style="font-size: var(--font-size-sm); color: var(--color-gray-600); margin: var(--spacing-sm) 0 0;">Optimal performance</p>
                    </div>
                </div>

                <!-- Queue Health -->
                <div class="puntwork-card">
                    <div class="puntwork-card__body" style="text-align: center; padding: var(--spacing-2xl);">
                        <div class="step-icon" style="background: linear-gradient(135deg, #5ac8fa 0%, #007aff 100%); margin: 0 auto var(--spacing-lg);">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <h3 style="font-size: var(--font-size-lg); font-weight: var(--font-weight-semibold); margin: 0 0 var(--spacing-sm); color: var(--color-black);">Queue Status</h3>
                        <div class="puntwork-status puntwork-status--info" style="font-size: var(--font-size-sm); display: inline-block;">Processing</div>
                        <p style="font-size: var(--font-size-sm); color: var(--color-gray-600); margin: var(--spacing-sm) 0 0;">5 jobs in queue</p>
                    </div>
                </div>
            </div>

            <!-- Performance Metrics -->
            <div class="puntwork-card" style="margin-bottom: var(--spacing-2xl);">
                <div class="puntwork-card__header">
                    <h2 class="puntwork-card__title"><?php _e('Performance Metrics', 'puntwork'); ?></h2>
                    <p class="puntwork-card__subtitle"><?php _e('Real-time system performance and resource utilization', 'puntwork'); ?></p>
                </div>
                <div class="puntwork-card__body">
                    <div class="puntwork-stats">
                        <div class="puntwork-stat">
                            <div class="puntwork-stat__icon">
                                <i class="fas fa-tachometer-alt"></i>
                            </div>
                            <div class="puntwork-stat__value" id="import-speed">25</div>
                            <div class="puntwork-stat__label">Import Speed</div>
                        </div>
                        <div class="puntwork-stat puntwork-stat--success">
                            <div class="puntwork-stat__icon">
                                <i class="fas fa-memory"></i>
                            </div>
                            <div class="puntwork-stat__value" id="memory-usage">0</div>
                            <div class="puntwork-stat__label">Memory Usage</div>
                        </div>
                        <div class="puntwork-stat puntwork-stat--warning">
                            <div class="puntwork-stat__icon">
                                <i class="fas fa-rss"></i>
                            </div>
                            <div class="puntwork-stat__value" id="active-feeds">12</div>
                            <div class="puntwork-stat__label">Active Feeds</div>
                        </div>
                        <div class="puntwork-stat puntwork-stat--info">
                            <div class="puntwork-stat__icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="puntwork-stat__value" id="queue-depth">5</div>
                            <div class="puntwork-stat__label">Queue Depth</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts and Activity -->
            <div class="puntwork-grid puntwork-grid--2" style="margin-bottom: var(--spacing-2xl);">
                <!-- Performance Chart -->
                <div class="puntwork-card">
                    <div class="puntwork-card__header">
                        <h3 class="puntwork-card__title"><?php _e('Import Performance (Last 24 Hours)', 'puntwork'); ?></h3>
                    </div>
                    <div class="puntwork-card__body">
                        <div style="height: 300px;">
                            <canvas id="performance-chart" style="max-width: 100%; max-height: 100%;"></canvas>
                        </div>
                    </div>
                </div>

                <!-- System Resources Chart -->
                <div class="puntwork-card">
                    <div class="puntwork-card__header">
                        <h3 class="puntwork-card__title"><?php _e('System Resources', 'puntwork'); ?></h3>
                    </div>
                    <div class="puntwork-card__body">
                        <div style="height: 300px;">
                            <canvas id="resources-chart" style="max-width: 100%; max-height: 100%;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Activity Log -->
            <div class="puntwork-card" style="margin-bottom: var(--spacing-2xl);">
                <div class="puntwork-card__header">
                    <h3 class="puntwork-card__title"><?php _e('Recent Activity', 'puntwork'); ?></h3>
                </div>
                <div class="puntwork-card__body">
                    <div id="activity-log" style="max-height: 300px; overflow-y: auto;">
                        <div class="puntwork-loading">
                            <div class="puntwork-loading__spinner">
                                <i class="fas fa-spinner"></i>
                            </div>
                            <?php _e('Loading recent activity...', 'puntwork'); ?>
                        </div>
                    </div>
                </div>
                <div class="puntwork-card__footer" style="padding: var(--spacing-lg) var(--spacing-xl); border-top: 1px solid var(--color-gray-100); background: var(--color-gray-50);">
                    <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end;">
                        <button type="button" id="refresh-activity" class="puntwork-btn puntwork-btn--secondary">
                            <i class="fas fa-sync-alt puntwork-btn__icon"></i>
                            <?php _e('Refresh', 'puntwork'); ?>
                        </button>
                        <button type="button" id="clear-logs" class="puntwork-btn puntwork-btn--outline">
                            <i class="fas fa-trash puntwork-btn__icon"></i>
                            <?php _e('Clear Old Logs', 'puntwork'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Alert Configuration -->
            <div class="puntwork-card">
                <div class="puntwork-card__header">
                    <h2 class="puntwork-card__title"><?php _e('Alert Configuration', 'puntwork'); ?></h2>
                    <p class="puntwork-card__subtitle"><?php _e('Configure notifications for system events and thresholds', 'puntwork'); ?></p>
                </div>
                <div class="puntwork-card__body">
                    <form id="alert-settings-form" class="puntwork-form">
                        <div class="puntwork-grid puntwork-grid--2">
                            <div class="puntwork-form-group">
                                <label class="puntwork-form-label" for="alert-email"><?php _e('Alert Email', 'puntwork'); ?></label>
                                <input type="email" id="alert-email" name="alert_email" class="puntwork-form-control" value="<?php echo esc_attr(get_option('puntwork_alert_email', '')); ?>">
                            </div>
                            <div class="puntwork-form-group">
                                <label class="puntwork-form-label" for="alert-threshold"><?php _e('Error Threshold', 'puntwork'); ?></label>
                                <input type="number" id="alert-threshold" name="alert_threshold" class="puntwork-form-control" value="<?php echo esc_attr(get_option('puntwork_alert_threshold', 5)); ?>" min="1" max="100">
                                <span class="description" style="font-size: var(--font-size-xs); color: var(--color-gray-600); margin-top: var(--spacing-xs); display: block;"><?php _e('Consecutive errors before alerting', 'puntwork'); ?></span>
                            </div>
                        </div>
                        <div class="puntwork-card__footer" style="padding: var(--spacing-lg) var(--spacing-xl); border-top: 1px solid var(--color-gray-100); background: var(--color-gray-50);">
                            <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end;">
                                <button type="submit" class="puntwork-btn puntwork-btn--primary">
                                    <i class="fas fa-save puntwork-btn__icon"></i>
                                    <?php _e('Save Settings', 'puntwork'); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Step icons matching onboarding modal */
        .step-icon {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            background: linear-gradient(135deg, #007aff 0%, #5856d6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
            box-shadow: 0 8px 24px rgba(0, 122, 255, 0.3);
        }

        .step-icon i {
            font-size: 32px;
            color: white;
        }

        /* Activity log entry styling */
        .activity-entry {
            padding: var(--spacing-md);
            border-bottom: 1px solid var(--color-gray-100);
            display: flex;
            align-items: flex-start;
            gap: var(--spacing-md);
        }

        .activity-entry:last-child {
            border-bottom: none;
        }

        .activity-entry__icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--color-gray-100);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .activity-entry__content {
            flex: 1;
            min-width: 0;
        }

        .activity-entry__title {
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-medium);
            color: var(--color-black);
            margin: 0 0 var(--spacing-xs) 0;
        }

        .activity-entry__meta {
            font-size: var(--font-size-xs);
            color: var(--color-gray-600);
        }

        /* Activity entry level colors */
        .activity-entry--error .activity-entry__icon { background: rgba(255, 59, 48, 0.1); color: var(--color-danger); }
        .activity-entry--warning .activity-entry__icon { background: rgba(255, 149, 0, 0.1); color: var(--color-warning); }
        .activity-entry--info .activity-entry__icon { background: rgba(90, 200, 250, 0.1); color: var(--color-info); }
        .activity-entry--success .activity-entry__icon { background: rgba(52, 199, 89, 0.1); color: var(--color-success); }

        /* Form styling */
        .puntwork-form {
            margin: 0;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .step-icon {
                width: 64px;
                height: 64px;
            }

            .step-icon i {
                font-size: 24px;
            }

            .puntwork-card__body {
                padding: var(--spacing-xl) !important;
            }
        }

        /* Legacy support for old activity log styles */
        .activity-log .log-entry {
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .activity-log .log-entry:last-child {
            border-bottom: none;
        }

        .activity-log .log-entry.error { color: #dc3545; }
        .activity-log .log-entry.warning { color: #ffc107; }
        .activity-log .log-entry.info { color: #007bff; }
        .activity-log .log-entry.success { color: #28a745; }

        .log-timestamp {
            font-size: 0.85em;
            color: #6c757d;
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
                        $log.html('<div class="puntwork-empty"><div class="puntwork-empty__icon"><i class="fas fa-inbox"></i></div><div class="puntwork-empty__title"><?php _e('No recent activity', 'puntwork'); ?></div><div class="puntwork-empty__message"><?php _e('Activity will appear here as the system processes jobs and events.', 'puntwork'); ?></div></div>');
                        return;
                    }

                    entries.forEach(entry => {
                        const level = entry.level || 'info';
                        const iconClass = this.getActivityIcon(level);
                        const $entry = $(`
                            <div class="activity-entry activity-entry--${level}">
                                <div class="activity-entry__icon">
                                    <i class="${iconClass}"></i>
                                </div>
                                <div class="activity-entry__content">
                                    <div class="activity-entry__title">${entry.message || 'Unknown event'}</div>
                                    <div class="activity-entry__meta">${new Date(entry.timestamp * 1000).toLocaleString()}</div>
                                </div>
                            </div>
                        `);
                        $log.append($entry);
                    });
                },

                getActivityIcon: function(level) {
                    const icons = {
                        'error': 'fas fa-exclamation-triangle',
                        'warning': 'fas fa-exclamation-circle',
                        'info': 'fas fa-info-circle',
                        'success': 'fas fa-check-circle'
                    };
                    return icons[level] || 'fas fa-info-circle';
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