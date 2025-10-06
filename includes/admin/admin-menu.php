<?php

/**
 * Admin menu setup for job import plugin.
 *
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
		PuntWorkLogger::info( 'Admin menu registration started', PuntWorkLogger::CONTEXT_ADMIN );
		error_log( '[PUNTWORK] [ADMIN-MENU] Admin menu registration started at ' . date( 'Y-m-d H:i:s T' ) );
		error_log( '[PUNTWORK] [ADMIN-MENU] Current URL: ' . ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : 'unknown' ) );
		error_log( '[PUNTWORK] [ADMIN-MENU] is_admin(): ' . ( is_admin() ? 'true' : 'false' ) );

		// Always register the main menu page first
		add_menu_page(
			__( 'puntWork Dashboard', 'puntwork' ),
			'.work',
			'manage_options',
			'puntwork-dashboard',
			'Puntwork\\puntwork_dashboard_page',
			PUNTWORK_URL . 'assets/images/icon.svg',
			2
		);
		error_log( '[PUNTWORK] [ADMIN-MENU] Main menu page added: puntwork-dashboard' );

		// Register the page handlers without adding to menu
		add_submenu_page(
			null, // Don't show in menu
			__( 'Feeds Dashboard', 'puntwork' ),
			'',
			'manage_options',
			'job-feed-dashboard',
			'Puntwork\\feeds_dashboard_page'
		);

		add_submenu_page(
			null, // Don't show in menu
			__( 'Jobs Dashboard', 'puntwork' ),
			'',
			'manage_options',
			'jobs-dashboard',
			'Puntwork\\jobs_dashboard_page'
		);

		add_submenu_page(
			null, // Don't show in menu
			__( 'Feed Configuration', 'puntwork' ),
			'',
			'manage_options',
			'puntwork-feed-config',
			'Puntwork\\feed_config_page'
		);

		add_submenu_page(
			null, // Don't show in menu
			__( 'API Settings', 'puntwork' ),
			'',
			'manage_options',
			'puntwork-api-settings',
			'Puntwork\\api_settings_page'
		);

		// Register submenu pages with different approach
		global $submenu;
		
		// Add submenus manually to ensure they work
		if (!isset($submenu['puntwork-dashboard'])) {
			$submenu['puntwork-dashboard'] = array();
		}

		// Add the main dashboard as first submenu (this replaces the parent menu)
		$submenu['puntwork-dashboard'][] = array(
			(string) __( 'Dashboard', 'puntwork' ),
			'manage_options',
			'admin.php?page=puntwork-dashboard'
		);

		// Add other submenus
		$submenu['puntwork-dashboard'][] = array(
			(string) __( 'Feeds', 'puntwork' ),
			'manage_options',
			'admin.php?page=job-feed-dashboard'
		);

		$submenu['puntwork-dashboard'][] = array(
			(string) __( 'Jobs', 'puntwork' ),
			'manage_options',
			'admin.php?page=jobs-dashboard'
		);

		$submenu['puntwork-dashboard'][] = array(
			(string) __( 'Feed Config', 'puntwork' ),
			'manage_options',
			'admin.php?page=puntwork-feed-config'
		);

		$submenu['puntwork-dashboard'][] = array(
			(string) __( 'API Settings', 'puntwork' ),
			'manage_options',
			'admin.php?page=puntwork-api-settings'
		);

		error_log( '[PUNTWORK] [ADMIN-MENU] Submenus added manually' );

		PuntWorkLogger::info( 'Admin menu registration completed', PuntWorkLogger::CONTEXT_ADMIN );
		error_log( '[PUNTWORK] [ADMIN-MENU] Admin menu registration completed' );
	},
	99
);

// Add setup wizard as the last menu item with high priority
add_action(
	'admin_menu',
	function () {
		// Setup wizard functionality removed
	},
	99
);
