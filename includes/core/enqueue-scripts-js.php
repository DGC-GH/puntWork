<?php
/**
 * Enqueue admin scripts and styles for job import dashboard.
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

/**
 * Enqueue admin scripts and styles for job import dashboard.
 */
function enqueue_job_import_scripts() {
    // Check if we're on the job import dashboard page
    $current_page = isset($_GET['page']) ? $_GET['page'] : '';
    $post_type = isset($_GET['post_type']) ? $_GET['post_type'] : '';
    $current_screen = get_current_screen();
    
    error_log('[PUNTWORK] Current URL: ' . $_SERVER['REQUEST_URI']);
    error_log('[PUNTWORK] Current page: ' . $current_page);
    error_log('[PUNTWORK] Post type: ' . $post_type);
    error_log('[PUNTWORK] Current screen: ' . ($current_screen ? $current_screen->id : 'none'));
    
    // Load scripts on job import dashboard or when editing jobs (to be safe)
    if ($current_page === 'job-import-dashboard' || $post_type === 'job' || 
        ($current_screen && $current_screen->id === 'job_page_job-import-dashboard')) {
        error_log('[PUNTWORK] Enqueueing scripts - condition met');
        error_log('[PUNTWORK] Current page: ' . $current_page);
        error_log('[PUNTWORK] Post type: ' . $post_type);
        error_log('[PUNTWORK] Current screen: ' . ($current_screen ? $current_screen->id : 'none'));
        // Font Awesome for icons
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', [], '5.15.4');

        // Add custom styles for scheduling UI
        wp_add_inline_style('font-awesome', '
            #import-scheduling .schedule-toggle {
                position: relative;
                display: inline-block;
                width: 44px;
                height: 24px;
            }
            #import-scheduling .schedule-toggle input {
                opacity: 0;
                width: 0;
                height: 0;
            }
            #import-scheduling .schedule-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccc;
                transition: .4s;
                border-radius: 24px;
            }
            #import-scheduling .schedule-slider:before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: .4s;
                border-radius: 50%;
            }
            #import-scheduling input:checked + .schedule-slider {
                background-color: #007aff;
            }
            #import-scheduling input:checked + .schedule-slider:before {
                transform: translateX(20px);
            }
            #import-scheduling .status-indicator {
                display: inline-block;
                width: 8px;
                height: 8px;
                border-radius: 50%;
                margin-right: 6px;
            }
            #import-scheduling .status-active {
                background-color: #34c759;
            }
            #import-scheduling .status-disabled {
                background-color: #8e8e93;
            }
            #import-scheduling .status-error {
                background-color: #ff3b30;
            }
        ');

        // Enqueue JavaScript modules
        wp_enqueue_script(
            'puntwork-logger-js',
            JOB_IMPORT_URL . 'assets/puntwork-logger.js',
            ['jquery'],
            JOB_IMPORT_VERSION,
            true
        );

        wp_enqueue_script(
            'job-import-ui-js',
            JOB_IMPORT_URL . 'assets/job-import-ui.js',
            ['jquery', 'puntwork-logger-js'],
            JOB_IMPORT_VERSION,
            true
        );

        wp_enqueue_script(
            'job-import-api-js',
            JOB_IMPORT_URL . 'assets/job-import-api.js',
            ['jquery', 'puntwork-logger-js'],
            JOB_IMPORT_VERSION,
            true
        );

        wp_enqueue_script(
            'job-import-logic-js',
            JOB_IMPORT_URL . 'assets/job-import-logic.js',
            ['jquery', 'job-import-api-js', 'puntwork-logger-js'],
            JOB_IMPORT_VERSION,
            true
        );

        wp_enqueue_script(
            'job-import-events-js',
            JOB_IMPORT_URL . 'assets/job-import-events.js',
            ['jquery', 'puntwork-logger-js', 'job-import-ui-js', 'job-import-api-js', 'job-import-logic-js'],
            JOB_IMPORT_VERSION,
            true
        );

        wp_enqueue_script(
            'job-import-scheduling-js',
            JOB_IMPORT_URL . 'assets/job-import-scheduling.js',
            ['jquery', 'puntwork-logger-js', 'job-import-api-js'],
            JOB_IMPORT_VERSION,
            true
        );

        // Enqueue the main JavaScript file
        wp_enqueue_script(
            'job-import-admin-js',
            JOB_IMPORT_URL . 'assets/job-import-admin.js',
            ['jquery', 'job-import-ui-js', 'job-import-api-js', 'job-import-logic-js', 'job-import-events-js', 'job-import-scheduling-js', 'puntwork-logger-js'],
            JOB_IMPORT_VERSION,
            true
        );

        // Localize script with data
        wp_localize_script('job-import-admin-js', 'jobImportData', [
            'nonce' => wp_create_nonce('job_import_nonce'),
            'feeds' => get_feeds(),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'resume_progress' => (int) get_option('job_import_progress', 0)
        ]);
    }
}
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_job_import_scripts');
