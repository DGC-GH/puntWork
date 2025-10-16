<?php
/**
 * AJAX diagnostics for import debugging
 */
namespace Puntwork\API;

require_once __DIR__ . '/../utilities/ajax-utilities.php';
require_once __DIR__ . '/../utilities/options-utilities.php';

add_action('wp_ajax_puntwork_import_diagnostics', __NAMESPACE__ . '\puntwork_import_diagnostics_ajax');

function puntwork_import_diagnostics_ajax() {
    if (!validate_ajax_request('puntwork_import_diagnostics')) {
        return;
    }

    // restrict to administrators
    if (!current_user_can('manage_options')) {
        send_ajax_error('puntwork_import_diagnostics', 'Permission denied');
        return;
    }

    try {
        $status = \Puntwork\Utilities\get_import_status();
    } catch (\Exception $e) {
        $status = ['error' => 'failed to get status', 'message' => $e->getMessage()];
    }

    $diagnostics = get_option('job_import_diagnostics', []);

    // scheduled hooks
    $scheduled = [];
    $scheduled['continue_import'] = wp_next_scheduled('puntwork_continue_import');
    $scheduled['continue_import_retry'] = wp_next_scheduled('puntwork_continue_import_retry');
    $scheduled['continue_import_manual'] = wp_next_scheduled('puntwork_continue_import_manual');

    // Action Scheduler basic counts (if available)
    $as = ['available' => false, 'pending' => null, 'running' => null];
    if (class_exists('\ActionScheduler\ActionScheduler')) {
        $as['available'] = true;
        try {
            // attempt to get counts via Action Scheduler store if present
            if (function_exists('as_get_scheduled_actions')) {
                $pending = as_get_scheduled_actions(['per_page' => 1, 'return_format' => 'count']);
                $as['pending'] = $pending;
            }
        } catch (\Exception $e) {
            $as['error'] = $e->getMessage();
        }
    }

    $response = [
        'import_status' => $status,
        'diagnostics' => $diagnostics,
        'scheduled' => $scheduled,
        'action_scheduler' => $as,
    ];

    send_ajax_success('puntwork_import_diagnostics', $response, ['import_status' => $status]);
}
