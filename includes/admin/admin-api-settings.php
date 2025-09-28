<?php

/**
 * API Settings admin page.
 *
 * @since      1.0.7
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * API Settings page callback.
 */
function api_settings_page()
{
    // Enqueue admin modern styles
    wp_enqueue_style('puntwork-admin-modern', PUNTWORK_URL . 'assets/css/admin-modern.css', [], PUNTWORK_VERSION);

    // Handle form submissions
    if (isset($_POST['regenerate_api_key']) && check_admin_referer('puntwork_api_settings')) {
        $new_key = regenerate_api_key();
        echo '<div class="notice notice-success"><p>' . __('API key regenerated successfully!', 'puntwork') . '</p></div>';
    }

    $api_key = get_or_create_api_key();
    $site_url = get_site_url();

    ?>
	<div class="wrap">
		<h1><?php _e('API Settings', 'puntwork'); ?></h1>

		<div class="puntwork-api-settings">
			<div class="puntwork-api-section">
				<h2><?php _e('Remote Import Trigger', 'puntwork'); ?></h2>
				<p><?php _e('Use these endpoints to trigger imports remotely via HTTP requests.', 'puntwork'); ?></p>

				<h3><?php _e('API Key', 'puntwork'); ?></h3>
				<div class="api-key-container">
					<input type="text" id="api-key-display" value="<?php echo esc_attr($api_key); ?>" readonly class="regular-text">
					<button type="button" id="toggle-api-key" class="puntwork-btn puntwork-btn--outline"><?php _e('Show/Hide', 'puntwork'); ?></button>
					<button type="button" id="copy-api-key" class="puntwork-btn puntwork-btn--secondary"><?php _e('Copy', 'puntwork'); ?></button>
				</div>

				<form method="post" style="margin-top: 20px;">
					<?php wp_nonce_field('puntwork_api_settings'); ?>
					<input type="submit" name="regenerate_api_key" value="<?php esc_attr_e('Regenerate API Key', 'puntwork'); ?>" class="puntwork-btn puntwork-btn--danger"
							onclick="return confirm('<?php esc_js(__('Are you sure? This will invalidate the current API key.', 'puntwork')); ?>');">
				</form>

				<h3><?php _e('API Endpoints', 'puntwork'); ?></h3>
				<div class="endpoint-info">
					<h4><?php _e('Trigger Import', 'puntwork'); ?></h4>
					<code>POST <?php echo esc_url($site_url); ?>/wp-json/puntwork/v1/trigger-import</code>

					<h5><?php _e('Parameters:', 'puntwork'); ?></h5>
					<ul>
						<li><code>api_key</code> <?php _e('(required): Your API key', 'puntwork'); ?></li>
						<li><code>force</code> <?php _e('(optional): Set to', 'puntwork'); ?> <code>true</code> <?php _e('to force import even if one is running', 'puntwork'); ?></li>
						<li><code>test_mode</code> <?php _e('(optional): Set to', 'puntwork'); ?> <code>true</code> <?php _e('to run in test mode', 'puntwork'); ?></li>
					</ul>

					<h5><?php _e('Example cURL:', 'puntwork'); ?></h5>
					<pre><code>curl -X POST "<?php echo esc_url($site_url); ?>/wp-json/puntwork/v1/trigger-import" \
	-d "api_key=<?php echo esc_attr($api_key); ?>" \
	-d "force=false" \
	-d "test_mode=false"</code></pre>

					<h4><?php _e('Get Import Status', 'puntwork'); ?></h4>
					<code>GET <?php echo esc_url($site_url); ?>/wp-json/puntwork/v1/import-status</code>

					<h5><?php _e('Parameters:', 'puntwork'); ?></h5>
					<ul>
						<li><code>api_key</code> <?php _e('(required): Your API key', 'puntwork'); ?></li>
					</ul>

					<h5><?php _e('Example cURL:', 'puntwork'); ?></h5>
					<pre><code>curl "<?php echo esc_url($site_url); ?>/wp-json/puntwork/v1/import-status?api_key=<?php echo esc_attr($api_key); ?>"</code></pre>
				</div>

				<h3><?php _e('Security Notes', 'puntwork'); ?></h3>
				<div class="security-notes">
					<ul>
						<li><strong><?php _e('Keep your API key secure', 'puntwork'); ?></strong> - <?php _e('Store it safely and never share it publicly', 'puntwork'); ?></li>
						<li><strong><?php _e('Use HTTPS', 'puntwork'); ?></strong> - <?php _e('Always use HTTPS when making API requests', 'puntwork'); ?></li>
						<li><strong><?php _e('Rate limiting', 'puntwork'); ?></strong> - <?php _e('The API includes built-in rate limiting to prevent abuse', 'puntwork'); ?></li>
						<li><strong><?php _e('Logging', 'puntwork'); ?></strong> - <?php _e('All API requests are logged for security monitoring', 'puntwork'); ?></li>
						<li><strong><?php _e('Test mode', 'puntwork'); ?></strong> - <?php _e('Use test_mode=true for testing without affecting live data', 'puntwork'); ?></li>
					</ul>
				</div>
			</div>

			<div class="puntwork-api-section">
				<h2><?php _e('Rate Limiting Configuration', 'puntwork'); ?></h2>
				<p><?php _e('Configure rate limits for different AJAX actions to prevent abuse and ensure system stability.', 'puntwork'); ?></p>

				<?php
                // Handle rate limit form submissions
                if (isset($_POST['update_rate_limits']) && check_admin_referer('puntwork_rate_limits')) {
                    $rate_limits = [];
                    if (isset($_POST['rate_limits']) && is_array($_POST['rate_limits'])) {
                        foreach ($_POST['rate_limits'] as $action => $config) {
                            $max_requests = intval($config['max_requests'] ?? 0);
                            $time_window = intval($config['time_window'] ?? 0);

                            if ($max_requests > 0 && $time_window > 0) {
                                $rate_limits[$action] = [
                                    'max_requests' => $max_requests,
                                    'time_window' => $time_window,
                                ];
                            }
                        }
                    }

                    if (update_option('puntwork_rate_limits', $rate_limits)) {
                        echo '<div class="notice notice-success"><p>' . __('Rate limits updated successfully!', 'puntwork') . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>' . __('Failed to update rate limits.', 'puntwork') . '</p></div>';
                    }
                }

    if (isset($_POST['reset_rate_limits']) && check_admin_referer('puntwork_rate_limits')) {
        if (delete_option('puntwork_rate_limits')) {
            echo '<div class="notice notice-success"><p>' . __('Rate limits reset to defaults!', 'puntwork') . '</p></div>';
        } else {
            echo '<div class="notice notice-info"><p>' . __('Rate limits were already at defaults.', 'puntwork') . '</p></div>';
        }
    }

    // Get current rate limit configurations
    $rate_limit_configs = \Puntwork\SecurityUtils::getAllRateLimitConfigs();
    ?>

				<form method="post">
					<?php wp_nonce_field('puntwork_rate_limits'); ?>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php _e('Action', 'puntwork'); ?></th>
								<th><?php _e('Max Requests', 'puntwork'); ?></th>
								<th><?php _e('Time Window', 'puntwork'); ?></th>
								<th><?php _e('Description', 'puntwork'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
                $action_descriptions = [
                    'default' => __('Default rate limit for unspecified actions', 'puntwork'),
                    'run_job_import_batch' => __('Batch job import processing', 'puntwork'),
                    'get_job_import_status' => __('Import status checking', 'puntwork'),
                    'process_feed' => __('Feed processing operations', 'puntwork'),
                    'test_single_job_import' => __('Single job import testing', 'puntwork'),
                    'clear_rate_limits' => __('Rate limit clearing operations', 'puntwork'),
                ];

    foreach ($rate_limit_configs as $action => $config) :
        $is_custom = isset(get_option('puntwork_rate_limits', [])[$action]);
        ?>
							<tr>
								<td>
									<strong><?php echo esc_html($action); ?></strong>
									<?php if ($is_custom) : ?>
										<span class="dashicons dashicons-admin-generic" title="<?php esc_attr_e('Custom setting', 'puntwork'); ?>"></span>
									<?php endif; ?>
								</td>
								<td>
									<input type="number" name="rate_limits[<?php echo esc_attr($action); ?>][max_requests]"
											value="<?php echo esc_attr($config['max_requests']); ?>"
											min="1" max="1000" class="small-text" />
								</td>
								<td>
									<input type="number" name="rate_limits[<?php echo esc_attr($action); ?>][time_window]"
											value="<?php echo esc_attr($config['time_window']); ?>"
											min="1" max="86400" class="small-text" />
									<span class="description"><?php _e('seconds', 'puntwork'); ?></span>
								</td>
								<td><?php echo esc_html($action_descriptions[$action] ?? __('Custom action', 'puntwork')); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p class="description">
						<?php _e('Time window is in seconds. For example: 60 = 1 minute, 300 = 5 minutes, 3600 = 1 hour.', 'puntwork'); ?>
						<br>
						<?php _e('Changes take effect immediately. Be careful with very low limits as they may break functionality.', 'puntwork'); ?>
					</p>

					<div style="margin-top: 20px;">
						<input type="submit" name="update_rate_limits" value="<?php esc_attr_e('Update Rate Limits', 'puntwork'); ?>"
								class="puntwork-btn puntwork-btn--primary" />

						<input type="submit" name="reset_rate_limits" value="<?php esc_attr_e('Reset to Defaults', 'puntwork'); ?>"
								class="puntwork-btn puntwork-btn--outline"
								onclick="return confirm('<?php esc_js(__('Are you sure you want to reset all rate limits to defaults?', 'puntwork')); ?>');" />

						<a href="<?php echo esc_url(admin_url('admin.php?page=puntwork-api-settings')); ?>" class="puntwork-btn puntwork-btn--outline">
							<?php _e('Refresh', 'puntwork'); ?>
						</a>
					</div>
				</form>

				<h3><?php _e('Rate Limit Status', 'puntwork'); ?></h3>
				<div class="rate-limit-status">
					<p><?php _e('Current rate limit usage for your user account:', 'puntwork'); ?></p>
					<div id="rate-limit-status-content">
						<p class="description"><?php _e('Loading current status...', 'puntwork'); ?></p>
					</div>
				</div>
			</div>

			<div class="puntwork-api-section">
				<h2><?php _e('Dynamic Rate Limiting', 'puntwork'); ?></h2>
				<p><?php _e('Configure intelligent rate limiting that automatically adjusts based on server performance and usage patterns.', 'puntwork'); ?></p>

				<?php
                // Handle dynamic rate limit form submissions
                if (isset($_POST['update_dynamic_rate_config']) && check_admin_referer('puntwork_dynamic_rate_limits')) {
                    $dynamic_config = [
                        'enabled' => isset($_POST['dynamic_enabled']) ? true : false,
                        'monitoring_interval' => intval($_POST['monitoring_interval'] ?? 60),
                        'adjustment_interval' => intval($_POST['adjustment_interval'] ?? 300),
                        'max_adjustment_percentage' => intval($_POST['max_adjustment_percentage'] ?? 200),
                        'min_adjustment_percentage' => intval($_POST['min_adjustment_percentage'] ?? 25),
                        'cpu_threshold_high' => intval($_POST['cpu_threshold_high'] ?? 80),
                        'cpu_threshold_low' => intval($_POST['cpu_threshold_low'] ?? 30),
                        'memory_threshold_high' => intval($_POST['memory_threshold_high'] ?? 85),
                        'memory_threshold_low' => intval($_POST['memory_threshold_low'] ?? 50),
                        'response_time_threshold' => floatval($_POST['response_time_threshold'] ?? 2.0),
                        'error_rate_threshold' => intval($_POST['error_rate_threshold'] ?? 10),
                        'import_boost_factor' => floatval($_POST['import_boost_factor'] ?? 1.5),
                        'peak_hours_boost' => floatval($_POST['peak_hours_boost'] ?? 1.2),
                        'off_peak_reduction' => floatval($_POST['off_peak_reduction'] ?? 0.8),
                        'peak_hours_start' => intval($_POST['peak_hours_start'] ?? 9),
                        'peak_hours_end' => intval($_POST['peak_hours_end'] ?? 17),
                    ];

                    if (\Puntwork\DynamicRateLimiter::updateConfig($dynamic_config)) {
                        echo '<div class="notice notice-success"><p>' . __('Dynamic rate limiting configuration updated successfully!', 'puntwork') . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>' . __('Failed to update dynamic rate limiting configuration.', 'puntwork') . '</p></div>';
                    }
                }

                if (isset($_POST['reset_dynamic_rate_metrics']) && check_admin_referer('puntwork_dynamic_rate_limits')) {
                    if (\Puntwork\DynamicRateLimiter::reset()) {
                        echo '<div class="notice notice-success"><p>' . __('Dynamic rate limiting metrics reset successfully!', 'puntwork') . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>' . __('Failed to reset dynamic rate limiting metrics.', 'puntwork') . '</p></div>';
                    }
                }

                // Get current dynamic configuration
                $dynamic_config = \Puntwork\DynamicRateLimiter::getConfig();
                $dynamic_status = \Puntwork\DynamicRateLimiter::getStatus();
                ?>

				<form method="post">
					<?php wp_nonce_field('puntwork_dynamic_rate_limits'); ?>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php _e('Setting', 'puntwork'); ?></th>
								<th><?php _e('Value', 'puntwork'); ?></th>
								<th><?php _e('Description', 'puntwork'); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><strong><?php _e('Enable Dynamic Rate Limiting', 'puntwork'); ?></strong></td>
								<td>
									<input type="checkbox" name="dynamic_enabled" value="1" <?php checked($dynamic_config['enabled']); ?> />
								</td>
								<td><?php _e('Enable automatic rate limit adjustments based on system performance', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('Monitoring Interval', 'puntwork'); ?></td>
								<td>
									<input type="number" name="monitoring_interval" value="<?php echo esc_attr($dynamic_config['monitoring_interval']); ?>" min="10" max="3600" class="small-text" />
									<span class="description"><?php _e('seconds', 'puntwork'); ?></span>
								</td>
								<td><?php _e('How often to collect performance metrics', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('Adjustment Interval', 'puntwork'); ?></td>
								<td>
									<input type="number" name="adjustment_interval" value="<?php echo esc_attr($dynamic_config['adjustment_interval']); ?>" min="60" max="3600" class="small-text" />
									<span class="description"><?php _e('seconds', 'puntwork'); ?></span>
								</td>
								<td><?php _e('How often to recalculate rate limit adjustments', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('Max Adjustment %', 'puntwork'); ?></td>
								<td>
									<input type="number" name="max_adjustment_percentage" value="<?php echo esc_attr($dynamic_config['max_adjustment_percentage']); ?>" min="100" max="1000" class="small-text" />
									<span class="description">%</span>
								</td>
								<td><?php _e('Maximum rate limit increase (as percentage of base)', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('Min Adjustment %', 'puntwork'); ?></td>
								<td>
									<input type="number" name="min_adjustment_percentage" value="<?php echo esc_attr($dynamic_config['min_adjustment_percentage']); ?>" min="1" max="100" class="small-text" />
									<span class="description">%</span>
								</td>
								<td><?php _e('Minimum rate limit (as percentage of base)', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('High CPU Threshold', 'puntwork'); ?></td>
								<td>
									<input type="number" name="cpu_threshold_high" value="<?php echo esc_attr($dynamic_config['cpu_threshold_high']); ?>" min="50" max="100" class="small-text" />
									<span class="description">%</span>
								</td>
								<td><?php _e('CPU usage above this triggers rate limit reduction', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('Low CPU Threshold', 'puntwork'); ?></td>
								<td>
									<input type="number" name="cpu_threshold_low" value="<?php echo esc_attr($dynamic_config['cpu_threshold_low']); ?>" min="1" max="50" class="small-text" />
									<span class="description">%</span>
								</td>
								<td><?php _e('CPU usage below this allows rate limit increase', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('High Memory Threshold', 'puntwork'); ?></td>
								<td>
									<input type="number" name="memory_threshold_high" value="<?php echo esc_attr($dynamic_config['memory_threshold_high']); ?>" min="50" max="100" class="small-text" />
									<span class="description">%</span>
								</td>
								<td><?php _e('Memory usage above this triggers rate limit reduction', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('Low Memory Threshold', 'puntwork'); ?></td>
								<td>
									<input type="number" name="memory_threshold_low" value="<?php echo esc_attr($dynamic_config['memory_threshold_low']); ?>" min="1" max="80" class="small-text" />
									<span class="description">%</span>
								</td>
								<td><?php _e('Memory usage below this allows rate limit increase', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('Response Time Threshold', 'puntwork'); ?></td>
								<td>
									<input type="number" name="response_time_threshold" value="<?php echo esc_attr($dynamic_config['response_time_threshold']); ?>" min="0.1" max="10" step="0.1" class="small-text" />
									<span class="description"><?php _e('seconds', 'puntwork'); ?></span>
								</td>
								<td><?php _e('Response time above this triggers rate limit reduction', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('Error Rate Threshold', 'puntwork'); ?></td>
								<td>
									<input type="number" name="error_rate_threshold" value="<?php echo esc_attr($dynamic_config['error_rate_threshold']); ?>" min="1" max="50" class="small-text" />
									<span class="description">%</span>
								</td>
								<td><?php _e('Error rate above this triggers rate limit reduction', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('Import Boost Factor', 'puntwork'); ?></td>
								<td>
									<input type="number" name="import_boost_factor" value="<?php echo esc_attr($dynamic_config['import_boost_factor']); ?>" min="1.0" max="3.0" step="0.1" class="small-text" />
									<span class="description">x</span>
								</td>
								<td><?php _e('Multiplier for import operations', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('Peak Hours Boost', 'puntwork'); ?></td>
								<td>
									<input type="number" name="peak_hours_boost" value="<?php echo esc_attr($dynamic_config['peak_hours_boost']); ?>" min="1.0" max="2.0" step="0.1" class="small-text" />
									<span class="description">x</span>
								</td>
								<td><?php _e('Multiplier during peak hours', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('Off-Peak Reduction', 'puntwork'); ?></td>
								<td>
									<input type="number" name="off_peak_reduction" value="<?php echo esc_attr($dynamic_config['off_peak_reduction']); ?>" min="0.1" max="1.0" step="0.1" class="small-text" />
									<span class="description">x</span>
								</td>
								<td><?php _e('Multiplier during off-peak hours', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('Peak Hours Start', 'puntwork'); ?></td>
								<td>
									<input type="number" name="peak_hours_start" value="<?php echo esc_attr($dynamic_config['peak_hours_start']); ?>" min="0" max="23" class="small-text" />
									<span class="description"><?php _e('hour (24h)', 'puntwork'); ?></span>
								</td>
								<td><?php _e('Start hour for peak hours (0-23)', 'puntwork'); ?></td>
							</tr>
							<tr>
								<td><?php _e('Peak Hours End', 'puntwork'); ?></td>
								<td>
									<input type="number" name="peak_hours_end" value="<?php echo esc_attr($dynamic_config['peak_hours_end']); ?>" min="0" max="23" class="small-text" />
									<span class="description"><?php _e('hour (24h)', 'puntwork'); ?></span>
								</td>
								<td><?php _e('End hour for peak hours (0-23)', 'puntwork'); ?></td>
							</tr>
						</tbody>
					</table>

					<div style="margin-top: 20px;">
						<input type="submit" name="update_dynamic_rate_config" value="<?php esc_attr_e('Update Dynamic Configuration', 'puntwork'); ?>"
								class="puntwork-btn puntwork-btn--primary" />

						<input type="submit" name="reset_dynamic_rate_metrics" value="<?php esc_attr_e('Reset Metrics', 'puntwork'); ?>"
								class="puntwork-btn puntwork-btn--outline"
								onclick="return confirm('<?php esc_js(__('Are you sure you want to reset all dynamic rate limiting metrics?', 'puntwork')); ?>');" />

						<a href="<?php echo esc_url(admin_url('admin.php?page=puntwork-api-settings')); ?>" class="puntwork-btn puntwork-btn--outline">
							<?php _e('Refresh', 'puntwork'); ?>
						</a>
					</div>
				</form>

				<h3><?php _e('Dynamic Rate Limiting Status', 'puntwork'); ?></h3>
				<div class="dynamic-rate-status">
					<div class="status-grid">
						<div class="status-item">
							<strong><?php _e('Status:', 'puntwork'); ?></strong>
							<span class="status-indicator <?php echo $dynamic_status['enabled'] ? 'enabled' : 'disabled'; ?>">
								<?php echo $dynamic_status['enabled'] ? __('Enabled', 'puntwork') : __('Disabled', 'puntwork'); ?>
							</span>
						</div>
						<div class="status-item">
							<strong><?php _e('Total Metrics:', 'puntwork'); ?></strong>
							<span><?php echo number_format($dynamic_status['total_metrics']); ?></span>
						</div>
						<div class="status-item">
							<strong><?php _e('Recent Metrics:', 'puntwork'); ?></strong>
							<span><?php echo number_format($dynamic_status['recent_metrics']); ?></span>
						</div>
						<div class="status-item">
							<strong><?php _e('Current Load:', 'puntwork'); ?></strong>
							<span><?php echo number_format($dynamic_status['current_load'], 2); ?></span>
						</div>
						<div class="status-item">
							<strong><?php _e('Current Memory:', 'puntwork'); ?></strong>
							<span><?php echo number_format($dynamic_status['current_memory'], 1); ?>%</span>
						</div>
						<div class="status-item">
							<strong><?php _e('Current CPU:', 'puntwork'); ?></strong>
							<span><?php echo number_format($dynamic_status['current_cpu'], 1); ?>%</span>
						</div>
					</div>
					<p class="description" style="margin-top: 15px;">
						<?php _e('Dynamic rate limiting automatically adjusts limits based on server performance. Enable it to allow intelligent scaling of rate limits.', 'puntwork'); ?>
					</p>
				</div>
			</div>
		</div>
	</div>

	<style>
		.puntwork-api-settings {
			max-width: 1200px;
		}
		.api-key-container {
			display: flex;
			gap: 10px;
			align-items: center;
			margin-bottom: 20px;
		}
		.api-key-container input {
			flex: 1;
		}
		.endpoint-info {
			background: #f9f9f9;
			padding: 20px;
			border-radius: 5px;
			margin: 20px 0;
		}
		.endpoint-info h4 {
			margin-top: 0;
			color: #23282d;
		}
		.endpoint-info code {
			background: #2d3748;
			color: #e2e8f0;
			padding: 2px 6px;
			border-radius: 3px;
			font-family: monospace;
		}
		.endpoint-info pre {
			background: #2d3748;
			color: #e2e8f0;
			padding: 15px;
			border-radius: 5px;
			overflow-x: auto;
		}
		.endpoint-info ul {
			margin: 10px 0;
		}
		.security-notes {
			background: #fff3cd;
			border: 1px solid #ffeaa7;
			padding: 15px;
			border-radius: 5px;
		}
		.security-notes ul {
			margin: 0;
		}
		.rate-limit-status {
			background: #f8f9fa;
			padding: 15px;
			border-radius: 5px;
			margin-top: 20px;
		}
		.puntwork-btn {
			display: inline-block;
			padding: 8px 16px;
			margin: 0 5px 5px 0;
			border: 1px solid #007cba;
			border-radius: 4px;
			background: #007cba;
			color: #fff;
			text-decoration: none;
			font-size: 13px;
			line-height: 1.4;
			cursor: pointer;
		}
		.puntwork-btn--primary {
			background: #007cba;
			border-color: #007cba;
		}
		.puntwork-btn--outline {
			background: #fff;
			color: #007cba;
		}
		.puntwork-btn--danger {
			background: #dc3232;
			border-color: #dc3232;
		}
		.puntwork-btn:hover {
			opacity: 0.9;
		}
		.wp-list-table input[type="number"] {
			width: 80px;
		}
		.notice {
			padding: 4px 12px;
			margin: 0;
			border-left: 4px solid;
		}
		.notice-success {
			background-color: #d4edda;
			border-color: #c3e6cb;
			color: #155724;
		}
		.notice-warning {
			background-color: #fff3cd;
			border-color: #ffeaa7;
			color: #856404;
		}
		.notice-error {
			background-color: #f8d7da;
			border-color: #f5c6cb;
			color: #721c24;
		}
	</style>

	<script>
		jQuery(document).ready(function($) {
			const apiKeyInput = $('#api-key-display');
			const toggleBtn = $('#toggle-api-key');
			const copyBtn = $('#copy-api-key');

			// Initially hide the API key
			apiKeyInput.attr('type', 'password');

			toggleBtn.on('click', function() {
				const isPassword = apiKeyInput.attr('type') == 'password';
				apiKeyInput.attr('type', isPassword ? 'text' : 'password');
			});

			copyBtn.on('click', function() {
				apiKeyInput.select();
				document.execCommand('copy');

				const originalText = copyBtn.text();
				copyBtn.text('<?php echo esc_js(__('Copied!', 'puntwork')); ?>');
				setTimeout(function() {
					copyBtn.text(originalText);
				}, 2000);
			});

			// Load rate limit status
			loadRateLimitStatus();

			function loadRateLimitStatus() {
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'get_rate_limit_status',
						nonce: '<?php echo wp_create_nonce('puntwork_rate_limits'); ?>'
					},
					success: function(response) {
						if (response.success) {
							displayRateLimitStatus(response.data);
						} else {
							$('#rate-limit-status-content').html('<p class="description">' + (response.data || '<?php esc_js(_e('Failed to load rate limit status.', 'puntwork')); ?>') + '</p>');
						}
					},
					error: function() {
						$('#rate-limit-status-content').html('<p class="description"><?php esc_js(_e('Failed to load rate limit status.', 'puntwork')); ?></p>');
					}
				});
			}

			function displayRateLimitStatus(data) {
				let html = '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
				html += '<thead><tr><th><?php esc_js(_e('Action', 'puntwork')); ?></th><th><?php esc_js(_e('Requests in Window', 'puntwork')); ?></th><th><?php esc_js(_e('Limit', 'puntwork')); ?></th><th><?php esc_js(_e('Status', 'puntwork')); ?></th></tr></thead>';
				html += '<tbody>';

				for (const [action, status] of Object.entries(data)) {
					const percentage = status.limit > 0 ? Math.round((status.requests / status.limit) * 100) : 0;
					let statusClass = 'notice-success';
					let statusText = '<?php esc_js(_e('OK', 'puntwork')); ?>';

					if (percentage >= 90) {
						statusClass = 'notice-error';
						statusText = '<?php esc_js(_e('Near Limit', 'puntwork')); ?>';
					} else if (percentage >= 70) {
						statusClass = 'notice-warning';
						statusText = '<?php esc_js(_e('High Usage', 'puntwork')); ?>';
					}

					html += '<tr>';
					html += '<td><strong>' + action + '</strong></td>';
					html += '<td>' + status.requests + '</td>';
					html += '<td>' + status.limit + '</td>';
					html += '<td><span class="notice ' + statusClass + '" style="padding: 2px 8px; margin: 0; display: inline-block;">' + statusText + ' (' + percentage + '%)</span></td>';
					html += '</tr>';
				}

				html += '</tbody></table>';
				html += '<p class="description" style="margin-top: 10px;"><?php esc_js(_e('Status updates every 30 seconds. Click refresh to update manually.', 'puntwork')); ?></p>';

				$('#rate-limit-status-content').html(html);
			}

			// Auto-refresh rate limit status every 30 seconds
			setInterval(loadRateLimitStatus, 30000);

			// Dynamic rate limiting functionality
			function refreshDynamicStatus() {
				const statusContainer = $('.dynamic-rate-status .status-grid');
				statusContainer.addClass('loading');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'get_dynamic_rate_status',
						nonce: '<?php echo wp_create_nonce('puntwork_dynamic_rate_limits'); ?>'
					},
					success: function(response) {
						if (response.success) {
							updateDynamicStatusDisplay(response.data);
						}
					},
					error: function() {
						console.error('Failed to load dynamic rate limiting status');
					},
					complete: function() {
						statusContainer.removeClass('loading');
					}
				});
			}

			function updateDynamicStatusDisplay(data) {
				// Update status indicator
				const statusIndicator = $('.status-indicator');
				statusIndicator.removeClass('enabled disabled');
				statusIndicator.addClass(data.enabled ? 'enabled' : 'disabled');
				statusIndicator.text(data.enabled ? '<?php esc_js(_e('Enabled', 'puntwork')); ?>' : '<?php esc_js(_e('Disabled', 'puntwork')); ?>');

				// Update metrics
				$('.status-item:contains("Total Metrics") span').text(number_format(data.total_metrics));
				$('.status-item:contains("Recent Metrics") span').text(number_format(data.recent_metrics));
				$('.status-item:contains("Current Load") span').text(number_format(data.current_load, 2));
				$('.status-item:contains("Current Memory") span').text(number_format(data.current_memory, 1) + '%');
				$('.status-item:contains("Current CPU") span').text(number_format(data.current_cpu, 1) + '%');
			}

			function number_format(number, decimals = 0) {
				return new Intl.NumberFormat('en-US', {
					minimumFractionDigits: decimals,
					maximumFractionDigits: decimals
				}).format(number);
			}

			// Auto-refresh dynamic status every 60 seconds
			setInterval(refreshDynamicStatus, 60000);

			// Handle dynamic configuration form validation
			$('form input[name="update_dynamic_rate_config"]').closest('form').on('submit', function(e) {
				const form = $(this);
				const inputs = form.find('input[type="number"]');
				let isValid = true;

				inputs.each(function() {
					const input = $(this);
					const value = parseFloat(input.val());
					const min = parseFloat(input.attr('min'));
					const max = parseFloat(input.attr('max'));

					if (value < min || value > max) {
						alert('<?php esc_js(_e('Please enter valid values within the allowed ranges.', 'puntwork')); ?>');
						input.focus();
						isValid = false;
						return false;
					}
				});

				if (!isValid) {
					e.preventDefault();
					return false;
				}

				// Show loading state
				const submitBtn = form.find('input[type="submit"][name="update_dynamic_rate_config"]');
				const originalText = submitBtn.val();
				submitBtn.val('<?php esc_js(_e('Updating...', 'puntwork')); ?>').prop('disabled', true);

				// Re-enable after form submission (will be handled by page reload)
				setTimeout(function() {
					submitBtn.val(originalText).prop('disabled', false);
				}, 1000);
			});

			// Handle reset metrics confirmation
			$('input[name="reset_dynamic_rate_metrics"]').on('click', function(e) {
				const confirmed = confirm('<?php esc_js(_e('Are you sure you want to reset all dynamic rate limiting metrics? This will clear all performance data and may temporarily affect rate limit accuracy.', 'puntwork')); ?>');
				if (!confirmed) {
					e.preventDefault();
					return false;
				}

				const btn = $(this);
				const originalText = btn.val();
				btn.val('<?php esc_js(_e('Resetting...', 'puntwork')); ?>').prop('disabled', true);
			});
		});
	</script>
	<?php
}
