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

    // Load scripts on job import dashboard, jobs dashboard and diagnostics pages
    $should_load = in_array($current_page, ['job-feed-dashboard', 'jobs-dashboard', 'puntwork-diagnostics']);

    if ($should_load) {
        // Font Awesome for icons
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', [], '5.15.4');

        // Add custom styles for scheduling UI
        wp_add_inline_style('font-awesome', '
            /* Apple-style Toggle Switch */
            #import-scheduling .schedule-toggle {
                position: relative;
                display: inline-block;
                width: 52px;
                height: 32px;
                cursor: pointer;
            }
            #import-scheduling .schedule-toggle input {
                opacity: 0;
                width: 0;
                height: 0;
            }
            #import-scheduling .schedule-slider {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: linear-gradient(135deg, #e5e5e7 0%, #d1d1d6 100%);
                border-radius: 16px;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
            }
            #import-scheduling .schedule-slider:before {
                position: absolute;
                content: "";
                height: 26px;
                width: 26px;
                left: 3px;
                bottom: 3px;
                background: linear-gradient(135deg, #ffffff 0%, #f9f9fa 100%);
                border-radius: 50%;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: 0 2px 4px rgba(0,0,0,0.15), 0 1px 2px rgba(0,0,0,0.1);
            }
            #import-scheduling input:checked + .schedule-slider {
                background: linear-gradient(135deg, #007aff 0%, #0056cc 100%);
                box-shadow: 0 0 0 1px rgba(0,122,255,0.2), inset 0 1px 1px rgba(255,255,255,0.2);
            }
            #import-scheduling input:checked + .schedule-slider:before {
                transform: translateX(20px);
                box-shadow: 0 2px 6px rgba(0,0,0,0.2), 0 1px 2px rgba(0,0,0,0.1);
            }
            #import-scheduling .schedule-toggle:hover .schedule-slider {
                box-shadow: inset 0 1px 3px rgba(0,0,0,0.15);
            }
            #import-scheduling input:checked + .schedule-slider:hover {
                box-shadow: 0 0 0 1px rgba(0,122,255,0.3), inset 0 1px 1px rgba(255,255,255,0.3);
            }

            /* Enhanced Status Indicators */
            #import-scheduling .status-indicator {
                display: inline-block;
                width: 10px;
                height: 10px;
                border-radius: 50%;
                margin-right: 8px;
                flex-shrink: 0;
                transition: all 0.3s ease;
                box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            }
            #import-scheduling .status-active {
                background: linear-gradient(135deg, #34c759 0%, #28a745 100%);
                box-shadow: 0 0 0 1px rgba(52,199,89,0.2), 0 1px 2px rgba(0,0,0,0.1);
            }
            #import-scheduling .status-disabled {
                background: linear-gradient(135deg, #8e8e93 0%, #86868b 100%);
                box-shadow: 0 0 0 1px rgba(142,142,147,0.2), 0 1px 2px rgba(0,0,0,0.1);
            }
            #import-scheduling .status-error {
                background: linear-gradient(135deg, #ff3b30 0%, #d63027 100%);
                box-shadow: 0 0 0 1px rgba(255,59,48,0.2), 0 1px 2px rgba(0,0,0,0.1);
                animation: pulse 2s infinite;
            }

            @keyframes pulse {
                0% { opacity: 1; }
                50% { opacity: 0.6; }
                100% { opacity: 1; }
            }

            /* Form Enhancements */
            #import-scheduling select,
            #import-scheduling input[type="number"] {
                transition: all 0.2s ease;
                cursor: pointer;
            }

            #import-scheduling select:hover,
            #import-scheduling input[type="number"]:hover {
                border-color: #007aff;
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(0,122,255,0.1);
            }

            #import-scheduling select:focus,
            #import-scheduling input[type="number"]:focus {
                border-color: #007aff;
                box-shadow: 0 0 0 3px rgba(0,122,255,0.1), 0 1px 2px rgba(0,0,0,0.05);
                transform: translateY(-1px);
            }

            /* Button Enhancements */
            #import-scheduling .primary-button,
            #import-scheduling .secondary-button,
            #import-scheduling .success-button {
                position: relative;
                overflow: hidden;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Helvetica Neue", Helvetica, Arial, sans-serif;
                letter-spacing: -0.01em;
            }

            #import-scheduling .primary-button:hover {
                background: linear-gradient(135deg, #0056cc 0%, #004499 100%);
                box-shadow: 0 4px 12px rgba(0,122,255,0.3);
                transform: translateY(-2px);
            }

            #import-scheduling .secondary-button:hover {
                background: linear-gradient(135deg, #e5e5e7 0%, #d1d1d6 100%);
                border-color: #007aff;
                transform: translateY(-1px);
            }

            #import-scheduling .success-button:hover {
                background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
                box-shadow: 0 4px 12px rgba(52,199,89,0.3);
                transform: translateY(-2px);
            }

            #import-scheduling .primary-button:active,
            #import-scheduling .success-button:active {
                transform: translateY(0);
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            /* Disabled button states */
            #import-scheduling .primary-button:disabled,
            #import-scheduling .secondary-button:disabled,
            #import-scheduling .success-button:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                transform: none;
                box-shadow: none;
            }

            /* Card animations */
            #import-scheduling .scheduling-card {
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }

            #import-scheduling .scheduling-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 24px rgba(0,0,0,0.12), 0 2px 8px rgba(0,0,0,0.08);
            }

            /* History list styling */
            #import-scheduling #run-history-list {
                scrollbar-width: thin;
                scrollbar-color: #d1d1d6 #f2f2f7;
            }

            #import-scheduling #run-history-list::-webkit-scrollbar {
                width: 6px;
            }

            #import-scheduling #run-history-list::-webkit-scrollbar-track {
                background: #f2f2f7;
                border-radius: 3px;
            }

            #import-scheduling #run-history-list::-webkit-scrollbar-thumb {
                background: #d1d1d6;
                border-radius: 3px;
            }

            #import-scheduling #run-history-list::-webkit-scrollbar-thumb:hover {
                background: #c7c7cc;
            }

            /* Loading animation for refresh button */
            #import-scheduling #refresh-history.loading {
                pointer-events: none;
            }

            #import-scheduling #refresh-history.loading i {
                animation: spin 1s linear infinite;
            }

            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }

            /* Notification styles */
            .job-import-notification {
                position: fixed;
                top: 48px;
                right: 24px;
                background: #ffffff;
                border-radius: 12px;
                padding: 16px 20px;
                box-shadow: 0 8px 24px rgba(0,0,0,0.15), 0 2px 8px rgba(0,0,0,0.1);
                border: 1px solid #e5e5e7;
                z-index: 10000;
                font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Helvetica Neue", Helvetica, Arial, sans-serif;
                font-size: 15px;
                font-weight: 500;
                color: #1d1d1f;
                max-width: 400px;
                animation: slideIn 0.3s ease-out;
            }

            .job-import-notification.success {
                border-color: #34c759;
                background: linear-gradient(135deg, #f8fff9 0%, #ffffff 100%);
            }

            .job-import-notification.error {
                border-color: #ff3b30;
                background: linear-gradient(135deg, #fff8f7 0%, #ffffff 100%);
            }

            @keyframes slideIn {
                0% {
                    transform: translateX(100%);
                    opacity: 0;
                }
                100% {
                    transform: translateX(0);
                    opacity: 1;
                }
            }

            /* Responsive enhancements */
            @media (max-width: 768px) {
                #import-scheduling {
                    margin: 20px 16px !important;
                    padding: 24px 20px !important;
                }

                #import-scheduling .scheduling-card {
                    padding: 20px 16px !important;
                }

                #import-scheduling h2 {
                    font-size: 24px !important;
                }

                #import-scheduling .action-buttons {
                    flex-direction: column !important;
                }

                #import-scheduling .action-buttons button {
                    width: 100% !important;
                    margin-bottom: 12px !important;
                }

                .job-import-notification {
                    left: 16px;
                    right: 16px;
                    max-width: none;
                }
            }

            /* Focus states for accessibility */
            #import-scheduling button:focus,
            #import-scheduling select:focus,
            #import-scheduling input:focus {
                outline: 2px solid #007aff;
                outline-offset: 2px;
            }

            /* High contrast mode support */
            @media (prefers-contrast: high) {
                #import-scheduling .schedule-slider {
                    border: 1px solid #000;
                }

                #import-scheduling input:checked + .schedule-slider {
                    border-color: #007aff;
                }
            }

            /* Reduced motion support */
            @media (prefers-reduced-motion: reduce) {
                #import-scheduling *,
                #import-scheduling *::before,
                #import-scheduling *::after {
                    animation-duration: 0.01ms !important;
                    animation-iteration-count: 1 !important;
                    transition-duration: 0.01ms !important;
                }
            }
        ');

        // Enqueue JavaScript modules
        wp_enqueue_script(
            'puntwork-logger-js',
            PUNTWORK_URL . 'assets/js/puntwork-logger.js',
            ['jquery'],
            PUNTWORK_VERSION . '.' . time(), // Add timestamp for cache busting
            true
        );

        wp_enqueue_script(
            'job-import-ui-js',
            PUNTWORK_URL . 'assets/js/job-import-ui.js',
            ['jquery', 'puntwork-logger-js'],
            PUNTWORK_VERSION . '.' . time(), // Add timestamp for cache busting
            true
        );

        wp_enqueue_script(
            'job-import-api-js',
            PUNTWORK_URL . 'assets/js/job-import-api.js',
            ['jquery', 'puntwork-logger-js'],
            PUNTWORK_VERSION . '.' . time(), // Add timestamp for cache busting
            true
        );

        wp_enqueue_script(
            'job-import-logic-js',
            PUNTWORK_URL . 'assets/js/job-import-logic.js',
            ['jquery', 'job-import-api-js', 'puntwork-logger-js'],
            PUNTWORK_VERSION . '.' . time(), // Add timestamp for cache busting
            true
        );

        wp_enqueue_script(
            'job-import-events-js',
            PUNTWORK_URL . 'assets/js/job-import-events.js',
            ['jquery', 'puntwork-logger-js', 'job-import-ui-js', 'job-import-api-js', 'job-import-logic-js'],
            PUNTWORK_VERSION . '.' . time(), // Add timestamp for cache busting
            true
        );

        wp_enqueue_script(
            'job-import-scheduling-js',
            PUNTWORK_URL . 'assets/js/job-import-scheduling.js',
            ['jquery', 'puntwork-logger-js', 'job-import-api-js'],
            PUNTWORK_VERSION . '.' . time(), // Add timestamp for cache busting
            true
        );

        // Enqueue the main JavaScript file
        wp_enqueue_script(
            'job-import-admin-js',
            PUNTWORK_URL . 'assets/js/job-import-admin.js',
            ['jquery', 'job-import-ui-js', 'job-import-api-js', 'job-import-logic-js', 'job-import-events-js', 'job-import-scheduling-js', 'puntwork-logger-js'],
            PUNTWORK_VERSION . '.' . time(), // Add timestamp for cache busting
            true
        );

        // Localize script with data
        wp_localize_script('job-import-admin-js', 'jobImportData', [
            'nonce' => wp_create_nonce('job_import_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'resume_progress' => (int) get_option('job_import_progress', 0)
        ]);
    }
}
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_job_import_scripts');
