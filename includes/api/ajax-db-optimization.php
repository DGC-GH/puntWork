<?php

/**
 * AJAX handlers for database optimization.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/*
 * AJAX handlers for database optimization
 * Handles optimization status, async settings, and performance monitoring
 */

// Explicitly load required utility classes for AJAX context
require_once __DIR__ . '/../utilities/SecurityUtils.php';
require_once __DIR__ . '/../utilities/AjaxErrorHandler.php';
require_once __DIR__ . '/../utilities/PuntWorkLogger.php';

/*
 * Get database optimization status
 */
add_action('wp_ajax_get_db_optimization_status', __NAMESPACE__ . '\\ajax_get_db_optimization_status');
function ajax_get_db_optimization_status()
{
    // Simple validation for debugging
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'job_import_nonce')) {
        error_log('[PUNTWORK] [DEBUG-AJAX] Nonce verification failed for get_db_optimization_status');
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    if (!current_user_can('manage_options')) {
        error_log('[PUNTWORK] [DEBUG-AJAX] Insufficient permissions for get_db_optimization_status');
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    try {
        $status = get_database_optimization_status();

        $response = [
            'success' => true,
            'status' => $status,
            'indexes_html' => '',
        ];

        // Build HTML for index status
        $response['indexes_html'] .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px;">';

        $index_names = [
            'idx_postmeta_guid' => 'GUID Index (postmeta)',
            'idx_postmeta_import_hash' => 'Import Hash Index (postmeta)',
            'idx_postmeta_last_update' => 'Last Update Index (postmeta)',
            'idx_posts_job_status' => 'Job Status Index (posts)',
            'idx_postmeta_feed_url' => 'Feed URL Index (postmeta)',
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

        AjaxErrorHandler::sendSuccess($response);
    } catch (\Exception $e) {
        // Check if this is a database permission error
        if (strpos($e->getMessage(), 'Access denied') !== false || strpos($e->getMessage(), 'information_schema') !== false) {
            \Puntwork\PuntWorkLogger::error('Database optimization status error - permission issue: ' . $e->getMessage(), \Puntwork\PuntWorkLogger::CONTEXT_AJAX);
            AjaxErrorHandler::sendError('Database permission error: Unable to check database indexes. The database user may not have permission to access system tables. Please contact your hosting provider to grant information_schema access or check database user permissions.');
        } else {
            \Puntwork\PuntWorkLogger::error('Database optimization status error: ' . $e->getMessage(), \Puntwork\PuntWorkLogger::CONTEXT_AJAX);
            AjaxErrorHandler::sendError('Failed to get database optimization status: ' . $e->getMessage());
        }
    }
}

/*
 * Get performance monitoring status
 */
add_action('wp_ajax_get_performance_status', __NAMESPACE__ . '\\ajax_get_performance_status');
function ajax_get_performance_status()
{
    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('get_performance_status', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        $stats = get_performance_statistics('batch_processing', 30);
        $snapshot = get_performance_snapshot();

        AjaxErrorHandler::sendSuccess(
            [
                'stats' => $stats,
                'snapshot' => $snapshot,
            ]
        );
    } catch (\Exception $e) {
        PuntWorkLogger::error('Get performance status error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::sendError('Failed to get performance status: ' . $e->getMessage());
    }
}

/*
 * Create database indexes
 */
add_action('wp_ajax_create_database_indexes', __NAMESPACE__ . '\\ajax_create_database_indexes');
function ajax_create_database_indexes()
{
    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('create_database_indexes', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        PuntWorkLogger::info('Creating database indexes', PuntWorkLogger::CONTEXT_AJAX);

        // Include the database optimization functions
        require_once __DIR__ . '/../utilities/database-optimization.php';

        $start_time = microtime(true);
        create_database_indexes();
        $duration = microtime(true) - $start_time;

        PuntWorkLogger::info(
            'Database indexes created successfully',
            PuntWorkLogger::CONTEXT_AJAX,
            ['duration' => $duration]
        );

        AjaxErrorHandler::sendSuccess(
            [
                'message' => 'Database indexes created successfully',
                'duration' => round($duration, 2),
            ]
        );
    } catch (\Exception $e) {
        PuntWorkLogger::error('Create database indexes error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::sendError('Failed to create database indexes: ' . $e->getMessage());
    }
}
