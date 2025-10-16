<?php
/**
 * Import Configuration System
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
 * Configuration-driven import system
 * Replaces hardcoded values with configurable, adaptive settings
 */

/**
 * Get comprehensive import configuration
 * Merges defaults with stored options
 */
function get_import_config() {
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    // Default configuration
    $defaults = [
        // Processing mode
        'processing_mode' => 'streaming', // 'streaming' or 'batch'
        'force_streaming' => true, // Force streaming for Phase 1

        // Streaming configuration
        'streaming' => [
            'progress_update_interval' => 10, // items
            'resource_check_interval' => 5, // seconds
            'progress_save_interval' => 100, // items
            'circuit_breaker_threshold' => 3,
            'acceptable_failure_rate' => 0.05,
        ],

        // Resource management (adaptive)
        'resources' => [
            'memory_limit_threshold' => 0.8, // 80% of available
            'time_limit_buffer' => 60, // seconds before timeout
            'cpu_intensive_threshold' => 8, // CPU cores for high-performance mode
            'adaptive_memory_boost' => 0.1, // Additional memory for powerful servers
        ],

        // Duplicate detection
        'duplicates' => [
            'composite_key_enabled' => true,
            'key_components' => ['source_feed_slug', 'guid', 'pubdate'],
            'update_existing' => true,
            'update_on_newer_pubdate' => true,
        ],

        // Cleanup strategy
        'cleanup' => [
            'strategy' => 'smart_retention', // 'none', 'auto_delete', 'smart_retention'
            'retention_days' => 90,
            'batch_size' => 100,
            'safety_checks' => true,
            'feed_integrity_validation' => true,
        ],

        // Performance monitoring
        'monitoring' => [
            'enabled' => true,
            'metrics_collection' => true,
            'performance_alerts' => true,
            'slow_import_threshold' => 5.0, // seconds per item
            'memory_alert_threshold' => 0.9, // 90% memory usage
        ],

        // Validation settings
        'validation' => [
            'feed_integrity_check' => true,
            'semantic_validation' => true,
            'required_fields' => ['guid', 'title'],
            'data_quality_checks' => true,
            'malformed_item_handling' => 'skip', // 'skip', 'warn', 'fail'
        ],

        // Health monitoring
        'health' => [
            'enabled' => true,
            'failure_detection' => true,
            'circuit_breaker' => true,
            'auto_recovery' => true,
            'alert_thresholds' => [
                'consecutive_failures' => 3,
                'success_rate_drop' => 0.1, // 10% drop
                'performance_degradation' => 2.0, // 2x slower
            ],
        ],

        // Legacy compatibility
        'legacy' => [
            'batch_fallback' => true,
            'backward_compatibility' => true,
        ],
    ];

    // Merge with stored configuration
    $stored = get_option('puntwork_import_config', []);
    $config = array_replace_recursive($defaults, $stored);

    // Apply server-specific adaptations
    $config = adapt_config_for_server($config);

    // Cache configuration
    set_transient('puntwork_import_config_cache', $config, 300); // 5 minute cache

    return $config;
}

/**
 * Adapt configuration based on server capabilities
 */
function adapt_config_for_server($config) {
    // Get server information
    $cpu_count = function_exists('shell_exec') ? (int)shell_exec('nproc 2>/dev/null') : 2;
    $memory_limit = get_memory_limit_bytes();
    $time_limit = ini_get('max_execution_time');

    // High-performance server detection
    if ($cpu_count >= $config['resources']['cpu_intensive_threshold']) {
        $config['resources']['memory_limit_threshold'] += $config['resources']['adaptive_memory_boost'];
        $config['processing_mode'] = 'streaming'; // Force streaming on powerful servers

        PuntWorkLogger::info('High-performance server detected, optimizing configuration', PuntWorkLogger::CONTEXT_IMPORT, [
            'cpu_cores' => $cpu_count,
            'memory_limit_bytes' => $memory_limit,
            'adapted_memory_threshold' => $config['resources']['memory_limit_threshold']
        ]);
    }

    // Memory-constrained server
    $memory_mb = $memory_limit / 1024 / 1024;
    if ($memory_mb < 256) { // Less than 256MB
        $config['streaming']['progress_save_interval'] = 50; // Save more frequently
        $config['resources']['memory_limit_threshold'] = 0.7; // More conservative memory usage

        PuntWorkLogger::info('Memory-constrained server detected, adjusting limits', PuntWorkLogger::CONTEXT_IMPORT, [
            'available_memory_mb' => $memory_mb,
            'conservative_threshold' => $config['resources']['memory_limit_threshold']
        ]);
    }

    // Time-constrained server
    if ($time_limit > 0 && $time_limit < 300) { // Less than 5 minutes
        $config['streaming']['resource_check_interval'] = 2; // Check more frequently
        $config['resources']['time_limit_buffer'] = 30; // Smaller buffer

        PuntWorkLogger::info('Time-constrained server detected, adjusting intervals', PuntWorkLogger::CONTEXT_IMPORT, [
            'max_execution_time' => $time_limit,
            'resource_check_interval' => $config['streaming']['resource_check_interval']
        ]);
    }

    return $config;
}

