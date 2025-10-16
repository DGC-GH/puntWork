<?php
/**
 * Import Performance Monitoring & Health System
 *
 * @package    Puntwork
 * @subpackage Import
 * @since      1.1.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Real-time performance monitoring and health checks for import system
 */

/**
 * Initialize monitoring for an import session
 */
function initialize_import_monitoring($import_id = null) {
    $import_id = $import_id ?: uniqid('import_', true);
    $monitoring_data = [
        'import_id' => $import_id,
        'start_time' => microtime(true),
        'metrics' => [
            'items_processed' => 0,
            'items_published' => 0,
            'items_updated' => 0,
            'items_skipped' => 0,
            'time_per_item' => [],
            'memory_peaks' => [],
            'performance_alerts' => [],
            'errors' => [],
            'circuit_breaker_events' => []
        ],
        'health_status' => 'healthy',
        'performance_baseline' => get_performance_baseline(),
        'last_health_check' => microtime(true)
    ];

    // Store monitoring data
    set_transient("puntwork_import_monitoring_{$import_id}", $monitoring_data, 3600); // 1 hour expiry

    PuntWorkLogger::info('Import monitoring initialized', PuntWorkLogger::CONTEXT_IMPORT, [
        'import_id' => $import_id,
        'baseline_performance' => $monitoring_data['performance_baseline']
    ]);

    return $import_id;
}

/**
 * Update monitoring metrics during import
 */
function update_import_metrics($import_id, $new_metrics) {
    $monitoring_key = "puntwork_import_monitoring_{$import_id}";
    $data = get_transient($monitoring_key);

    if (!$data) {
        PuntWorkLogger::warn('Import monitoring data not found, reinitializing', PuntWorkLogger::CONTEXT_IMPORT, [
            'import_id' => $import_id
        ]);
        $import_id = initialize_import_monitoring($import_id);
        $data = get_transient("puntwork_import_monitoring_{$import_id}");
    }

    // Update metrics
    foreach ($new_metrics as $key => $value) {
        if ($key === 'time_per_item' || $key === 'memory_peaks' || $key === 'performance_alerts' || $key === 'errors' || $key === 'circuit_breaker_events') {
            if (!isset($data['metrics'][$key])) {
                $data['metrics'][$key] = [];
            }

            if (is_array($value)) {
                $data['metrics'][$key] = array_merge($data['metrics'][$key], $value);
            } else {
                $data['metrics'][$key][] = $value;
            }
        } else {
            $data['metrics'][$key] = $value;
        }
    }

    // Health status assessment
    $data['health_status'] = assess_import_health($data);
    $data['last_health_check'] = microtime(true);

    set_transient($monitoring_key, $data, 3600);

    // Alert on critical issues
    if ($data['health_status'] === 'critical') {
        send_import_health_alert($data, 'critical');
    }

    return $data;
}

/**
 * Assess import health based on metrics
 */
function assess_import_health($monitoring_data) {
    $metrics = $monitoring_data['metrics'];
    $baseline = $monitoring_data['performance_baseline'];
    $health_score = 100;

    // Check error rate
    $total_processed = $metrics['items_processed'] ?: 1;
    $error_rate = count($metrics['errors']) / $total_processed;
    if ($error_rate > 0.1) { // 10% error rate
        $health_score -= 50;
    } elseif ($error_rate > 0.05) { // 5% error rate
        $health_score -= 25;
    }

    // Check performance degradation
    $current_avg_time = empty($metrics['time_per_item']) ? 0 :
                       array_sum($metrics['time_per_item']) / count($metrics['time_per_item']);
    if ($current_avg_time > 0 && $baseline['avg_time_per_item'] > 0) {
        $degradation_ratio = $current_avg_time / $baseline['avg_time_per_item'];
        if ($degradation_ratio > 5.0) {
            $health_score -= 40;
        } elseif ($degradation_ratio > 2.0) {
            $health_score -= 20;
        }
    }

    // Check memory pressure
    $memory_peaks = $metrics['memory_peaks'];
    if (!empty($memory_peaks)) {
        $max_memory_peak = max($memory_peaks);
        $memory_limit = get_memory_limit_bytes();
        $memory_ratio = $max_memory_peak / $memory_limit;

        if ($memory_ratio > 0.95) {
            $health_score -= 50;
        } elseif ($memory_ratio > 0.85) {
            $health_score -= 30;
        }
    }

    // Check circuit breaker events
    $circuit_breaker_events = count($metrics['circuit_breaker_events']);
    if ($circuit_breaker_events > 0) {
        $health_score -= ($circuit_breaker_events * 10);
    }

    // Determine health status
    if ($health_score <= 20) {
        return 'critical';
    } elseif ($health_score <= 50) {
        return 'poor';
    } elseif ($health_score <= 75) {
        return 'fair';
    } else {
        return 'healthy';
    }
}

