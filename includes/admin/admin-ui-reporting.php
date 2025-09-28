<?php

/**
 * Advanced Reporting Admin Interface
 *
 * Provides admin interface for generating and managing custom reports.
 *
 * @package    Puntwork
 * @subpackage Reporting
 * @since      2.4.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advanced Reporting Admin UI
 */
class ReportingAdminUI {

	/**
	 * Initialize admin interface
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'addAdminMenu' ) );
		add_action( 'admin_enqueueScripts', array( self::class, 'enqueueScripts' ) );
		add_action( 'wp_ajaxExportReport', array( self::class, 'ajaxExportReport' ) );
		add_action( 'wp_ajaxDeleteReport', array( self::class, 'ajaxDeleteReport' ) );
	}

	/**
	 * Add admin menu
	 */
	public static function addAdminMenu(): void {
		add_submenu_page(
			'puntwork-admin',
			__( 'Reports', 'puntwork' ),
			__( 'Reports', 'puntwork' ),
			'manage_options',
			'puntwork-reports',
			array( self::class, 'renderAdminPage' )
		);
	}

	/**
	 * Enqueue admin scripts and styles
	 */
	public static function enqueueScripts( string $hook ): void {
		if ( $hook !== 'puntwork_page_puntwork-reports' ) {
			return;
		}

		wp_enqueue_style( 'puntwork-reports-admin', plugins_url( 'assets/css/reports-admin.css', dirname( __DIR__, 1 ) ), array(), '2.4.0' );
		wp_enqueue_script( 'puntwork-reports-admin', plugins_url( 'assets/js/reports-admin.js', dirname( __DIR__, 1 ) ), array( 'jquery' ), '2.4.0', true );

		wp_localize_script(
			'puntwork-reports-admin',
			'puntworkReports',
			array(
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'puntwork_reports_admin' ),
				'generate_nonce' => wp_create_nonce( 'puntwork_generate_report' ),
				'export_nonce'   => wp_create_nonce( 'puntwork_export_report' ),
				'delete_nonce'   => wp_create_nonce( 'puntwork_delete_report' ),
				'strings'        => array(
					'generating'       => __( 'Generating report...', 'puntwork' ),
					'exporting'        => __( 'Exporting report...', 'puntwork' ),
					'deleting'         => __( 'Deleting report...', 'puntwork' ),
					'confirm_delete'   => __( 'Are you sure you want to delete this report?', 'puntwork' ),
					'report_generated' => __( 'Report generated successfully', 'puntwork' ),
					'report_exported'  => __( 'Report exported successfully', 'puntwork' ),
					'report_deleted'   => __( 'Report deleted successfully', 'puntwork' ),
					'error'            => __( 'An error occurred', 'puntwork' ),
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
			<h1><?php _e( 'Advanced Reports', 'puntwork' ); ?></h1>

			<div class="puntwork-reports-container">
				<!-- Report Generation -->
				<div class="puntwork-reports-section">
					<h2><?php _e( 'Generate Custom Report', 'puntwork' ); ?></h2>
					<div class="report-generator">
						<form id="report-generator-form">
							<div class="form-row">
								<div class="form-group">
									<label for="report-type"><?php _e( 'Report Type', 'puntwork' ); ?></label>
									<select id="report-type" name="report_type">
										<option value="performance"><?php _e( 'Performance Report', 'puntwork' ); ?></option>
										<option value="feed_health"><?php _e( 'Feed Health Report', 'puntwork' ); ?></option>
										<option value="job_analytics"><?php _e( 'Job Analytics Report', 'puntwork' ); ?></option>
										<option value="network"><?php _e( 'Network Report', 'puntwork' ); ?></option>
										<option value="ml_insights"><?php _e( 'ML Insights Report', 'puntwork' ); ?></option>
									</select>
								</div>
								<div class="form-group">
									<label for="date-range"><?php _e( 'Date Range', 'puntwork' ); ?></label>
									<select id="date-range" name="date_range">
										<option value="7"><?php _e( 'Last 7 days', 'puntwork' ); ?></option>
										<option value="30" selected><?php _e( 'Last 30 days', 'puntwork' ); ?></option>
										<option value="90"><?php _e( 'Last 90 days', 'puntwork' ); ?></option>
										<option value="365"><?php _e( 'Last year', 'puntwork' ); ?></option>
									</select>
								</div>
								<div class="form-group">
									<label for="report-format"><?php _e( 'Format', 'puntwork' ); ?></label>
									<select id="report-format" name="format">
										<option value="html"><?php _e( 'HTML', 'puntwork' ); ?></option>
										<option value="pdf"><?php _e( 'PDF', 'puntwork' ); ?></option>
										<option value="csv"><?php _e( 'CSV', 'puntwork' ); ?></option>
										<option value="json"><?php _e( 'JSON', 'puntwork' ); ?></option>
									</select>
								</div>
							</div>
							<div class="form-actions">
								<button type="submit" class="puntwork-btn puntwork-btn--primary" id="generate-report">
									<?php _e( 'Generate Report', 'puntwork' ); ?>
								</button>
							</div>
						</form>
					</div>
				</div>

				<!-- Report Preview -->
				<div class="puntwork-reports-section" id="report-preview" style="display: none;">
					<h2><?php _e( 'Report Preview', 'puntwork' ); ?></h2>
					<div class="report-preview-container">
						<div class="report-actions">
							<button type="button" class="button" id="export-report">
								<?php _e( 'Export Report', 'puntwork' ); ?>
							</button>
							<button type="button" class="button" id="schedule-report">
								<?php _e( 'Schedule Report', 'puntwork' ); ?>
							</button>
						</div>
						<div id="report-content" class="report-content">
							<!-- Report content will be loaded here -->
						</div>
					</div>
				</div>

				<!-- Saved Reports -->
				<div class="puntwork-reports-section">
					<h2><?php _e( 'Saved Reports', 'puntwork' ); ?></h2>
					<div class="reports-list-container">
						<div class="reports-filters">
							<select id="filter-type">
								<option value=""><?php _e( 'All Types', 'puntwork' ); ?></option>
								<option value="performance"><?php _e( 'Performance', 'puntwork' ); ?></option>
								<option value="feed_health"><?php _e( 'Feed Health', 'puntwork' ); ?></option>
								<option value="job_analytics"><?php _e( 'Job Analytics', 'puntwork' ); ?></option>
								<option value="network"><?php _e( 'Network', 'puntwork' ); ?></option>
								<option value="ml_insights"><?php _e( 'ML Insights', 'puntwork' ); ?></option>
							</select>
							<input type="text" id="search-reports" placeholder="<?php esc_attr_e( 'Search reports...', 'puntwork' ); ?>">
						</div>
						<div id="reports-list" class="reports-list">
							<!-- Reports will be loaded here -->
						</div>
					</div>
				</div>

				<!-- Report Settings -->
				<div class="puntwork-reports-section">
					<h2><?php _e( 'Report Settings', 'puntwork' ); ?></h2>
					<form method="post" action="options.php">
		<?php settings_fields( 'puntwork_reporting' ); ?>

						<table class="form-table">
							<tr>
								<th scope="row"><?php _e( 'Automated Reports', 'puntwork' ); ?></th>
								<td>
									<label for="automated-reports-enabled">
										<input type="checkbox" id="automated-reports-enabled" name="puntwork_automated_reports_enabled" value="1" <?php checked( get_option( 'puntwork_automated_reports_enabled', true ) ); ?> />
										<?php _e( 'Enable automated report generation', 'puntwork' ); ?>
									</label>
									<p class="description">
										<?php _e( 'Automatically generate reports daily for performance monitoring.', 'puntwork' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php _e( 'Report Retention', 'puntwork' ); ?></th>
								<td>
									<input type="number" id="report-retention" name="puntwork_report_retention_days" value="<?php echo esc_attr( get_option( 'puntwork_report_retention_days', 90 ) ); ?>" min="7" max="365" />
									<span><?php _e( 'days', 'puntwork' ); ?></span>
									<p class="description">
										<?php _e( 'How long to keep generated reports before automatic deletion.', 'puntwork' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php _e( 'Dashboard Refresh', 'puntwork' ); ?></th>
								<td>
									<input type="number" id="dashboard-refresh" name="puntwork_dashboard_refresh_interval" value="<?php echo esc_attr( get_option( 'puntwork_dashboard_refresh_interval', 300 ) ); ?>" min="60" max="3600" />
									<span><?php _e( 'seconds', 'puntwork' ); ?></span>
									<p class="description">
										<?php _e( 'How often to refresh dashboard widgets with new data.', 'puntwork' ); ?>
									</p>
								</td>
							</tr>
						</table>

		<?php submit_button( __( 'Save Settings', 'puntwork' ) ); ?>
					</form>
				</div>
			</div>
		</div>

		<!-- Report Modal -->
		<div id="report-modal" class="puntwork-modal" style="display: none;">
			<div class="puntwork-modal-content">
				<div class="puntwork-modal-header">
					<h3 id="modal-title"><?php _e( 'Report', 'puntwork' ); ?></h3>
					<button type="button" class="puntwork-modal-close">&times;</button>
				</div>
				<div class="puntwork-modal-body" id="modal-content">
					<!-- Modal content will be loaded here -->
				</div>
			</div>
		</div>

		<style>
		.puntwork-reports-container {
			margin-top: 20px;
		}

		.puntwork-reports-section {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			margin-bottom: 20px;
			padding: 20px;
		}

		.puntwork-reports-section h2 {
			margin-top: 0;
			margin-bottom: 20px;
			padding-bottom: 10px;
			border-bottom: 1px solid #eee;
		}

		.report-generator {
			max-width: 800px;
		}

		.form-row {
			display: flex;
			gap: 20px;
			margin-bottom: 20px;
		}

		.form-group {
			flex: 1;
		}

		.form-group label {
			display: block;
			margin-bottom: 5px;
			font-weight: 600;
		}

		.form-group select,
		.form-group input {
			width: 100%;
			padding: 8px;
			border: 1px solid #ddd;
			border-radius: 4px;
		}

		.form-actions {
			margin-top: 20px;
		}

		.report-preview-container {
			border: 1px solid #dee2e6;
			border-radius: 4px;
			overflow: hidden;
		}

		.report-actions {
			background: #f8f9fa;
			padding: 15px;
			border-bottom: 1px solid #dee2e6;
			display: flex;
			gap: 10px;
		}

		.report-content {
			max-height: 600px;
			overflow-y: auto;
			padding: 20px;
		}

		.reports-list-container {
			border: 1px solid #dee2e6;
			border-radius: 4px;
		}

		.reports-filters {
			padding: 15px;
			background: #f8f9fa;
			border-bottom: 1px solid #dee2e6;
			display: flex;
			gap: 15px;
			align-items: center;
		}

		.reports-filters select,
		.reports-filters input {
			padding: 6px 10px;
			border: 1px solid #ddd;
			border-radius: 4px;
		}

		.reports-list {
			max-height: 400px;
			overflow-y: auto;
		}

		.report-item {
			padding: 15px;
			border-bottom: 1px solid #eee;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}

		.report-item:last-child {
			border-bottom: none;
		}

		.report-info {
			flex: 1;
		}

		.report-title {
			font-weight: 600;
			margin-bottom: 5px;
		}

		.report-meta {
			color: #666;
			font-size: 13px;
		}

		.report-actions {
			display: flex;
			gap: 8px;
		}

		.puntwork-modal {
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
			border: 1px solid #888;
			width: 80%;
			max-width: 1000px;
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

		.loading {
			text-align: center;
			padding: 40px;
		}

		.spinner {
			border: 4px solid #f3f3f3;
			border-top: 4px solid #007cba;
			border-radius: 50%;
			width: 40px;
			height: 40px;
			animation: spin 1s linear infinite;
			display: inline-block;
			margin-right: 15px;
		}

		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}

		.error {
			color: #dc3545;
			background: #f8d7da;
			border: 1px solid #f5c6cb;
			border-radius: 4px;
			padding: 12px;
			text-align: center;
		}

		.success {
			color: #155724;
			background: #d4edda;
			border: 1px solid #c3e6cb;
			border-radius: 4px;
			padding: 12px;
			text-align: center;
		}
		</style>

		<script>
		jQuery(document).ready(function($) {
			// Load initial reports
			loadReportsList();

			// Form submission
			$('#report-generator-form').on('submit', function(e) {
				e.preventDefault();
				generateReport();
			});

			// Export report
			$('#export-report').on('click', function() {
				exportReport();
			});

			// Schedule report
			$('#schedule-report').on('click', function() {
				scheduleReport();
			});

			// Modal close
			$('.puntwork-modal-close').on('click', function() {
				$('#report-modal').hide();
			});

			// Click outside modal to close
			$(window).on('click', function(e) {
				if ($(e.target).is('#report-modal')) {
					$('#report-modal').hide();
				}
			});

			// Filters
			$('#filter-type, #search-reports').on('input change', function() {
				loadReportsList();
			});
		});

		function generateReport() {
			var formData = new FormData(document.getElementById('report-generator-form'));
			var data = {
				action: 'generate_custom_report',
				nonce: puntworkReports.generate_nonce,
				report_type: formData.get('report_type'),
				date_range: formData.get('date_range'),
				format: formData.get('format')
			};

			$('#generate-report').prop('disabled', true).text(puntworkReports.strings.generating);

			$.ajax({
				url: puntworkReports.ajax_url,
				type: 'POST',
				data: data,
				success: function(response) {
					if (response.success) {
						showReportPreview(response.data);
						loadReportsList();
						showMessage(puntworkReports.strings.report_generated, 'success');
					} else {
						showMessage(response.data || puntworkReports.strings.error, 'error');
					}
				},
				error: function(xhr, status, error) {
					showMessage(puntworkReports.strings.error + ': ' + error, 'error');
				},
				complete: function() {
					$('#generate-report').prop('disabled', false).text('<?php _e( 'Generate Report', 'puntwork' ); ?>');
				}
			});
		}

		function showReportPreview(reportData) {
			if (reportData.formatted) {
				$('#report-content').html(reportData.formatted);
				$('#report-preview').show();
				$('#report-preview')[0].scrollIntoView({ behavior: 'smooth' });
			}
		}

		function exportReport() {
			// Implementation for report export
			showMessage('Export functionality coming soon', 'info');
		}

		function scheduleReport() {
			// Implementation for report scheduling
			showMessage('Scheduling functionality coming soon', 'info');
		}

		function loadReportsList() {
			var filterType = $('#filter-type').val();
			var searchTerm = $('#search-reports').val();

			$('#reports-list').html('<div class="loading"><div class="spinner"></div>Loading reports...</div>');

			$.ajax({
				url: puntworkReports.ajax_url,
				type: 'POST',
				data: {
					action: 'get_reports_list',
					nonce: puntworkReports.nonce,
					filter_type: filterType,
					search: searchTerm
				},
				success: function(response) {
					if (response.success) {
						renderReportsList(response.data);
					} else {
						$('#reports-list').html('<p class="error">Error loading reports: ' + response.data + '</p>');
					}
				},
				error: function(xhr, status, error) {
					$('#reports-list').html('<p class="error">Error loading reports: ' + error + '</p>');
				}
			});
		}

		function renderReportsList(reports) {
			if (!reports || reports.length == 0) {
				$('#reports-list').html('<p>No reports found</p>');
				return;
			}

			var html = '';
			reports.forEach(function(report) {
				var reportDate = new Date(report.created_at).toLocaleDateString();
				html += '<div class="report-item" data-report-id="' + report.id + '">';
				html += '<div class="report-info">';
				html += '<div class="report-title">' + escapeHtml(report.report_title) + '</div>';
				html += '<div class="report-meta">';
				html += 'Type: ' + escapeHtml(report.report_type) + ' | ';
				html += 'Format: ' + escapeHtml(report.report_format) + ' | ';
				html += 'Created: ' + reportDate;
				html += '</div>';
				html += '</div>';
				html += '<div class="report-actions">';
				html += '<button type="button" class="puntwork-btn puntwork-btn--outline" data-report-id="' + report.id + '">View</button>';
				html += '<button type="button" class="puntwork-btn puntwork-btn--danger" data-report-id="' + report.id + '">Delete</button>';
				html += '</div>';
				html += '</div>';
			});

			$('#reports-list').html(html);

			// Bind event handlers
			$('.view-report').on('click', function() {
				var reportId = $(this).data('report-id');
				viewReport(reportId);
			});

			$('.delete-report').on('click', function() {
				var reportId = $(this).data('report-id');
				if (confirm(puntworkReports.strings.confirm_delete)) {
					deleteReport(reportId);
				}
			});
		}

		function viewReport(reportId) {
			$('#modal-title').text('Loading Report...');
			$('#modal-content').html('<div class="loading"><div class="spinner"></div>Loading...</div>');
			$('#report-modal').show();

			$.ajax({
				url: puntworkReports.ajax_url,
				type: 'POST',
				data: {
					action: 'get_report_data',
					nonce: puntworkReports.generate_nonce,
					report_id: reportId
				},
				success: function(response) {
					if (response.success) {
						$('#modal-title').text(response.data.report.report_title);
						$('#modal-content').html(response.data.report.report_data);
					} else {
						$('#modal-content').html('<p class="error">Error loading report: ' + response.data + '</p>');
					}
				},
				error: function(xhr, status, error) {
					$('#modal-content').html('<p class="error">Error loading report: ' + error + '</p>');
				}
			});
		}

		function deleteReport(reportId) {
			$.ajax({
				url: puntworkReports.ajax_url,
				type: 'POST',
				data: {
					action: 'delete_report',
					nonce: puntworkReports.delete_nonce,
					report_id: reportId
				},
				success: function(response) {
					if (response.success) {
						loadReportsList();
						showMessage(puntworkReports.strings.report_deleted, 'success');
					} else {
						showMessage(response.data || puntworkReports.strings.error, 'error');
					}
				},
				error: function(xhr, status, error) {
					showMessage(puntworkReports.strings.error + ': ' + error, 'error');
				}
			});
		}

		function showMessage(message, type) {
			// Remove existing messages
			$('.puntwork-message').remove();

			var messageClass = type == 'error' ? 'error' : 'success';
			var $message = $('<div class="puntwork-message ' + messageClass + '">' + escapeHtml(message) + '</div>');
			$('.puntwork-reports-container').prepend($message);

			// Auto-hide after 5 seconds
			setTimeout(function() {
				$message.fadeOut(function() {
					$(this).remove();
				});
			}, 5000);
		}

		function escapeHtml(text) {
			var div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
		</script>
		<?php
	}

	/**
	 * AJAX handler for exporting reports
	 */
	public static function ajaxExportReport(): void {
		try {
			if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'puntwork_export_report' ) ) {
				wp_send_json_error( 'Security check failed' );
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Insufficient permissions' );
				return;
			}

			$report_id = intval( $_POST['report_id'] ?? 0 );
			$format    = sanitize_text_field( $_POST['format'] ?? 'html' );

			if ( ! $report_id ) {
				wp_send_json_error( 'Invalid report ID' );
				return;
			}

			global $wpdb;
			$table_name = $wpdb->prefix . 'puntwork_reports';

			$report = $wpdb->get_row(
				$wpdb->prepare(
					"
                SELECT * FROM $table_name WHERE id = %d
            ",
					$report_id
				),
				ARRAY_A
			);

			if ( ! $report ) {
					wp_send_json_error( 'Report not found' );
					return;
			}

			// Generate filename
			$filename = sanitize_title( $report['report_title'] ) . '_' . date( 'Y-m-d' ) . '.' . $format;

			// Set headers for download
			header( 'Content-Type: text/plain' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . strlen( $report['report_data'] ) );

			echo $report['report_data'];
			exit;
		} catch ( \Exception $e ) {
			wp_send_json_error( 'Export failed: ' . $e->getMessage() );
		}
	}

	/**
	 * AJAX handler for deleting reports
	 */
	public static function ajaxDeleteReport(): void {
		try {
			if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'puntwork_delete_report' ) ) {
				wp_send_json_error( 'Security check failed' );
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Insufficient permissions' );
				return;
			}

			$report_id = intval( $_POST['report_id'] ?? 0 );

			if ( ! $report_id ) {
				wp_send_json_error( 'Invalid report ID' );
				return;
			}

			global $wpdb;
			$table_name = $wpdb->prefix . 'puntwork_reports';

			$result = $wpdb->delete( $table_name, array( 'id' => $report_id ), array( '%d' ) );

			if ( $result == false ) {
				wp_send_json_error( 'Failed to delete report' );
				return;
			}

			wp_send_json_success( array( 'message' => 'Report deleted successfully' ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( 'Delete failed: ' . $e->getMessage() );
		}
	}
}

// Initialize the admin UI
ReportingAdminUI::init();