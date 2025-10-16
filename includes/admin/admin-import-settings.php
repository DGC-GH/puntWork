<?php
/**
 * Import Configuration Admin Interface
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      1.1.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/../import/import-config.php';

/**
 * Add import settings to admin menu
 */
function add_import_settings_menu() {
    add_submenu_page(
        'puntwork-admin',
        'Import Settings',
        'Import Settings',
        'manage_options',
        'puntwork-import-settings',
        __NAMESPACE__ . '\\render_import_settings_page'
    );
}
add_action('admin_menu', __NAMESPACE__ . '\\add_import_settings_menu');

/**
 * Register settings for import configuration
 */
function register_import_settings() {
    register_setting('puntwork_import_settings', 'puntwork_import_config', [
        'sanitize_callback' => __NAMESPACE__ . '\\sanitize_import_config',
        'default' => []
    ]);
}
add_action('admin_init', __NAMESPACE__ . '\\register_import_settings');

/**
 * Sanitize import configuration input
 */
function sanitize_import_config($input) {
    // Get current config for validation
    $current = get_import_config();

    // Sanitize streaming settings
    if (isset($input['streaming'])) {
        $input['streaming']['progress_update_interval'] = max(1, min(100, intval($input['streaming']['progress_update_interval'])));
        $input['streaming']['resource_check_interval'] = max(1, min(60, intval($input['streaming']['resource_check_interval'])));
        $input['streaming']['progress_save_interval'] = max(10, min(1000, intval($input['streaming']['progress_save_interval'])));
        $input['streaming']['circuit_breaker_threshold'] = max(1, min(10, intval($input['streaming']['circuit_breaker_threshold'])));
        $input['streaming']['acceptable_failure_rate'] = max(0.01, min(1.0, floatval($input['streaming']['acceptable_failure_rate'])));
    }

    // Sanitize resource settings
    if (isset($input['resources'])) {
        $input['resources']['memory_limit_threshold'] = max(0.5, min(1.0, floatval($input['resources']['memory_limit_threshold'])));
        $input['resources']['time_limit_buffer'] = max(10, min(300, intval($input['resources']['time_limit_buffer'])));
        $input['resources']['cpu_intensive_threshold'] = max(2, min(32, intval($input['resources']['cpu_intensive_threshold'])));
        $input['resources']['adaptive_memory_boost'] = max(0.0, min(0.5, floatval($input['resources']['adaptive_memory_boost'])));
    }

    // Sanitize cleanup settings
    if (isset($input['cleanup'])) {
        $input['cleanup']['retention_days'] = max(1, min(1000, intval($input['cleanup']['retention_days'])));
        $input['cleanup']['batch_size'] = max(10, min(1000, intval($input['cleanup']['batch_size'])));
        $input['cleanup']['strategy'] = in_array($input['cleanup']['strategy'], ['none', 'auto_delete', 'smart_retention']) ?
                                        $input['cleanup']['strategy'] : 'smart_retention';
    }

    // Sanitize health monitoring settings
    if (isset($input['health']['alert_thresholds'])) {
        $thresholds = $input['health']['alert_thresholds'];
        $thresholds['consecutive_failures'] = max(1, min(10, intval($thresholds['consecutive_failures'])));
        $thresholds['success_rate_drop'] = max(0.01, min(1.0, floatval($thresholds['success_rate_drop'])));
        $thresholds['performance_degradation'] = max(1.1, min(10.0, floatval($thresholds['performance_degradation'])));
        $input['health']['alert_thresholds'] = $thresholds;
    }

    // Validate the configuration
    $validation = validate_import_config($input);
    if (!$validation['valid']) {
        add_settings_error(
            'puntwork_import_config',
            'config_validation_error',
            'Configuration validation failed: ' . implode(', ', $validation['errors']),
            'error'
        );
        return $current; // Return current config if validation fails
    }

    add_settings_error(
        'puntwork_import_config',
        'config_updated',
        'Import configuration updated successfully.',
        'updated'
    );

    return $input;
}

/**
 * Render the import settings page
 */
