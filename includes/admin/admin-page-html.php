<?php

/**
 * Admin page HTML for job import plugin
 * Main entry point that loads all admin UI components
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load admin UI components
require_once __DIR__ . '/admin-ui-main.php';
require_once __DIR__ . '/admin-ui-scheduling.php';
require_once __DIR__ . '/admin-api-settings.php';
require_once __DIR__ . '/admin-feed-config.php';
require_once __DIR__ . '/job-board-admin.php';
require_once __DIR__ . '/onboarding-wizard.php';
require_once __DIR__ . '/accessibility.php';

// Load queue management components
require_once __DIR__ . '/../queue/queue-manager.php';
require_once __DIR__ . '/../queue/queue-ajax.php';

function feeds_dashboard_page() {
	// Enqueue admin modern styles
	wp_enqueue_style( 'puntwork-admin-modern', PUNTWORK_URL . 'assets/css/admin-modern.css', array(), PUNTWORK_VERSION );

	// Add inline styles as fallback
	wp_add_inline_style(
		'puntwork-admin-modern',
		'
        .puntwork-admin { font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Helvetica Neue", Helvetica, Arial, sans-serif; color: #1d1d1f; background-color: #f9f9f9; min-height: 100vh; }
        .puntwork-container { max-width: 1200px; margin: 0 auto; padding: 0 24px; }
        .puntwork-header { background: #ffffff; border-bottom: 1px solid #e5e5e7; padding: 32px 0; margin-bottom: 48px; }
        .puntwork-header__title { font-size: 36px; font-weight: 700; color: #1d1d1f; margin: 0 0 8px 0; text-align: center; }
        .puntwork-header__subtitle { font-size: 18px; color: #8e8e93; text-align: center; margin: 0; }
        .puntwork-card { background: #ffffff; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.07); border: 1px solid #e5e5e7; overflow: hidden; margin-bottom: 32px; }
        .puntwork-card__header { padding: 24px; border-bottom: 1px solid #f2f2f7; background: linear-gradient(135deg, #f9f9f9 0%, #ffffff 100%); }
        .puntwork-card__title { font-size: 20px; font-weight: 600; color: #1d1d1f; margin: 0 0 4px 0; }
        .puntwork-card__subtitle { font-size: 16px; color: #8e8e93; margin: 0; }
        .puntwork-card__footer { padding: 20px 24px; border-top: 1px solid #f2f2f7; background: #f9f9f9; }
        .puntwork-btn { display: inline-flex; align-items: center; padding: 8px 16px; font-size: 14px; font-weight: 500; border-radius: 8px; cursor: pointer; transition: all 0.2s ease; text-decoration: none; border: none; }
        .puntwork-btn--primary { background: linear-gradient(135deg, #007aff 0%, #0056cc 100%); color: #ffffff; }
        .puntwork-btn--primary:hover { background: linear-gradient(135deg, #0056cc 0%, #004499 100%); transform: translateY(-1px); }
        .puntwork-btn--danger { background: linear-gradient(135deg, #ff3b30 0%, #d63027 100%); color: #ffffff; }
        .puntwork-btn--success { background: linear-gradient(135deg, #34c759 0%, #28a745 100%); color: #ffffff; }
        .puntwork-btn--outline { background: transparent; color: #007aff; border: 1px solid #007aff; }
        .puntwork-btn__icon { margin-right: 6px; }
    '
	);

	// Remove debug logging for security
	wp_enqueue_script( 'jquery' );

	// Render main import UI
	render_main_import_ui();

	// Render scheduling UI
	render_scheduling_ui();

	// Render import history UI
	render_import_history_ui();

	// Render JavaScript initialization
	render_javascript_init();
}

/**
 * Feed Configuration page callback
 */
function feed_config_page() {
	// Enqueue admin modern styles
	wp_enqueue_style( 'puntwork-admin-modern', PUNTWORK_URL . 'assets/css/admin-modern.css', array(), PUNTWORK_VERSION );

	// Enqueue Sortable library for drag-and-drop
	wp_enqueue_script( 'jquery-ui-sortable' );

	// Render feed configuration UI
	render_feed_config_ui();
}

/**
 * Render JavaScript initialization for the admin page
 */
