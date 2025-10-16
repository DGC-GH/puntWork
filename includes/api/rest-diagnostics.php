<?php
/**
 * REST diagnostics endpoint for import status, progress and diagnostics
 */
namespace Puntwork\API;

require_once __DIR__ . '/../utilities/options-utilities.php';
require_once __DIR__ . '/../utilities/ajax-utilities.php';

use WP_REST_Server;
use function Puntwork\get_import_status;

add_action('rest_api_init', __NAMESPACE__ . '\\register_puntwork_rest_routes');

function register_puntwork_rest_routes() {
    register_rest_route('puntwork/v1', '/diagnostics', [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => __NAMESPACE__ . '\\rest_get_diagnostics',
        'permission_callback' => __NAMESPACE__ . '\\rest_diagnostics_permission',
    ]);
}

function rest_diagnostics_permission(\WP_REST_Request $request) {
    // Allow a token header if configured, otherwise require manage_options capability
    $configured = get_option('puntwork_rest_token', '');
    $header = $request->get_header('x-puntwork-token');

    if ( ! empty( $configured ) ) {
        // timing-safe compare
        return hash_equals( (string) $configured, (string) $header );
    }

    return current_user_can('manage_options');
}

function rest_get_diagnostics(\WP_REST_Request $request) {
    try {
        $status = get_import_status();
    } catch (\Throwable $e) {
        $status = [ 'error' => 'failed_to_get_status', 'message' => $e->getMessage() ];
    }

    $diagnostics = get_option('job_import_diagnostics', []);

    // scheduled hooks
    $scheduled = [];
    $scheduled['continue_import'] = wp_next_scheduled('puntwork_continue_import');
    $scheduled['continue_import_retry'] = wp_next_scheduled('puntwork_continue_import_retry');
    $scheduled['continue_import_manual'] = wp_next_scheduled('puntwork_continue_import_manual');

    // Action Scheduler basic counts (if available)
    $as = [ 'available' => false, 'pending' => null, 'running' => null ];
    if ( class_exists('\ActionScheduler\ActionScheduler') ) {
        $as['available'] = true;
        try {
            if ( function_exists('as_get_scheduled_actions') ) {
                $pending = as_get_scheduled_actions([ 'per_page' => 1, 'return_format' => 'count' ]);
                $as['pending'] = $pending;
            }
        } catch (\Throwable $t) {
            $as['error'] = $t->getMessage();
        }
    }

    $response = [
        'import_status' => $status,
        'diagnostics' => $diagnostics,
        'scheduled' => $scheduled,
        'action_scheduler' => $as,
    ];

    return rest_ensure_response( $response );
}
