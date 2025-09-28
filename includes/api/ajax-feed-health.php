<?php

/**
 * AJAX handlers for Feed Health Monitor
 *
 * @package    Puntwork
 * @subpackage AJAX
 * @since      1.0.11
 */

namespace Puntwork;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for getting feed health status
 */
add_action('wp_ajax_get_feed_health_status', __NAMESPACE__ . '\\get_feed_health_status_ajax');
function get_feed_health_status_ajax()
{
    PuntWorkLogger::logAjaxRequest('get_feed_health_status', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('get_feed_health_status', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);
        return;
    }

    try {
        $health_status = FeedHealthMonitor::get_feed_health_status();

        PuntWorkLogger::logAjaxResponse(
            'get_feed_health_status',
            array(
                'status_count' => count($health_status),
            )
        );

        AjaxErrorHandler::sendSuccess(
            array(
                'health_status' => $health_status,
                'timestamp'     => current_time('timestamp'),
            )
        );
    } catch (\Exception $e) {
        PuntWorkLogger::error('Get feed health status failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_MONITORING);
        AjaxErrorHandler::sendError('Get feed health status failed: ' . $e->getMessage());
    }
}

/**
 * AJAX handler for getting feed health history
 */
add_action('wp_ajax_get_feed_health_history', __NAMESPACE__ . '\\get_feed_health_history_ajax');
function get_feed_health_history_ajax()
{
    PuntWorkLogger::logAjaxRequest('get_feed_health_history', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('get_feed_health_history', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);
        return;
    }

    try {
        // Validate and sanitize input fields
        $feed_key = SecurityUtils::validateField(
            $_POST,
            'feed_key',
            'string',
            array(
                'required'   => true,
                'max_length' => 100,
            )
        );
        $days     = SecurityUtils::validateField(
            $_POST,
            'days',
            'integer',
            array(
                'min'     => 1,
                'max'     => 30,
                'default' => 7,
            )
        );

        $history = FeedHealthMonitor::get_feed_health_history($feed_key, $days);

        PuntWorkLogger::logAjaxResponse(
            'get_feed_health_history',
            array(
                'feed_key'      => $feed_key,
                'days'          => $days,
                'history_count' => count($history),
            )
        );

        AjaxErrorHandler::sendSuccess(
            array(
                'feed_key' => $feed_key,
                'history'  => $history,
                'days'     => $days,
            )
        );
    } catch (\Exception $e) {
        PuntWorkLogger::error('Get feed health history failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_MONITORING);
        AjaxErrorHandler::sendError('Get feed health history failed: ' . $e->getMessage());
    }
}

/**
 * AJAX handler for triggering manual health check
 */
add_action('wp_ajax_trigger_feed_health_check', __NAMESPACE__ . '\\trigger_feed_health_check_ajax');
function trigger_feed_health_check_ajax()
{
    PuntWorkLogger::logAjaxRequest('trigger_feed_health_check', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('trigger_feed_health_check', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);
        return;
    }

    try {
        PuntWorkLogger::info('Manual feed health check triggered', PuntWorkLogger::CONTEXT_MONITORING);

        FeedHealthMonitor::trigger_manual_check();

        PuntWorkLogger::logAjaxResponse(
            'trigger_feed_health_check',
            array(
                'message' => 'Health check completed',
            )
        );

        AjaxErrorHandler::sendSuccess(
            array(
                'message'   => 'Feed health check completed successfully',
                'timestamp' => current_time('timestamp'),
            )
        );
    } catch (\Exception $e) {
        PuntWorkLogger::error('Trigger feed health check failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_MONITORING);
        AjaxErrorHandler::sendError('Trigger feed health check failed: ' . $e->getMessage());
    }
}

/**
 * AJAX handler for updating alert settings
 */
add_action('wp_ajax_update_feed_alert_settings', __NAMESPACE__ . '\\update_feed_alert_settings_ajax');
function update_feed_alert_settings_ajax()
{
    PuntWorkLogger::logAjaxRequest('update_feed_alert_settings', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('update_feed_alert_settings', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);
        return;
    }

    try {
        // Validate and sanitize alert settings
        $email_enabled    = SecurityUtils::validateField($_POST, 'email_enabled', 'boolean', array( 'default' => false ));
        $email_recipients = SecurityUtils::validateField(
            $_POST,
            'email_recipients',
            'string',
            array(
                'default'    => get_option('admin_email'),
                'max_length' => 500,
            )
        );

        // Validate alert types
        $alert_types       = array();
        $valid_alert_types = array(
            FeedHealthMonitor::ALERT_FEED_DOWN,
            FeedHealthMonitor::ALERT_FEED_SLOW,
            FeedHealthMonitor::ALERT_FEED_EMPTY,
            FeedHealthMonitor::ALERT_FEED_CHANGED,
        );

        foreach ($valid_alert_types as $alert_type) {
            $alert_types[ $alert_type ] = SecurityUtils::validateField($_POST, 'alert_types[' . $alert_type . ']', 'boolean', array( 'default' => false ));
        }

        $alert_settings = array(
            'email_enabled'    => $email_enabled,
            'email_recipients' => $email_recipients,
            'alert_types'      => $alert_types,
        );

        update_option('puntwork_feed_alerts', $alert_settings);

        PuntWorkLogger::info(
            'Feed alert settings updated',
            PuntWorkLogger::CONTEXT_MONITORING,
            array(
                'email_enabled'    => $email_enabled,
                'recipients_count' => count(explode(',', $email_recipients)),
            )
        );

        PuntWorkLogger::logAjaxResponse(
            'update_feed_alert_settings',
            array(
                'message' => 'Alert settings updated',
            )
        );

        AjaxErrorHandler::sendSuccess(
            array(
                'message'  => 'Feed alert settings updated successfully',
                'settings' => $alert_settings,
            )
        );
    } catch (\Exception $e) {
        PuntWorkLogger::error('Update feed alert settings failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_MONITORING);
        AjaxErrorHandler::sendError('Update feed alert settings failed: ' . $e->getMessage());
    }
}
