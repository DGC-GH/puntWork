<?php

/**
 * Multi-Site Admin Interface
 *
 * Provides admin interface for managing network-wide job distribution.
 *
 * @package    Puntwork
 * @subpackage MultiSite
 * @since      0.0.4
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-Site Admin UI
 */
class MultiSiteAdminUI {

	/**
	 * Initialize admin interface
	 */
	public static function init(): void {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'admin_menu', array( self::class, 'addAdminMenu' ) );
		add_action( 'admin_enqueueScripts', array( self::class, 'enqueueScripts' ) );
		add_action( 'wp_ajax_puntwork_network_test_connection', array( self::class, 'ajaxTestConnection' ) );
	}

	/**
	 * Add admin menu
	 */
	public static function addAdminMenu(): void {
		add_submenu_page(
			'puntwork-admin',
			__( 'Network Management', 'puntwork' ),
			__( 'Network', 'puntwork' ),
			'manage_options',
			'puntwork-network',
			array( self::class, 'renderAdminPage' )
		);
	}

	/**
	 * Enqueue admin scripts and styles
	 */
	public static function enqueueScripts( string $hook ): void {
		if ( $hook !== 'puntwork_page_puntwork-network' ) {
			return;
		}

		wp_enqueue_style( 'puntwork-network-admin', plugins_url( 'assets/css/network-admin.css', dirname( __DIR__, 1 ) ), array(), '0.0.4' );
		wp_enqueue_script( 'puntwork-network-admin', plugins_url( 'assets/js/network-admin.js', dirname( __DIR__, 1 ) ), array( 'jquery' ), '0.0.4', true );

		wp_localize_script(
			'puntwork-network-admin',
			'puntworkNetwork',
			array(
				'ajax_url'         => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'puntwork_network_admin' ),
				'sync_nonce'       => wp_create_nonce( 'puntwork_network_sync' ),
				'stats_nonce'      => wp_create_nonce( 'puntwork_network_stats' ),
				'distribute_nonce' => wp_create_nonce( 'puntwork_network_distribute' ),
				'strings'          => array(
					'syncing'               => __( 'Syncing network data...', 'puntwork' ),
					'sync_complete'         => __( 'Network sync completed', 'puntwork' ),
					'sync_failed'           => __( 'Network sync failed', 'puntwork' ),
					'testing_connection'    => __( 'Testing connection...', 'puntwork' ),
					'connection_success'    => __( 'Connection successful', 'puntwork' ),
					'connection_failed'     => __( 'Connection failed', 'puntwork' ),
					'distributing'          => __( 'Distributing jobs...', 'puntwork' ),
					'distribution_complete' => __( 'Jobs distributed successfully', 'puntwork' ),
					'distribution_failed'   => __( 'Job distribution failed', 'puntwork' ),
				),
			)
		);
	}

	/**
	 * Render admin page
	 */
	public static function renderAdminPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}

		?>
		<div class="wrap">
			<h1><?php _e( 'Network Management', 'puntwork' ); ?></h1>

			<div class="puntwork-network-container">
				<!-- Network Overview -->
				<div class="puntwork-network-section">
					<h2><?php _e( 'Network Overview', 'puntwork' ); ?></h2>
					<div id="network-overview" class="network-overview-grid">
						<div class="network-stat-card">
							<h3><?php _e( 'Total Sites', 'puntwork' ); ?></h3>
							<div class="stat-value" id="total-sites">-</div>
						</div>
						<div class="network-stat-card">
							<h3><?php _e( 'Active Sites', 'puntwork' ); ?></h3>
							<div class="stat-value" id="active-sites">-</div>
						</div>
						<div class="network-stat-card">
							<h3><?php _e( 'Total Jobs', 'puntwork' ); ?></h3>
							<div class="stat-value" id="total-jobs">-</div>
						</div>
						<div class="network-stat-card">
							<h3><?php _e( 'Avg Success Rate', 'puntwork' ); ?></h3>
							<div class="stat-value" id="avg-success-rate">-%</div>
						</div>
					</div>
				</div>

				<!-- Network Settings -->
				<div class="puntwork-network-section">
					<h2><?php _e( 'Network Settings', 'puntwork' ); ?></h2>
					<form method="post" action="options.php">
						<?php settings_fields( 'puntwork_network' ); ?>

						<table class="form-table">
							<tr>
								<th scope="row"><?php _e( 'Distribution Strategy', 'puntwork' ); ?></th>
								<td>
									<select name="puntwork_network_distribution_strategy" id="distribution-strategy">
										<option value="round_robin" <?php selected( get_option( 'puntwork_network_distribution_strategy' ), 'round_robin' ); ?>>
											<?php _e( 'Round Robin', 'puntwork' ); ?>
										</option>
										<option value="load_balanced" <?php selected( get_option( 'puntwork_network_distribution_strategy' ), 'load_balanced' ); ?>>
											<?php _e( 'Load Balanced', 'puntwork' ); ?>
										</option>
										<option value="capability_based" <?php selected( get_option( 'puntwork_network_distribution_strategy' ), 'capability_based' ); ?>>
											<?php _e( 'Capability Based', 'puntwork' ); ?>
										</option>
										<option value="geographic" <?php selected( get_option( 'puntwork_network_distribution_strategy' ), 'geographic' ); ?>>
											<?php _e( 'Geographic', 'puntwork' ); ?>
										</option>
									</select>
									<p class="description">
										<?php _e( 'Choose how jobs are distributed across network sites.', 'puntwork' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php _e( 'Network Sync', 'puntwork' ); ?></th>
								<td>
									<label for="network-sync-enabled">
										<input type="checkbox" id="network-sync-enabled" name="puntwork_network_sync_enabled" value="1" <?php checked( get_option( 'puntwork_network_sync_enabled', true ) ); ?> />
										<?php _e( 'Enable automatic network synchronization', 'puntwork' ); ?>
									</label>
									<p class="description">
										<?php _e( 'Automatically sync job templates and configurations across sites.', 'puntwork' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php _e( 'Max Sites', 'puntwork' ); ?></th>
								<td>
									<input type="number" id="max-sites" name="puntwork_network_max_sites" value="<?php echo esc_attr( get_option( 'puntwork_network_max_sites', 10 ) ); ?>" min="1" max="100" />
									<p class="description">
										<?php _e( 'Maximum number of sites to include in network operations.', 'puntwork' ); ?>
									</p>
								</td>
							</tr>
						</table>

						<?php submit_button( __( 'Save Settings', 'puntwork' ) ); ?>
					</form>
				</div>

				<!-- Site Management -->
				<div class="puntwork-network-section">
					<h2><?php _e( 'Site Management', 'puntwork' ); ?></h2>
					<div class="site-management-actions">
						<button type="button" id="sync-network" class="puntwork-btn puntwork-btn--primary">
							<?php _e( 'Sync Network Data', 'puntwork' ); ?>
						</button>
						<button type="button" id="refresh-stats" class="button">
							<?php _e( 'Refresh Statistics', 'puntwork' ); ?>
						</button>
					</div>

					<div id="sites-list" class="sites-grid">
						<!-- Sites will be loaded here -->
					</div>
				</div>

				<!-- Job Distribution -->
				<div class="puntwork-network-section">
					<h2><?php _e( 'Job Distribution', 'puntwork' ); ?></h2>
					<div class="distribution-controls">
						<div class="distribution-form">
							<textarea id="distribution-jobs" placeholder="<?php esc_attr_e( 'Enter jobs to distribute (JSON format)', 'puntwork' ); ?>" rows="5"></textarea>
							<div class="distribution-actions">
								<select id="distribution-strategy-select">
									<option value=""><?php _e( 'Use Default Strategy', 'puntwork' ); ?></option>
									<option value="round_robin"><?php _e( 'Round Robin', 'puntwork' ); ?></option>
									<option value="load_balanced"><?php _e( 'Load Balanced', 'puntwork' ); ?></option>
									<option value="capability_based"><?php _e( 'Capability Based', 'puntwork' ); ?></option>
									<option value="geographic"><?php _e( 'Geographic', 'puntwork' ); ?></option>
								</select>
								<button type="button" id="distribute-jobs" class="puntwork-btn puntwork-btn--primary">
									<?php _e( 'Distribute Jobs', 'puntwork' ); ?>
								</button>
							</div>
						</div>
					</div>

					<div id="distribution-results" class="distribution-results" style="display: none;">
						<!-- Distribution results will be shown here -->
					</div>
				</div>
			</div>

			<!-- Network Activity Log -->
			<div class="puntwork-network-section">
				<h2><?php _e( 'Network Activity', 'puntwork' ); ?></h2>
				<div id="network-activity" class="network-activity-log">
					<!-- Activity log will be loaded here -->
				</div>
			</div>
		</div>

		<style>
		.puntwork-network-container {
			margin-top: 20px;
		}

		.puntwork-network-section {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			margin-bottom: 20px;
			padding: 20px;
		}

		.puntwork-network-section h2 {
			margin-top: 0;
			margin-bottom: 20px;
			padding-bottom: 10px;
			border-bottom: 1px solid #eee;
		}

		.network-overview-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 20px;
			margin-bottom: 20px;
		}

		.network-stat-card {
			background: #f8f9fa;
			border: 1px solid #dee2e6;
			border-radius: 6px;
			padding: 20px;
			text-align: center;
		}

		.network-stat-card h3 {
			margin: 0 0 10px 0;
			color: #495057;
			font-size: 14px;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}

		.stat-value {
			font-size: 32px;
			font-weight: bold;
			color: #007cba;
			margin-bottom: 5px;
		}

		.site-management-actions {
			margin-bottom: 20px;
		}

		.sites-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
			gap: 20px;
		}

		.site-card {
			border: 1px solid #dee2e6;
			border-radius: 6px;
			padding: 15px;
			background: #fff;
		}

		.site-card-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 10px;
		}

		.site-name {
			font-weight: 600;
			color: #333;
		}

		.site-status {
			padding: 2px 8px;
			border-radius: 12px;
			font-size: 12px;
			font-weight: 500;
		}

		.site-status.active {
			background: #d4edda;
			color: #155724;
		}

		.site-status.inactive {
			background: #f8d7da;
			color: #721c24;
		}

		.site-metrics {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 10px;
			font-size: 13px;
		}

		.site-metric {
			display: flex;
			justify-content: space-between;
		}

		.site-metric-label {
			color: #666;
		}

		.site-metric-value {
			font-weight: 600;
		}

		.distribution-form {
			margin-bottom: 20px;
		}

		#distribution-jobs {
			width: 100%;
			margin-bottom: 10px;
			font-family: monospace;
		}

		.distribution-actions {
			display: flex;
			gap: 10px;
			align-items: center;
		}

		.distribution-results {
			background: #f8f9fa;
			border: 1px solid #dee2e6;
			border-radius: 4px;
			padding: 15px;
		}

		.network-activity-log {
			background: #f8f9fa;
			border: 1px solid #dee2e6;
			border-radius: 4px;
			padding: 15px;
			max-height: 300px;
			overflow-y: auto;
			font-family: monospace;
			font-size: 13px;
		}

		.activity-entry {
			margin-bottom: 8px;
			padding-bottom: 8px;
			border-bottom: 1px solid #eee;
		}

		.activity-entry:last-child {
			border-bottom: none;
		}

		.activity-timestamp {
			color: #666;
			font-size: 11px;
		}

		.activity-message {
			margin-top: 2px;
		}

		.activity-error {
			color: #dc3545;
		}

		.activity-success {
			color: #28a745;
		}

		.loading {
			text-align: center;
			padding: 20px;
		}

		.spinner {
			border: 4px solid #f3f3f3;
			border-top: 4px solid #007cba;
			border-radius: 50%;
			width: 20px;
			height: 20px;
			animation: spin 1s linear infinite;
			display: inline-block;
			margin-right: 10px;
		}

		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}
		</style>

		<script>
		jQuery(document).ready(function($) {
			// Load initial data
			loadNetworkStats();
			loadSitesList();
			loadActivityLog();

			// Bind events
			$('#sync-network').on('click', function() {
				syncNetworkData();
			});

			$('#refresh-stats').on('click', function() {
				loadNetworkStats();
				loadSitesList();
			});

			$('#distribute-jobs').on('click', function() {
				distributeJobs();
			});

			// Auto-refresh stats every 30 seconds
			setInterval(function() {
				loadNetworkStats();
			}, 30000);
		});

		function loadNetworkStats() {
			$.ajax({
				url: puntworkNetwork.ajax_url,
				type: 'POST',
				data: {
					action: 'get_ml_insights',
					nonce: puntworkNetwork.stats_nonce
				},
				success: function(response) {
					if (response.success) {
						updateNetworkStats(response.data);
					}
				}
			});
		}

		function updateNetworkStats(data) {
			$('#total-sites').text(data.total_sites || 0);
			$('#active-sites').text(data.active_sites || 0);
			$('#total-jobs').text(data.total_jobs || 0);
			$('#avg-success-rate').text((data.avg_success_rate || 0) + '%');
		}

		function loadSitesList() {
			$('#sites-list').html('<div class="loading"><div class="spinner"></div>Loading sites...</div>');

			$.ajax({
				url: puntworkNetwork.ajax_url,
				type: 'POST',
				data: {
					action: 'get_network_stats',
					nonce: puntworkNetwork.stats_nonce
				},
				success: function(response) {
					if (response.success) {
						renderSitesList(response.data.sites);
					} else {
						$('#sites-list').html('<p>Error loading sites: ' + response.data + '</p>');
					}
				},
				error: function() {
					$('#sites-list').html('<p>Error loading sites</p>');
				}
			});
		}

		function renderSitesList(sites) {
			if (!sites || sites.length === 0) {
				$('#sites-list').html('<p>No sites found in network</p>');
				return;
			}

			let html = '';
			sites.forEach(function(site) {
				const statusClass = site.stats.active_feeds > 0 ? 'active' : 'inactive';
				const statusText = site.stats.active_feeds > 0 ? 'Active' : 'Inactive';

				html += '<div class="site-card">';
				html += '<div class="site-card-header">';
				html += '<span class="site-name">' + site.name + '</span>';
				html += '<span class="site-status ' + statusClass + '">' + statusText + '</span>';
				html += '</div>';
				html += '<div class="site-metrics">';
				html += '<div class="site-metric"><span class="site-metric-label">Jobs:</span> <span class="site-metric-value">' + site.stats.total_jobs + '</span></div>';
				html += '<div class="site-metric"><span class="site-metric-label">Feeds:</span> <span class="site-metric-value">' + site.stats.active_feeds + '</span></div>';
				html += '<div class="site-metric"><span class="site-metric-label">Success:</span> <span class="site-metric-value">' + site.stats.success_rate + '%</span></div>';
				html += '<div class="site-metric"><span class="site-metric-label">Load:</span> <span class="site-metric-value">' + site.stats.current_load + '%</span></div>';
				html += '</div>';
				html += '</div>';
			});

			$('#sites-list').html(html);
		}

		function syncNetworkData() {
			const $button = $('#sync-network');
			const originalText = $button.text();

			$button.prop('disabled', true).text(puntworkNetwork.strings.syncing);

			$.ajax({
				url: puntworkNetwork.ajax_url,
				type: 'POST',
				data: {
					action: 'sync_network_jobs',
					nonce: puntworkNetwork.sync_nonce
				},
				success: function(response) {
					if (response.success) {
						$button.text(puntworkNetwork.strings.sync_complete);
						loadSitesList();
						addActivityLog('success', 'Network sync completed successfully');
					} else {
						$button.text(puntworkNetwork.strings.sync_failed);
						addActivityLog('error', 'Network sync failed: ' + response.data);
					}
				},
				error: function() {
					$button.text(puntworkNetwork.strings.sync_failed);
					addActivityLog('error', 'Network sync failed');
				},
				complete: function() {
					setTimeout(function() {
						$button.prop('disabled', false).text(originalText);
					}, 2000);
				}
			});
		}

		function distributeJobs() {
			const jobsJson = $('#distribution-jobs').val().trim();
			const strategy = $('#distribution-strategy-select').val();

			if (!jobsJson) {
				alert('Please enter jobs to distribute');
				return;
			}

			let jobs;
			try {
				jobs = JSON.parse(jobsJson);
			} catch (e) {
				alert('Invalid JSON format');
				return;
			}

			const $button = $('#distribute-jobs');
			const originalText = $button.text();

			$button.prop('disabled', true).text(puntworkNetwork.strings.distributing);

			$.ajax({
				url: puntworkNetwork.ajax_url,
				type: 'POST',
				data: {
					action: 'distribute_jobs_network',
					nonce: puntworkNetwork.distribute_nonce,
					jobs: JSON.stringify(jobs),
					strategy: strategy
				},
				success: function(response) {
					if (response.success) {
						$button.text(puntworkNetwork.strings.distribution_complete);
						showDistributionResults(response.data.distribution);
						addActivityLog('success', 'Jobs distributed successfully');
					} else {
						$button.text(puntworkNetwork.strings.distribution_failed);
						addActivityLog('error', 'Job distribution failed: ' + response.data);
					}
				},
				error: function() {
					$button.text(puntworkNetwork.strings.distribution_failed);
					addActivityLog('error', 'Job distribution failed');
				},
				complete: function() {
					setTimeout(function() {
						$button.prop('disabled', false).text(originalText);
					}, 2000);
				}
			});
		}

		function showDistributionResults(distribution) {
			let html = '<h3>Distribution Results</h3>';

			if (distribution.distributed) {
				html += '<div class="distribution-summary">';
				Object.entries(distribution.distributed).forEach(([siteId, jobs]) => {
					html += '<div class="distribution-site">';
					html += '<strong>Site ' + siteId + ':</strong> ' + jobs.length + ' jobs';
					html += '</div>';
				});
				html += '</div>';
			}

			if (distribution.errors && distribution.errors.length > 0) {
				html += '<div class="distribution-errors">';
				html += '<h4>Errors:</h4>';
				html += '<ul>';
				distribution.errors.forEach(function(error) {
					html += '<li>' + error + '</li>';
				});
				html += '</ul>';
				html += '</div>';
			}

			$('#distribution-results').html(html).show();
		}

		function loadActivityLog() {
			// Load recent activity from local storage or generate sample data
			const activities = JSON.parse(localStorage.getItem('puntwork_network_activity') || '[]');
			renderActivityLog(activities);
		}

		function addActivityLog(type, message) {
			const activity = {
				timestamp: new Date().toISOString(),
				type: type,
				message: message
			};

			let activities = JSON.parse(localStorage.getItem('puntwork_network_activity') || '[]');
			activities.unshift(activity);
			activities = activities.slice(0, 50); // Keep last 50 entries

			localStorage.setItem('puntwork_network_activity', JSON.stringify(activities));
			renderActivityLog(activities);
		}

		function renderActivityLog(activities) {
			if (activities.length === 0) {
				$('#network-activity').html('<p>No recent activity</p>');
				return;
			}

			let html = '';
			activities.forEach(function(activity) {
				const typeClass = activity.type === 'error' ? 'activity-error' : 'activity-success';
				html += '<div class="activity-entry">';
				html += '<div class="activity-timestamp">' + new Date(activity.timestamp).toLocaleString() + '</div>';
				html += '<div class="activity-message ' + typeClass + '">' + activity.message + '</div>';
				html += '</div>';
			});

			$('#network-activity').html(html);
		}
		</script>
		<?php
	}

	/**
	 * AJAX handler for testing site connections
	 */
	public static function ajaxTestConnection(): void {
		try {
			$site_id = intval( $_POST['site_id'] ?? 0 );

			if ( ! $site_id ) {
				wp_send_json_error( 'Invalid site ID' );
				return;
			}

			// Test connection by switching to site and checking basic functionality
			$connection_test = false;

			switch_to_blog( $site_id );
			if ( function_exists( 'wp_count_posts' ) && wp_count_posts( 'job' ) !== false ) {
				$connection_test = true;
			}
			restore_current_blog();

			if ( $connection_test ) {
				wp_send_json_success( array( 'message' => 'Connection successful' ) );
			} else {
				wp_send_json_error( 'Connection failed' );
			}
		} catch ( \Exception $e ) {
			wp_send_json_error( 'Connection test failed: ' . $e->getMessage() );
		}
	}
}

// Initialize if multisite
if ( is_multisite() ) {
	MultiSiteAdminUI::init();
}