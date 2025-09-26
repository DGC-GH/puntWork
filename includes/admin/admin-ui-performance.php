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
function performance_metrics_page()
{
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
                    <?php foreach ($operation_types as $op) : ?>
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

                    <?php if ($current_snapshot['load_average']) : ?>
                    <div class="status-card">
                        <div class="status-value"><?php echo number_format($current_snapshot['load_average'][0], 2); ?></div>
                        <div class="status-label"><?php _e('Load Average (1m)', 'puntwork'); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Performance Overview -->
            <?php if (!empty($performance_stats)) : ?>
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

                    <?php if ($performance_stats['avg_items_per_second']) : ?>
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

                <?php if (empty($recent_logs)) : ?>
                    <div class="notice notice-info">
                        <p><?php _e('No performance logs found for the selected period.', 'puntwork'); ?></p>
                    </div>
                <?php else : ?>
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
                                <?php foreach ($recent_logs as $log) : ?>
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

            <!-- AI Feed Optimization -->
            <div class="performance-section">
                <h2><?php _e('AI Feed Optimization', 'puntwork'); ?></h2>
                <?php
                $last_optimization = get_option('puntwork_last_optimization', []);
                $recommendations = [];

                if (class_exists('\Puntwork\AI\FeedOptimizer')) {
                    $recommendations = \Puntwork\AI\FeedOptimizer::getOptimizationRecommendations();
                }
                ?>
                <div class="optimization-controls">
                    <button id="run-optimization" class="button button-primary">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Run Feed Optimization', 'puntwork'); ?>
                    </button>
                    <span id="optimization-status"></span>
                </div>

                <?php if (!empty($last_optimization)) : ?>
                    <div class="optimization-results">
                        <h3><?php _e('Last Optimization Results', 'puntwork'); ?></h3>
                        <p class="optimization-timestamp">
                            <?php echo sprintf(__('Last run: %s', 'puntwork'), wp_date('M j, Y H:i', $last_optimization['timestamp'])); ?>
                        </p>
                        <div class="optimization-stats">
                            <div class="optimization-stat">
                                <span class="optimization-stat-value"><?php echo $last_optimization['results']['feeds_analyzed'] ?? 0; ?></span>
                                <span class="optimization-stat-label"><?php _e('Feeds Analyzed', 'puntwork'); ?></span>
                            </div>
                            <div class="optimization-stat">
                                <span class="optimization-stat-value"><?php echo $last_optimization['results']['optimizations_applied'] ?? 0; ?></span>
                                <span class="optimization-stat-label"><?php _e('Optimizations Applied', 'puntwork'); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($recommendations['feed_optimizations'])) : ?>
                    <div class="optimization-recommendations">
                        <h3><?php _e('Feed Optimization Recommendations', 'puntwork'); ?></h3>
                        <?php foreach ($recommendations['feed_optimizations'] as $feed_rec) : ?>
                            <div class="recommendation-card">
                                <h4><?php echo esc_html($feed_rec['feed_name']); ?></h4>
                                <?php foreach ($feed_rec['recommendations'] as $rec) : ?>
                                    <div class="recommendation-item recommendation-<?php echo esc_attr($rec['severity']); ?>">
                                        <span class="recommendation-type"><?php echo esc_html(ucfirst($rec['type'])); ?>:</span>
                                        <?php echo esc_html($rec['message']); ?>
                                        <?php if (!empty($rec['suggested_action'])) : ?>
                                            <br><small><em><?php echo esc_html($rec['suggested_action']); ?></em></small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($recommendations['global_optimizations'])) : ?>
                    <div class="optimization-recommendations">
                        <h3><?php _e('Global Optimization Recommendations', 'puntwork'); ?></h3>
                        <?php foreach ($recommendations['global_optimizations'] as $rec) : ?>
                            <div class="recommendation-item recommendation-<?php echo esc_attr($rec['severity']); ?>">
                                <span class="recommendation-type"><?php echo esc_html(ucfirst($rec['type'])); ?>:</span>
                                <?php echo esc_html($rec['message']); ?>
                                <?php if (!empty($rec['suggested_action'])) : ?>
                                    <br><small><em><?php echo esc_html($rec['suggested_action']); ?></em></small>
                                <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
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

            // Feed optimization functionality
            const runOptimizationBtn = document.getElementById('run-optimization');
            const optimizationStatus = document.getElementById('optimization-status');

            if (runOptimizationBtn && optimizationStatus) {
                runOptimizationBtn.addEventListener('click', function() {
                    runOptimizationBtn.disabled = true;
                    runOptimizationBtn.innerHTML = '<span class="dashicons dashicons-update dashicons-spin"></span> Running...';
                    optimizationStatus.textContent = 'Running feed optimization...';

                    const data = {
                        action: 'run_feed_optimization',
                        nonce: '<?php echo wp_create_nonce("puntwork_feed_optimization"); ?>'
                    };

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams(data)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            optimizationStatus.style.color = 'green';
                            optimizationStatus.textContent = 'Optimization completed successfully!';
                            // Reload the page to show updated results
                            setTimeout(() => {
                                location.reload();
                            }, 2000);
                        } else {
                            optimizationStatus.style.color = 'red';
                            optimizationStatus.textContent = 'Error: ' + (data.data || 'Unknown error');
                            runOptimizationBtn.disabled = false;
                            runOptimizationBtn.innerHTML = '<span class="dashicons dashicons-update"></span> Run Feed Optimization';
                        }
                    })
                    .catch(error => {
                        optimizationStatus.style.color = 'red';
                        optimizationStatus.textContent = 'Error: ' + error.message;
                        runOptimizationBtn.disabled = false;
                        runOptimizationBtn.innerHTML = '<span class="dashicons dashicons-update"></span> Run Feed Optimization';
                    });
                });
            }

            // Enhanced cache management functionality
            const warmCachesBtn = document.getElementById('warm-caches');
            const clearAnalyticsBtn = document.getElementById('clear-analytics-cache');
            const cacheStatus = document.getElementById('cache-status');

            if (warmCachesBtn && cacheStatus) {
                warmCachesBtn.addEventListener('click', function() {
                    warmCachesBtn.disabled = true;
                    warmCachesBtn.innerHTML = '<span class="dashicons dashicons-update dashicons-spin"></span> Warming...';
                    cacheStatus.textContent = 'Warming up caches...';

                    const data = {
                        action: 'warm_performance_caches',
                        nonce: '<?php echo wp_create_nonce("puntwork_performance_caches"); ?>'
                    };

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams(data)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            cacheStatus.style.color = 'green';
                            cacheStatus.textContent = 'Caches warmed successfully!';
                            // Reload the page to show updated stats
                            setTimeout(() => {
                                location.reload();
                            }, 2000);
                        } else {
                            cacheStatus.style.color = 'red';
                            cacheStatus.textContent = 'Error: ' + (data.data || 'Unknown error');
                            warmCachesBtn.disabled = false;
                            warmCachesBtn.innerHTML = '<span class="dashicons dashicons-update"></span> Warm Up Caches';
                        }
                    })
                    .catch(error => {
                        cacheStatus.style.color = 'red';
                        cacheStatus.textContent = 'Error: ' + error.message;
                        warmCachesBtn.disabled = false;
                        warmCachesBtn.innerHTML = '<span class="dashicons dashicons-update"></span> Warm Up Caches';
                    });
                });
            }

            if (clearAnalyticsBtn && cacheStatus) {
                clearAnalyticsBtn.addEventListener('click', function() {
                    clearAnalyticsBtn.disabled = true;
                    cacheStatus.textContent = 'Resetting cache analytics...';

                    const data = {
                        action: 'reset_cache_analytics',
                        nonce: '<?php echo wp_create_nonce("puntwork_cache_analytics"); ?>'
                    };

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams(data)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            cacheStatus.style.color = 'green';
                            cacheStatus.textContent = 'Cache analytics reset successfully!';
                            // Reload the page to show updated stats
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        } else {
                            cacheStatus.style.color = 'red';
                            cacheStatus.textContent = 'Error: ' + (data.data || 'Unknown error');
                            clearAnalyticsBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        cacheStatus.style.color = 'red';
                        cacheStatus.textContent = 'Error: ' + error.message;
                        clearAnalyticsBtn.disabled = false;
                    });
                });
            }

            // Advanced memory management functionality
            const runMemoryTestBtn = document.getElementById('run-memory-test');
            const clearMemoryPoolBtn = document.getElementById('clear-memory-pool');
            const memoryStatus = document.getElementById('memory-status');

            if (runMemoryTestBtn && memoryStatus) {
                runMemoryTestBtn.addEventListener('click', function() {
                    runMemoryTestBtn.disabled = true;
                    runMemoryTestBtn.innerHTML = '<span class="dashicons dashicons-performance dashicons-spin"></span> Testing...';
                    memoryStatus.textContent = 'Running memory performance test...';

                    const data = {
                        action: 'run_memory_performance_test',
                        nonce: '<?php echo wp_create_nonce("puntwork_memory_test"); ?>'
                    };

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams(data)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            memoryStatus.style.color = 'green';
                            memoryStatus.textContent = 'Memory test completed! Peak: ' + formatBytes(data.data.peak_memory) + ', Time: ' + data.data.test_time + 's';
                            runMemoryTestBtn.disabled = false;
                            runMemoryTestBtn.innerHTML = '<span class="dashicons dashicons-performance"></span> Run Memory Test';
                        } else {
                            memoryStatus.style.color = 'red';
                            memoryStatus.textContent = 'Error: ' + (data.data || 'Unknown error');
                            runMemoryTestBtn.disabled = false;
                            runMemoryTestBtn.innerHTML = '<span class="dashicons dashicons-performance"></span> Run Memory Test';
                        }
                    })
                    .catch(error => {
                        memoryStatus.style.color = 'red';
                        memoryStatus.textContent = 'Error: ' + error.message;
                        runMemoryTestBtn.disabled = false;
                        runMemoryTestBtn.innerHTML = '<span class="dashicons dashicons-performance"></span> Run Memory Test';
                    });
                });
            }

            if (clearMemoryPoolBtn && memoryStatus) {
                clearMemoryPoolBtn.addEventListener('click', function() {
                    clearMemoryPoolBtn.disabled = true;
                    memoryStatus.textContent = 'Clearing memory pool...';

                    const data = {
                        action: 'clear_memory_pool',
                        nonce: '<?php echo wp_create_nonce("puntwork_memory_pool"); ?>'
                    };

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams(data)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            memoryStatus.style.color = 'green';
                            memoryStatus.textContent = 'Memory pool cleared successfully!';
                            clearMemoryPoolBtn.disabled = false;
                        } else {
                            memoryStatus.style.color = 'red';
                            memoryStatus.textContent = 'Error: ' + (data.data || 'Unknown error');
                            clearMemoryPoolBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        memoryStatus.style.color = 'red';
                        memoryStatus.textContent = 'Error: ' + error.message;
                        clearMemoryPoolBtn.disabled = false;
                    });
                });
            }
        });
    </script>
    <style>
        .cache-controls, .memory-controls {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .cache-controls button, .memory-controls button {
            margin-right: 10px;
        }

        .memory-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .memory-stat-card {
            text-align: center;
            padding: 20px;
            border: 1px solid #e1e1e1;
            border-radius: 8px;
            background: #f8f9fa;
        }

        .memory-stat-value {
            font-size: 1.8em;
            font-weight: bold;
            color: #17a2b8;
            display: block;
            margin-bottom: 5px;
        }

        .memory-stat-label {
            color: #666;
            font-size: 0.9em;
        }
    </style>
    <?php
}
