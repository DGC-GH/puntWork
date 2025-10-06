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
		// Prevent duplicate menu registration
		if ( defined( 'PUNTWORK_ADMIN_MENU_REGISTERED' ) && PUNTWORK_ADMIN_MENU_REGISTERED ) {
			return;
		}
		define( 'PUNTWORK_ADMIN_MENU_REGISTERED', true );

		PuntWorkLogger::info( 'Admin menu registration started', PuntWorkLogger::CONTEXT_ADMIN );
		error_log( '[PUNTWORK] [ADMIN-MENU] Admin menu registration started at ' . date( 'Y-m-d H:i:s T' ) );
		error_log( '[PUNTWORK] [ADMIN-MENU] Current URL: ' . ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : 'unknown' ) );
		error_log( '[PUNTWORK] [ADMIN-MENU] is_admin(): ' . ( is_admin() ? 'true' : 'false' ) );

		// Check if menu already exists to prevent conflicts
		global $menu;
		$menu_exists = false;
		if ( isset( $menu ) && is_array( $menu ) ) {
			foreach ( $menu as $menu_item ) {
				if ( isset( $menu_item[2] ) && $menu_item[2] === 'puntwork-dashboard' ) {
					$menu_exists = true;
					break;
				}
			}
		}

		if ( ! $menu_exists ) {
			add_menu_page(
				__( 'puntWork Dashboard', 'puntwork' ),
				'.work',
				'manage_options',
				'puntwork-dashboard',
				__NAMESPACE__ . '\\puntwork_dashboard_page',
				PUNTWORK_URL . 'assets/images/icon.svg',
				2
			);
			error_log( '[PUNTWORK] [ADMIN-MENU] Main menu page added: puntwork-dashboard' );
		} else {
			error_log( '[PUNTWORK] [ADMIN-MENU] Main menu page already exists, skipping registration' );
		}

		add_submenu_page(
			'puntwork-dashboard',
			__( 'Feeds Dashboard', 'puntwork' ),
			__( 'Feeds', 'puntwork' ),
			'manage_options',
			'job-feed-dashboard',
			__NAMESPACE__ . '\\feeds_dashboard_page'
		);
		error_log( '[PUNTWORK] [ADMIN-MENU] Feeds dashboard submenu added' );

		add_submenu_page(
			'puntwork-dashboard',
			__( 'Jobs Dashboard', 'puntwork' ),
			__( 'Jobs', 'puntwork' ),
			'manage_options',
			'jobs-dashboard',
			__NAMESPACE__ . '\\jobs_dashboard_page'
		);
		error_log( '[PUNTWORK] [ADMIN-MENU] Jobs dashboard submenu added' );

		add_submenu_page(
			'puntwork-dashboard',
			__( 'Feed Configuration', 'puntwork' ),
			__( 'Feed Config', 'puntwork' ),
			'manage_options',
			'puntwork-feed-config',
			__NAMESPACE__ . '\\feed_config_page'
		);
		error_log( '[PUNTWORK] [ADMIN-MENU] Feed configuration submenu added' );

		add_submenu_page(
			'puntwork-dashboard',
			__( 'API Settings', 'puntwork' ),
			__( 'API Settings', 'puntwork' ),
			'manage_options',
			'puntwork-api-settings',
			__NAMESPACE__ . '\\api_settings_page'
		);
		error_log( '[PUNTWORK] [ADMIN-MENU] API settings submenu added' );

		PuntWorkLogger::info( 'Admin menu registration completed', PuntWorkLogger::CONTEXT_ADMIN );
		error_log( '[PUNTWORK] [ADMIN-MENU] Admin menu registration completed' );
	}
);

// Add setup wizard as the last menu item with high priority
add_action(
	'admin_menu',
	function () {
		// Prevent duplicate menu registration
		if ( defined( 'PUNTWORK_ADMIN_MENU_REGISTERED' ) && PUNTWORK_ADMIN_MENU_REGISTERED ) {
			return;
		}

	},
	99
);
