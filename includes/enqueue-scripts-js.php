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
    
    // Load scripts on job import dashboard or when editing jobs (to be safe)
    if ($current_page === 'job-import-dashboard' || $post_type === 'job') {
        // Font Awesome for icons
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', [], '5.15.4');

        // Enqueue JavaScript modules
        wp_enqueue_script(
            'puntwork-logger-js',
            JOB_IMPORT_URL . 'assets/js/puntwork-logger.js',
            ['jquery'],
            JOB_IMPORT_VERSION,
            true
        );

        wp_enqueue_script(
            'job-import-ui-js',
            JOB_IMPORT_URL . 'assets/js/job-import-ui.js',
            ['jquery', 'puntwork-logger-js'],
            JOB_IMPORT_VERSION,
            true
        );

        wp_enqueue_script(
            'job-import-api-js',
            JOB_IMPORT_URL . 'assets/js/job-import-api.js',
            ['jquery', 'puntwork-logger-js'],
            JOB_IMPORT_VERSION,
            true
        );

        wp_enqueue_script(
            'job-import-logic-js',
            JOB_IMPORT_URL . 'assets/js/job-import-logic.js',
            ['jquery', 'job-import-api-js', 'puntwork-logger-js'],
            JOB_IMPORT_VERSION,
            true
        );

        wp_enqueue_script(
            'job-import-events-js',
            JOB_IMPORT_URL . 'assets/js/job-import-events.js',
            ['jquery', 'puntwork-logger-js', 'job-import-ui-js', 'job-import-api-js', 'job-import-logic-js'],
            JOB_IMPORT_VERSION,
            true
        );

        // Enqueue the main JavaScript file
        wp_enqueue_script(
            'job-import-admin-js',
            JOB_IMPORT_URL . 'assets/js/job-import-admin.js',
            ['jquery', 'job-import-ui-js', 'job-import-api-js', 'job-import-logic-js', 'job-import-events-js', 'puntwork-logger-js'],
            JOB_IMPORT_VERSION,
            true
        );

        // Localize script with data
        $feeds = [];
        try {
            $feeds = array_keys(get_feeds());
            error_log('Job Import: Found ' . count($feeds) . ' feeds: ' . implode(', ', $feeds));
        } catch (Exception $e) {
            error_log('Error getting feeds for JavaScript: ' . $e->getMessage());
            $feeds = [];
        }

        wp_localize_script('job-import-admin-js', 'jobImportData', [
            'nonce' => wp_create_nonce('job_import_nonce'),
            'feeds' => $feeds,
            'ajaxurl' => admin_url('admin-ajax.php'),
            'resume_progress' => (int) get_option('job_import_progress', 0)
        ]);
    }
}
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_job_import_scripts');
