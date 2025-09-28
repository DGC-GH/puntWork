<?php

/**
 * Admin menu setup for job import plugin
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

// Force admin menu refresh on plugin load to ensure icon updates
add_action(
	'admin_init',
	function () {
		// This helps ensure the admin menu icon is refreshed
		if ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'puntwork' ) === 0 ) {
			// Add a small cache-busting parameter to force icon reload
			add_action(
				'admin_head',
				function () {
					echo '<style>#adminmenu .toplevel_page_puntwork-dashboard .wp-menu-image img { display: none; }</style>';
				}
			);
		}
	}
);

add_action(
	'admin_menu',
	function () {
		add_menu_page(
			__( 'puntWork Dashboard', 'puntwork' ),
			'.work',
			'manage_options',
			'puntwork-dashboard',
			__NAMESPACE__ . '\\puntwork_dashboard_page',
			PUNTWORK_URL . 'assets/images/icon.svg',
			0
		);

		add_submenu_page(
			'puntwork-dashboard',
			__( 'Feeds Dashboard', 'puntwork' ),
			__( 'Feeds', 'puntwork' ),
			'manage_options',
			'job-feed-dashboard',
			__NAMESPACE__ . '\\feeds_dashboard_page'
		);

		add_submenu_page(
			'puntwork-dashboard',
			__( 'Jobs Dashboard', 'puntwork' ),
			__( 'Jobs', 'puntwork' ),
			'manage_options',
			'jobs-dashboard',
			__NAMESPACE__ . '\\jobs_dashboard_page'
		);

		add_submenu_page(
			'puntwork-dashboard',
			__( 'Feed Configuration', 'puntwork' ),
			__( 'Feed Config', 'puntwork' ),
			'manage_options',
			'puntwork-feed-config',
			__NAMESPACE__ . '\\feed_config_page'
		);

		add_submenu_page(
			'puntwork-dashboard',
			__( 'Feed Health Monitor', 'puntwork' ),
			__( 'Health Monitor', 'puntwork' ),
			'manage_options',
			'puntwork-feed-health',
			__NAMESPACE__ . '\\feed_health_monitor_page'
		);

		add_submenu_page(
			'puntwork-dashboard',
			__( 'Import Analytics', 'puntwork' ),
			__( 'Analytics', 'puntwork' ),
			'manage_options',
			'puntwork-analytics',
			__NAMESPACE__ . '\\import_analytics_page'
		);

		add_submenu_page(
			'puntwork-dashboard',
			__( 'Performance Metrics', 'puntwork' ),
			__( 'Performance', 'puntwork' ),
			'manage_options',
			'puntwork-performance',
			__NAMESPACE__ . '\\performance_metrics_page'
		);

		add_submenu_page(
			'puntwork-dashboard',
			__( 'API Settings', 'puntwork' ),
			__( 'API Settings', 'puntwork' ),
			'manage_options',
			'puntwork-api-settings',
			__NAMESPACE__ . '\\api_settings_page'
		);

		add_submenu_page(
			'puntwork-dashboard',
			__( 'System Monitoring', 'puntwork' ),
			__( 'Monitoring', 'puntwork' ),
			'manage_options',
			'puntwork-monitoring',
			__NAMESPACE__ . '\\system_monitoring_page'
		);
	}
);

// Add setup wizard as the last menu item with high priority
add_action(
	'admin_menu',
	function () {
		// Onboarding menu item (only show if onboarding not completed) - always last
		if ( ! PuntworkOnboardingWizard::isOnboardingCompleted() ) {
			add_submenu_page(
				'puntwork-dashboard',
				__( 'Setup Wizard', 'puntwork' ),
				__( 'Setup Wizard', 'puntwork' ),
				'manage_options',
				'puntwork-onboarding',
				function () {
					// Reset onboarding and redirect to dashboard with onboarding modal
					delete_option( 'puntwork_onboarding_completed' );
					wp_redirect( admin_url( 'admin.php?page=puntwork-dashboard&show_onboarding=1' ) );
					exit;
				}
			);
		}
	},
	99
);
