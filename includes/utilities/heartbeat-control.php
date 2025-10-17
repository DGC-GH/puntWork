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

        // Check if import status should be considered stale/inactive
        $should_respond = true;
        if (!empty($import_status)) {
            $current_time = time();
            $last_update = $import_status['last_update'] ?? 0;
            $time_since_update = $current_time - $last_update;

            // Check if import has completed with cleanup (extended inactive period)
            $is_complete = $import_status['complete'] ?? false;
            $cleanup_completed = isset($import_status['cleanup_completed']) && $import_status['cleanup_completed'];

            // HEARTBEAT FIX: Detect old completed imports that should not respond via heartbeat
            $is_old_completed_import = false;
            if ($is_complete) {
                // Consider any completed import older than 5 minutes (300 seconds) as old/inactive
                $STALE_COMPLETION_THRESHOLD = 300; // 5 minutes in seconds
                if ($time_since_update > $STALE_COMPLETION_THRESHOLD) {
                    $is_old_completed_import = true;
                    $should_respond = false;
                    PuntWorkLogger::debug('[CLIENT] Heartbeat status request ignored - old completed import', PuntWorkLogger::CONTEXT_AJAX, [
                        'time_since_update' => $time_since_update,
                        'threshold' => $STALE_COMPLETION_THRESHOLD,
                        'processed' => $import_status['processed'] ?? 0,
                        'total' => $import_status['total'] ?? 0,
                        'is_complete' => true
                    ]);
                }
            }

            if (!$is_old_completed_import) {
                if ($is_complete && $cleanup_completed) {
                    // Completed imports with cleanup: Stop responding after 60 seconds
                    $should_respond = $time_since_update < 60;
                } elseif ($is_complete) {
                    // Completed imports without cleanup: Stop responding after 30 seconds
                    $should_respond = $time_since_update < 30;
                }
            }
        }

        // Only respond if status is still considered active/recent
        if ($should_respond) {
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
                    'heartbeat_force_requested' => $data['puntwork_import_status'] === 'force',
                    'should_respond' => $should_respond
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
        } elseif (defined('WP_DEBUG') && WP_DEBUG && defined('PUNTWORK_DEBUG_POLLING') && PUNTWORK_DEBUG_POLLING) {
            PuntWorkLogger::debug('[CLIENT] Heartbeat status request ignored - import completed too long ago', PuntWorkLogger::CONTEXT_AJAX, [
                'last_update' => $import_status['last_update'] ?? 'null',
                'time_since_update' => $current_time - ($import_status['last_update'] ?? 0),
                'is_complete' => $import_status['complete'] ?? false,
                'cleanup_completed' => $cleanup_completed ?? false
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

        // Check if import has completed with cleanup (extended inactive period)
        $is_complete = $import_status['complete'] ?? false;
        $cleanup_completed = isset($import_status['cleanup_completed']) && $import_status['cleanup_completed'];

        // HEARTBEAT FIX: Detect old completed imports that should not be sent via heartbeat
        $is_old_completed_import = false;
        if ($is_complete) {
            // Consider any completed import older than 5 minutes (300 seconds) as old/inactive
            $STALE_COMPLETION_THRESHOLD = 300; // 5 minutes in seconds
            if ($time_since_update > $STALE_COMPLETION_THRESHOLD) {
                $is_old_completed_import = true;
                PuntWorkLogger::debug('[CLIENT] Detected old completed import - heartbeat inactive', PuntWorkLogger::CONTEXT_AJAX, [
                    'time_since_update' => $time_since_update,
                    'threshold' => $STALE_COMPLETION_THRESHOLD,
                    'processed' => $import_status['processed'] ?? 0,
                    'total' => $import_status['total'] ?? 0,
                    'is_complete' => true
                ]);
            }
        }

        if ($is_old_completed_import) {
            // Suppress heartbeat for old completed imports to prevent stale data display
            $is_active = false;
        } elseif ($is_complete && $cleanup_completed) {
            // Completed imports with cleanup: Consider inactive after brief period (60 seconds)
            // to prevent continuous updates after finalization, but allow enough time for final status display
            $is_active = $time_since_update < 60;
        } elseif ($is_complete) {
            // Completed imports without cleanup yet: Only active for very brief period (15 seconds)
            // This prevents continuous updates after auto-cleanup runs
            $time_since_completion = $time_since_update; // Use last_update as completion time proxy
            $is_active = $time_since_completion < 15;
        } else {
            // Running imports: Active if currently running or recently started, BUT with safety timeout
            $has_recent_progress = $time_since_update < 60; // Progress within last minute
            $within_reasonable_time = $time_since_start < 900; // Max 15 minutes total runtime

            $is_active = ($start_time > 0 && $time_since_start < 300 && $has_recent_progress) || // Recent start + recent progress
                        ($start_time > 0 && !$import_status['complete'] && $within_reasonable_time); // Not complete + hasn't run too long

            // Safety: If import has been running too long without progress, consider inactive to prevent loops
            if (!$is_active && $start_time > 0 && $time_since_start > 300) {
                PuntWorkLogger::warn('Import appears stuck - deactivating heartbeat updates', PuntWorkLogger::CONTEXT_SYSTEM, [
                    'start_time' => $start_time,
                    'time_since_start' => $time_since_start,
                    'last_update' => $last_update,
                    'time_since_update' => $time_since_update,
                    'processed' => $import_status['processed'] ?? 0,
                    'phase' => $import_status['phase'] ?? 'unknown'
                ]);
            }
        }

        // Debug: Log heartbeat activity determination
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[PUNTWORK] Heartbeat active check: complete=' . ($is_complete ? 'true' : 'false') .
                     ', cleanup_completed=' . ($cleanup_completed ? 'true' : 'false') .
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
