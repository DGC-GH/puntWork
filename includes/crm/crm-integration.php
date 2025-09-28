<?php

/**
 * CRM Integration Framework
 *
 * @package    Puntwork
 * @subpackage CRM
 * @since      0.0.4
 */

namespace Puntwork\CRM;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Abstract base class for CRM integrations
 */
abstract class CRMIntegration
{
    /**
     * CRM platform identifier
     */
    protected string $platform_id;

    /**
     * CRM platform name
     */
    protected string $platform_name;

    /**
     * Configuration array
     */
    protected array $config = array();

    /**
     * API rate limits
     */
    protected array $rate_limits = array(
    'requests_per_minute' => 60,
    'requests_per_hour'   => 1000,
    'requests_per_day'    => 50000,
    );

    /**
     * Constructor
     */
    public function __construct(array $config = array())
    {
        $this->config = $config;
        $this->initialize();
    }

    /**
     * Initialize the CRM integration
     */
    abstract protected function initialize(): void;

    /**
     * Check if CRM is properly configured
     */
    abstract public function isConfigured(): bool;

    /**
     * Test CRM connection
     */
    abstract public function testConnection(): array;

    /**
     * Create or update a contact/lead
     */
    abstract public function createContact(array $contact_data): array;

    /**
     * Update existing contact
     */
    abstract public function updateContact(string $contact_id, array $contact_data): array;

    /**
     * Find contact by email
     */
    abstract public function findContactByEmail(string $email): ?array;

    /**
     * Create a deal/opportunity
     */
    abstract public function createDeal(array $deal_data): array;

    /**
     * Get platform identifier
     */
    public function getPlatformId(): string
    {
        return $this->platform_id;
    }

    /**
     * Get platform name
     */
    abstract public static function getPlatformName(): string;

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check rate limits
     */
    protected function checkRateLimit(): bool
    {
        $transient_key   = 'crm_ratelimit_' . $this->platform_id;
        $requests_today  = get_transient($transient_key) ?: 0;
        $requests_hour   = get_transient($transient_key . '_hour_' . date('Y-m-d-H')) ?: 0;
        $requests_minute = get_transient($transient_key . '_minute_' . date('Y-m-d-H-i')) ?: 0;

        if ($requests_today >= $this->rate_limits['requests_per_day']) {
            throw new \Exception('Daily rate limit exceeded for ' . $this->platform_name);
        }

        if ($requests_hour >= $this->rate_limits['requests_per_hour']) {
            throw new \Exception('Hourly rate limit exceeded for ' . $this->platform_name);
        }

        if ($requests_minute >= $this->rate_limits['requests_per_minute']) {
            throw new \Exception('Minute rate limit exceeded for ' . $this->platform_name);
        }

        return true;
    }

    /**
     * Record API request for rate limiting
     */
    protected function recordRequest(): void
    {
        $transient_key = 'crm_ratelimit_' . $this->platform_id;

        // Daily counter
        $requests_today = get_transient($transient_key) ?: 0;
        set_transient($transient_key, $requests_today + 1, DAY_IN_SECONDS);

        // Hourly counter
        $hour_key      = $transient_key . '_hour_' . date('Y-m-d-H');
        $requests_hour = get_transient($hour_key) ?: 0;
        set_transient($hour_key, $requests_hour + 1, HOUR_IN_SECONDS);

        // Minute counter
        $minute_key      = $transient_key . '_minute_' . date('Y-m-d-H-i');
        $requests_minute = get_transient($minute_key) ?: 0;
        set_transient($minute_key, $requests_minute + 1, MINUTE_IN_SECONDS);
    }

    /**
     * Make API request with error handling
     */
    protected function makeApiRequest(string $endpoint, array $params = array(), string $method = 'GET', array $headers = array()): array
    {
        $this->checkRateLimit();

        $url = $this->getApiBaseUrl() . $endpoint;

        $args = array(
        'method'  => $method,
        'headers' => array_merge($this->getDefaultHeaders(), $headers),
        'timeout' => 30,
        );

        if ($method === 'GET') {
            $url .= '?' . http_build_query($params);
        } else {
            $args['body'] = json_encode($params);
        }

        $response = wp_remote_request($url, $args);

        $this->recordRequest();

        if (is_wp_error($response)) {
            throw new \Exception('API request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response from CRM API');
        }

        $this->handleApiError($data);

        return $data;
    }

    /**
     * Get API base URL
     */
    abstract protected function getApiBaseUrl(): string;

    /**
     * Get default headers for API requests
     */
    abstract protected function getDefaultHeaders(): array;

    /**
     * Handle API-specific errors
     */
    abstract protected function handleApiError(array $response): void;

    /**
     * Standardize contact data format
     */
    protected function standardizeContactData(array $contact_data): array
    {
        return array(
        'first_name'    => $contact_data['first_name'] ?? '',
        'last_name'     => $contact_data['last_name'] ?? '',
        'email'         => $contact_data['email'] ?? '',
        'phone'         => $contact_data['phone'] ?? '',
        'company'       => $contact_data['company'] ?? '',
        'job_title'     => $contact_data['job_title'] ?? '',
        'address'       => $contact_data['address'] ?? '',
        'city'          => $contact_data['city'] ?? '',
        'state'         => $contact_data['state'] ?? '',
        'zip'           => $contact_data['zip'] ?? '',
        'country'       => $contact_data['country'] ?? '',
        'website'       => $contact_data['website'] ?? '',
        'notes'         => $contact_data['notes'] ?? '',
        'tags'          => $contact_data['tags'] ?? array(),
        'custom_fields' => $contact_data['custom_fields'] ?? array(),
        );
    }

    /**
     * Standardize deal data format
     */
    protected function standardizeDealData(array $deal_data): array
    {
        return array(
        'title'               => $deal_data['title'] ?? '',
        'value'               => $deal_data['value'] ?? 0,
        'currency'            => $deal_data['currency'] ?? 'USD',
        'stage'               => $deal_data['stage'] ?? 'lead',
        'contact_id'          => $deal_data['contact_id'] ?? '',
        'expected_close_date' => $deal_data['expected_close_date'] ?? '',
        'description'         => $deal_data['description'] ?? '',
        'source'              => $deal_data['source'] ?? 'puntwork',
        'tags'                => $deal_data['tags'] ?? array(),
        );
    }
}
