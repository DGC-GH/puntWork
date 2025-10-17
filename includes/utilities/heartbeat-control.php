<?php
/**
 * Heartbeat control for admin interface
 * Provides real-time import status updates without polling loops
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

require_once __DIR__ . '/options-utilities.php';
require_once __DIR__ . '/puntwork-logger.php';

/**
 * Initialize heartbeat for import status updates on all admin pages
 */
add_action('admin_enqueue_scripts', function($hook) {
    // Enable heartbeat on job-related admin pages for import status updates
    $job_pages = array(
        'puntwork-dashboard_page_job-feed-dashboard',
        'puntwork-dashboard_page_jobs-dashboard',
        'toplevel_page_puntwork-dashboard'
    );

    if (in_array($hook, $job_pages) || strpos($hook, 'puntwork') !== false || strpos($hook, 'job') !== false) {
        // Ensure heartbeat is available for job-related dashboards
        // Note: We don't deregister heartbeat globally to avoid breaking wp-auth-check dependencies

        // Enqueue our heartbeat handler script
        wp_enqueue_script(
            'puntwork-heartbeat',
            plugin_dir_url(__FILE__) . '../../assets/js/job-import-heartbeat.js',
            array('jquery', 'heartbeat'),
            '1.0.0',
            true
        );

        // Localize script with necessary data
        wp_localize_script('puntwork-heartbeat', 'PuntWorkHeartbeat', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('heartbeat-nonce')
        ));

        // Log initialization for debugging
        PuntWorkLogger::info('Heartbeat initialized for admin page', PuntWorkLogger::CONTEXT_SYSTEM, [
            'hook' => $hook,
            'heartbeat_enabled' => true
        ]);
    }
});

/**
 * Handle heartbeat ticks with minimal code to test basic functionality
 */
add_filter('heartbeat_received', function($response, $data, $screen_id) {
    // Minimal heartbeat test - just return the response without any complex logic
    if (isset($data['puntwork_import_status'])) {
        $response['puntwork_import_status'] = array(
            'status' => array('test' => true),
            'timestamp' => time(),
            'has_changes' => true
        );
    }
    return $response;
}, 10, 3);

/**
 * Handle heartbeat send with minimal code to test basic functionality
 */
add_filter('heartbeat_send', function($response, $screen_id) {
    // Minimal heartbeat test - just return empty response
    return $response;
}, 10, 2);
