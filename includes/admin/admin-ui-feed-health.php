<?php
/**
 * Admin UI for Feed Health Monitor
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      1.0.11
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Feed Health Monitor Admin Page
 */
function feed_health_monitor_page() {
    // Handle AJAX actions
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'run_health_check':
                check_admin_referer('feed_health_check_nonce');
                FeedHealthMonitor::trigger_manual_check();
                add_settings_error('feed_health_monitor', 'health_check_run', 'Health check completed successfully.', 'success');
                break;

            case 'update_alert_settings':
                check_admin_referer('feed_health_alerts_nonce');
                update_alert_settings();
                break;
        }
    }

    // Get current health status
    $health_status = FeedHealthMonitor::get_feed_health_status();
    $alert_settings = get_option('puntwork_feed_alerts', [
        'email_enabled' => true,
        'email_recipients' => get_option('admin_email'),
        'alert_types' => [
            FeedHealthMonitor::ALERT_FEED_DOWN => true,
            FeedHealthMonitor::ALERT_FEED_SLOW => true,
            FeedHealthMonitor::ALERT_FEED_EMPTY => true,
            FeedHealthMonitor::ALERT_FEED_CHANGED => false
        ]
    ]);

    ?>
    <div class="wrap">
        <h1><?php _e('Feed Health Monitor', 'puntwork'); ?></h1>

        <?php settings_errors('feed_health_monitor'); ?>

        <div class="health-monitor-container">
            <!-- Current Status Overview -->
            <div class="health-status-overview">
                <h2><?php _e('Current Feed Status', 'puntwork'); ?></h2>

                <?php if (empty($health_status)): ?>
                    <div class="notice notice-info">
                        <p><?php _e('No health checks have been performed yet. Click "Run Health Check" to start monitoring your feeds.', 'puntwork'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="health-status-grid">
                        <?php foreach ($health_status as $feed_key => $status): ?>
                            <div class="health-status-card status-<?php echo esc_attr($status['status']); ?>">
                                <div class="status-header">
                                    <h3><?php echo esc_html($feed_key); ?></h3>
                                    <span class="status-badge status-<?php echo esc_attr($status['status']); ?>">
                                        <?php echo esc_html(ucfirst($status['status'])); ?>
                                    </span>
                                </div>

                                <div class="status-details">
                                    <div class="metric">
                                        <span class="label"><?php _e('Response Time', 'puntwork'); ?>:</span>
                                        <span class="value"><?php echo $status['response_time'] ? round($status['response_time'], 2) . 's' : 'N/A'; ?></span>
                                    </div>

                                    <div class="metric">
                                        <span class="label"><?php _e('HTTP Code', 'puntwork'); ?>:</span>
                                        <span class="value"><?php echo $status['http_code'] ?: 'N/A'; ?></span>
                                    </div>

                                    <div class="metric">
                                        <span class="label"><?php _e('Items', 'puntwork'); ?>:</span>
                                        <span class="value"><?php echo $status['item_count'] !== null ? number_format($status['item_count']) : 'N/A'; ?></span>
                                    </div>

                                    <div class="metric">
                                        <span class="label"><?php _e('Last Check', 'puntwork'); ?>:</span>
                                        <span class="value"><?php echo $status['check_time'] ? wp_date('M j, H:i', strtotime($status['check_time'])) : 'Never'; ?></span>
                                    </div>

                                    <?php if ($status['error_message']): ?>
                                        <div class="error-message">
                                            <strong><?php _e('Error', 'puntwork'); ?>:</strong> <?php echo esc_html($status['error_message']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Manual Health Check -->
            <div class="health-actions">
                <h2><?php _e('Actions', 'puntwork'); ?></h2>

                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('feed_health_check_nonce'); ?>
                    <input type="hidden" name="action" value="run_health_check">
                    <button type="submit" class="button button-primary">
                        <?php _e('Run Health Check Now', 'puntwork'); ?>
                    </button>
                </form>

                <p class="description">
                    <?php _e('Manually trigger a health check for all configured feeds. Health checks run automatically every 15 minutes.', 'puntwork'); ?>
                </p>
            </div>

            <!-- Alert Settings -->
            <div class="alert-settings">
                <h2><?php _e('Alert Settings', 'puntwork'); ?></h2>

                <form method="post">
                    <?php wp_nonce_field('feed_health_alerts_nonce'); ?>
                    <input type="hidden" name="action" value="update_alert_settings">

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Email Alerts', 'puntwork'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="email_enabled" value="1" <?php checked($alert_settings['email_enabled']); ?>>
                                    <?php _e('Enable email notifications for feed health issues', 'puntwork'); ?>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php _e('Email Recipients', 'puntwork'); ?></th>
                            <td>
                                <input type="email" name="email_recipients" value="<?php echo esc_attr($alert_settings['email_recipients']); ?>" class="regular-text" multiple>
                                <p class="description"><?php _e('Comma-separated list of email addresses to receive alerts.', 'puntwork'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php _e('Alert Types', 'puntwork'); ?></th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="checkbox" name="alert_types[feed_down]" value="1" <?php checked($alert_settings['alert_types'][FeedHealthMonitor::ALERT_FEED_DOWN]); ?>>
                                        <?php _e('Feed Down - When feeds are unreachable or return errors', 'puntwork'); ?>
                                    </label><br>

                                    <label>
                                        <input type="checkbox" name="alert_types[feed_slow]" value="1" <?php checked($alert_settings['alert_types'][FeedHealthMonitor::ALERT_FEED_SLOW]); ?>>
                                        <?php _e('Slow Response - When feeds take longer than 10 seconds to respond', 'puntwork'); ?>
                                    </label><br>

                                    <label>
                                        <input type="checkbox" name="alert_types[feed_empty]" value="1" <?php checked($alert_settings['alert_types'][FeedHealthMonitor::ALERT_FEED_EMPTY]); ?>>
                                        <?php _e('Empty Feed - When feeds contain no job listings', 'puntwork'); ?>
                                    </label><br>

                                    <label>
                                        <input type="checkbox" name="alert_types[feed_changed]" value="1" <?php checked($alert_settings['alert_types'][FeedHealthMonitor::ALERT_FEED_CHANGED]); ?>>
                                        <?php _e('Content Changed - When feed content changes significantly', 'puntwork'); ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Save Alert Settings', 'puntwork')); ?>
                </form>
            </div>

            <!-- Health History -->
            <?php if (!empty($health_status)): ?>
            <div class="health-history">
                <h2><?php _e('Health History', 'puntwork'); ?></h2>

                <div class="feed-history-tabs">
                    <?php foreach (array_keys($health_status) as $index => $feed_key): ?>
                        <button class="tab-button <?php echo $index === 0 ? 'active' : ''; ?>" data-feed="<?php echo esc_attr($feed_key); ?>">
                            <?php echo esc_html($feed_key); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($health_status as $feed_key => $current_status): ?>
                    <div class="feed-history-content" id="history-<?php echo esc_attr($feed_key); ?>" style="<?php echo $feed_key === array_key_first($health_status) ? '' : 'display: none;'; ?>">
                        <?php
                        $history = FeedHealthMonitor::get_feed_health_history($feed_key, 7);
                        if (empty($history)): ?>
                            <p><?php _e('No historical data available for this feed.', 'puntwork'); ?></p>
                        <?php else: ?>
                            <div class="history-chart">
                                <canvas id="chart-<?php echo esc_attr($feed_key); ?>" width="400" height="200"></canvas>
                            </div>

                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php _e('Time', 'puntwork'); ?></th>
                                        <th><?php _e('Status', 'puntwork'); ?></th>
                                        <th><?php _e('Response Time', 'puntwork'); ?></th>
                                        <th><?php _e('HTTP Code', 'puntwork'); ?></th>
                                        <th><?php _e('Items', 'puntwork'); ?></th>
                                        <th><?php _e('Error', 'puntwork'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($history, 0, 50) as $record): ?>
                                        <tr>
                                            <td><?php echo wp_date('M j, H:i', strtotime($record['check_time'])); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo esc_attr($record['status']); ?>">
                                                    <?php echo esc_html(ucfirst($record['status'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $record['response_time'] ? round($record['response_time'], 2) . 's' : 'N/A'; ?></td>
                                            <td><?php echo $record['http_code'] ?: 'N/A'; ?></td>
                                            <td><?php echo $record['item_count'] !== null ? number_format($record['item_count']) : 'N/A'; ?></td>
                                            <td><?php echo $record['error_message'] ? esc_html(substr($record['error_message'], 0, 50)) . (strlen($record['error_message']) > 50 ? '...' : '') : '—'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <style>
        .health-monitor-container {
            max-width: none;
        }

        .health-status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .health-status-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            background: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .health-status-card.status-healthy { border-left: 4px solid #28a745; }
        .health-status-card.status-warning { border-left: 4px solid #ffc107; }
        .health-status-card.status-critical { border-left: 4px solid #fd7e14; }
        .health-status-card.status-down { border-left: 4px solid #dc3545; }

        .status-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .status-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.status-healthy { background: #d4edda; color: #155724; }
        .status-badge.status-warning { background: #fff3cd; color: #856404; }
        .status-badge.status-critical { background: #f8d7da; color: #721c24; }
        .status-badge.status-down { background: #f8d7da; color: #721c24; }

        .status-details .metric {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .status-details .label { font-weight: 500; color: #666; }
        .status-details .value { font-weight: 600; }

        .error-message {
            margin-top: 10px;
            padding: 10px;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            color: #721c24;
            font-size: 14px;
        }

        .health-actions, .alert-settings {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .feed-history-tabs {
            margin-bottom: 20px;
        }

        .tab-button {
            background: #f1f1f1;
            border: 1px solid #ddd;
            padding: 8px 16px;
            cursor: pointer;
            border-radius: 4px 4px 0 0;
            margin-right: 5px;
        }

        .tab-button.active {
            background: #fff;
            border-bottom: 1px solid #fff;
            margin-bottom: -1px;
        }

        .feed-history-content {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 0 8px 8px 8px;
            padding: 20px;
        }

        .history-chart {
            margin-bottom: 20px;
            height: 200px;
        }
    </style>

    <script>
        // Tab switching for feed history
        document.addEventListener('DOMContentLoaded', function() {
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.feed-history-content');

            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const feedKey = this.getAttribute('data-feed');

                    // Hide all contents
                    tabContents.forEach(content => content.style.display = 'none');
                    tabButtons.forEach(btn => btn.classList.remove('active'));

                    // Show selected content
                    document.getElementById('history-' + feedKey).style.display = 'block';
                    this.classList.add('active');
                });
            });
        });
    </script>
    <?php
}

/**
 * Update alert settings from form submission
 */
function update_alert_settings() {
    $alert_settings = [
        'email_enabled' => isset($_POST['email_enabled']),
        'email_recipients' => sanitize_text_field($_POST['email_recipients']),
        'alert_types' => [
            FeedHealthMonitor::ALERT_FEED_DOWN => isset($_POST['alert_types']['feed_down']),
            FeedHealthMonitor::ALERT_FEED_SLOW => isset($_POST['alert_types']['feed_slow']),
            FeedHealthMonitor::ALERT_FEED_EMPTY => isset($_POST['alert_types']['feed_empty']),
            FeedHealthMonitor::ALERT_FEED_CHANGED => isset($_POST['alert_types']['feed_changed'])
        ]
    ];

    update_option('puntwork_feed_alerts', $alert_settings);
    add_settings_error('feed_health_monitor', 'alerts_updated', 'Alert settings updated successfully.', 'success');
}