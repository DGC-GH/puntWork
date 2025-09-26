<?php
/**
 * Queue AJAX Handlers for puntWork
 * Server-side handlers for queue management operations
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get queue statistics via AJAX
 */
function get_queue_stats_ajax() {
    // Verify nonce and permissions
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_queue_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    try {
        global $wpdb;
        $table_name = $wpdb->prefix . 'puntwork_queue';

        $stats = $wpdb->get_row("
            SELECT
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
                COUNT(*) as total
            FROM $table_name
        ", ARRAY_A);

        wp_send_json_success($stats ?: ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'total' => 0]);

    } catch (\Exception $e) {
        wp_send_json_error(['message' => 'Failed to get queue stats: ' . $e->getMessage()]);
    }
}

/**
 * Get recent jobs via AJAX
 */
function get_recent_jobs_ajax() {
    // Verify nonce and permissions
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_queue_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    try {
        global $wpdb;
        $table_name = $wpdb->prefix . 'puntwork_queue';

        $jobs = $wpdb->get_results("
            SELECT id, job_type, status, attempts, max_attempts, created_at, updated_at
            FROM $table_name
            ORDER BY updated_at DESC
            LIMIT 20
        ", ARRAY_A);

        wp_send_json_success($jobs ?: []);

    } catch (\Exception $e) {
        wp_send_json_error(['message' => 'Failed to get recent jobs: ' . $e->getMessage()]);
    }
}

/**
 * Clear completed jobs via AJAX
 */
function clear_completed_jobs_ajax() {
    // Verify nonce and permissions
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_queue_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    try {
        global $wpdb;
        $table_name = $wpdb->prefix . 'puntwork_queue';

        $deleted = $wpdb->delete($table_name, ['status' => 'completed'], ['%s']);

        wp_send_json_success([
            'message' => "Cleared $deleted completed jobs",
            'deleted' => $deleted
        ]);

    } catch (\Exception $e) {
        wp_send_json_error(['message' => 'Failed to clear completed jobs: ' . $e->getMessage()]);
    }
}

/**
 * Add test job via AJAX
 */
function add_test_job_ajax() {
    // Verify nonce and permissions
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_queue_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    try {
        // Load queue manager
        require_once __DIR__ . '/queue-manager.php';

        // Add a test cleanup job
        $queue_manager = new PuntworkQueueManager();
        $job_id = $queue_manager->add_job('cleanup', [
            'type' => 'general',
            'test' => true,
            'timestamp' => time()
        ], 1); // High priority

        wp_send_json_success([
            'message' => 'Test job added successfully',
            'job_id' => $job_id
        ]);

    } catch (\Exception $e) {
        wp_send_json_error(['message' => 'Failed to add test job: ' . $e->getMessage()]);
    }
}

/**
 * Manual queue processing trigger
 */
function manual_process_queue_ajax() {
    // Verify nonce and permissions
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_queue_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    try {
        // Load queue manager
        require_once __DIR__ . '/queue-manager.php';

        $queue_manager = new PuntworkQueueManager();
        $queue_manager->process_queue();

        wp_send_json_success(['message' => 'Queue processed manually']);

    } catch (\Exception $e) {
        wp_send_json_error(['message' => 'Failed to process queue: ' . $e->getMessage()]);
    }
}

/**
 * Get queue configuration
 */
function get_queue_config() {
    return [
        'max_retries' => 3,
        'batch_size' => 10,
        'cron_interval' => 30, // seconds
        'max_execution_time' => 120, // seconds
        'table_name' => 'puntwork_queue'
    ];
}

/**
 * Clean up old queue entries
 */
function cleanup_old_queue_entries() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'puntwork_queue';

    // Delete completed jobs older than 7 days
    $wpdb->query($wpdb->prepare("
        DELETE FROM $table_name
        WHERE status = 'completed'
        AND completed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
    "));

    // Delete failed jobs older than 30 days
    $wpdb->query($wpdb->prepare("
        DELETE FROM $table_name
        WHERE status = 'failed'
        AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    "));
}

/**
 * Register AJAX handlers
 */
add_action('wp_ajax_puntwork_get_queue_stats', __NAMESPACE__ . '\\get_queue_stats_ajax');
add_action('wp_ajax_puntwork_get_recent_jobs', __NAMESPACE__ . '\\get_recent_jobs_ajax');
add_action('wp_ajax_puntwork_clear_completed_jobs', __NAMESPACE__ . '\\clear_completed_jobs_ajax');
add_action('wp_ajax_puntwork_add_test_job', __NAMESPACE__ . '\\add_test_job_ajax');
add_action('wp_ajax_puntwork_process_queue', __NAMESPACE__ . '\\manual_process_queue_ajax');

/**
 * Schedule daily cleanup
 */
if (!wp_next_scheduled('puntwork_queue_cleanup')) {
    wp_schedule_event(time(), 'daily', 'puntwork_queue_cleanup');
}

add_action('puntwork_queue_cleanup', __NAMESPACE__ . '\\cleanup_old_queue_entries');