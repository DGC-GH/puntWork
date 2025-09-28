<?php

/**
 * Modern Admin UI Styles for puntWork.
 *
 * @since      0.0.4
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue modern admin styles.
 */
function enqueue_modern_admin_styles()
{
    $current_page = isset($_GET['page']) ? $_GET['page'] : '';

    // Load styles on job import dashboard and jobs dashboard pages
    if (in_array($current_page, ['job-feed-dashboard', 'jobs-dashboard', 'puntwork-dashboard'])) {
        // Enqueue FontAwesome
        wp_enqueue_style(
            'font-awesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
            [],
            '6.4.0'
        );

        wp_enqueue_style(
            'puntwork-admin-modern',
            PUNTWORK_URL . 'assets/css/admin-modern.css',
            ['font-awesome'],
            PUNTWORK_VERSION
        );
    }
}
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_modern_admin_styles');