/**
 * Update import configuration
 */
function update_import_config($new_config) {
    $current = get_import_config();
    $updated = array_replace_recursive($current, $new_config);

    // Validate configuration
    $validation = validate_import_config($updated);
    if (!$validation['valid']) {
        PuntWorkLogger::error('Invalid import configuration', PuntWorkLogger::CONTEXT_IMPORT, [
            'errors' => $validation['errors']
        ]);
        return ['success' => false, 'errors' => $validation['errors']];
    }

    // Save configuration
    update_option('puntwork_import_config', $updated);
    delete_transient('puntwork_import_config_cache'); // Clear cache

    PuntWorkLogger::info('Import configuration updated', PuntWorkLogger::CONTEXT_IMPORT, [
        'changes' => $new_config
    ]);

    return ['success' => true, 'config' => $updated];
}

/**
 * Validate configuration settings
 */
function validate_import_config($config) {
    $errors = [];

    // Processing mode validation
    if (!in_array($config['processing_mode'], ['streaming', 'batch'])) {
        $errors[] = 'Invalid processing mode';
    }

    // Resource validation
    if ($config['resources']['memory_limit_threshold'] <= 0 || $config['resources']['memory_limit_threshold'] > 1) {
        $errors[] = 'Memory limit threshold must be between 0 and 1';
    }

    // Streaming validation
    if ($config['streaming']['progress_update_interval'] < 1) {
        $errors[] = 'Progress update interval must be positive';
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Get configuration value with fallback
 */
function get_import_config_value($key, $default = null) {
    $config = get_import_config();
    $keys = explode('.', $key);

    foreach ($keys as $k) {
        if (!isset($config[$k])) {
            return $default;
        }
        $config = $config[$k];
    }

    return $config;
}

/**
 * Set configuration value
 */
function set_import_config_value($key, $value) {
    $keys = explode('.', $key);
    $config = get_import_config();

    $temp = &$config;
    foreach ($keys as $k) {
        if (!isset($temp[$k])) {
            $temp[$k] = [];
        }
        $temp = &$temp[$k];
    }
    $temp = $value;

    return update_import_config($config);
}

/**
 * Reset configuration to defaults
 */
function reset_import_config() {
    delete_option('puntwork_import_config');
    delete_transient('puntwork_import_config_cache');

    PuntWorkLogger::info('Import configuration reset to defaults', PuntWorkLogger::CONTEXT_IMPORT);

    return ['success' => true, 'config' => get_import_config()];
}

/**
 * Export configuration for backup/debugging
 */
function export_import_config() {
    $config = get_import_config();
    $export = [
        'version' => '1.1.0',
        'exported_at' => date('Y-m-d H:i:s'),
        'config' => $config,
        'server_info' => [
            'php_version' => phpversion(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'cpu_cores' => function_exists('shell_exec') ? (int)shell_exec('nproc 2>/dev/null') : 'unknown',
        ]
    ];

    return $export;
}

/**
 * Import configuration from backup
 */
function import_import_config($export_data) {
    if (!isset($export_data['config'])) {
        return ['success' => false, 'error' => 'Invalid export data'];
    }

    $config = $export_data['config'];

    // Validate imported configuration
    $validation = validate_import_config($config);
    if (!$validation['valid']) {
        return ['success' => false, 'errors' => $validation['errors']];
    }

    // Save configuration
    update_option('puntwork_import_config', $config);
    delete_transient('puntwork_import_config_cache');

    PuntWorkLogger::info('Import configuration imported', PuntWorkLogger::CONTEXT_IMPORT, [
        'version' => $export_data['version'] ?? 'unknown',
        'exported_at' => $export_data['exported_at'] ?? 'unknown'
    ]);

    return ['success' => true, 'config' => $config];
}
