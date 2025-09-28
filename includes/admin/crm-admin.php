<?php

/**
 * CRM Admin Interface
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      0.0.4
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Admin Class
 */
class PuntworkCrmAdmin {

	/**
	 * CRM Manager instance
	 */
	private $crm_manager;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->crm_manager = new CRM\CRMManager();

		add_action( 'admin_menu', array( $this, 'addCrmMenu' ) );
		add_action( 'admin_enqueueScripts', array( $this, 'enqueueScripts' ) );
		add_action( 'wp_ajax_puntwork_crm_test_platform', array( $this, 'ajaxTestPlatform' ) );
		add_action( 'wp_ajax_puntwork_crm_save_config', array( $this, 'ajaxSaveConfig' ) );
		add_action( 'wp_ajax_puntwork_crm_sync_application', array( $this, 'ajaxSyncApplication' ) );
	}

	/**
	 * Add CRM menu to admin
	 */
	public function addCrmMenu(): void {
		add_submenu_page(
			'puntwork-admin',
			__( 'CRM Integration', 'puntwork' ),
			__( 'CRM Integration', 'puntwork' ),
			'manage_options',
			'puntwork-crm',
			array( $this, 'renderCrmPage' )
		);
	}

	/**
	 * Enqueue admin scripts and styles
	 */
	public function enqueueScripts( $hook ): void {
		if ( $hook !== 'puntwork_page_puntwork-crm' ) {
			return;
		}

		wp_enqueue_script(
			'puntwork-crm-admin',
			plugins_url( 'assets/js/crm-admin.js', dirname( __DIR__, 1 ) ),
			array( 'jquery' ),
			PUNTWORK_VERSION,
			true
		);

		wp_enqueue_style(
			'puntwork-crm-admin',
			plugins_url( 'assets/css/crm-admin.css', dirname( __DIR__, 1 ) ),
			array(),
			PUNTWORK_VERSION
		);

		wp_localize_script(
			'puntwork-crm-admin',
			'puntwork_crm_ajax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'puntwork_crm_nonce' ),
				'strings'  => array(
					'testing'      => __( 'Testing connection...', 'puntwork' ),
					'test_success' => __( 'Connection successful!', 'puntwork' ),
					'test_failed'  => __( 'Connection failed!', 'puntwork' ),
					'saving'       => __( 'Saving...', 'puntwork' ),
					'save_success' => __( 'Configuration saved!', 'puntwork' ),
					'save_failed'  => __( 'Save failed!', 'puntwork' ),
					'syncing'      => __( 'Syncing...', 'puntwork' ),
					'sync_success' => __( 'Synced successfully!', 'puntwork' ),
					'sync_failed'  => __( 'Sync failed!', 'puntwork' ),
				),
			)
		);
	}

	/**
	 * Render CRM admin page
	 */
	public function renderCrmPage(): void {
		$available_platforms = CRM\CRMManager::getAvailablePlatforms();
		$platform_configs    = CRM\CRMManager::getAllPlatformConfigs();
		$statistics          = $this->crm_manager->getStatistics();

		?>
		<div class="wrap">
			<h1><?php _e( 'CRM Integration', 'puntwork' ); ?></h1>

			<!-- Statistics Dashboard -->
			<div class="crm-statistics">
				<h2><?php _e( 'CRM Sync Statistics', 'puntwork' ); ?></h2>
				<div class="stats-grid">
					<div class="stat-card">
						<h3><?php echo esc_html( $statistics['total_syncs'] ); ?></h3>
						<p><?php _e( 'Total Syncs (30 days)', 'puntwork' ); ?></p>
					</div>
					<div class="stat-card">
						<h3><?php echo esc_html( $statistics['successful_syncs'] ); ?></h3>
						<p><?php _e( 'Successful Syncs', 'puntwork' ); ?></p>
					</div>
					<div class="stat-card">
						<h3><?php echo esc_html( $statistics['failed_syncs'] ); ?></h3>
						<p><?php _e( 'Failed Syncs', 'puntwork' ); ?></p>
					</div>
					<div class="stat-card">
						<h3>
		<?php
		echo $statistics['last_sync'] ?
			esc_html( human_time_diff( strtotime( $statistics['last_sync'] ) ) ) . ' ago' :
			__( 'Never', 'puntwork' );
		?>
						</h3>
						<p><?php _e( 'Last Sync', 'puntwork' ); ?></p>
					</div>
				</div>
			</div>

			<div class="puntwork-crm-container">
				<div class="crm-tabs">
					<button class="tab-button active" data-tab="platforms">
		<?php _e( 'Platform Configuration', 'puntwork' ); ?>
					</button>
					<button class="tab-button" data-tab="sync">
		<?php _e( 'Data Synchronization', 'puntwork' ); ?>
					</button>
					<button class="tab-button" data-tab="logs">
		<?php _e( 'Sync Logs', 'puntwork' ); ?>
					</button>
				</div>

				<!-- Platform Configuration Tab -->
				<div id="platforms-tab" class="tab-content active">
					<h2><?php _e( 'Configure CRM Platforms', 'puntwork' ); ?></h2>

		<?php foreach ( $available_platforms as $platform_id => $platform_info ) : ?>
						<div class="platform-config-card" data-platform="<?php echo esc_attr( $platform_id ); ?>">
							<div class="platform-header">
								<h3><?php echo esc_html( $platform_info['name'] ); ?></h3>
								<div class="platform-toggles">
									<label class="platform-toggle">
										<input type="checkbox"
												class="platform-enabled"
			<?php
			checked(
				isset( $platform_configs[ $platform_id ]['enabled'] ) &&
												$platform_configs[ $platform_id ]['enabled']
			);
			?>
											>
			<?php _e( 'Enable', 'puntwork' ); ?>
									</label>
								</div>
							</div>

							<div class="platform-config" style="display: 
			<?php
			echo ( isset( $platform_configs[ $platform_id ]['enabled'] ) &&
			$platform_configs[ $platform_id ]['enabled'] ) ? 'block' : 'none';
			?>
							;">
			<?php
			$this->renderPlatformConfig( $platform_id, $platform_configs[ $platform_id ] ?? array() );
			?>

								<div class="platform-actions">
									<button class="puntwork-btn puntwork-btn--secondary test-platform">
			<?php _e( 'Test Connection', 'puntwork' ); ?>
									</button>
									<button class="puntwork-btn puntwork-btn--primary save-platform">
			<?php _e( 'Save Configuration', 'puntwork' ); ?>
									</button>
								</div>

								<div class="platform-status" style="display: none;"></div>
							</div>
						</div>
		<?php endforeach; ?>
				</div>

				<!-- Data Synchronization Tab -->
				<div id="sync-tab" class="tab-content">
					<h2><?php _e( 'Data Synchronization', 'puntwork' ); ?></h2>

					<div class="sync-settings">
						<h3><?php _e( 'Synchronization Settings', 'puntwork' ); ?></h3>

						<table class="form-table">
							<tr>
								<th scope="row"><?php _e( 'Auto-sync Job Applications', 'puntwork' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="auto_sync_applications" value="1"
												<?php checked( get_option( 'puntwork_crm_auto_sync_applications', false ) ); ?>>
										<?php
										_e(
											'Automatically sync new job applications to configured CRM ' .
											'platforms',
											'puntwork'
										);
										?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php _e( 'Default Platforms', 'puntwork' ); ?></th>
								<td>
			<?php
			$default_platforms = get_option( 'puntwork_crm_default_platforms', array() );
			foreach ( $available_platforms as $platform_id => $platform_info ) :
				?>
										<label style="display: block; margin-bottom: 5px;">
											<input type="checkbox"
													name="default_platforms[]"
													value="<?php echo esc_attr( $platform_id ); ?>"
				<?php checked( in_array( $platform_id, $default_platforms ) ); ?>>
				<?php echo esc_html( $platform_info['name'] ); ?>
										</label>
			<?php endforeach; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php _e( 'Sync Contact Fields', 'puntwork' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="sync_contact_fields" value="1"
												<?php checked( get_option( 'puntwork_crm_sync_contact_fields', true ) ); ?>>
			<?php _e( 'Include detailed contact information in sync', 'puntwork' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php _e( 'Create Deals', 'puntwork' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="create_deals" value="1"
												<?php checked( get_option( 'puntwork_crm_create_deals', true ) ); ?>>
			<?php _e( 'Create deal/opportunity records for job applications', 'puntwork' ); ?>
									</label>
								</td>
							</tr>
						</table>

						<p class="submit">
							<button type="button" class="puntwork-btn puntwork-btn--primary" id="save-sync-settings">
			<?php _e( 'Save Settings', 'puntwork' ); ?>
							</button>
						</p>
					</div>

					<div class="manual-sync">
						<h3><?php _e( 'Manual Synchronization', 'puntwork' ); ?></h3>
						<p><?php _e( 'Manually sync job application data to CRM platforms.', 'puntwork' ); ?></p>

						<div class="sync-form">
							<label for="application_id"><?php _e( 'Application ID:', 'puntwork' ); ?></label>
							<input type="text" id="application_id" placeholder="Enter application ID">

							<div class="sync-platforms">
			<?php foreach ( $available_platforms as $platform_id => $platform_info ) : ?>
									<label>
										<input type="checkbox" class="sync-platform" value="<?php echo esc_attr( $platform_id ); ?>">
				<?php echo esc_html( $platform_info['name'] ); ?>
									</label>
			<?php endforeach; ?>
							</div>

							<button type="button" class="puntwork-btn puntwork-btn--primary" id="sync-manually">
			<?php _e( 'Sync Now', 'puntwork' ); ?>
							</button>
						</div>

						<div id="sync-status" style="display: none;"></div>
					</div>
				</div>

				<!-- Sync Logs Tab -->
				<div id="logs-tab" class="tab-content">
					<h2><?php _e( 'CRM Sync Logs', 'puntwork' ); ?></h2>
					<div id="sync-logs-container">
		<?php $this->renderSyncLogs(); ?>
					</div>
				</div>
			</div>
		</div>

		<style>
			.crm-statistics {
				background: #fff;
				border: 1px solid #ddd;
				border-radius: 5px;
				padding: 20px;
				margin-bottom: 20px;
			}

			.stats-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
				gap: 20px;
				margin-top: 15px;
			}

			.stat-card {
				background: #f8f9fa;
				border: 1px solid #e9ecef;
				border-radius: 8px;
				padding: 15px;
				text-align: center;
			}

			.stat-card h3 {
				margin: 0 0 5px 0;
				font-size: 2em;
				color: #007cba;
			}

			.stat-card p {
				margin: 0;
				color: #666;
				font-size: 0.9em;
			}

			.puntwork-crm-container {
				margin-top: 20px;
			}

			.crm-tabs {
				display: flex;
				border-bottom: 1px solid #ccc;
				margin-bottom: 20px;
			}

			.tab-button {
				background: none;
				border: none;
				padding: 10px 20px;
				cursor: pointer;
				border-bottom: 2px solid transparent;
			}

			.tab-button.active {
				border-bottom-color: #007cba;
				font-weight: bold;
			}

			.tab-content {
				display: none;
			}

			.tab-content.active {
				display: block;
			}

			.platform-config-card {
				border: 1px solid #ddd;
				border-radius: 5px;
				margin-bottom: 20px;
				padding: 15px;
			}

			.platform-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 15px;
			}

			.platform-toggle {
				font-weight: normal;
			}

			.platform-config {
				margin-top: 15px;
			}

			.platform-actions {
				margin-top: 15px;
				padding-top: 15px;
				border-top: 1px solid #eee;
			}

			.platform-status {
				margin-top: 10px;
				padding: 10px;
				border-radius: 3px;
			}

			.status-success {
				background-color: #d4edda;
				border-color: #c3e6cb;
				color: #155724;
			}

			.status-error {
				background-color: #f8d7da;
				border-color: #f5c6cb;
				color: #721c24;
			}

			.sync-settings, .manual-sync {
				background: #fff;
				border: 1px solid #ddd;
				border-radius: 5px;
				padding: 20px;
				margin-bottom: 20px;
			}

			.sync-form {
				margin-top: 15px;
			}

			.sync-form input[type="text"] {
				margin-bottom: 10px;
				width: 300px;
			}

			.sync-platforms {
				margin: 10px 0;
			}

			.sync-platforms label {
				display: inline-block;
				margin-right: 15px;
			}
		</style>
		<?php
	}

	/**
	 * Render platform-specific configuration
	 */
	private function renderPlatformConfig( string $platform_id, array $config ): void {
		switch ( $platform_id ) {
			case 'hubspot':
				?>
				<table class="form-table">
					<tr>
						<th scope="row"><?php _e( 'Access Token', 'puntwork' ); ?></th>
						<td>
							<input type="password" name="access_token" value="<?php echo esc_attr( $config['access_token'] ?? '' ); ?>" class="regular-text">
							<p class="description"><?php _e( 'HubSpot Private App Access Token', 'puntwork' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e( 'Portal ID', 'puntwork' ); ?></th>
						<td>
							<input type="text" name="portal_id" value="<?php echo esc_attr( $config['portal_id'] ?? '' ); ?>" class="regular-text">
							<p class="description"><?php _e( 'HubSpot Portal ID (optional, for reference)', 'puntwork' ); ?></p>
						</td>
					</tr>
				</table>
				<?php
				break;
		}
	}

	/**
	 * Render sync logs
	 */
	private function renderSyncLogs(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'puntwork_crm_sync_log';

		$logs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 50"
			),
			ARRAY_A
		);

		if ( empty( $logs ) ) {
			echo '<p>' . __( 'No sync logs found.', 'puntwork' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . __( 'Date', 'puntwork' ) . '</th>';
		echo '<th>' . __( 'Platform', 'puntwork' ) . '</th>';
		echo '<th>' . __( 'Operation', 'puntwork' ) . '</th>';
		echo '<th>' . __( 'Status', 'puntwork' ) . '</th>';
		echo '<th>' . __( 'Details', 'puntwork' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $logs as $log ) {
			$data   = json_decode( $log['data'], true );
			$status = $log['success'] ? '✅ Success' : '❌ Failed';
			$color  = $log['success'] ? 'green' : 'red';

			echo '<tr>';
			echo '<td>' . esc_html( $log['created_at'] ) . '</td>';
			echo '<td>' . esc_html( ucfirst( $log['platform_id'] ) ) . '</td>';
			echo '<td>' . esc_html( ucfirst( $log['operation'] ) ) . '</td>';
			echo '<td><span style="color: ' . $color . ';">' . $status . '</span></td>';
			echo '<td>';

			if ( ! empty( $data ) ) {
				if ( isset( $data['contact_id'] ) ) {
					echo 'Contact ID: ' . esc_html( $data['contact_id'] );
				}
				if ( isset( $data['deal_id'] ) ) {
					echo ' Deal ID: ' . esc_html( $data['deal_id'] );
				}
				if ( isset( $data['error'] ) ) {
					echo ' Error: ' . esc_html( $data['error'] );
				}
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * AJAX handler for testing platform connection
	 */
	public function ajaxTestPlatform(): void {
		check_ajax_referer( 'puntwork_crm_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions', 'puntwork' ) );
		}

		$platform_id = sanitize_text_field( $_POST['platform_id'] ?? '' );

		if ( empty( $platform_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Platform ID required', 'puntwork' ) ) );
		}

		$result = $this->crm_manager->testPlatform( $platform_id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX handler for saving platform configuration
	 */
	public function ajaxSaveConfig(): void {
		check_ajax_referer( 'puntwork_crm_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions', 'puntwork' ) );
		}

		$platform_id = sanitize_text_field( $_POST['platform_id'] ?? '' );
		$config      = $_POST['config'] ?? array();

		if ( empty( $platform_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Platform ID required', 'puntwork' ) ) );
		}

		// Sanitize config data
		$sanitized_config = array();
		foreach ( $config as $key => $value ) {
			$sanitized_config[ $key ] = sanitize_text_field( $value );
		}

		$success = CRM\CRMManager::configurePlatform( $platform_id, $sanitized_config );

		if ( $success ) {
			wp_send_json_success( array( 'message' => __( 'Configuration saved successfully', 'puntwork' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save configuration', 'puntwork' ) ) );
		}
	}

	/**
	 * AJAX handler for manual application sync
	 */
	public function ajaxSyncApplication(): void {
		check_ajax_referer( 'puntwork_crm_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions', 'puntwork' ) );
		}

		$application_id = sanitize_text_field( $_POST['application_id'] ?? '' );
		$platforms      = $_POST['platforms'] ?? array();

		if ( empty( $application_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Application ID is required', 'puntwork' ) ) );
		}

		if ( empty( $platforms ) ) {
			wp_send_json_error( array( 'message' => __( 'At least one platform must be selected', 'puntwork' ) ) );
		}

		$platforms = array_map( 'sanitize_text_field', $platforms );

		// Mock application data - in real implementation, fetch from database
		$application_data = array(
			'id'                 => $application_id,
			'first_name'         => 'John',
			'last_name'          => 'Doe',
			'email'              => 'john.doe@example.com',
			'phone'              => '+1-555-0123',
			'job_title'          => 'Software Developer',
			'current_company'    => 'Tech Corp',
			'current_position'   => 'Junior Developer',
			'experience_years'   => 3,
			'skills'             => array( 'PHP', 'JavaScript', 'MySQL' ),
			'education'          => 'Bachelor of Computer Science',
			'salary_expectation' => 75000,
			'availability'       => 'Immediate',
			'source'             => 'puntwork_job_board',
		);

		$results = $this->crm_manager->syncJobApplication( $application_data, $platforms );

		wp_send_json_success(
			array(
				'message' => __( 'Application synced to CRM platforms', 'puntwork' ),
				'results' => $results,
			)
		);
	}
}

// Initialize admin interface
new PuntworkCrmAdmin();