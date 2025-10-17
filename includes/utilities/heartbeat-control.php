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
 * Handle heartbeat ticks and respond with import status when needed
 */
add_filter('heartbeat_received', function($response, $data, $screen_id) {
    // Always respond to heartbeat requests for import status, regardless of screen
    // This ensures import status is available across the admin interface

    // Check if client requested import status
    if (isset($data['puntwork_import_status'])) {
        $import_status = get_import_status([]);

        // Debug logging for heartbeat polling
        if (defined('WP_DEBUG') && WP_DEBUG && defined('PUNTWORK_DEBUG_POLLING') && PUNTWORK_DEBUG_POLLING) {
            PuntWorkLogger::debug('[CLIENT] Heartbeat status request received', PuntWorkLogger::CONTEXT_AJAX, [
                'processed_from_status' => $import_status['processed'] ?? 'null',
                'total_from_status' => $import_status['total'] ?? 'null',
                'phase_from_status' => $import_status['phase'] ?? 'null',
                'complete_from_status' => $import_status['complete'] ?? 'null',
                'time_elapsed' => $import_status['time_elapsed'] ?? 'null',
                'last_update' => $import_status['last_update'] ?? 'null',
                'has_recent_update' => isset($import_status['last_update']) && $import_status['last_update'] > 0,
                'heartbeat_force_requested' => $data['puntwork_import_status'] === 'force'
            ]);
        }

        // Only send update if status has changed or if this is the first request
        static $last_status_hash = null;
        $current_hash = md5(serialize($import_status));

        if ($last_status_hash !== $current_hash || $data['puntwork_import_status'] === 'force') {
            $response['puntwork_import_status'] = array(
                'status' => $import_status,
                'timestamp' => time(),
                'has_changes' => ($last_status_hash !== $current_hash)
            );
            $last_status_hash = $current_hash;

            if (defined('WP_DEBUG') && WP_DEBUG && defined('PUNTWORK_DEBUG_POLLING') && PUNTWORK_DEBUG_POLLING) {
                PuntWorkLogger::debug('[CLIENT] Heartbeat status update sent', PuntWorkLogger::CONTEXT_AJAX, [
                    'processed_sent' => $import_status['processed'] ?? 'null',
                    'has_changes' => ($last_status_hash !== $current_hash),
                    'force_requested' => $data['puntwork_import_status'] === 'force',
                    'timestamp' => time()
                ]);
            }
        } elseif (defined('WP_DEBUG') && WP_DEBUG && defined('PUNTWORK_DEBUG_POLLING') && PUNTWORK_DEBUG_POLLING) {
            PuntWorkLogger::debug('[CLIENT] Heartbeat status unchanged - no update sent', PuntWorkLogger::CONTEXT_AJAX, [
                'processed_current' => $import_status['processed'] ?? 'null',
                'hash_match' => true
            ]);
        }
    }

    // Check for active scheduled imports
    if (isset($data['puntwork_scheduled_imports'])) {
        $scheduled_data = array(
            'schedule' => get_option('job_import_schedule', array()),
            'next_run' => wp_next_scheduled('puntwork_scheduled_import'),
            'last_run' => get_option('job_import_last_run', array()),
            'active_imports' => get_active_scheduled_imports_status()
        );

        static $last_scheduled_hash = null;
        $current_scheduled_hash = md5(serialize($scheduled_data));

        if ($last_scheduled_hash !== $current_scheduled_hash) {
            $response['puntwork_scheduled_imports'] = array(
                'data' => $scheduled_data,
                'timestamp' => time(),
                'has_changes' => true
            );
            $last_scheduled_hash = $current_scheduled_hash;
        }
    }

    return $response;
}, 10, 3);

/**
 * Handle heartbeat ticks from client and send import status updates
 */
add_filter('heartbeat_send', function($response, $screen_id) {
    // Always include basic import status in heartbeat response for any admin page
    // This ensures import status is available across the admin interface
    $import_status = get_import_status([]);

    // Check if import is active (running or very recently completed)
    $is_active = false;
    if (!empty($import_status)) {
        $current_time = time();
        $start_time = $import_status['start_time'] ?? 0;
        $last_update = $import_status['last_update'] ?? 0;
        $time_since_start = $current_time - $start_time;
        $time_since_update = $current_time - $last_update;

        // FIX: Prevent continuous heartbeat updates for completed imports after cleanup
        $is_complete = $import_status['complete'] ?? false;

        if ($is_complete) {
            // Completed imports: Only active for very brief period (15 seconds) after completion
            // This prevents continuous updates after auto-cleanup runs
            $time_since_completion = $time_since_update; // Use last_update as completion time proxy
            $is_active = $time_since_completion < 15;
        } else {
            // Running imports: Active if currently running or recently started
            $is_active = ($start_time > 0 && $time_since_start < 300) || // Started within last 5 minutes
                        ($start_time > 0 && !$import_status['complete']); // Has start_time and not complete
        }

        // Debug: Log heartbeat activity determination
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[PUNTWORK] Heartbeat active check: complete=' . ($is_complete ? 'true' : 'false') .
                     ', time_since_update=' . $time_since_update .
                     ', is_active=' . ($is_active ? 'true' : 'false') .
                     ', processed=' . ($import_status['processed'] ?? 0));
        }
    }

    if ($is_active) {
        static $last_sent_hash = null;
        $current_hash = md5(serialize($import_status));

        // Only send if status changed
        if ($last_sent_hash !== $current_hash) {
            $response['puntwork_import_update'] = array(
                'status' => $import_status,
                'timestamp' => time(),
                'is_active' => true
            );
            $last_sent_hash = $current_hash;

            // Debug logging for heartbeat sends
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[PUNTWORK] Heartbeat sending import update: processed=' . ($import_status['processed'] ?? 0) . ', total=' . ($import_status['total'] ?? 0) . ', complete=' . ($import_status['complete'] ? 'true' : 'false'));
            }
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[PUNTWORK] Heartbeat skipping send - status unchanged');
        }
    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[PUNTWORK] Heartbeat not active: is_active=' . ($is_active ? 'true' : 'false') . ', status_empty=' . (empty($import_status) ? 'true' : 'false'));
    }

    return $response;
}, 10, 2);

/**
 * Get active scheduled imports status
 */
function get_active_scheduled_imports_status() {
    // Check if Action Scheduler has any puntwork import actions queued or running
    if (class_exists('ActionScheduler')) {
        try {
            $actions = \ActionScheduler::store()->query_actions(array(
                'hook' => 'puntwork_scheduled_import_async',
                'status' => array('pending', 'in-progress'),
                'per_page' => 10
            ));

            return array(
                'has_active_imports' => !empty($actions),
                'active_count' => count($actions),
                'actions' => array_map(function($action_id) {
                    $action = \ActionScheduler::store()->fetch_action($action_id);
                    return array(
                        'id' => $action_id,
                        'status' => $action->get_status(),
                        'scheduled_date' => $action->get_schedule()->get_date()->format('Y-m-d H:i:s')
                    );
                }, $actions)
            );
        } catch (\Exception $e) {
            return array(
                'has_active_imports' => false,
                'error' => $e->getMessage()
            );
        }
    }

    return array('has_active_imports' => false);
}
