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
require_once __DIR__ . '/admin-ui-debug.php';

function job_import_admin_page() {
    wp_enqueue_script('jquery');

    // Render main import UI
    render_main_import_ui();

    // Render scheduling UI
    render_scheduling_ui();

    // Render debug UI (only in development)
    render_debug_ui();

    // Render JavaScript initialization
    render_javascript_init();
}