function render_javascript_init() {
	?>
	<script type="text/javascript">
		jQuery(document).ready(function($) {
			console.log('[PUNTWORK] Inline script: Document ready, checking modules...');
			console.log('[PUNTWORK] Inline script: JobImportEvents available:', typeof JobImportEvents);
			console.log('[PUNTWORK] Inline script: JobImportUI available:', typeof JobImportUI);
			console.log('[PUNTWORK] Inline script: JobImportAPI available:', typeof JobImportAPI);
			console.log('[PUNTWORK] Inline script: JobImportLogic available:', typeof JobImportLogic);
			console.log('[PUNTWORK] Inline script: jobImportInitialized:', typeof window.jobImportInitialized);

			// Check if buttons exist
			console.log('[PUNTWORK] Inline script: cleanup-duplicates button exists:', $('#cleanup-duplicates').length);
			console.log('[PUNTWORK] Inline script: test-single-job button exists:', $('#test-single-job').length);

			// Add a simple test function to global scope
			window.testButtons = function() {
				console.log('[PUNTWORK] Testing buttons...');
				console.log('Cleanup button found:', $('#cleanup-duplicates').length);
				console.log('Test single job button found:', $('#test-single-job').length);

				if ($('#cleanup-duplicates').length > 0) {
					console.log('Cleanup button HTML:', $('#cleanup-duplicates')[0].outerHTML);
				}
				if ($('#test-single-job').length > 0) {
					console.log('Test single job button HTML:', $('#test-single-job')[0].outerHTML);
				}

				// Test click events
				$('#cleanup-duplicates').trigger('click');
				$('#test-single-job').trigger('click');
			};

			console.log('[PUNTWORK] Run testButtons() in console to test button functionality');

			// Only initialize if not already initialized
			if (typeof window.jobImportInitialized === 'undefined') {
				console.log('[PUNTWORK] Inline script: Initializing job import system...');

				// Initialize the job import system
				if (typeof JobImportEvents !== 'undefined') {
					console.log('[PUNTWORK] Inline script: Calling JobImportEvents.init()');
					JobImportEvents.init();
				} else {
					console.error('[PUNTWORK] Inline script: JobImportEvents not available!');
				}

				// Initialize UI components
				if (typeof JobImportUI !== 'undefined') {
					console.log('[PUNTWORK] Inline script: Calling JobImportUI.clearProgress()');
					JobImportUI.clearProgress();
				}

				// Note: Import status checking is now handled by JobImportEvents.checkInitialStatus()
				// to avoid duplicate checks and potential race conditions

				// Initialize scheduling if available
				if (typeof JobImportScheduling !== 'undefined') {
					console.log('[PUNTWORK] Inline script: Calling JobImportScheduling.init()');
					JobImportScheduling.init();
				}

				// Bind refresh button for main import history section
				$('#refresh-history-main').on('click', function(e) {
					e.preventDefault();
					console.log('[PUNTWORK] Main history refresh clicked');
					if (typeof JobImportScheduling !== 'undefined' && typeof JobImportScheduling.loadRunHistory === 'function') {
						$(this).addClass('manual-refresh');
						JobImportScheduling.loadRunHistory('#refresh-history-main');
					}
				});

				// Load import history on page load
				if (typeof JobImportScheduling !== 'undefined' && typeof JobImportScheduling.loadRunHistory === 'function') {
					console.log('[PUNTWORK] Loading import history on page load');
					JobImportScheduling.loadRunHistory('#refresh-history-main');
				}

				// Mark as initialized to prevent double initialization
				window.jobImportInitialized = true;
				console.log('[PUNTWORK] Inline script: Admin page JavaScript initialized');
			} else {
				console.log('[PUNTWORK] Inline script: Job import already initialized, skipping...');
			}
		});
	</script>
	<?php
}

function jobs_dashboard_page() {
	// Enqueue admin modern styles
	wp_enqueue_style( 'puntwork-admin-modern', PUNTWORK_URL . 'assets/css/admin-modern.css', array(), PUNTWORK_VERSION );

	error_log( '[PUNTWORK] jobs_dashboard_page() called' );
	wp_enqueue_script( 'jquery' );

	// Render jobs dashboard UI
	render_jobs_dashboard_ui();

	// Render JavaScript initialization for jobs dashboard
	render_jobs_javascript_init();
}

/**
 * Render the main puntWork dashboard page
 */
