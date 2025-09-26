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
    // Check nonce for security
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_admin_nonce')) {
        wp_die('Security check failed');
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }

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

    wp_send_json($response);
}

/**
 * Create database indexes
 */
add_action('wp_ajax_create_database_indexes', __NAMESPACE__ . '\\ajax_create_database_indexes');
function ajax_create_database_indexes() {
    // Check nonce for security
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_admin_nonce')) {
        wp_die('Security check failed');
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }

    try {
        create_database_indexes();

        $status = get_database_optimization_status();

        $response = [
            'success' => true,
            'message' => 'Database indexes created successfully!',
            'status' => $status
        ];

        if (!$status['optimization_complete']) {
            $response['message'] = 'Some indexes may have failed to create. Check database permissions.';
        }

        wp_send_json($response);

    } catch (\Exception $e) {
        wp_send_json([
            'success' => false,
            'message' => 'Failed to create indexes: ' . $e->getMessage()
        ]);
    }
}