<?php
/**
 * AJAX Utilities for PuntWork Plugin
 *
 * Centralized functions for AJAX request handling, validation, and response formatting.
 */

namespace Puntwork;

/**
 * Validates AJAX request security and permissions
 *
 * @param string $action_name The name of the AJAX action for logging
 * @return bool True if validation passes, false if it fails (response sent)
 */
function validate_ajax_request($action_name) {
    PuntWorkLogger::logAjaxRequest($action_name, $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for ' . $action_name, PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
        return false;
    }

    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for ' . $action_name, PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
        return false;
    }

    return true;
}

/**
 * Logs successful AJAX response and sends success
 *
 * @param string $action_name The name of the AJAX action
 * @param mixed $response_data The response data to send
 * @param mixed $log_data Optional log data (defaults to response_data)
 * @param bool $success Whether the operation was successful
 */
function send_ajax_success($action_name, $response_data, $log_data = null, $success = true) {
    if ($log_data === null) {
        $log_data = $response_data;
    }
    PuntWorkLogger::logAjaxResponse($action_name, $log_data, $success);
    wp_send_json_success($response_data);
}

/**
 * Sends standardized error response
 *
 * @param string $action_name The name of the AJAX action
 * @param string $message The error message
 * @param array $additional_data Additional data to include in response
 */
function send_ajax_error($action_name, $message, $additional_data = []) {
    $log_data = array_merge(['message' => $message], $additional_data);
    PuntWorkLogger::logAjaxResponse($action_name, $log_data, false);
    wp_send_json_error($log_data);
}