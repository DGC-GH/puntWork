<?php
/**
 * Feed Health Monitoring and Alert System
 *
 * @package    Puntwork
 * @subpackage Monitoring
 * @since      1.0.11
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Feed Health Monitor Class
 * Monitors feed availability, performance, and sends alerts
 */
class FeedHealthMonitor {

    const TABLE_NAME = 'puntwork_feed_health';
    const ALERT_TRANSIENT_PREFIX = 'puntwork_feed_alert_';
    const HEALTH_CHECK_TRANSIENT = 'puntwork_feed_health_check';

    // Health status constants
    const STATUS_HEALTHY = 'healthy';
    const STATUS_WARNING = 'warning';
    const STATUS_CRITICAL = 'critical';
    const STATUS_DOWN = 'down';

    // Alert types
    const ALERT_FEED_DOWN = 'feed_down';
    const ALERT_FEED_SLOW = 'feed_slow';
    const ALERT_FEED_EMPTY = 'feed_empty';
    const ALERT_FEED_CHANGED = 'feed_changed';

    /**
     * Initialize the feed health monitoring system
     */
    public static function init() {
        self::create_health_table();
        add_action('puntwork_feed_health_check', [__CLASS__, 'perform_health_check']);
        add_action('admin_init', [__CLASS__, 'schedule_health_checks']);
    }

    /**
     * Create the feed health monitoring database table
     */
    private static function create_health_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            feed_key varchar(100) NOT NULL,
            feed_url text NOT NULL,
            check_time datetime NOT NULL,
            status varchar(20) NOT NULL,
            response_time float DEFAULT NULL,
            http_code int DEFAULT NULL,
            item_count int DEFAULT NULL,
            error_message text DEFAULT NULL,
            last_modified varchar(100) DEFAULT NULL,
            content_hash varchar(64) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY feed_key_time (feed_key, check_time),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Schedule regular health checks
     */
    public static function schedule_health_checks() {
        if (!wp_next_scheduled('puntwork_feed_health_check')) {
            // Run health checks every 15 minutes
            wp_schedule_event(time(), '15min', 'puntwork_feed_health_check');
        }
    }

    /**
     * Perform health check on all feeds
     */
    public static function perform_health_check() {
        $feeds = get_feeds();

        if (empty($feeds)) {
            PuntWorkLogger::warning('No feeds configured for health monitoring', PuntWorkLogger::CONTEXT_MONITORING);
            return;
        }

        foreach ($feeds as $feed_key => $feed_url) {
            self::check_feed_health($feed_key, $feed_url);
        }

        // Clean up old health records (keep last 30 days)
        self::cleanup_old_records();
    }

