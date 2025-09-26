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
add_action('admin_init', function() {
    // This helps ensure the admin menu icon is refreshed
    if (isset($_GET['page']) && strpos($_GET['page'], 'puntwork') === 0) {
        // Add a small cache-busting parameter to force icon reload
        add_action('admin_head', function() {
            echo '<style>#adminmenu .toplevel_page_puntwork-dashboard .wp-menu-image img { display: none; }</style>';
        });
    }
});

add_action('admin_menu', function() {
    add_menu_page(
        'puntWork Dashboard',
        '.work',
        'manage_options',
        'puntwork-dashboard',
        __NAMESPACE__ . '\\puntwork_dashboard_page',
        PUNTWORK_URL . 'assets/images/icon.svg?v=' . PUNTWORK_VERSION,
        0
    );

    add_submenu_page(
        'puntwork-dashboard',
        'Feeds Dashboard',
        'Feeds',
        'manage_options',
        'job-feed-dashboard',
        __NAMESPACE__ . '\\feeds_dashboard_page'
    );

    add_submenu_page(
        'puntwork-dashboard',
        'Jobs Dashboard',
        'Jobs',
        'manage_options',
        'jobs-dashboard',
        __NAMESPACE__ . '\\jobs_dashboard_page'
    );

    add_submenu_page(
        'puntwork-dashboard',
        'API Settings',
        'API',
        'manage_options',
        'puntwork-api-settings',
        __NAMESPACE__ . '\\api_settings_page'
    );

    add_submenu_page(
        'puntwork-dashboard',
        'Feed Health Monitor',
        'Health Monitor',
        'manage_options',
        'puntwork-feed-health',
        __NAMESPACE__ . '\\feed_health_monitor_page'
    );
});
