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
 * Handle heartbeat ticks with comprehensive import status updates
 */
add_filter('heartbeat_received', function($response, $data, $screen_id) {
    // Handle import status requests
    if (isset($data['puntwork_import_status'])) {
        $import_status = get_import_status([]);

        // Check for status changes compared to last heartbeat
        static $last_status_hash = null;
        $current_hash = md5(serialize($import_status));
        $has_changes = ($last_status_hash !== $current_hash);
        $last_status_hash = $current_hash;

        // Format status for heartbeat response - keep it lightweight
        $heartbeat_status = [
            'processed' => $import_status['processed'] ?? 0,
            'total' => $import_status['total'] ?? 0,
            'published' => $import_status['published'] ?? 0,
            'updated' => $import_status['updated'] ?? 0,
            'skipped' => $import_status['skipped'] ?? 0,
            'complete' => $import_status['complete'] ?? false,
            'success' => $import_status['success'] ?? null,
            'time_elapsed' => $import_status['time_elapsed'] ?? 0,
            'last_update' => $import_status['last_update'] ?? null,
            'phase' => $import_status['phase'] ?? '',
            'progress_percentage' => ($import_status['total'] ?? 0) > 0 ?
                round((($import_status['processed'] ?? 0) / ($import_status['total'] ?? 0)) * 100, 2) : 0,
            'timestamp' => microtime(true)
        ];

        $response['puntwork_import_update'] = [
            'status' => $heartbeat_status,
            'is_active' => !($import_status['complete'] ?? false) &&
                          (($import_status['processed'] ?? 0) > 0 || ($import_status['total'] ?? 0) > 0),
            'has_changes' => $has_changes,
            'timestamp' => microtime(true)
        ];
    }

    // Handle scheduled imports status
    if (isset($data['puntwork_scheduled_imports'])) {
        // Check for active scheduled imports
        $active_imports = [];

        // Check Action Scheduler
        if (function_exists('as_get_scheduled_actions')) {
            $pending_actions = as_get_scheduled_actions([
                'hook' => 'puntwork_scheduled_import',
                'status' => \ActionScheduler_Store::STATUS_PENDING
            ]);
            if (!empty($pending_actions)) {
                $active_imports['scheduled_pending'] = count($pending_actions);
            }
        }

        // Check WP cron
        $next_scheduled = wp_next_scheduled('puntwork_scheduled_import');
        if ($next_scheduled) {
            $active_imports['wp_cron_scheduled'] = $next_scheduled;
        }

        $response['puntwork_scheduled_imports'] = [
            'has_changes' => !empty($active_imports),
            'data' => $active_imports,
            'timestamp' => microtime(true)
        ];
    }

    return $response;
}, 10, 3);

/**
 * Handle heartbeat send - prepare data for outgoing heartbeat
 */
add_filter('heartbeat_send', function($response, $screen_id) {
    // No additional data to send on heartbeat ticks
    return $response;
}, 10, 2);