    /**
     * Check health of a specific feed
     */
    public static function check_feed_health($feed_key, $feed_url) {
        $start_time = microtime(true);

        try {
            // Perform HTTP check
            $response = wp_remote_head($feed_url, [
                'timeout' => 30,
                'redirection' => 5,
                'user-agent' => 'WordPress PuntWork Health Monitor',
                'headers' => [
                    'Accept' => 'application/xml, text/xml, application/rss+xml, */*'
                ]
            ]);

            $response_time = microtime(true) - $start_time;
            $http_code = wp_remote_retrieve_response_code($response);

            if (is_wp_error($response)) {
                self::record_health_check($feed_key, $feed_url, self::STATUS_DOWN, $response_time, null, null, null, $response->get_error_message());
                self::send_alert($feed_key, $feed_url, self::ALERT_FEED_DOWN, ['error' => $response->get_error_message()]);
                return;
            }

            // Check if response is successful
            if ($http_code < 200 || $http_code >= 300) {
                self::record_health_check($feed_key, $feed_url, self::STATUS_DOWN, $response_time, $http_code, null, null, "HTTP $http_code");
                self::send_alert($feed_key, $feed_url, self::ALERT_FEED_DOWN, ['http_code' => $http_code]);
                return;
            }

            // Get content for analysis
            $content_response = wp_remote_get($feed_url, [
                'timeout' => 60,
                'redirection' => 5,
                'user-agent' => 'WordPress PuntWork Health Monitor'
            ]);

            if (is_wp_error($content_response)) {
                self::record_health_check($feed_key, $feed_url, self::STATUS_WARNING, $response_time, $http_code, null, null, 'Content fetch failed: ' . $content_response->get_error_message());
                return;
            }

            $body = wp_remote_retrieve_body($content_response);
            $content_length = strlen($body);

            // Check for empty or very small content
            if ($content_length < 1000) {
                self::record_health_check($feed_key, $feed_url, self::STATUS_CRITICAL, $response_time, $http_code, 0, null, 'Feed content too small');
                self::send_alert($feed_key, $feed_url, self::ALERT_FEED_EMPTY, ['content_length' => $content_length]);
                return;
            }

            // Parse XML to count items
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);

            if (!$xml) {
                self::record_health_check($feed_key, $feed_url, self::STATUS_CRITICAL, $response_time, $http_code, null, null, 'Invalid XML format');
                self::send_alert($feed_key, $feed_url, self::ALERT_FEED_CHANGED, ['error' => 'Invalid XML format']);
                return;
            }

            // Count items (different feed formats may use different element names)
            $item_count = 0;
            if (isset($xml->job)) {
                $item_count = count($xml->job);
            } elseif (isset($xml->item)) {
                $item_count = count($xml->item);
            } elseif (isset($xml->channel->item)) {
                $item_count = count($xml->channel->item);
            }

            // Get last modified header
            $last_modified = wp_remote_retrieve_header($content_response, 'last-modified');

            // Generate content hash for change detection
            $content_hash = hash('sha256', $body);

            // Determine status based on response time and item count
            $status = self::STATUS_HEALTHY;

            if ($response_time > 10) { // Slow response
                $status = self::STATUS_WARNING;
                self::send_alert($feed_key, $feed_url, self::ALERT_FEED_SLOW, [
                    'response_time' => round($response_time, 2),
                    'threshold' => 10
                ]);
            }

            if ($item_count === 0) {
                $status = self::STATUS_CRITICAL;
                self::send_alert($feed_key, $feed_url, self::ALERT_FEED_EMPTY, ['item_count' => $item_count]);
            }

            // Check for significant content changes
            $previous_hash = self::get_previous_content_hash($feed_key);
            if ($previous_hash && $previous_hash !== $content_hash) {
                self::send_alert($feed_key, $feed_url, self::ALERT_FEED_CHANGED, [
                    'old_hash' => substr($previous_hash, 0, 8),
                    'new_hash' => substr($content_hash, 0, 8),
                    'item_count' => $item_count
                ]);
            }

            self::record_health_check($feed_key, $feed_url, $status, $response_time, $http_code, $item_count, $last_modified, null, $content_hash);

        } catch (\Exception $e) {
            $response_time = microtime(true) - $start_time;
            self::record_health_check($feed_key, $feed_url, self::STATUS_DOWN, $response_time, null, null, null, $e->getMessage());
            self::send_alert($feed_key, $feed_url, self::ALERT_FEED_DOWN, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record a health check result in the database
     */
    private static function record_health_check($feed_key, $feed_url, $status, $response_time, $http_code, $item_count, $last_modified, $error_message = null, $content_hash = null) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $wpdb->insert(
            $table_name,
            [
                'feed_key' => $feed_key,
                'feed_url' => $feed_url,
                'check_time' => current_time('mysql'),
                'status' => $status,
                'response_time' => $response_time,
                'http_code' => $http_code,
                'item_count' => $item_count,
                'error_message' => $error_message,
                'last_modified' => $last_modified,
                'content_hash' => $content_hash
            ],
            ['%s', '%s', '%s', '%s', '%f', '%d', '%d', '%s', '%s', '%s']
        );

        PuntWorkLogger::info("Feed health check: $feed_key - $status", PuntWorkLogger::CONTEXT_MONITORING, [
            'feed_key' => $feed_key,
            'status' => $status,
            'response_time' => round($response_time, 2),
            'http_code' => $http_code,
            'item_count' => $item_count
        ]);
    }

    /**
     * Send an alert for a feed issue
     */
    private static function send_alert($feed_key, $feed_url, $alert_type, $data = []) {
        $alert_key = $alert_type . '_' . $feed_key;
        $transient_key = self::ALERT_TRANSIENT_PREFIX . $alert_key;

        // Check if we already sent this alert recently (prevent spam)
        if (get_transient($transient_key)) {
            return; // Alert already sent recently
        }

        // Set transient to prevent repeated alerts (24 hours)
        set_transient($transient_key, time(), DAY_IN_SECONDS);

        $alert_settings = get_option('puntwork_feed_alerts', [
            'email_enabled' => true,
            'email_recipients' => get_option('admin_email'),
            'alert_types' => [
                self::ALERT_FEED_DOWN => true,
                self::ALERT_FEED_SLOW => true,
                self::ALERT_FEED_EMPTY => true,
                self::ALERT_FEED_CHANGED => false // Disabled by default
            ]
        ]);

        // Check if this alert type is enabled
        if (empty($alert_settings['alert_types'][$alert_type])) {
            return;
        }

        $subject = self::get_alert_subject($alert_type, $feed_key);
        $message = self::get_alert_message($alert_type, $feed_key, $feed_url, $data);

        // Send email alert
        if (!empty($alert_settings['email_enabled']) && !empty($alert_settings['email_recipients'])) {
            $recipients = is_array($alert_settings['email_recipients'])
                ? $alert_settings['email_recipients']
                : [$alert_settings['email_recipients']];

            foreach ($recipients as $email) {
                wp_mail($email, $subject, $message, [
                    'Content-Type: text/html; charset=UTF-8',
                    'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
                ]);
            }
        }

        // Log the alert
        PuntWorkLogger::warning("Feed alert sent: $alert_type for $feed_key", PuntWorkLogger::CONTEXT_MONITORING, [
            'alert_type' => $alert_type,
            'feed_key' => $feed_key,
            'data' => $data
        ]);
    }

    /**
     * Get alert subject line
     */
    private static function get_alert_subject($alert_type, $feed_key) {
        $subjects = [
            self::ALERT_FEED_DOWN => "🚨 Feed Down Alert: $feed_key",
            self::ALERT_FEED_SLOW => "⚠️ Slow Feed Alert: $feed_key",
            self::ALERT_FEED_EMPTY => "🚨 Empty Feed Alert: $feed_key",
            self::ALERT_FEED_CHANGED => "ℹ️ Feed Content Changed: $feed_key"
        ];

        return $subjects[$alert_type] ?? "Feed Alert: $feed_key";
    }

    /**
     * Get alert message content
     */
    private static function get_alert_message($alert_type, $feed_key, $feed_url, $data) {
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();

        $messages = [
            self::ALERT_FEED_DOWN => "
                <h2>Feed Down Alert</h2>
                <p><strong>Feed:</strong> {$feed_key}</p>
                <p><strong>URL:</strong> {$feed_url}</p>
                <p><strong>Issue:</strong> Feed is not responding or returning errors</p>
                " . (isset($data['error']) ? "<p><strong>Error:</strong> {$data['error']}</p>" : "") .
                (isset($data['http_code']) ? "<p><strong>HTTP Code:</strong> {$data['http_code']}</p>" : "") . "
                <p><strong>Time:</strong> " . current_time('mysql') . "</p>
                <p>Please check the feed URL and contact the feed provider if necessary.</p>
            ",
            self::ALERT_FEED_SLOW => "
                <h2>Slow Feed Alert</h2>
                <p><strong>Feed:</strong> {$feed_key}</p>
                <p><strong>URL:</strong> {$feed_url}</p>
                <p><strong>Response Time:</strong> {$data['response_time']} seconds</p>
                <p><strong>Threshold:</strong> {$data['threshold']} seconds</p>
                <p><strong>Time:</strong> " . current_time('mysql') . "</p>
                <p>The feed is responding slowly, which may affect import performance.</p>
            ",
            self::ALERT_FEED_EMPTY => "
                <h2>Empty Feed Alert</h2>
                <p><strong>Feed:</strong> {$feed_key}</p>
                <p><strong>URL:</strong> {$feed_url}</p>
                <p><strong>Item Count:</strong> {$data['item_count']}</p>
                <p><strong>Time:</strong> " . current_time('mysql') . "</p>
                <p>The feed appears to be empty or contains no job listings.</p>
            ",
            self::ALERT_FEED_CHANGED => "
                <h2>Feed Content Changed</h2>
                <p><strong>Feed:</strong> {$feed_key}</p>
                <p><strong>URL:</strong> {$feed_url}</p>
                <p><strong>Item Count:</strong> {$data['item_count']}</p>
                <p><strong>Content Hash Changed:</strong> {$data['old_hash']} → {$data['new_hash']}</p>
                <p><strong>Time:</strong> " . current_time('mysql') . "</p>
                <p>The feed content has changed significantly since the last check.</p>
            "
        ];

        $message = $messages[$alert_type] ?? "<h2>Feed Alert</h2><p>Unknown alert type: $alert_type</p>";

        return "
            <html>
            <body style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px;'>
                    <h1 style='color: #dc3545; margin: 0;'>{$site_name} - Feed Monitoring Alert</h1>
                    <p style='margin: 10px 0 0 0; color: #6c757d;'>Site: <a href='{$site_url}'>{$site_url}</a></p>
                </div>
                <div style='background: #ffffff; padding: 20px; border: 1px solid #dee2e6; border-radius: 5px;'>
                    {$message}
                </div>
                <div style='margin-top: 20px; padding: 15px; background: #e9ecef; border-radius: 5px;'>
                    <p style='margin: 0; font-size: 12px; color: #6c757d;'>
                        This alert was generated by the PuntWork Feed Health Monitor.<br>
                        You can manage alert settings in the WordPress admin under PuntWork → Settings.
                    </p>
                </div>
            </body>
            </html>
        ";
    }

    /**
     * Get the previous content hash for a feed
     */
    private static function get_previous_content_hash($feed_key) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT content_hash FROM $table_name
             WHERE feed_key = %s AND content_hash IS NOT NULL
             ORDER BY check_time DESC LIMIT 1",
            $feed_key
        ));
    }

    /**
     * Clean up old health records (keep last 30 days)
     */
    private static function cleanup_old_records() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-30 days'));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE check_time < %s",
            $cutoff_date
        ));
    }

    /**
     * Get current health status for all feeds
     */
    public static function get_feed_health_status() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT feed_key, status, response_time, http_code, item_count, check_time, error_message
             FROM $table_name
             WHERE check_time >= %s
             ORDER BY check_time DESC",
            date('Y-m-d H:i:s', strtotime('-1 hour'))
        ), ARRAY_A);

        $status = [];
        foreach ($results as $row) {
            if (!isset($status[$row['feed_key']]) || strtotime($row['check_time']) > strtotime($status[$row['feed_key']]['check_time'])) {
                $status[$row['feed_key']] = $row;
            }
        }

        return $status;
    }

    /**
     * Get health history for a specific feed
     */
    public static function get_feed_health_history($feed_key, $days = 7) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name
             WHERE feed_key = %s AND check_time >= %s
             ORDER BY check_time DESC",
            $feed_key,
            date('Y-m-d H:i:s', strtotime("-{$days} days"))
        ), ARRAY_A);
    }

    /**
     * Manually trigger a health check for all feeds
     */
    public static function trigger_manual_check() {
        self::perform_health_check();
    }
}