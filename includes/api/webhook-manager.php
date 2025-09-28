<?php

/**
 * Webhook system for real-time integrations and notifications.
 *
 * @since      2.2.0
 */

namespace Puntwork\API;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Webhook Manager for outbound notifications.
 */
class WebhookManager
{
    public const TABLE_NAME = 'puntwork_webhooks';
    public const LOG_TABLE_NAME = 'puntwork_webhook_logs';

    /**
     * Initialize webhook system.
     */
    public static function init(): void
    {
        self::createTables();
        add_action('puntwork_import_completed', [__CLASS__, 'triggerImportWebhooks'], 10, 1);
        add_action('puntwork_import_failed', [__CLASS__, 'triggerFailureWebhooks'], 10, 1);
        add_action('puntwork_job_created', [__CLASS__, 'triggerJobWebhooks'], 10, 1);
    }

    /**
     * Create webhook tables.
     */
    private static function createTables(): void
    {
        global $wpdb;

        $webhookTable = $wpdb->prefix . self::TABLE_NAME;
        $logTable = $wpdb->prefix . self::LOG_TABLE_NAME;

        $charset_collate = $wpdb->get_charset_collate();

        // Webhooks table
        $sql1 = "CREATE TABLE $webhookTable (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            url text NOT NULL,
            method varchar(10) DEFAULT 'POST',
            events text NOT NULL,
            headers text,
            secret varchar(255),
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY events (events(100)),
            KEY is_active (is_active)
        ) $charset_collate;";

