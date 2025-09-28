<?php

/**
 * API Settings admin page
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      1.0.7
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API Settings page callback
 */
function api_settings_page() {
	// Enqueue admin modern styles
	wp_enqueue_style( 'puntwork-admin-modern', PUNTWORK_URL . 'assets/css/admin-modern.css', array(), PUNTWORK_VERSION );

	// Handle form submissions
	if ( isset( $_POST['regenerate_api_key'] ) && check_admin_referer( 'puntwork_api_settings' ) ) {
		$new_key = regenerate_api_key();
		echo '<div class="notice notice-success"><p>' . __( 'API key regenerated successfully!', 'puntwork' ) . '</p></div>';
	}

	$api_key  = get_or_create_api_key();
	$site_url = get_site_url();

	?>
	<div class="wrap">
		<h1><?php _e( 'API Settings', 'puntwork' ); ?></h1>

		<div class="puntwork-api-settings">
			<div class="puntwork-api-section">
				<h2><?php _e( 'Remote Import Trigger', 'puntwork' ); ?></h2>
				<p><?php _e( 'Use these endpoints to trigger imports remotely via HTTP requests.', 'puntwork' ); ?></p>

				<h3><?php _e( 'API Key', 'puntwork' ); ?></h3>
				<div class="api-key-container">
					<input type="text" id="api-key-display" value="<?php echo esc_attr( $api_key ); ?>" readonly class="regular-text">
					<button type="button" id="toggle-api-key" class="puntwork-btn puntwork-btn--outline"><?php _e( 'Show/Hide', 'puntwork' ); ?></button>
					<button type="button" id="copy-api-key" class="puntwork-btn puntwork-btn--secondary"><?php _e( 'Copy', 'puntwork' ); ?></button>
				</div>

				<form method="post" style="margin-top: 20px;">
					<?php wp_nonce_field( 'puntwork_api_settings' ); ?>
					<input type="submit" name="regenerate_api_key" value="<?php esc_attr_e( 'Regenerate API Key', 'puntwork' ); ?>" class="puntwork-btn puntwork-btn--danger"
							onclick="return confirm('<?php esc_js( __( 'Are you sure? This will invalidate the current API key.', 'puntwork' ) ); ?>');">
				</form>

				<h3><?php _e( 'API Endpoints', 'puntwork' ); ?></h3>
				<div class="endpoint-info">
					<h4><?php _e( 'Trigger Import', 'puntwork' ); ?></h4>
					<code>POST <?php echo esc_url( $site_url ); ?>/wp-json/puntwork/v1/trigger-import</code>

					<h5><?php _e( 'Parameters:', 'puntwork' ); ?></h5>
					<ul>
						<li><code>api_key</code> <?php _e( '(required): Your API key', 'puntwork' ); ?></li>
						<li><code>force</code> <?php _e( '(optional): Set to', 'puntwork' ); ?> <code>true</code> <?php _e( 'to force import even if one is running', 'puntwork' ); ?></li>
						<li><code>test_mode</code> <?php _e( '(optional): Set to', 'puntwork' ); ?> <code>true</code> <?php _e( 'to run in test mode', 'puntwork' ); ?></li>
					</ul>

					<h5><?php _e( 'Example cURL:', 'puntwork' ); ?></h5>
					<pre><code>curl -X POST "<?php echo esc_url( $site_url ); ?>/wp-json/puntwork/v1/trigger-import" \
	-d "api_key=<?php echo esc_attr( $api_key ); ?>" \
	-d "force=false" \
	-d "test_mode=false"</code></pre>

					<h4><?php _e( 'Get Import Status', 'puntwork' ); ?></h4>
					<code>GET <?php echo esc_url( $site_url ); ?>/wp-json/puntwork/v1/import-status</code>

					<h5><?php _e( 'Parameters:', 'puntwork' ); ?></h5>
					<ul>
						<li><code>api_key</code> <?php _e( '(required): Your API key', 'puntwork' ); ?></li>
					</ul>

					<h5><?php _e( 'Example cURL:', 'puntwork' ); ?></h5>
					<pre><code>curl "<?php echo esc_url( $site_url ); ?>/wp-json/puntwork/v1/import-status?api_key=<?php echo esc_attr( $api_key ); ?>"</code></pre>
				</div>

				<h3><?php _e( 'Security Notes', 'puntwork' ); ?></h3>
				<div class="security-notes">
					<ul>
						<li><strong><?php _e( 'Keep your API key secure', 'puntwork' ); ?></strong> - <?php _e( 'Store it safely and never share it publicly', 'puntwork' ); ?></li>
						<li><strong><?php _e( 'Use HTTPS', 'puntwork' ); ?></strong> - <?php _e( 'Always use HTTPS when making API requests', 'puntwork' ); ?></li>
						<li><strong><?php _e( 'Rate limiting', 'puntwork' ); ?></strong> - <?php _e( 'The API includes built-in rate limiting to prevent abuse', 'puntwork' ); ?></li>
						<li><strong><?php _e( 'Logging', 'puntwork' ); ?></strong> - <?php _e( 'All API requests are logged for security monitoring', 'puntwork' ); ?></li>
						<li><strong><?php _e( 'Test mode', 'puntwork' ); ?></strong> - <?php _e( 'Use test_mode=true for testing without affecting live data', 'puntwork' ); ?></li>
					</ul>
				</div>
			</div>

			<div class="puntwork-api-section">
				<h2><?php _e( 'Rate Limiting Configuration', 'puntwork' ); ?></h2>
				<p><?php _e( 'Configure rate limits for different AJAX actions to prevent abuse and ensure system stability.', 'puntwork' ); ?></p>

				<?php
				// Handle rate limit form submissions
				if ( isset( $_POST['update_rate_limits'] ) && check_admin_referer( 'puntwork_rate_limits' ) ) {
					$rate_limits = array();
					if ( isset( $_POST['rate_limits'] ) && is_array( $_POST['rate_limits'] ) ) {
						foreach ( $_POST['rate_limits'] as $action => $config ) {
							$max_requests = intval( $config['max_requests'] ?? 0 );
							$time_window  = intval( $config['time_window'] ?? 0 );

							if ( $max_requests > 0 && $time_window > 0 ) {
								$rate_limits[ $action ] = array(
									'max_requests' => $max_requests,
									'time_window'  => $time_window,
								);
							}
						}
					}

					if ( update_option( 'puntwork_rate_limits', $rate_limits ) ) {
						echo '<div class="notice notice-success"><p>' . __( 'Rate limits updated successfully!', 'puntwork' ) . '</p></div>';
					} else {
						echo '<div class="notice notice-error"><p>' . __( 'Failed to update rate limits.', 'puntwork' ) . '</p></div>';
					}
				}

				if ( isset( $_POST['reset_rate_limits'] ) && check_admin_referer( 'puntwork_rate_limits' ) ) {
					if ( delete_option( 'puntwork_rate_limits' ) ) {
						echo '<div class="notice notice-success"><p>' . __( 'Rate limits reset to defaults!', 'puntwork' ) . '</p></div>';
					} else {
						echo '<div class="notice notice-info"><p>' . __( 'Rate limits were already at defaults.', 'puntwork' ) . '</p></div>';
					}
				}

				// Get current rate limit configurations
				$rate_limit_configs = \Puntwork\SecurityUtils::getAllRateLimitConfigs();
				?>

				<form method="post">
					<?php wp_nonce_field( 'puntwork_rate_limits' ); ?>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php _e( 'Action', 'puntwork' ); ?></th>
								<th><?php _e( 'Max Requests', 'puntwork' ); ?></th>
								<th><?php _e( 'Time Window', 'puntwork' ); ?></th>
								<th><?php _e( 'Description', 'puntwork' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$action_descriptions = array(
								'default'                => __( 'Default rate limit for unspecified actions', 'puntwork' ),
								'run_job_import_batch'   => __( 'Batch job import processing', 'puntwork' ),
								'get_job_import_status'  => __( 'Import status checking', 'puntwork' ),
								'process_feed'           => __( 'Feed processing operations', 'puntwork' ),
								'test_single_job_import' => __( 'Single job import testing', 'puntwork' ),
								'clear_rate_limits'      => __( 'Rate limit clearing operations', 'puntwork' ),
							);

							foreach ( $rate_limit_configs as $action => $config ) :
								$is_custom = isset( get_option( 'puntwork_rate_limits', array() )[ $action ] );
								?>
							<tr>
								<td>
									<strong><?php echo esc_html( $action ); ?></strong>
									<?php if ( $is_custom ) : ?>
										<span class="dashicons dashicons-admin-generic" title="<?php esc_attr_e( 'Custom setting', 'puntwork' ); ?>"></span>
									<?php endif; ?>
								</td>
								<td>
									<input type="number" name="rate_limits[<?php echo esc_attr( $action ); ?>][max_requests]"
											value="<?php echo esc_attr( $config['max_requests'] ); ?>"
											min="1" max="1000" class="small-text" />
								</td>
								<td>
									<input type="number" name="rate_limits[<?php echo esc_attr( $action ); ?>][time_window]"
											value="<?php echo esc_attr( $config['time_window'] ); ?>"
											min="1" max="86400" class="small-text" />
									<span class="description"><?php _e( 'seconds', 'puntwork' ); ?></span>
								</td>
								<td><?php echo esc_html( $action_descriptions[ $action ] ?? __( 'Custom action', 'puntwork' ) ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p class="description">
						<?php _e( 'Time window is in seconds. For example: 60 = 1 minute, 300 = 5 minutes, 3600 = 1 hour.', 'puntwork' ); ?>
						<br>
						<?php _e( 'Changes take effect immediately. Be careful with very low limits as they may break functionality.', 'puntwork' ); ?>
					</p>

					<div style="margin-top: 20px;">
						<input type="submit" name="update_rate_limits" value="<?php esc_attr_e( 'Update Rate Limits', 'puntwork' ); ?>"
								class="puntwork-btn puntwork-btn--primary" />

						<input type="submit" name="reset_rate_limits" value="<?php esc_attr_e( 'Reset to Defaults', 'puntwork' ); ?>"
								class="puntwork-btn puntwork-btn--outline"
								onclick="return confirm('<?php esc_js( __( 'Are you sure you want to reset all rate limits to defaults?', 'puntwork' ) ); ?>');" />

						<a href="<?php echo esc_url( admin_url( 'admin.php?page=puntwork-api-settings' ) ); ?>" class="puntwork-btn puntwork-btn--outline">
							<?php _e( 'Refresh', 'puntwork' ); ?>
						</a>
					</div>
				</form>

				<h3><?php _e( 'Rate Limit Status', 'puntwork' ); ?></h3>
				<div class="rate-limit-status">
					<p><?php _e( 'Current rate limit usage for your user account:', 'puntwork' ); ?></p>
					<div id="rate-limit-status-content">
						<p class="description"><?php _e( 'Loading current status...', 'puntwork' ); ?></p>
					</div>
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
				copyBtn.text('<?php echo esc_js( __( 'Copied!', 'puntwork' ) ); ?>');
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
						nonce: '<?php echo wp_create_nonce( 'puntwork_rate_limits' ); ?>'
					},
					success: function(response) {
						if (response.success) {
							displayRateLimitStatus(response.data);
						} else {
							$('#rate-limit-status-content').html('<p class="description">' + (response.data || '<?php esc_js( _e( 'Failed to load rate limit status.', 'puntwork' ) ); ?>') + '</p>');
						}
					},
					error: function() {
						$('#rate-limit-status-content').html('<p class="description"><?php esc_js( _e( 'Failed to load rate limit status.', 'puntwork' ) ); ?></p>');
					}
				});
			}

			function displayRateLimitStatus(data) {
				let html = '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
				html += '<thead><tr><th><?php esc_js( _e( 'Action', 'puntwork' ) ); ?></th><th><?php esc_js( _e( 'Requests in Window', 'puntwork' ) ); ?></th><th><?php esc_js( _e( 'Limit', 'puntwork' ) ); ?></th><th><?php esc_js( _e( 'Status', 'puntwork' ) ); ?></th></tr></thead>';
				html += '<tbody>';

				for (const [action, status] of Object.entries(data)) {
					const percentage = status.limit > 0 ? Math.round((status.requests / status.limit) * 100) : 0;
					let statusClass = 'notice-success';
					let statusText = '<?php esc_js( _e( 'OK', 'puntwork' ) ); ?>';

					if (percentage >= 90) {
						statusClass = 'notice-error';
						statusText = '<?php esc_js( _e( 'Near Limit', 'puntwork' ) ); ?>';
					} else if (percentage >= 70) {
						statusClass = 'notice-warning';
						statusText = '<?php esc_js( _e( 'High Usage', 'puntwork' ) ); ?>';
					}

					html += '<tr>';
					html += '<td><strong>' + action + '</strong></td>';
					html += '<td>' + status.requests + '</td>';
					html += '<td>' + status.limit + '</td>';
					html += '<td><span class="notice ' + statusClass + '" style="padding: 2px 8px; margin: 0; display: inline-block;">' + statusText + ' (' + percentage + '%)</span></td>';
					html += '</tr>';
				}

				html += '</tbody></table>';
				html += '<p class="description" style="margin-top: 10px;"><?php esc_js( _e( 'Status updates every 30 seconds. Click refresh to update manually.', 'puntwork' ) ); ?></p>';

				$('#rate-limit-status-content').html(html);
			}

			// Auto-refresh rate limit status every 30 seconds
			setInterval(loadRateLimitStatus, 30000);
		});
	</script>
	<?php
}