function puntwork_dashboard_page() {
	// Enqueue admin modern styles
	wp_enqueue_style( 'puntwork-admin-modern', PUNTWORK_URL . 'assets/css/admin-modern.css', array(), PUNTWORK_VERSION );

	?>
	<div class="wrap" style="max-width: 1200px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1d1d1f; padding: 0 20px;">
		<h1 style="font-size: 34px; font-weight: 600; text-align: center; margin: 40px 0 20px;"><?php _e( 'puntWork Dashboard', 'puntwork' ); ?></h1>
		<p style="font-size: 16px; color: #8e8e93; text-align: center; margin-bottom: 40px;"><?php _e( 'Manage your job feeds and content with ease', 'puntwork' ); ?></p>

		<!-- PWA Status Indicator -->
		<div id="pwa-status-indicator" style="display: none; background: linear-gradient(135deg, #007aff 0%, #0056cc 100%); color: white; padding: 12px 20px; border-radius: 12px; text-align: center; margin-bottom: 24px; font-size: 14px; font-weight: 500; box-shadow: 0 2px 8px rgba(0,122,255,0.2);">
			<i class="fas fa-mobile-alt" style="margin-right: 8px;"></i>
			<?php _e( 'puntWork Admin is now available as a Progressive Web App!', 'puntwork' ); ?>
			<button id="pwa-install-btn" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: white; padding: 4px 12px; border-radius: 6px; font-size: 12px; margin-left: 12px; cursor: pointer; transition: background 0.2s ease;" aria-label="<?php esc_attr_e( 'Install puntWork Admin PWA', 'puntwork' ); ?>">
				<?php _e( 'Install', 'puntwork' ); ?>
			</button>
		</div>

		<!-- Overview Cards -->
		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; margin-bottom: 40px;">
			<!-- Feeds Card -->
			<div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: pointer;" onclick="window.location.href='admin.php?page=job-feed-dashboard'">
				<div style="display: flex; align-items: center; margin-bottom: 16px;">
					<div style="width: 80px; height: 80px; border-radius: 20px; background: linear-gradient(135deg, #007aff 0%, #5856d6 100%); display: flex; align-items: center; justify-content: center; margin-right: 16px; box-shadow: 0 8px 24px rgba(0, 122, 255, 0.3);">
						<i class="fas fa-rss" style="font-size: 32px; color: white;"></i>
					</div>
					<div>
						<h3 style="font-size: 20px; font-weight: 600; margin: 0;"><?php _e( 'Job Feeds', 'puntwork' ); ?></h3>
						<p style="font-size: 14px; color: #8e8e93; margin: 4px 0 0;"><?php _e( 'Import and manage job feeds', 'puntwork' ); ?></p>
					</div>
				</div>
				<div style="display: flex; justify-content: space-between; align-items: center;">
					<span style="font-size: 14px; color: #8e8e93;"><?php _e( 'Manage feeds →', 'puntwork' ); ?></span>
					<span style="font-size: 18px; color: #007aff;">→</span>
				</div>
			</div>

			<!-- Jobs Card -->
			<div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: pointer;" onclick="window.location.href='admin.php?page=jobs-dashboard'">
				<div style="display: flex; align-items: center; margin-bottom: 16px;">
					<div style="width: 80px; height: 80px; border-radius: 20px; background: linear-gradient(135deg, #34c759, #30d158); display: flex; align-items: center; justify-content: center; margin-right: 16px; box-shadow: 0 8px 24px rgba(52, 199, 89, 0.3);">
						<i class="fas fa-briefcase" style="font-size: 32px; color: white;"></i>
					</div>
					<div>
						<h3 style="font-size: 20px; font-weight: 600; margin: 0;"><?php _e( 'Jobs', 'puntwork' ); ?></h3>
						<p style="font-size: 14px; color: #8e8e93; margin: 4px 0 0;"><?php _e( 'View and manage job posts', 'puntwork' ); ?></p>
					</div>
				</div>
				<div style="display: flex; justify-content: space-between; align-items: center;">
					<span style="font-size: 14px; color: #34c759;"><?php _e( 'Browse jobs →', 'puntwork' ); ?></span>
					<span style="font-size: 18px; color: #34c759;">→</span>
				</div>
			</div>

			<!-- Feed Config Card -->
			<div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: pointer;" onclick="window.location.href='admin.php?page=puntwork-feed-config'">
				<div style="display: flex; align-items: center; margin-bottom: 16px;">
					<div style="width: 80px; height: 80px; border-radius: 20px; background: linear-gradient(135deg, #ff9500, #ff9f0a); display: flex; align-items: center; justify-content: center; margin-right: 16px; box-shadow: 0 8px 24px rgba(255, 149, 0, 0.3);">
						<i class="fas fa-cog" style="font-size: 32px; color: white;"></i>
					</div>
					<div>
						<h3 style="font-size: 20px; font-weight: 600; margin: 0;"><?php _e( 'Feed Config', 'puntwork' ); ?></h3>
						<p style="font-size: 14px; color: #8e8e93; margin: 4px 0 0;"><?php _e( 'Configure and reorder feeds', 'puntwork' ); ?></p>
					</div>
				</div>
				<div style="display: flex; justify-content: space-between; align-items: center;">
					<span style="font-size: 14px; color: #8e8e93;"><?php _e( 'Configure feeds →', 'puntwork' ); ?></span>
					<span style="font-size: 18px; color: #ff9500;">→</span>
				</div>
			</div>

			<!-- Scheduling Card -->
			<div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: pointer;" onclick="window.location.href='admin.php?page=job-feed-dashboard'">
				<div style="display: flex; align-items: center; margin-bottom: 16px;">
					<div style="width: 80px; height: 80px; border-radius: 20px; background: linear-gradient(135deg, #af52de, #c25ae7); display: flex; align-items: center; justify-content: center; margin-right: 16px; box-shadow: 0 8px 24px rgba(175, 82, 222, 0.3);">
						<i class="fas fa-clock" style="font-size: 32px; color: white;"></i>
					</div>
					<div>
						<h3 style="font-size: 20px; font-weight: 600; margin: 0;"><?php _e( 'Scheduling', 'puntwork' ); ?></h3>
						<p style="font-size: 14px; color: #8e8e93; margin: 4px 0 0;"><?php _e( 'Automated import schedules', 'puntwork' ); ?></p>
					</div>
				</div>
				<div style="display: flex; justify-content: space-between; align-items: center;">
					<span style="font-size: 14px; color: #8e8e93;"><?php _e( 'Configure schedules →', 'puntwork' ); ?></span>
					<span style="font-size: 18px; color: #af52de;">→</span>
				</div>
			</div>
		</div>

		<!-- Quick Stats -->
		<div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 40px;">
			<h3 style="font-size: 24px; font-weight: 600; margin: 0 0 20px;"><?php _e( 'Quick Overview', 'puntwork' ); ?></h3>
			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px;">
				<div style="text-align: center;">
					<div style="font-size: 32px; font-weight: 700; color: #007aff; margin-bottom: 8px;">0</div>
					<div style="font-size: 14px; color: #86868b;"><?php _e( 'Active Feeds', 'puntwork' ); ?></div>
				</div>
				<div style="text-align: center;">
					<div style="font-size: 32px; font-weight: 700; color: #34c759; margin-bottom: 8px;">0</div>
					<div style="font-size: 14px; color: #86868b;"><?php _e( 'Total Jobs', 'puntwork' ); ?></div>
				</div>
				<div style="text-align: center;">
					<div style="font-size: 32px; font-weight: 700; color: #ff9500; margin-bottom: 8px;">0</div>
					<div style="font-size: 14px; color: #86868b;"><?php _e( 'Scheduled Imports', 'puntwork' ); ?></div>
				</div>
				<div style="text-align: center;">
					<div style="font-size: 32px; font-weight: 700; color: #ff3b30; margin-bottom: 8px;">0</div>
					<div style="font-size: 14px; color: #86868b;"><?php _e( 'Failed Imports', 'puntwork' ); ?></div>
				</div>
			</div>
		</div>

		<!-- Recent Activity -->
		<div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
			<h3 style="font-size: 24px; font-weight: 600; margin: 0 0 20px;"><?php _e( 'Recent Activity', 'puntwork' ); ?></h3>
			<div style="text-align: center; padding: 40px 20px; color: #8e8e93;">
				<div style="font-size: 48px; margin-bottom: 16px;"><i class="fas fa-chart-bar" style="color: #8e8e93;"></i></div>
				<p style="font-size: 16px; margin: 0;"><?php _e( 'Activity feed will appear here once you start importing jobs', 'puntwork' ); ?></p>
			</div>
		</div>
	</div>

	<!-- Onboarding Modal -->
	<?php render_onboarding_modal(); ?>

	<script>
		// PWA Status Indicator
		document.addEventListener('DOMContentLoaded', function() {
			const pwaIndicator = document.getElementById('pwa-status-indicator');
			const installBtn = document.getElementById('pwa-install-btn');

			// Check if PWA is supported and not already installed
			if ('serviceWorker' in navigator && 'BeforeInstallPromptEvent' in window) {
				// Check if not running in standalone mode
				if (!window.matchMedia('(display-mode: standalone)').matches) {
					pwaIndicator.style.display = 'block';

					// Handle install button click
					installBtn.addEventListener('click', function() {
						// The PWA manager will handle the install prompt
						if (window.PuntworkPWAManager && window.PuntworkPWAManager.deferredPrompt) {
							window.PuntworkPWAManager.installPWA();
						} else {
							// Fallback: try to trigger install prompt
							if ('serviceWorker' in navigator) {
								navigator.serviceWorker.getRegistrations().then(registrations => {
									if (registrations.length > 0) {
										// PWA is registered, show manual install instructions
										alert('<?php echo esc_js( __( "To install puntWork Admin as a PWA, click the install icon in your browser\'s address bar or use the browser menu.", 'puntwork' ) ); ?>');
									}
								});
							}
						}
					});
				}
			}
		});
	</script>

	<style>
		.wrap > div:hover {
			transform: translateY(-2px);
			box-shadow: 0 4px 20px rgba(0,0,0,0.15);
		}
	</style>
	<?php
}

