<?php

/**
 * Admin UI for Performance Metrics Dashboard.
 *
 * @since      0.0.4
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Puntwork\Utilities\CacheManager;

/**
 * Performance Metrics Dashboard Admin Page.
 */
function performance_metrics_page()
{
    // Enqueue admin modern styles
    wp_enqueue_style('puntwork-admin-modern', PUNTWORK_URL . 'assets/css/admin-modern.css', [], PUNTWORK_VERSION);

    // Handle AJAX actions
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'clear_performance_logs':
                check_admin_referer('performance_metrics_nonce');
                \Puntwork\Utilities\PerformanceMonitor::cleanupOldLogs(7); // Keep only 7 days
                add_settings_error(
                    'performance_metrics',
                    'logs_cleared',
                    'Performance logs cleared successfully.',
                    'success'
                );

                break;
        }
    }

    // Get performance data
    $period = sanitize_text_field($_GET['period'] ?? '7days');
    $operation = sanitize_text_field($_GET['operation'] ?? '');

    $days = $period == '7days' ? 7 : ($period == '30days' ? 30 : 90);
    $performance_stats = get_performance_statistics($operation, $days);
    $current_snapshot = get_performance_snapshot();

    // Get recent performance logs
    global $wpdb;
    $table_name = $wpdb->prefix . 'puntwork_performance_logs';

    $where_clause = 'WHERE created_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)';
    if ($operation) {
        $where_clause .= $wpdb->prepare(' AND operation = %s', $operation);
    }

    $recent_logs = $wpdb->get_results(
        $wpdb->prepare(
            "
        SELECT * FROM $table_name
        $where_clause
        ORDER BY created_at DESC
        LIMIT 50
    "
        )
    );

    // Get operation types for filter
    $operation_types = $wpdb->get_col(
        "
        SELECT DISTINCT operation FROM $table_name
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY operation
    "
    );

    ?>
	<div class="puntwork-admin">
		<div class="puntwork-container">
			<header class="puntwork-header">
				<h1 class="puntwork-header__title"><?php _e('Performance Metrics Dashboard', 'puntwork'); ?></h1>
				<p class="puntwork-header__subtitle">Monitor and analyze your job import performance</p>
			</header>

	<?php settings_errors('performance_metrics'); ?>

			<!-- Filters -->
			<div class="puntwork-card" style="margin-bottom: var(--spacing-xl);">
				<div class="puntwork-card__body">
					<form method="get" style="display: inline;">
						<input type="hidden" name="page" value="puntwork-performance">
						<label for="period-select"
								style="margin-right: var(--spacing-md); font-weight: var(--font-weight-medium);">
							<?php _e('Time Period:', 'puntwork'); ?>
						</label>
						<select name="period" id="period-select" onchange="this.form.submit()"
								style="margin-right: var(--spacing-lg);">
							<option value="7days" <?php selected($period, '7days'); ?>>
								<?php _e('Last 7 Days', 'puntwork'); ?>
							</option>
							<option value="30days" <?php selected($period, '30days'); ?>>
								<?php _e('Last 30 Days', 'puntwork'); ?>
							</option>
							<option value="90days" <?php selected($period, '90days'); ?>>
								<?php _e('Last 90 Days', 'puntwork'); ?>
							</option>
						</select>

						<label for="operation-select"
								style="margin-right: var(--spacing-md); font-weight: var(--font-weight-medium);">
							<?php _e('Operation:', 'puntwork'); ?>
						</label>
						<select name="operation" id="operation-select" onchange="this.form.submit()"
								style="margin-right: var(--spacing-lg);">
							<option value=""><?php _e('All Operations', 'puntwork'); ?></option>
							<?php foreach ($operation_types as $op) : ?>
								<option value="<?php echo esc_attr($op); ?>" <?php selected($operation, $op); ?>>
									<?php echo esc_html($op); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</form>

					<!-- Clear Logs Button -->
					<form method="post" style="display: inline;">
						<?php wp_nonce_field('performance_metrics_nonce'); ?>
						<input type="hidden" name="action" value="clear_performance_logs">
						<button type="submit" class="puntwork-btn puntwork-btn--secondary"
								onclick="return confirm('
								<?php
                                    _e('Are you sure you want to clear old performance logs?', 'puntwork');
    ?>
								')">
							<i class="fas fa-trash-alt puntwork-btn__icon"></i>
							<?php _e('Clear Old Logs', 'puntwork'); ?>
						</button>
					</form>
				</div>
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
						<div class="status-value">
						<?php
                            echo number_format($current_snapshot['load_average'][0], 2);
					    ?>
						</div>
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
										<td>                                        <td>
										<?php
					                        echo $log->items_per_second
					                            ? number_format($log->items_per_second, 1) . '/s'
					                            : '—';
								    ?>
										</td></td>
										<td>
											<button class="puntwork-btn puntwork-btn--outline"
													data-log-id="<?php echo $log->id; ?>"
													data-checkpoints='<?php echo esc_attr($log->checkpoints); ?>'
													data-metadata='<?php echo esc_attr($log->metadata); ?>'>
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
				<?php $cache_stats = CacheManager::getStats(); ?>
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
						<div class="cache-stat-value">
						<?php
                            echo $cache_stats['wp_cache_supports_groups'] ? '✅' : '❌';
    ?>
						</div>
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
					<button id="run-optimization" class="puntwork-btn puntwork-btn--primary">
						<i class="fas fa-rocket puntwork-btn__icon"></i>
						<?php _e('Run Feed Optimization', 'puntwork'); ?>
					</button>
					<span id="optimization-status"></span>
				</div>

				<?php if (!empty($last_optimization)) : ?>
					<div class="optimization-results">
						<h3><?php _e('Last Optimization Results', 'puntwork'); ?></h3>
						<p class="optimization-timestamp">
					<?php
        printf(
            __('Last run: %s', 'puntwork'),
            wp_date('M j, Y H:i', $last_optimization['timestamp'])
        );
				    ?>
						</p>
						<div class="optimization-stats">
							<div class="optimization-stat">
								<span class="optimization-stat-value">
									<?php echo $last_optimization['results']['feeds_analyzed'] ?? 0; ?>
								</span>
								<span class="optimization-stat-label">
									<?php _e('Feeds Analyzed', 'puntwork'); ?>
								</span>
							</div>
							<div class="optimization-stat">
								<span class="optimization-stat-value">
									<?php echo $last_optimization['results']['optimizations_applied'] ?? 0; ?>
								</span>
								<span class="optimization-stat-label">
									<?php _e('Optimizations Applied', 'puntwork'); ?>
								</span>
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
									<div class="recommendation-item recommendation-
									<?php
				                        echo esc_attr($rec['severity']);
						    ?>
									">
										<span class="recommendation-type">
											<?php echo esc_html(ucfirst($rec['type'])); ?>:
										</span>
										<?php echo esc_html($rec['message']); ?>
										<?php if (!empty($rec['suggested_action'])) : ?>
											<br><small><em>
											<?php
						            echo esc_html($rec['suggested_action']);
										    ?>
											</em></small>
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

			<!-- Machine Learning Analytics -->
			<div class="performance-section">
				<h2><?php _e('Machine Learning Analytics', 'puntwork'); ?></h2>
				<div class="ml-analytics-grid">
					<div class="ml-stat-card">
						<div class="ml-stat-value">
							<?php echo count(\Puntwork\AI\MachineLearningEngine::getTrainedModels()); ?>
						</div>
						<div class="ml-stat-label"><?php _e('Trained Models', 'puntwork'); ?></div>
					</div>

					<div class="ml-stat-card">
						<div class="ml-stat-value">
							<?php echo \Puntwork\AI\MachineLearningEngine::getAverageAccuracy(); ?>%
						</div>
						<div class="ml-stat-label"><?php _e('Avg Model Accuracy', 'puntwork'); ?></div>
					</div>

					<div class="ml-stat-card">
						<div class="ml-stat-value">
							<?php echo \Puntwork\AI\MachineLearningEngine::getPredictionsToday(); ?>
						</div>
						<div class="ml-stat-label"><?php _e('Predictions Today', 'puntwork'); ?></div>
					</div>

					<div class="ml-stat-card">
						<div class="ml-stat-value">
							<?php echo \Puntwork\AI\MachineLearningEngine::getOptimizationsApplied(); ?>
						</div>
						<div class="ml-stat-label"><?php _e('Auto Optimizations', 'puntwork'); ?></div>
					</div>
				</div>

				<div class="ml-controls">
					<button id="run-ml-optimization" class="puntwork-btn puntwork-btn--primary">
						<i class="fas fa-brain puntwork-btn__icon"></i>
						<?php _e('Run ML Optimization', 'puntwork'); ?>
					</button>
					<button id="train-models" class="puntwork-btn puntwork-btn--secondary">
						<i class="fas fa-chart-line puntwork-btn__icon"></i>
						<?php _e('Train Models', 'puntwork'); ?>
					</button>
					<button id="view-ml-insights" class="puntwork-btn puntwork-btn--outline">
						<i class="fas fa-eye puntwork-btn__icon"></i>
						<?php _e('View Insights', 'puntwork'); ?>
					</button>
					<span id="ml-status"></span>
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

	<!-- ML Insights Modal -->
	<div id="ml-insights-modal" class="puntwork-modal" style="display: none;">
		<div class="puntwork-modal-content">
			<div class="puntwork-modal-header">
				<h3>Machine Learning Insights</h3>
				<button type="button" class="puntwork-modal-close">&times;</button>
			</div>
			<div class="puntwork-modal-body">
				<div id="ml-insights-content">
					<div class="puntwork-loading">
						<div class="puntwork-spinner"></div>
						<p>Loading ML insights...</p>
					</div>
				</div>
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
							content += `<li><strong>${checkpoint.name}</strong>: ` +
								`${checkpoint.elapsed.toFixed(3)}s elapsed, ` +
								`${formatBytes(checkpoint.memory_used)} memory used`;
							if (checkpoint.data && Object.keys(checkpoint.data).length > 0) {
								content += ` (${JSON.stringify(checkpoint.data)})`;
							}
							content += '</li>';
						});
								content += ` (${JSON.stringify(checkpoint.data)})`;
							}
							content += '</li>';
						});
						content += '</ul>';
					}

					if (Object.keys(metadata).length > 0) {
						content += '<h4>System Info:</h4><ul>';
						Object.entries(metadata).forEach(([key, value]) => {
							if (key == 'memory_limit') {
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
					runOptimizationBtn.innerHTML = '<span class="dashicons dashicons-update dashicons-spin"></span> ' +
						'Running...';
					optimizationStatus.textContent = 'Running feed optimization...';

					const data = {
						action: 'run_feed_optimization',
						nonce: '<?php echo wp_create_nonce('puntwork_feed_optimization'); ?>'
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
							runOptimizationBtn.innerHTML = '<span class="dashicons dashicons-update"></span> ' +
								'Run Feed Optimization';
						}
					})
					.catch(error => {
						optimizationStatus.style.color = 'red';
						optimizationStatus.textContent = 'Error: ' + error.message;
						runOptimizationBtn.disabled = false;
						runOptimizationBtn.innerHTML = '<span class="dashicons dashicons-update"></span> ' +
							'Run Feed Optimization';
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
					warmCachesBtn.innerHTML = '<span class="dashicons dashicons-update dashicons-spin"></span> ' +
						'Warming...';
					cacheStatus.textContent = 'Warming up caches...';

					const data = {
						action: 'warm_performance_caches',
						nonce: '<?php echo wp_create_nonce('puntwork_performance_caches'); ?>'
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
						nonce: '<?php echo wp_create_nonce('puntwork_cache_analytics'); ?>'
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
					runMemoryTestBtn.innerHTML =
						'<span class="dashicons dashicons-performance dashicons-spin"></span> ' +
						'Testing...';
					memoryStatus.textContent = 'Running memory performance test...';

					const data = {
						action: 'run_memory_performance_test',
						nonce: '<?php echo wp_create_nonce('puntwork_memory_test'); ?>'
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
														memoryStatus.textContent = 'Memory test completed! Peak: ' +
								formatBytes(data.data.peak_memory) + ', Time: ' + data.data.test_time + 's';
							runMemoryTestBtn.disabled = false;
							runMemoryTestBtn.innerHTML = '<span class="dashicons dashicons-performance"></span> ' +
								'Run Memory Test';
						} else {
							memoryStatus.style.color = 'red';
							memoryStatus.textContent = 'Error: ' + (data.data || 'Unknown error');
							runMemoryTestBtn.disabled = false;
							runMemoryTestBtn.innerHTML = '<span class="dashicons dashicons-performance"></span> ' +
								'Run Memory Test';
						}
					})
					.catch(error => {
						memoryStatus.style.color = 'red';
						memoryStatus.textContent = 'Error: ' + error.message;
						runMemoryTestBtn.disabled = false;
						runMemoryTestBtn.innerHTML = '<span class="dashicons dashicons-performance"></span> ' +
							'Run Memory Test';
					});
				});
			}

			if (clearMemoryPoolBtn && memoryStatus) {
				clearMemoryPoolBtn.addEventListener('click', function() {
					clearMemoryPoolBtn.disabled = true;
					memoryStatus.textContent = 'Clearing memory pool...';

					const data = {
						action: 'clear_memory_pool',
						nonce: '<?php echo wp_create_nonce('puntwork_memory_pool'); ?>'
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

			// Machine Learning functionality
			const runMLOptimizationBtn = document.getElementById('run-ml-optimization');
			const trainModelsBtn = document.getElementById('train-models');
			const viewMLInsightsBtn = document.getElementById('view-ml-insights');
			const mlStatus = document.getElementById('ml-status');

			if (runMLOptimizationBtn && mlStatus) {
				runMLOptimizationBtn.addEventListener('click', function() {
					runMLOptimizationBtn.disabled = true;
					runMLOptimizationBtn.innerHTML =
						'<span class="dashicons dashicons-brain dashicons-spin"></span> ' +
						'Running ML Optimization...';
					mlStatus.textContent = 'Running machine learning optimization...';                    const data = {
						action: 'run_ml_feed_optimization',
						nonce: '<?php echo wp_create_nonce('puntwork_ml_optimization'); ?>'
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
							mlStatus.style.color = 'green';
														mlStatus.textContent = `ML optimization completed! Applied ` +
								`${data.data.optimizations_applied} optimizations to ` +
								`${data.data.feeds_analyzed} feeds.`;
							runMLOptimizationBtn.disabled = false;
							runMLOptimizationBtn.innerHTML = '<span class="dashicons dashicons-brain"></span> ' +
								'Run ML Optimization';
							// Reload the page to show updated stats
							setTimeout(() => {
								location.reload();
							}, 3000);
						} else {
							mlStatus.style.color = 'red';
							mlStatus.textContent = 'Error: ' + (data.data || 'Unknown error');
							runMLOptimizationBtn.disabled = false;
							runMLOptimizationBtn.innerHTML = '<span class="dashicons dashicons-brain"></span> ' +
								'Run ML Optimization';
						}
					})
					.catch(error => {
						mlStatus.style.color = 'red';
						mlStatus.textContent = 'Error: ' + error.message;
						runMLOptimizationBtn.disabled = false;
						runMLOptimizationBtn.innerHTML = '<span class="dashicons dashicons-brain"></span> ' +
							'Run ML Optimization';
					});
				});
			}

			if (trainModelsBtn && mlStatus) {
				trainModelsBtn.addEventListener('click', function() {
					trainModelsBtn.disabled = true;
					trainModelsBtn.innerHTML =
						'<span class="dashicons dashicons-chart-line dashicons-spin"></span> ' +
						'Training Models...';
					mlStatus.textContent = 'Training machine learning models...';

					const data = {
						action: 'train_ml_models',
						nonce: '<?php echo wp_create_nonce('puntwork_train_models'); ?>'
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
							mlStatus.style.color = 'green';
														mlStatus.textContent = `Model training completed! Trained ` +
								`${data.data.models_trained} models with avg accuracy ` +
								`${data.data.avg_accuracy}%.`;
							trainModelsBtn.disabled = false;
							trainModelsBtn.innerHTML = '<span class="dashicons dashicons-chart-line"></span> ' +
								'Train Models';
							// Reload the page to show updated stats
							setTimeout(() => {
								location.reload();
							}, 2000);
						} else {
							mlStatus.style.color = 'red';
							mlStatus.textContent = 'Error: ' + (data.data || 'Unknown error');
							trainModelsBtn.disabled = false;
							trainModelsBtn.innerHTML = '<span class="dashicons dashicons-chart-line"></span> ' +
								'Train Models';
						}
					})
					.catch(error => {
						mlStatus.style.color = 'red';
						mlStatus.textContent = 'Error: ' + error.message;
						trainModelsBtn.disabled = false;
						trainModelsBtn.innerHTML = '<span class="dashicons dashicons-chart-line"></span> ' +
							'Train Models';
					});
				});
			}

			if (viewMLInsightsBtn) {
				viewMLInsightsBtn.addEventListener('click', function() {
					// Open ML insights modal
					const modal = document.getElementById('ml-insights-modal') || createMLInsightsModal();
					modal.style.display = 'block';

					// Load insights data
					loadMLInsights();
				});
			}
		});

		// ML Insights Modal
		$('#view-ml-insights').on('click', function() {
			$('#ml-insights-modal').show();
			loadMLInsights();
		});

		$('.puntwork-modal-close').on('click', function() {
			$(this).closest('.puntwork-modal').hide();
		});

		$(window).on('click', function(event) {
			if ($(event.target).hasClass('puntwork-modal')) {
				$('.puntwork-modal').hide();
			}
		});

		function loadMLInsights() {
			const $content = $('#ml-insights-content');
			$content.html('<div class="puntwork-loading"><div class="puntwork-spinner"></div>' +
				'<p>Loading ML insights...</p></div>');

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'get_ml_insights',
					nonce: puntwork_ml.nonce
				},
				success: function(response) {
					if (response.success) {
						displayMLInsights(response.data.insights);
					} else {
						$content.html('<div class="puntwork-error">Failed to load insights: ' +
							response.data + '</div>');
					}
				},
				error: function() {
					$content.html('<div class="puntwork-error">Failed to load ML insights. Please try again.</div>');
				}
			});
		}

		function displayMLInsights(insights) {
			let html = '<div class="ml-insights-container">';

			if (insights.model_performance) {
				html += '<div class="ml-insights-section">';
				html += '<h4>Model Performance</h4>';
				html += '<div class="ml-insights-grid">';

				Object.entries(insights.model_performance).forEach(([model, perf]) => {
					html += '<div class="ml-insight-card">';
					html += '<h5>' + model.replace('_', ' ').toUpperCase() + '</h5>';
										html += '<div class="ml-metric">Accuracy: <span class="ml-value">' +
						(perf.accuracy * 100).toFixed(1) + '%</span></div>';
					html += '<div class="ml-metric">Precision: <span class="ml-value">' +
						(perf.precision * 100).toFixed(1) + '%</span></div>';
					html += '<div class="ml-metric">Recall: <span class="ml-value">' +
						(perf.recall * 100).toFixed(1) + '%</span></div>';
					html += '</div>';
				});

				html += '</div></div>';
			}

			if (insights.feature_importance) {
				html += '<div class="ml-insights-section">';
				html += '<h4>Feature Importance</h4>';
				html += '<div class="ml-feature-list">';

				insights.feature_importance.slice(0, 10).forEach(feature => {
					html += '<div class="ml-feature-item">';
					html += '<span class="ml-feature-name">' + feature.name + '</span>';
					html += '<div class="ml-feature-bar"><div class="ml-feature-fill" style="width: ' +
						(feature.importance * 100) + '%"></div></div>';
					html += '<span class="ml-feature-value">' + (feature.importance * 100).toFixed(1) + '%</span>';
					html += '</div>';
				});

				html += '</div></div>';
			}

			if (insights.predictions) {
				html += '<div class="ml-insights-section">';
				html += '<h4>Recent Predictions</h4>';
				html += '<div class="ml-predictions-list">';

				insights.predictions.slice(0, 5).forEach(pred => {
					html += '<div class="ml-prediction-item">';
					html += '<div class="ml-prediction-feed">' + pred.feed_name + '</div>';
					html += '<div class="ml-prediction-metric">Success Rate: <span class="ml-value">' +
						(pred.predicted_success_rate * 100).toFixed(1) + '%</span></div>';
					html += '<div class="ml-prediction-confidence">Confidence: <span class="ml-value">' +
						(pred.confidence * 100).toFixed(1) + '%</span></div>';
					html += '</div>';
				});

				html += '</div></div>';
			}

			html += '</div>';
			$('#ml-insights-content').html(html);
		}
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

		.ml-analytics-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
			gap: 15px;
			margin-top: 10px;
		}

		.ml-stat-card {
			text-align: center;
			padding: 15px;
			border: 1px solid #d1e7dd;
			border-radius: 8px;
			background: #f1f8e9;
		}

		.ml-stat-value {
			font-size: 1.6em;
			font-weight: bold;
			color: #28a745;
			display: block;
			margin-bottom: 5px;
		}

		.ml-stat-label {
			color: #666;
			font-size: 0.9em;
		}

		.optimization-controls {
			margin-top: 10px;
		}

		.optimization-results {
			margin-top: 20px;
			padding: 15px;
			background: #f8f9fa;
			border: 1px solid #ddd;
			border-radius: 8px;
		}

		.optimization-stat {
			display: inline-block;
			width: 48%;
			text-align: center;
			margin-bottom: 10px;
		}

		.optimization-stat-value {
			font-size: 1.4em;
			font-weight: bold;
			color: #007cba;
			display: block;
			margin-bottom: 5px;
		}

		.optimization-stat-label {
			color: #666;
			font-size: 0.9em;
		}

		.recommendation-card {
			margin-bottom: 15px;
			padding: 10px;
			border: 1px solid #007cba;
			border-radius: 8px;
			background: #e9f7fe;
		}

		.recommendation-item {
			margin-bottom: 8px;
			padding: 8px;
			border-left: 4px solid transparent;
			border-radius: 4px;
		}

		.recommendation-info {
			color: #155724;
			background: #d1e7dd;
			padding: 10px;
			border-radius: 4px;
			margin-top: 10px;
		}

		.recommendation-warning {
			color: #856404;
			background: #fff3cd;
			padding: 10px;
			border-radius: 4px;
			margin-top: 10px;
		}

		.recommendation-error {
			color: #721c24;
			background: #f8d7da;
			padding: 10px;
			border-radius: 4px;
			margin-top: 10px;
		}

		.dashicons-spin {
			animation: spin 2s infinite linear;
		}

		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}

		/* ML Insights Modal Styles */
		.puntwork-modal {
			display: none;
			position: fixed;
			z-index: 10000;
			left: 0;
			top: 0;
			width: 100%;
			height: 100%;
			background-color: rgba(0,0,0,0.5);
		}

		.puntwork-modal-content {
			background-color: #fefefe;
			margin: 5% auto;
			padding: 0;
			border: 1px solid #888;
			width: 80%;
			max-width: 800px;
			border-radius: 8px;
			box-shadow: 0 4px 6px rgba(0,0,0,0.1);
		}

		.puntwork-modal-header {
			padding: 15px 20px;
			background: #f8f9fa;
			border-bottom: 1px solid #dee2e6;
			border-radius: 8px 8px 0 0;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}

		.puntwork-modal-header h3 {
			margin: 0;
			color: #333;
		}

		.puntwork-modal-close {
			color: #aaa;
			font-size: 28px;
			font-weight: bold;
			cursor: pointer;
			background: none;
			border: none;
			padding: 0;
			width: 30px;
			height: 30px;
			display: flex;
			align-items: center;
			justify-content: center;
		}

		.puntwork-modal-close:hover {
			color: #000;
		}

		.puntwork-modal-body {
			padding: 20px;
			max-height: 70vh;
			overflow-y: auto;
		}

		.ml-insights-container {
			display: flex;
			flex-direction: column;
			gap: 20px;
		}

		.ml-insights-section {
			background: #f8f9fa;
			border: 1px solid #dee2e6;
			border-radius: 6px;
			padding: 15px;
		}

		.ml-insights-section h4 {
			margin: 0 0 15px 0;
			color: #495057;
			font-size: 16px;
			font-weight: 600;
		}

		.ml-insights-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
			gap: 15px;
		}

		.ml-insight-card {
			background: white;
			border: 1px solid #e9ecef;
			border-radius: 6px;
			padding: 15px;
		}

		.ml-insight-card h5 {
			margin: 0 0 10px 0;
			color: #333;
			font-size: 14px;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}

		.ml-metric {
			display: flex;
			justify-content: space-between;
			margin-bottom: 8px;
			font-size: 13px;
		}

		.ml-value {
			font-weight: 600;
			color: #28a745;
		}

		.ml-feature-list {
			display: flex;
			flex-direction: column;
			gap: 8px;
		}

		.ml-feature-item {
			display: flex;
			align-items: center;
			gap: 10px;
		}

		.ml-feature-name {
			flex: 1;
			font-size: 13px;
			color: #495057;
		}

		.ml-feature-bar {
			flex: 2;
			height: 8px;
			background: #e9ecef;
			border-radius: 4px;
			overflow: hidden;
		}

		.ml-feature-fill {
			height: 100%;
			background: linear-gradient(90deg, #28a745, #20c997);
			border-radius: 4px;
		}

		.ml-feature-value {
			flex: 0 0 50px;
			text-align: right;
			font-size: 12px;
			font-weight: 600;
			color: #28a745;
		}

		.ml-predictions-list {
			display: flex;
			flex-direction: column;
			gap: 10px;
		}

		.ml-prediction-item {
			background: white;
			border: 1px solid #e9ecef;
			border-radius: 6px;
			padding: 12px;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}

		.ml-prediction-feed {
			font-weight: 600;
			color: #333;
		}

		.ml-prediction-metric {
			font-size: 13px;
			color: #666;
		}

		.puntwork-loading {
			text-align: center;
			padding: 40px;
		}

		.puntwork-spinner {
			border: 4px solid #f3f3f3;
			border-top: 4px solid #007cba;
			border-radius: 50%;
			width: 40px;
			height: 40px;
			animation: spin 1s linear infinite;
			margin: 0 auto 15px;
		}

		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}

		.puntwork-error {
			color: #dc3545;
			background: #f8d7da;
			border: 1px solid #f5c6cb;
			border-radius: 4px;
			padding: 12px;
			text-align: center;
		}
	</style>
	<?php

    // Localize script data for ML AJAX nonces
    wp_localize_script(
        'puntwork-admin-performance',
        'puntwork_ml',
        [
            'nonce' => wp_create_nonce('puntwork_ml_optimization'),
            'train_nonce' => wp_create_nonce('puntwork_train_models'),
            'insights_nonce' => wp_create_nonce('puntwork_ml_insights'),
            'ajax_url' => admin_url('admin-ajax.php'),
        ]
    );
}