/**
 * Send health alert for critical issues
 */
function send_import_health_alert($monitoring_data, $severity) {
    $alert_data = [
        'severity' => $severity,
        'import_id' => $monitoring_data['import_id'],
        'health_status' => $monitoring_data['health_status'],
        'metrics' => $monitoring_data['metrics'],
        'timestamp' => microtime(true),
        'server_info' => [
            'memory_usage' => memory_get_usage(true) / 1024 / 1024,
            'cpu_load' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
            'php_version' => phpversion()
        ]
    ];

    PuntWorkLogger::error('Import health alert triggered', PuntWorkLogger::CONTEXT_IMPORT, $alert_data);

    // Send admin notification
    send_admin_health_notification($alert_data);

    // Store alert for later analysis
    store_health_alert($alert_data);
}

/**
 * Get performance baseline from historical data
 */
function get_performance_baseline() {
    // Get recent successful imports
    $recent_imports = get_option('puntwork_import_history', []);
    if (empty($recent_imports)) {
        return [
            'avg_time_per_item' => 1.0,
            'avg_memory_usage' => get_memory_limit_bytes() * 0.5,
            'success_rate' => 1.0
        ];
    }

    // Calculate baseline from last 10 successful imports
    $successful_imports = array_filter($recent_imports, function($import) {
        return isset($import['success']) && $import['success'] &&
               isset($import['processing_time_per_item']) && $import['processing_time_per_item'] > 0;
    });

    $recent_successful = array_slice(array_reverse($successful_imports), 0, 10);

    if (empty($recent_successful)) {
        return [
            'avg_time_per_item' => 1.0,
            'avg_memory_usage' => get_memory_limit_bytes() * 0.5,
            'success_rate' => 1.0
        ];
    }

    $avg_time_per_item = array_sum(array_column($recent_successful, 'processing_time_per_item')) / count($recent_successful);
    $avg_memory = array_sum(array_column($recent_successful, 'peak_memory_mb', get_memory_limit_bytes() * 0.5 * 1024 * 1024)) / count($recent_successful) / (1024 * 1024);

    return [
        'avg_time_per_item' => $avg_time_per_item,
        'avg_memory_usage' => $avg_memory * 1024 * 1024, // Convert back to bytes
        'success_rate' => count($recent_successful) / max(count($recent_imports), 1),
        'sample_size' => count($recent_successful)
    ];
}

/**
 * Get real-time import status including health metrics
 */
function get_import_status_with_monitoring() {
    $status = get_import_status([]);

    // Add monitoring data if available
    $monitoring_data = get_current_import_monitoring();
    if ($monitoring_data) {
        $status['monitoring'] = [
            'health_status' => $monitoring_data['health_status'],
            'current_metrics' => $monitoring_data['metrics'],
            'performance_baseline' => $monitoring_data['performance_baseline'],
            'last_health_check' => $monitoring_data['last_health_check']
        ];
    }

    return $status;
}

/**
 * Get monitoring data for current import
 */