function render_import_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $config = get_import_config();
    $system_health = check_system_health_status();
    $performance_analytics = get_import_performance_analytics(30);

    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <?php settings_errors('puntwork_import_config'); ?>

        <!-- System Health Overview -->
        <div class="health-overview metabox-holder">
            <div class="postbox">
                <h2 class="hndle"><span>System Health Overview</span></h2>
                <div class="inside">
                    <div class="health-status <?php echo esc_attr($system_health['overall_status']); ?>">
                        <span class="status-indicator <?php echo esc_attr($system_health['overall_status']); ?>"></span>
                        <strong><?php echo ucfirst($system_health['overall_status']); ?></strong>
                    </div>

                    <div class="health-metrics">
                        <div class="metric">
                            <span class="label">Critical Alerts (24h):</span>
                            <span class="value <?php echo $system_health['critical_alerts_24h'] > 0 ? 'warning' : 'good'; ?>">
                                <?php echo intval($system_health['critical_alerts_24h']); ?>
                            </span>
                        </div>

                        <div class="metric">
                            <span class="label">Performance Trend:</span>
                            <span class="value <?php echo $system_health['performance_trend'] === 'degrading' ? 'error' : ($system_health['performance_trend'] === 'improving' ? 'good' : 'neutral'); ?>">
                                <?php echo ucfirst($system_health['performance_trend']); ?>
                            </span>
                        </div>

                        <div class="metric">
                            <span class="label">Avg Import Time:</span>
                            <span class="value">
                                <?php echo number_format($system_health['avg_import_time'], 2); ?> sec/item
                            </span>
                        </div>

                        <div class="metric">
                            <span class="label">Success Rate:</span>
                            <span class="value <?php echo $system_health['success_rate'] < 0.95 ? 'warning' : 'good'; ?>">
                                <?php echo number_format($system_health['success_rate'] * 100, 1); ?>%
                            </span>
                        </div>
                    </div>

                    <?php if (!empty($system_health['recommendations'])): ?>
                    <div class="health-recommendations">
                        <h4>Recommendations:</h4>
                        <ul>
                            <?php foreach ($system_health['recommendations'] as $rec): ?>
                            <li><?php echo esc_html($rec); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Performance Analytics -->
        <div class="performance-analytics metabox-holder">
            <div class="postbox">
                <h2 class="hndle"><span>Performance Analytics (30 Days)</span></h2>
                <div class="inside">
                    <div class="analytics-grid">
                        <div class="metric">
                            <span class="label">Total Imports:</span>
                            <span class="value"><?php echo intval($performance_analytics['total_imports']); ?></span>
                        </div>

                        <div class="metric">
                            <span class="label">Avg Time/Item:</span>
                            <span class="value"><?php echo number_format($performance_analytics['avg_time_per_item'], 3); ?>s</span>
                        </div>

                        <div class="metric">
                            <span class="label">Avg Duration:</span>
                            <span class="value"><?php echo number_format($performance_analytics['avg_duration'] / 60, 1); ?> min</span>
                        </div>

                        <div class="metric">
                            <span class="label">Success Rate:</span>
                            <span class="value"><?php echo number_format($performance_analytics['success_rate'] * 100, 1); ?>%</span>
                        </div>

                        <div class="metric">
                            <span class="label">Trend:</span>
                            <span class="value <?php echo $performance_analytics['performance_trend']; ?>">
                                <?php echo ucfirst($performance_analytics['performance_trend']); ?>
                            </span>
                        </div>

                        <div class="metric">
                            <span class="label">Memory Usage:</span>
                            <span class="value"><?php echo number_format($performance_analytics['memory_usage_mb'], 1); ?>MB</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Configuration Form -->
        <form method="post" action="options.php">
            <?php settings_fields('puntwork_import_settings'); ?>

            <!-- Processing Mode -->
            <div class="settings-section metabox-holder">
                <div class="postbox">
                    <h2 class="hndle"><span>Processing Configuration</span></h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Processing Mode</th>
                                <td>
                                    <select name="puntwork_import_config[processing_mode]">
                                        <option value="streaming" <?php selected($config['processing_mode'], 'streaming'); ?>>Streaming (Recommended)</option>
                                        <option value="batch" <?php selected($config['processing_mode'], 'batch'); ?>>Batch (Legacy)</option>
                                    </select>
                                    <p class="description">Streaming processes items individually for better reliability. Batch mode is kept for backward compatibility.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Force Streaming (Phase 1)</th>
                                <td>
                                    <input type="checkbox" name="puntwork_import_config[force_streaming]" value="1" <?php checked($config['force_streaming']); ?>>
                                    <p class="description">Force the use of streaming architecture for all imports (recommended).</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Streaming Configuration -->
            <div class="settings-section metabox-holder">
                <div class="postbox">
                    <h2 class="hndle"><span>Streaming Configuration</span></h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Progress Update Interval</th>
                                <td>
                                    <input type="number" name="puntwork_import_config[streaming][progress_update_interval]"
                                           value="<?php echo intval($config['streaming']['progress_update_interval']); ?>" min="1" max="100">
                                    <p class="description">Items processed between progress updates (1-100).</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Resource Check Interval</th>
                                <td>
                                    <input type="number" name="puntwork_import_config[streaming][resource_check_interval]"
                                           value="<?php echo intval($config['streaming']['resource_check_interval']); ?>" min="1" max="60">
                                    <p class="description">Seconds between resource limit checks (1-60).</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Circuit Breaker Threshold</th>
                                <td>
                                    <input type="number" name="puntwork_import_config[streaming][circuit_breaker_threshold]"
                                           value="<?php echo intval($config['streaming']['circuit_breaker_threshold']); ?>" min="1" max="10">
                                    <p class="description">Consecutive failures before circuit breaker opens (1-10).</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Acceptable Failure Rate</th>
                                <td>
                                    <input type="number" name="puntwork_import_config[streaming][acceptable_failure_rate]"
                                           value="<?php echo number_format($config['streaming']['acceptable_failure_rate'], 3); ?>"
                                           min="0.01" max="1.0" step="0.01">
                                    <p class="description">Maximum acceptable failure rate (0.01-1.0).</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Resource Management -->
            <div class="settings-section metabox-holder">
                <div class="postbox">
                    <h2 class="hndle"><span>Resource Management</span></h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Memory Limit Threshold</th>
                                <td>
                                    <input type="number" name="puntwork_import_config[resources][memory_limit_threshold]"
                                           value="<?php echo number_format($config['resources']['memory_limit_threshold'], 2); ?>"
                                           min="0.5" max="1.0" step="0.05">
                                    <p class="description">Fraction of available memory before triggering limits (0.5-1.0).</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Time Limit Buffer</th>
                                <td>
                                    <input type="number" name="puntwork_import_config[resources][time_limit_buffer]"
                                           value="<?php echo intval($config['resources']['time_limit_buffer']); ?>" min="10" max="300">
                                    <p class="description">Seconds buffer before execution time limit (10-300).</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">High-Performance CPU Threshold</th>
                                <td>
                                    <input type="number" name="puntwork_import_config[resources][cpu_intensive_threshold]"
                                           value="<?php echo intval($config['resources']['cpu_intensive_threshold']); ?>" min="2" max="32">
                                    <p class="description">Minimum CPU cores for high-performance optimizations (2-32).</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Cleanup Strategy -->
            <div class="settings-section metabox-holder">
                <div class="postbox">
                    <h2 class="hndle"><span>Cleanup Strategy</span></h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Cleanup Strategy</th>
                                <td>
                                    <select name="puntwork_import_config[cleanup][strategy]">
                                        <option value="smart_retention" <?php selected($config['cleanup']['strategy'], 'smart_retention'); ?>>Smart Retention (Recommended)</option>
                                        <option value="auto_delete" <?php selected($config['cleanup']['strategy'], 'auto_delete'); ?>>Auto Delete</option>
                                        <option value="none" <?php selected($config['cleanup']['strategy'], 'none'); ?>>No Cleanup</option>
                                    </select>
                                    <p class="description">Strategy for handling expired job posts.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Retention Period (Days)</th>
                                <td>
                                    <input type="number" name="puntwork_import_config[cleanup][retention_days]"
                                           value="<?php echo intval($config['cleanup']['retention_days']); ?>" min="1" max="1000">
                                    <p class="description">Days to retain job posts before cleanup (1-1000).</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Safety Checks</th>
                                <td>
                                    <input type="checkbox" name="puntwork_import_config[cleanup][safety_checks]" value="1" <?php checked($config['cleanup']['safety_checks']); ?>>
                                    <p class="description">Perform additional safety checks before cleanup operations.</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Validation Settings -->
            <div class="settings-section metabox-holder">
                <div class="postbox">
                    <h2 class="hndle"><span>Feed Validation</span></h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Feed Integrity Check</th>
                                <td>
                                    <input type="checkbox" name="puntwork_import_config[validation][feed_integrity_check]" value="1" <?php checked($config['validation']['feed_integrity_check']); ?>>
                                    <p class="description">Validate feed file integrity before import.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Semantic Validation</th>
                                <td>
                                    <input type="checkbox" name="puntwork_import_config[validation][semantic_validation]" value="1" <?php checked($config['validation']['semantic_validation']); ?>>
                                    <p class="description">Validate data correctness and relationships.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Malformed Item Handling</th>
                                <td>
                                    <select name="puntwork_import_config[validation][malformed_item_handling]">
                                        <option value="skip" <?php selected($config['validation']['malformed_item_handling'], 'skip'); ?>>Skip & Continue</option>
                                        <option value="warn" <?php selected($config['validation']['malformed_item_handling'], 'warn'); ?>>Warn & Continue</option>
                                        <option value="fail" <?php selected($config['validation']['malformed_item_handling'], 'fail'); ?>>Fail Import</option>
                                    </select>
                                    <p class="description">How to handle malformed feed items.</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Health Monitoring -->
            <div class="settings-section metabox-holder">
                <div class="postbox">
                    <h2 class="hndle"><span>Health Monitoring</span></h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Enable Monitoring</th>
                                <td>
                                    <input type="checkbox" name="puntwork_import_config[monitoring][enabled]" value="1" <?php checked($config['monitoring']['enabled']); ?>>
                                    <p class="description">Enable real-time performance and health monitoring.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Slow Import Threshold</th>
                                <td>
                                    <input type="number" name="puntwork_import_config[monitoring][slow_import_threshold]"
                                           value="<?php echo number_format($config['monitoring']['slow_import_threshold'], 2); ?>"
                                           min="1.0" max="10.0" step="0.5">
                                    <p class="description">Seconds per item threshold for slow import alerts (1.0-10.0).</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Consecutive Failures Threshold</th>
                                <td>
                                    <input type="number" name="puntwork_import_config[health][alert_thresholds][consecutive_failures]"
                                           value="<?php echo intval($config['health']['alert_thresholds']['consecutive_failures']); ?>" min="1" max="10">
                                    <p class="description">Consecutive failures before triggering alerts (1-10).</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="settings-actions">
                <?php submit_button('Save Configuration', 'primary', 'submit', false); ?>

                <button type="button" class="button button-secondary" onclick="resetImportConfig()">Reset to Defaults</button>

                <button type="button" class="button button-secondary" onclick="exportImportConfig()">Export Configuration</button>

                <input type="file" id="import-config-file" style="display: none;" accept=".json">
                <button type="button" class="button button-secondary" onclick="document.getElementById('import-config-file').click()">Import Configuration</button>
            </div>
        </form>
    </div>

    <style>
        .health-overview .health-status { margin-bottom: 15px; }
        .health-overview .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .health-overview .status-indicator.healthy { background-color: #46b450; }
        .health-overview .status-indicator.warning { background-color: #ffb900; }
        .health-overview .status-indicator.critical { background-color: #dc3232; }

        .health-metrics, .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .health-metrics .metric, .analytics-grid .metric {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }

        .health-metrics .metric .label, .analytics-grid .metric .label {
            font-weight: 500;
            color: #23282d;
        }

        .health-metrics .metric .value, .analytics-grid .metric .value {
            font-weight: 600;
        }

        .value.warning { color: #ffb900; }
        .value.error { color: #dc3232; }
        .value.good { color: #46b450; }
        .value.improving { color: #46b450; }
        .value.degrading { color: #dc3232; }
        .value.stable { color: #666; }

        .settings-section { margin-bottom: 20px; }
        .settings-actions { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; }

        .health-recommendations { margin-top: 15px; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; }
        .health-recommendations ul { margin: 0; }
        .health-recommendations li { margin-bottom: 5px; }
    </style>

    <script>
    function resetImportConfig() {
        if (confirm('Are you sure you want to reset all import settings to defaults? This action cannot be undone.')) {
            // Reset via AJAX or form submission
            const form = document.querySelector('form');
            const resetInput = document.createElement('input');
            resetInput.type = 'hidden';
            resetInput.name = 'reset_import_config';
            resetInput.value = '1';
            form.appendChild(resetInput);
            form.submit();
        }
    }

    function exportImportConfig() {
        // Trigger AJAX export
        const data = {
            action: 'export_import_config',
            nonce: '<?php echo wp_create_nonce('export_import_config'); ?>'
        };

        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const blob = new Blob([JSON.stringify(data.config, null, 2)], { type: 'application/json' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'puntwork-import-config-' + new Date().toISOString().split('T')[0] + '.json';
                a.click();
                URL.revokeObjectURL(url);
            } else {
                alert('Export failed: ' + data.message);
            }
        })
        .catch(error => {
            alert('Export failed: ' + error.message);
        });
    }

    // Handle config import file selection
    document.getElementById('import-config-file').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(event) {
                try {
                    const config = JSON.parse(event.target.result);

                    // Send to server for validation and import
                    const data = {
                        action: 'import_import_config',
                        config: JSON.stringify(config),
                        nonce: '<?php echo wp_create_nonce('import_import_config'); ?>'
                    };

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams(data)
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            alert('Configuration imported successfully. Page will reload.');
                            location.reload();
                        } else {
                            alert('Import failed: ' + result.message);
                        }
                    })
                    .catch(error => {
                        alert('Import failed: ' + error.message);
                    });
                } catch (error) {
                    alert('Invalid JSON file: ' + error.message);
                }
            };
            reader.readAsText(file);
        }
    });
    </script>

    <?php
}

/**
 * Handle config reset request
 */
function handle_config_reset_request() {
    if (isset($_POST['reset_import_config']) && $_POST['reset_import_config'] === '1') {
        if (reset_import_config()) {
            add_settings_error(
                'puntwork_import_config',
                'config_reset',
                'Import configuration has been reset to defaults.',
                'updated'
            );
        } else {
            add_settings_error(
                'puntwork_import_config',
                'config_reset_error',
                'Failed to reset import configuration.',
                'error'
            );
        }
    }
}
add_action('admin_init', __NAMESPACE__ . '\\handle_config_reset_request');

/**
 * AJAX handler for config export
 */
function ajax_export_import_config() {
    check_ajax_referer('export_import_config');

    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }

    $export = export_import_config();

    wp_send_json_success(['config' => $export]);
}
add_action('wp_ajax_export_import_config', __NAMESPACE__ . '\\ajax_export_import_config');

/**
 * AJAX handler for config import
 */
function ajax_import_import_config() {
    check_ajax_referer('import_import_config');

    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }

    $config_json = sanitize_text_field($_POST['config'] ?? '');
    if (empty($config_json)) {
        wp_send_json_error(['message' => 'No configuration data provided']);
    }

    $config = json_decode($config_json, true);
    if ($config === null) {
        wp_send_json_error(['message' => 'Invalid JSON configuration']);
    }

    $result = import_import_config($config);

    if ($result['success']) {
        wp_send_json_success(['config' => $result['config']]);
    } else {
        wp_send_json_error(['message' => implode(', ', $result['errors'])]);
    }
}
add_action('wp_ajax_import_import_config', __NAMESPACE__ . '\\ajax_import_import_config');