        // Webhook logs table
        $sql2 = "CREATE TABLE $logTable (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            webhook_id bigint(20) NOT NULL,
            event varchar(100) NOT NULL,
            payload text,
            response_code int,
            response_body text,
            success tinyint(1) DEFAULT 0,
            error_message text,
            executed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY webhook_id (webhook_id),
            KEY event (event),
            KEY executed_at (executed_at)
        ) $charset_collate;";

        include_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql1);
        dbDelta($sql2);
    }

    /**
     * Register a webhook.
     */
    public static function registerWebhook(array $config): int
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        $data = [
            'name' => sanitize_text_field($config['name']),
            'url' => esc_url_raw($config['url']),
            'method' => strtoupper($config['method'] ?? 'POST'),
            'events' => json_encode($config['events'] ?? []),
            'headers' => json_encode($config['headers'] ?? []),
            'secret' => $config['secret'] ?? wp_generate_password(32, false),
            'is_active' => $config['is_active'] ?? true,
        ];

        $wpdb->insert($table, $data);

        return $wpdb->insert_id;
    }

    /**
     * Update webhook.
     */
    public static function updateWebhook(int $webhookId, array $config): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        $data = [];
        if (isset($config['name'])) {
            $data['name'] = sanitize_text_field($config['name']);
        }
        if (isset($config['url'])) {
            $data['url'] = esc_url_raw($config['url']);
        }
        if (isset($config['method'])) {
            $data['method'] = strtoupper($config['method']);
        }
        if (isset($config['events'])) {
            $data['events'] = json_encode($config['events']);
        }
        if (isset($config['headers'])) {
            $data['headers'] = json_encode($config['headers']);
        }
        if (isset($config['secret'])) {
            $data['secret'] = $config['secret'];
        }
        if (isset($config['is_active'])) {
            $data['is_active'] = (bool)$config['is_active'];
        }

        return $wpdb->update($table, $data, ['id' => $webhookId]) !== false;
    }

    /**
     * Delete webhook.
     */
    public static function deleteWebhook(int $webhookId): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        return $wpdb->delete($table, ['id' => $webhookId]) !== false;
    }

    /**
     * Get webhooks for event.
     */
    private static function getWebhooksForEvent(string $event): array
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "
            SELECT * FROM $table
            WHERE is_active = 1
            AND JSON_CONTAINS(events, JSON_QUOTE(%s))
        ",
                $event
            )
        );

        $webhooks = [];
        foreach ($results as $row) {
            $webhooks[] = [
                'id' => (int)$row->id,
                'name' => $row->name,
                'url' => $row->url,
                'method' => $row->method,
                'events' => json_decode($row->events, true),
                'headers' => json_decode($row->headers, true),
                'secret' => $row->secret,
            ];
        }

        return $webhooks;
    }

    /**
     * Trigger webhooks for import completion.
     */
    public static function triggerImportWebhooks(array $importData): void
    {
        $webhooks = self::getWebhooksForEvent('import.completed');

        $payload = [
            'event' => 'import.completed',
            'timestamp' => time(),
            'data' => [
                'import_id' => $importData['import_id'] ?? null,
                'jobs_processed' => $importData['processed'] ?? 0,
                'jobs_created' => $importData['published'] ?? 0,
                'jobs_updated' => $importData['updated'] ?? 0,
                'duration' => $importData['time_elapsed'] ?? 0,
                'success' => $importData['success'] ?? true,
            ],
        ];

        foreach ($webhooks as $webhook) {
            self::sendWebhook($webhook, $payload);
        }
    }

    /**
     * Trigger webhooks for import failure.
     */
    public static function triggerFailureWebhooks(array $errorData): void
    {
        $webhooks = self::getWebhooksForEvent('import.failed');

        $payload = [
            'event' => 'import.failed',
            'timestamp' => time(),
            'data' => [
                'error_message' => $errorData['message'] ?? '',
                'error_code' => $errorData['code'] ?? null,
                'import_id' => $errorData['import_id'] ?? null,
            ],
        ];

        foreach ($webhooks as $webhook) {
            self::sendWebhook($webhook, $payload);
        }
    }

    /**
     * Trigger webhooks for job creation/update.
     */
    public static function triggerJobWebhooks(array $jobData): void
    {
        $webhooks = self::getWebhooksForEvent('job.created');

        $payload = [
            'event' => 'job.created',
            'timestamp' => time(),
            'data' => [
                'job_id' => $jobData['id'] ?? null,
                'title' => $jobData['title'] ?? '',
                'category' => $jobData['category'] ?? '',
                'location' => $jobData['location'] ?? '',
                'quality_score' => $jobData['quality_score'] ?? null,
            ],
        ];

        foreach ($webhooks as $webhook) {
            self::sendWebhook($webhook, $payload);
        }
    }

    /**
     * Send webhook request.
     */
    private static function sendWebhook(array $webhook, array $payload): void
    {
        // Sign payload if secret is provided
        if (!empty($webhook['secret'])) {
            $payload['signature'] = hash_hmac('sha256', json_encode($payload), $webhook['secret']);
        }

        $args = [
            'method' => $webhook['method'],
            'body' => json_encode($payload),
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'PuntWork-Webhook/1.0',
            ],
            'timeout' => 30,
        ];

        // Add custom headers
        if (!empty($webhook['headers'])) {
            $args['headers'] = array_merge($args['headers'], $webhook['headers']);
        }

        // Send request asynchronously
        wp_remote_request($webhook['url'], $args);

        // Log webhook attempt (async logging would be better in production)
        self::logWebhookAttempt($webhook['id'], $payload['event'], $payload, null, null, true);
    }

    /**
     * Log webhook attempt.
     */
    private static function logWebhookAttempt(int $webhookId, string $event, array $payload, ?int $responseCode, ?string $responseBody, bool $success, ?string $error = null): void
    {
        global $wpdb;

        $table = $wpdb->prefix . self::LOG_TABLE_NAME;

        $data = [
            'webhook_id' => $webhookId,
            'event' => $event,
            'payload' => json_encode($payload),
            'response_code' => $responseCode,
            'response_body' => $responseBody,
            'success' => $success,
            'error_message' => $error,
        ];

        $wpdb->insert($table, $data);

        // Clean old logs (keep last 1000 entries per webhook)
        $wpdb->query(
            $wpdb->prepare(
                "
            DELETE FROM $table
            WHERE webhook_id = %d
            AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM $table
                    WHERE webhook_id = %d
                    ORDER BY executed_at DESC
                    LIMIT 1000
                ) tmp
            )
        ",
                $webhookId,
                $webhookId
            )
        );
    }

    /**
     * Get webhook logs.
     */
    public static function getWebhookLogs(int $webhookId, int $limit = 50): array
    {
        global $wpdb;

        $table = $wpdb->prefix . self::LOG_TABLE_NAME;

        return $wpdb->get_results(
            $wpdb->prepare(
                "
            SELECT * FROM $table
            WHERE webhook_id = %d
            ORDER BY executed_at DESC
            LIMIT %d
        ",
                $webhookId,
                $limit
            ),
            ARRAY_A
        );
    }

    /**
     * Test webhook.
     */
    public static function testWebhook(int $webhookId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $webhook = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $webhookId), ARRAY_A);

        if (!$webhook) {
            return [
                'success' => false,
                'message' => 'Webhook not found',
            ];
        }

        $testPayload = [
            'event' => 'test',
            'timestamp' => time(),
            'data' => ['message' => 'Test webhook from PuntWork'],
        ];

        // Send test request
        $args = [
            'method' => $webhook['method'],
            'body' => json_encode($testPayload),
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'PuntWork-Webhook-Test/1.0',
            ],
            'timeout' => 10,
        ];

        if (!empty($webhook['headers'])) {
            $args['headers'] = array_merge($args['headers'], json_decode($webhook['headers'], true));
        }

        $response = wp_remote_request($webhook['url'], $args);

        $success = !is_wp_error($response);
        $responseCode = $success ? wp_remote_retrieve_response_code($response) : null;
        $responseBody = $success ? wp_remote_retrieve_body($response) : wp_error_get_error_message($response);

        // Log test
        self::logWebhookAttempt($webhookId, 'test', $testPayload, $responseCode, $responseBody, $success);

        return [
            'success' => $success,
            'response_code' => $responseCode,
            'response_body' => $responseBody,
            'message' => $success ? 'Webhook test successful' : 'Webhook test failed',
        ];
    }
}
