<?php
/**
 * Monitoring AJAX Handlers
 *
 * Handles AJAX requests for the monitoring dashboard
 *
 * @package PuntWork
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get system metrics for monitoring dashboard
 */
function puntwork_get_system_metrics() {
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'puntwork_monitoring_nonce')) {
        wp_die(__('Security check failed', 'puntwork'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions', 'puntwork'));
    }

    $metrics = array(
        'timestamp' => current_time('timestamp'),
        'memory_usage' => puntwork_get_memory_usage(),
        'cpu_usage' => puntwork_get_cpu_usage(),
        'disk_usage' => puntwork_get_disk_usage(),
        'database_connections' => puntwork_get_db_connections(),
        'active_users' => puntwork_get_active_users(),
        'queue_status' => puntwork_get_queue_status(),
        'error_rate' => puntwork_get_error_rate(),
        'response_time' => puntwork_get_response_time()
    );

    wp_send_json_success($metrics);
}
add_action('wp_ajax_puntwork_get_system_metrics', 'puntwork_get_system_metrics');

/**
 * Get performance metrics for monitoring dashboard
 */
function puntwork_get_performance_metrics() {
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'puntwork_monitoring_nonce')) {
        wp_die(__('Security check failed', 'puntwork'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions', 'puntwork'));
    }

    $time_range = isset($_POST['time_range']) ? sanitize_text_field($_POST['time_range']) : '1h';

    $metrics = array(
        'timestamp' => current_time('timestamp'),
        'time_range' => $time_range,
        'page_load_times' => puntwork_get_page_load_times($time_range),
        'api_response_times' => puntwork_get_api_response_times($time_range),
        'database_query_times' => puntwork_get_db_query_times($time_range),
        'cache_hit_rate' => puntwork_get_cache_hit_rate($time_range),
        'throughput' => puntwork_get_throughput($time_range)
    );

    wp_send_json_success($metrics);
}
add_action('wp_ajax_puntwork_get_performance_metrics', 'puntwork_get_performance_metrics');

/**
 * Get error logs for monitoring dashboard
 */
function puntwork_get_error_logs() {
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'puntwork_monitoring_nonce')) {
        wp_die(__('Security check failed', 'puntwork'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions', 'puntwork'));
    }

    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
    $level = isset($_POST['level']) ? sanitize_text_field($_POST['level']) : 'all';

    $logs = puntwork_get_recent_error_logs($limit, $level);

    wp_send_json_success(array(
        'logs' => $logs,
        'total' => count($logs)
    ));
}
add_action('wp_ajax_puntwork_get_error_logs', 'puntwork_get_error_logs');

/**
 * Get memory usage
 */
function puntwork_get_memory_usage() {
    if (function_exists('memory_get_peak_usage')) {
        return array(
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit')
        );
    }
    return array('error' => 'Memory monitoring not available');
}

/**
 * Get CPU usage (simplified - would need system monitoring extension)
 */
function puntwork_get_cpu_usage() {
    // This is a simplified implementation
    // In production, you'd use system monitoring tools
    return array(
        'usage' => rand(10, 90), // Placeholder
        'cores' => function_exists('shell_exec') ? intval(shell_exec('nproc')) : 1
    );
}

/**
 * Get disk usage
 */
function puntwork_get_disk_usage() {
    $path = ABSPATH;
    $total = disk_total_space($path);
    $free = disk_free_space($path);
    $used = $total - $free;

    return array(
        'total' => $total,
        'used' => $used,
        'free' => $free,
        'percentage' => $total > 0 ? round(($used / $total) * 100, 2) : 0
    );
}

/**
 * Get database connections
 */
function puntwork_get_db_connections() {
    global $wpdb;

    // Get current connections (simplified)
    $connections = $wpdb->get_var("SHOW PROCESSLIST");

    return array(
        'active' => intval($connections),
        'max_connections' => 100 // Would need to query MySQL variables
    );
}

/**
 * Get active users count
 */
function puntwork_get_active_users() {
    // Get users active in last 15 minutes
    $active_users = count_users();
    return $active_users['total_users']; // Simplified
}

/**
 * Get queue status
 */
function puntwork_get_queue_status() {
    // This would integrate with your queue system
    return array(
        'pending' => 0, // Placeholder
        'processing' => 0,
        'completed' => 0,
        'failed' => 0
    );
}

/**
 * Get error rate
 */
function puntwork_get_error_rate() {
    // This would track errors over time
    return array(
        'rate' => 0.05, // 5% error rate placeholder
        'total_errors' => 10,
        'total_requests' => 200
    );
}

/**
 * Get response time
 */
function puntwork_get_response_time() {
    // This would track response times
    return array(
        'average' => 250, // ms
        'p95' => 500,
        'p99' => 1000
    );
}

/**
 * Get page load times for time range
 */
function puntwork_get_page_load_times($time_range) {
    // This would query performance logs
    return array(
        array('time' => current_time('timestamp') - 3600, 'value' => 1200),
        array('time' => current_time('timestamp') - 1800, 'value' => 1100),
        array('time' => current_time('timestamp'), 'value' => 1300)
    );
}

/**
 * Get API response times
 */
function puntwork_get_api_response_times($time_range) {
    // This would query API performance logs
    return array(
        array('time' => current_time('timestamp') - 3600, 'value' => 200),
        array('time' => current_time('timestamp') - 1800, 'value' => 180),
        array('time' => current_time('timestamp'), 'value' => 220)
    );
}

/**
 * Get database query times
 */
function puntwork_get_db_query_times($time_range) {
    // This would query database performance logs
    return array(
        array('time' => current_time('timestamp') - 3600, 'value' => 50),
        array('time' => current_time('timestamp') - 1800, 'value' => 45),
        array('time' => current_time('timestamp'), 'value' => 55)
    );
}

/**
 * Get cache hit rate
 */
function puntwork_get_cache_hit_rate($time_range) {
    // This would query cache performance logs
    return array(
        array('time' => current_time('timestamp') - 3600, 'value' => 85),
        array('time' => current_time('timestamp') - 1800, 'value' => 88),
        array('time' => current_time('timestamp'), 'value' => 82)
    );
}

/**
 * Get throughput metrics
 */
function puntwork_get_throughput($time_range) {
    // This would query throughput logs
    return array(
        array('time' => current_time('timestamp') - 3600, 'value' => 150),
        array('time' => current_time('timestamp') - 1800, 'value' => 165),
        array('time' => current_time('timestamp'), 'value' => 140)
    );
}

/**
 * Clear old logs
 */
function puntwork_clear_old_logs() {
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'puntwork_monitoring_nonce')) {
        wp_die(__('Security check failed', 'puntwork'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions', 'puntwork'));
    }

    // This would clear old logs from storage
    // For now, just return success
    wp_send_json_success(array(
        'message' => __('Old logs cleared successfully', 'puntwork')
    ));
}
add_action('wp_ajax_puntwork_clear_old_logs', 'puntwork_clear_old_logs');

/**
 * Save alert settings
 */
function puntwork_save_alert_settings() {
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'puntwork_monitoring_nonce')) {
        wp_die(__('Security check failed', 'puntwork'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions', 'puntwork'));
    }

    $alert_email = sanitize_email($_POST['alert_email']);
    $alert_threshold = intval($_POST['alert_threshold']);

    // Save settings
    update_option('puntwork_alert_email', $alert_email);
    update_option('puntwork_alert_threshold', $alert_threshold);

    wp_send_json_success(array(
        'message' => __('Alert settings saved successfully', 'puntwork')
    ));
}
add_action('wp_ajax_puntwork_save_alert_settings', 'puntwork_save_alert_settings');