/**
 * Render the onboarding modal HTML
 */
function render_onboarding_modal() {
	// Check if onboarding has been completed
	$onboarding_completed = get_option( 'puntwork_onboarding_completed', false );

	// Only show if not completed
	if ( $onboarding_completed ) {
		return;
	}

	?>
	<!-- Onboarding Modal Overlay -->
	<div class="onboarding-overlay" id="onboarding-overlay" style="display: none;"></div>

	<!-- Onboarding Modal -->
	<div class="puntwork-onboarding-modal" id="puntwork-onboarding-modal" role="dialog" aria-modal="true" aria-labelledby="onboarding-title" aria-hidden="true" style="display: none;">
		<div class="onboarding-modal">
			<!-- Modal Header -->
			<div class="onboarding-header">
				<button type="button" id="onboarding-close" class="onboarding-close" aria-label="<?php esc_attr_e( 'Close onboarding', 'puntwork' ); ?>">
					<i class="fas fa-times"></i>
				</button>
				<button type="button" id="onboarding-skip" class="onboarding-skip-btn" aria-label="<?php esc_attr_e( 'Skip onboarding', 'puntwork' ); ?>">
					<?php _e( 'Skip', 'puntwork' ); ?>
				</button>
			</div>

			<!-- Step Indicators -->
			<div class="step-indicators">
				<div class="step-indicator active" data-step="0" aria-label="<?php esc_attr_e( 'Welcome step', 'puntwork' ); ?>"></div>
				<div class="step-indicator" data-step="1" aria-label="<?php esc_attr_e( 'Configure feeds step', 'puntwork' ); ?>"></div>
				<div class="step-indicator" data-step="2" aria-label="<?php esc_attr_e( 'Set up scheduling step', 'puntwork' ); ?>"></div>
				<div class="step-indicator" data-step="3" aria-label="<?php esc_attr_e( 'API configuration step', 'puntwork' ); ?>"></div>
				<div class="step-indicator" data-step="4" aria-label="<?php esc_attr_e( 'Setup complete step', 'puntwork' ); ?>"></div>
			</div>

			<!-- Step Content -->
			<div class="onboarding-content">
				<div id="onboarding-step-content" class="step-content">
					<!-- Content will be populated by JavaScript -->
				</div>
			</div>

			<!-- Progress Bar -->
			<div class="onboarding-progress" style="padding-top: 16px; margin-bottom: 16px;">
				<div class="onboarding-progress-bar">
					<div class="onboarding-progress-fill" id="onboarding-progress-fill"></div>
				</div>
			</div>

			<!-- Navigation Buttons -->
			<div class="onboarding-footer">
				<div class="footer-actions">
					<!-- Navigation Buttons -->
					<button type="button" id="onboarding-prev" class="onboarding-btn secondary prev-btn" style="display: none;" aria-label="<?php esc_attr_e( 'Previous step', 'puntwork' ); ?>">
						<i class="fas fa-arrow-left"></i> <?php _e( 'Previous', 'puntwork' ); ?>
					</button>
					<button type="button" id="onboarding-next" class="onboarding-btn primary next-btn" aria-label="<?php esc_attr_e( 'Next step', 'puntwork' ); ?>">
						<?php _e( 'Next', 'puntwork' ); ?> <i class="fas fa-arrow-right"></i>
					</button>
				</div>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Render import history UI section
 */
function render_import_history_ui() {
	?>
	<!-- Import History Section -->
	<div class="wrap" style="max-width: 900px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #1d1d1f; padding: 0 24px; background-color: #f5f5f7;">
		<div id="import-history" style="max-width: 900px; margin: 0 auto; margin-top: 40px; background-color: #ffffff; border-radius: 16px; padding: 32px; box-shadow: 0 2px 10px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04); position: relative; overflow: hidden;">

			<!-- Header Section -->
			<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; padding-bottom: 24px; border-bottom: 1px solid #e5e5e7;">
				<div>
					<h2 style="font-size: 28px; font-weight: 700; margin: 0 0 4px 0; color: #1d1d1f; letter-spacing: -0.02em;"><?php _e( 'Import History', 'puntwork' ); ?></h2>
					<p style="font-size: 15px; color: #86868b; margin: 0; font-weight: 400;"><?php _e( 'View all import runs including manual, scheduled, and API-triggered imports', 'puntwork' ); ?></p>
				</div>
				<button id="refresh-history-main" class="puntwork-btn puntwork-btn--secondary" aria-label="<?php esc_attr_e( 'Refresh import history', 'puntwork' ); ?>">
					<i class="fas fa-sync-alt" style="margin-right: 6px;"></i><?php _e( 'Refresh', 'puntwork' ); ?>
				</button>
			</div>

			<!-- Import History Content -->
			<div id="run-history-list" style="max-height: 600px; overflow-y: auto; font-size: 14px; border-radius: 8px; background-color: #fafbfc; padding: 20px;">
				<div style="color: #86868b; text-align: center; padding: 24px; font-style: italic;"><?php _e( 'Loading history...', 'puntwork' ); ?></div>
			</div>
		</div>
	</div>

	<style>
		/* Import History specific styles */
		#import-history .secondary-button:hover {
			background-color: #e5e5e7;
			border-color: #007aff;
		}

		/* Loading animation for refresh button */
		#import-history #refresh-history-main.loading i {
			animation: spin 1s linear infinite;
		}

		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}

		/* Responsive design */
		@media (max-width: 768px) {
			#import-history {
				margin: 20px 16px;
				padding: 24px 20px;
			}

			#import-history h2 {
				font-size: 24px;
			}
		}
	</style>
	<?php
}