function get_current_import_monitoring() {
    global $wpdb;

    // Find most recent import monitoring data
    $monitoring_keys = $wpdb->get_col("
        SELECT option_name
        FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_puntwork_import_monitoring_%'
        ORDER BY option_id DESC
        LIMIT 1
    ");

    if (!empty($monitoring_keys)) {
        $key = str_replace('_transient_', '', $monitoring_keys[0]);
        return get_transient($key);
    }

    return null;
}

/**
 * Store import performance data for analytics
 */
function store_import_performance_data($import_result) {
    $performance_data = [
        'timestamp' => time(),
        'duration' => $import_result['time_elapsed'] ?? 0,
        'items_processed' => $import_result['processed'] ?? 0,
        'processing_time_per_item' => 0,
        'success' => $import_result['success'] ?? false,
        'error_message' => $import_result['message'] ?? '',
        'peak_memory_mb' => memory_get_peak_usage(true) / (1024 * 1024),
        'streaming_metrics' => $import_result['streaming_metrics'] ?? []
    ];

    if ($performance_data['items_processed'] > 0) {
        $performance_data['processing_time_per_item'] = $performance_data['duration'] / $performance_data['items_processed'];
    }

    // Store in history (keep last 50)
    $history = get_option('puntwork_import_history', []);
    array_unshift($history, $performance_data); // Add to beginning
    $history = array_slice($history, 0, 50); // Keep only last 50

    update_option('puntwork_import_history', $history);

    PuntWorkLogger::info('Import performance data stored', PuntWorkLogger::CONTEXT_IMPORT, [
        'duration' => $performance_data['duration'],
        'items_processed' => $performance_data['items_processed'],
        'time_per_item' => $performance_data['processing_time_per_item'],
        'success' => $performance_data['success']
    ]);
}

/**
 * Get import performance analytics
 */
function get_import_performance_analytics($days = 30) {
    $history = get_option('puntwork_import_history', []);
    $cutoff = strtotime("-{$days} days");

    // Filter by date and successful imports
    $relevant_imports = array_filter($history, function($import) use ($cutoff) {
        return $import['timestamp'] >= $cutoff && isset($import['success']) && $import['success'];
    });

    if (empty($relevant_imports)) {
        return [
            'period_days' => $days,
            'total_imports' => 0,
            'avg_time_per_item' => 0,
            'avg_duration' => 0,
            'success_rate' => 0,
            'performance_trend' => 'insufficient_data'
        ];
    }

    $durations = array_column($relevant_imports, 'duration');
    $times_per_item = array_column($relevant_imports, 'processing_time_per_item');
    $success_count = count($relevant_imports);

    // Calculate trends
    $recent = array_slice($relevant_imports, 0, min(5, count($relevant_imports)));
    $recent_avg = empty($recent) ? 0 : array_sum(array_column($recent, 'processing_time_per_item')) / count($recent);

    $older = array_slice($relevant_imports, 5);
    $older_avg = empty($older) ? 0 : array_sum(array_column($older, 'processing_time_per_item')) / count($older);

    $trend = 'stable';
    if ($recent_avg > 0 && $older_avg > 0) {
        $ratio = $recent_avg / $older_avg;
        if ($ratio > 1.2) {
            $trend = 'degrading';
        } elseif ($ratio < 0.8) {
            $trend = 'improving';
        }
    }

    return [
        'period_days' => $days,
        'total_imports' => $success_count,
        'avg_time_per_item' => array_sum($times_per_item) / count($times_per_item),
        'avg_duration' => array_sum($durations) / count($durations),
        'success_rate' => 1.0, // Only successful imports in dataset
        'performance_trend' => $trend,
        'memory_usage_mb' => array_sum(array_column($relevant_imports, 'peak_memory_mb', 0)) / count($relevant_imports)
    ];
}

/**
 * Send admin notification for health issues
 */
function send_admin_health_notification($alert_data) {
    $subject = sprintf('[Puntwork Import Alert] %s - Import Health: %s',
                     $alert_data['severity'], $alert_data['health_status']);

    $message = "Import Health Alert\n\n";
    $message .= "Severity: {$alert_data['severity']}\n";
    $message .= "Health Status: {$alert_data['health_status']}\n";
    $message .= "Import ID: {$alert_data['import_id']}\n";
    $message .= "Timestamp: " . date('Y-m-d H:i:s', $alert_data['timestamp']) . "\n\n";

    $message .= "Metrics:\n";
    foreach ($alert_data['metrics'] as $key => $value) {
        if (is_array($value)) {
            $message .= "  {$key}: " . json_encode($value) . "\n";
        } else {
            $message .= "  {$key}: {$value}\n";
        }
    }

    $message .= "\nServer Info:\n";
    foreach ($alert_data['server_info'] as $key => $value) {
        $message .= "  {$key}: " . (is_array($value) ? json_encode($value) : $value) . "\n";
    }

    // Send email to admin
    $admin_email = get_option('admin_email');
    wp_mail($admin_email, $subject, $message);
}

/**
 * Store health alert for analysis
 */
function store_health_alert($alert_data) {
    $alerts = get_option('puntwork_import_health_alerts', []);
    array_unshift($alerts, $alert_data);
    $alerts = array_slice($alerts, 0, 100); // Keep last 100 alerts

    update_option('puntwork_import_health_alerts', $alerts);
}

/**
 * Check for import health issues across all recent imports
 */
function check_system_health_status() {
    $recent_alerts = get_option('puntwork_import_health_alerts', []);
    $critical_alerts = array_filter($recent_alerts, function($alert) {
        return $alert['severity'] === 'critical';
    });

    $analytics = get_import_performance_analytics(7); // Last 7 days

    $system_health = [
        'overall_status' => 'healthy',
        'critical_alerts_24h' => count(array_filter($critical_alerts, function($alert) {
            return $alert['timestamp'] > (time() - 86400);
        })),
        'performance_trend' => $analytics['performance_trend'],
        'avg_import_time' => $analytics['avg_time_per_item'],
        'success_rate' => $analytics['success_rate'],
        'recommendations' => []
    ];

    // Assess overall health
    if ($system_health['critical_alerts_24h'] > 2 || $analytics['performance_trend'] === 'degrading') {
        $system_health['overall_status'] = 'critical';
    } elseif ($system_health['critical_alerts_24h'] > 0) {
        $system_health['overall_status'] = 'warning';
    }

    // Generate recommendations
    if ($analytics['performance_trend'] === 'degrading') {
        $system_health['recommendations'][] = 'Performance degrading - consider server upgrade or optimization';
    }

    if ($system_health['critical_alerts_24h'] > 0) {
        $system_health['recommendations'][] = 'Recent critical issues detected - review recent import logs';
    }

    if ($analytics['avg_time_per_item'] > 5.0) {
        $system_health['recommendations'][] = 'Slow import performance - optimize database queries or server resources';
    }

    return $system_health;
}

/**
 * Clean up old monitoring data
 */
function cleanup_monitoring_data() {
    global $wpdb;

    // Remove old transients (older than 24 hours)
    $old_time = time() - 86400;
    $wpdb->query($wpdb->prepare("
        DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_puntwork_import_monitoring_%'
        AND option_value LIKE %s
    ", '%start_time%' . strval($old_time) . '%'));

    // Trim health alerts (keep last 50)
    $alerts = get_option('puntwork_import_health_alerts', []);
    if (count($alerts) > 50) {
        $alerts = array_slice($alerts, 0, 50);
        update_option('puntwork_import_health_alerts', $alerts);
    }

    // Trim import history (keep last 25)
    $history = get_option('puntwork_import_history', []);
    if (count($history) > 25) {
        $history = array_slice($history, 0, 25);
        update_option('puntwork_import_history', $history);
    }
}
