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

/**
 * Render JavaScript initialization for the admin page
 */
function render_javascript_init() {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Initialize the job import system
            if (typeof JobImportLogic !== 'undefined') {
                JobImportLogic.init();
            }

            // Initialize UI components
            if (typeof JobImportUI !== 'undefined') {
                JobImportUI.clearProgress();
            }

            // Initialize scheduling if available
            if (typeof JobImportScheduling !== 'undefined') {
                JobImportScheduling.init();
            }

            console.log('[PUNTWORK] Admin page JavaScript initialized');
        });
    </script>
    <?php
}