/**
 * Render JavaScript initialization for the jobs dashboard page
 */
function render_jobs_javascript_init() {
	?>
	<script type="text/javascript">
		jQuery(document).ready(function($) {
			console.log('[PUNTWORK] Jobs Dashboard: Document ready, checking modules...');

			// Check if buttons exist
			console.log('[PUNTWORK] Jobs Dashboard: cleanup-duplicates button exists:', $('#cleanup-duplicates').length);

			// Add a simple test function to global scope
			window.testJobsButtons = function() {
				console.log('[PUNTWORK] Testing jobs buttons...');
				console.log('Cleanup button found:', $('#cleanup-duplicates').length);

				if ($('#cleanup-duplicates').length > 0) {
					console.log('Cleanup button HTML:', $('#cleanup-duplicates')[0].outerHTML);
				}

				// Test click events
				$('#cleanup-duplicates').trigger('click');
			};

			console.log('[PUNTWORK] Run testJobsButtons() in console to test button functionality');

			// Initialize jobs dashboard
			if (typeof JobImportEvents !== 'undefined') {
				console.log('[PUNTWORK] Jobs Dashboard: Initializing events...');
				// Only bind cleanup events, not the full import system
				JobImportEvents.bindCleanupEvents();
			} else {
				console.error('[PUNTWORK] Jobs Dashboard: JobImportEvents not available!');
			}

			// Initialize UI components
			if (typeof JobImportUI !== 'undefined') {
				console.log('[PUNTWORK] Jobs Dashboard: Clearing cleanup progress...');
				JobImportUI.clearCleanupProgress();
			}
		});
	</script>
	<?php
}
