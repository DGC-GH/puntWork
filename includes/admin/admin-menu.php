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

add_action('admin_menu', function() {
    add_menu_page(
        'puntWork Dashboard',
        '.work',
        'manage_options',
        'puntwork-dashboard',
        __NAMESPACE__ . '\\puntwork_dashboard_page',
        home_url('/favicon.ico'),
        0
    );

    add_submenu_page(
        'puntwork-dashboard',
        'Job Import Dashboard',
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
});
