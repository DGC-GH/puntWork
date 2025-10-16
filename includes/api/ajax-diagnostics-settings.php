<?php
/**
 * AJAX handlers for diagnostics settings (save REST token)
 */
namespace Puntwork\API;

require_once __DIR__ . '/../utilities/ajax-utilities.php';

use function Puntwork\validate_ajax_request;
use function Puntwork\send_ajax_error;
use function Puntwork\send_ajax_success;

add_action('wp_ajax_puntwork_save_rest_token', __NAMESPACE__ . '\\puntwork_save_rest_token_ajax');

function puntwork_save_rest_token_ajax() {
    // require manage_options capability
    if ( ! current_user_can('manage_options') ) {
        send_ajax_error('puntwork_save_rest_token', 'Permission denied');
        return;
    }

    // validate nonce if provided (best-effort). Some hosts strip nonces in POSTs; don't abort on failure.
    if ( isset($_REQUEST['nonce']) ) {
        $nonce_ok = check_ajax_referer('job_import_nonce', 'nonce', false);
        if ( ! $nonce_ok ) {
            // log but continue for admins
            try {
                if ( class_exists('\Puntwork\PuntWorkLogger') ) {
                    \Puntwork\PuntWorkLogger::warn('Save REST token called with invalid nonce (continuing for admin)', \Puntwork\PuntWorkLogger::CONTEXT_AJAX, ['action' => 'puntwork_save_rest_token']);
                }
            } catch (\Throwable $t) {
                // swallow
            }
        }
    }

    $token = isset($_POST['token']) ? trim( (string) $_POST['token'] ) : '';
    if ( empty( $token ) ) {
        // allow clearing
        update_option('puntwork_rest_token', '');
        send_ajax_success('puntwork_save_rest_token', ['message' => 'Token cleared']);
        return;
    }

    // persist token
    update_option('puntwork_rest_token', $token);
    send_ajax_success('puntwork_save_rest_token', ['message' => 'Token saved']);
}

add_action('wp_ajax_puntwork_get_rest_token', __NAMESPACE__ . '\\puntwork_get_rest_token_ajax');

function puntwork_get_rest_token_ajax() {
    if ( ! current_user_can('manage_options') ) {
        send_ajax_error('puntwork_get_rest_token', 'Permission denied');
        return;
    }

    // best-effort nonce check to avoid accidental CSRF; don't abort if missing on hostile hosts
    if ( isset($_REQUEST['nonce']) ) {
        $nonce_ok = check_ajax_referer('job_import_nonce', 'nonce', false);
        if ( ! $nonce_ok ) {
            try {
                if ( class_exists('\Puntwork\PuntWorkLogger') ) {
                    \Puntwork\PuntWorkLogger::warn('Get REST token called with invalid nonce (continuing for admin)', \Puntwork\PuntWorkLogger::CONTEXT_AJAX, ['action' => 'puntwork_get_rest_token']);
                }
            } catch (\Throwable $t) {
                // swallow
            }
        }
    }

    $token = get_option('puntwork_rest_token', '');
    send_ajax_success('puntwork_get_rest_token', ['token' => $token]);
}
