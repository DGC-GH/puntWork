<?php
/**
 * AJAX handlers for database optimization
 *
 * @package    Puntwork
 * @subpackage AJAX
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get database optimization status
 */
add_action('wp_ajax_get_db_optimization_status', __NAMESPACE__ . '\\ajax_get_db_optimization_status');
function ajax_get_db_optimization_status() {
    // Use comprehensive security validation
    $validation = SecurityUtils::validate_ajax_request('get_db_optimization_status');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::send_error($validation);
        return;
    }

    try {
        $status = get_database_optimization_status();

        $response = [
            'success' => true,
            'status' => $status,
            'indexes_html' => ''
        ];

        // Build HTML for index status
        $response['indexes_html'] .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px;">';

        $index_names = [
            'idx_postmeta_guid' => 'GUID Index (postmeta)',
            'idx_postmeta_import_hash' => 'Import Hash Index (postmeta)',
            'idx_postmeta_last_update' => 'Last Update Index (postmeta)',
            'idx_posts_job_status' => 'Job Status Index (posts)',
            'idx_postmeta_feed_url' => 'Feed URL Index (postmeta)'
        ];

        foreach ($index_names as $index => $name) {
            $exists = $status['missing_indexes'] ? !in_array($index, $status['missing_indexes']) : true;
            $color = $exists ? '#34c759' : '#ff3b30';
            $icon = $exists ? 'check-circle' : 'times-circle';
            $status_text = $exists ? 'Created' : 'Missing';

            $response['indexes_html'] .= '<div style="display: flex; align-items: center; padding: 6px 0;">';
            $response['indexes_html'] .= '<i class="fas fa-' . $icon . '" style="color: ' . $color . '; margin-right: 8px; font-size: 12px;"></i>';
            $response['indexes_html'] .= '<span style="font-size: 12px;">' . $name . '</span>';
            $response['indexes_html'] .= '</div>';
        }

        $response['indexes_html'] .= '</div>';

        // Set badge status
        if ($status['optimization_complete']) {
            $response['badge_class'] = 'success';
            $response['badge_text'] = 'Optimized';
        } elseif ($status['indexes_created'] > 0) {
            $response['badge_class'] = 'warning';
            $response['badge_text'] = 'Partial';
        } else {
            $response['badge_class'] = 'error';
            $response['badge_text'] = 'Not Optimized';
        }

        AjaxErrorHandler::send_success($response);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Database optimization status error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::send_error('Failed to get database optimization status: ' . $e->getMessage());
    }
}

/**
 * Save async processing settings
 */
add_action('wp_ajax_save_async_settings', __NAMESPACE__ . '\\ajax_save_async_settings');
function ajax_save_async_settings() {
    // Use comprehensive security validation with field validation
    $validation = SecurityUtils::validate_ajax_request(
        'save_async_settings',
        'puntwork_admin_nonce',
        ['enabled'], // required fields
        [
            'enabled' => ['type' => 'bool'] // validation rules
        ]
    );

    if (is_wp_error($validation)) {
        AjaxErrorHandler::send_error($validation);
        return;
    }

    try {
        $enabled = $_POST['enabled'];

        update_option('puntwork_async_enabled', $enabled);

        $status = get_async_processing_status();

        AjaxErrorHandler::send_success($status, ['message' => 'Async settings saved successfully']);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Save async settings error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::send_error('Failed to save async settings: ' . $e->getMessage());
    }
}

/**
 * Get async processing status
 */
add_action('wp_ajax_get_async_status', __NAMESPACE__ . '\\ajax_get_async_status');
function ajax_get_async_status() {
    // Use comprehensive security validation
    $validation = SecurityUtils::validate_ajax_request('get_async_status');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::send_error($validation);
        return;
    }

    try {
        $status = get_async_processing_status();
        AjaxErrorHandler::send_success($status);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Get async status error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::send_error('Failed to get async status: ' . $e->getMessage());
    }
}

/**
 * Get performance monitoring status
 */
add_action('wp_ajax_get_performance_status', __NAMESPACE__ . '\\ajax_get_performance_status');
function ajax_get_performance_status() {
    // Use comprehensive security validation
    $validation = SecurityUtils::validate_ajax_request('get_performance_status');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::send_error($validation);
        return;
    }

    try {
        $stats = get_performance_statistics('batch_processing', 30);
        $snapshot = get_performance_snapshot();

        AjaxErrorHandler::send_success([
            'stats' => $stats,
            'snapshot' => $snapshot
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Get performance status error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::send_error('Failed to get performance status: ' . $e->getMessage());
    }
}

/**
 * Clear old performance logs
 */
add_action('wp_ajax_clear_performance_logs', __NAMESPACE__ . '\\ajax_clear_performance_logs');
function ajax_clear_performance_logs() {
    // Use comprehensive security validation
    $validation = SecurityUtils::validate_ajax_request('clear_performance_logs');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::send_error($validation);
        return;
    }

    try {
        // Import the cleanup function
        if (function_exists(__NAMESPACE__ . '\\PerformanceMonitor::cleanup_old_logs')) {
            \Puntwork\PerformanceMonitor::cleanup_old_logs(30); // Keep 30 days
            $message = 'Performance logs older than 30 days have been cleared.';
        } else {
            $message = 'Performance monitoring not available.';
        }

        AjaxErrorHandler::send_success(null, ['message' => $message]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Clear performance logs error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::send_error('Failed to clear performance logs: ' . $e->getMessage());
    }
}