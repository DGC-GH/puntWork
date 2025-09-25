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
    error_log('[PUNTWORK] job_import_admin_page() called');
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
            console.log('[PUNTWORK] Inline script: Document ready, checking modules...');
            console.log('[PUNTWORK] Inline script: JobImportEvents available:', typeof JobImportEvents);
            console.log('[PUNTWORK] Inline script: JobImportUI available:', typeof JobImportUI);
            console.log('[PUNTWORK] Inline script: JobImportAPI available:', typeof JobImportAPI);
            console.log('[PUNTWORK] Inline script: JobImportLogic available:', typeof JobImportLogic);
            console.log('[PUNTWORK] Inline script: jobImportInitialized:', typeof window.jobImportInitialized);

            // Check if buttons exist
            console.log('[PUNTWORK] Inline script: cleanup-duplicates button exists:', $('#cleanup-duplicates').length);

            // Add a simple test function to global scope
            window.testButtons = function() {
                console.log('[PUNTWORK] Testing buttons...');
                console.log('Cleanup button found:', $('#cleanup-duplicates').length);

                if ($('#cleanup-duplicates').length > 0) {
                    console.log('Cleanup button HTML:', $('#cleanup-duplicates')[0].outerHTML);
                }

                // Test click events
                $('#cleanup-duplicates').trigger('click');
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

                // Initialize scheduling if available
                if (typeof JobImportScheduling !== 'undefined') {
                    console.log('[PUNTWORK] Inline script: Calling JobImportScheduling.init()');
                    JobImportScheduling.init();
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
    error_log('[PUNTWORK] jobs_dashboard_page() called');
    wp_enqueue_script('jquery');

    // Render jobs dashboard UI
    render_jobs_dashboard_ui();

    // Render JavaScript initialization for jobs dashboard
    render_jobs_javascript_init();
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
