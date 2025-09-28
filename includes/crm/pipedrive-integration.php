<?php

/**
 * Pipedrive CRM Integration
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
 * Pipedrive CRM integration
 */
class PipedriveIntegration extends CRMIntegration
{
    /**
     * API base URL
     */
    private string $api_base = 'https://api.pipedrive.com/v1';

    /**
     * Constructor
     */
    public function __construct(array $config = array())
    {
        $this->platform_id   = 'pipedrive';
        $this->platform_name = 'Pipedrive';
        $this->rate_limits   = array(
            'requests_per_minute' => 60,   // Pipedrive allows 60 requests per minute
            'requests_per_hour'   => 480,    // 480 per hour for most plans
            'requests_per_day'    => 20000,    // 20k per day for most plans
        );

        parent::__construct($config);
    }

    /**
     * Initialize Pipedrive integration
     */
    protected function initialize(): void
    {
        // Pipedrive specific initialization if needed
    }

    /**
     * Check if Pipedrive is properly configured
     */
    public function isConfigured(): bool
    {
        return isset($this->config['api_token']) && ! empty($this->config['api_token']);
    }

    /**
     * Test Pipedrive connection
     */
    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return array(
                'success' => false,
                'message' => 'Pipedrive API token not configured',
            );
        }

        try {
            // Test by getting user info
            $response = $this->makeApiRequest('users/me', array(), 'GET');

            return array(
                'success'   => true,
                'message'   => 'Pipedrive connection successful',
                'user_info' => $response['data'],
            );
        } catch (\Exception $e) {
            return array(
                'success' => false,
                'message' => 'Pipedrive connection failed: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Create or update a contact/person
     */
    public function createContact(array $contact_data): array
    {
        $standardized_data = $this->standardizeContactData($contact_data);

        $pd_person = $this->formatPersonData($standardized_data);

        try {
            $response = $this->makeApiRequest('persons', $pd_person, 'POST');

            return array(
                'success'    => true,
                'contact_id' => $response['data']['id'],
                'platform'   => 'pipedrive',
                'timestamp'  => time(),
            );
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'Pipedrive person creation failed',
                PuntWorkLogger::CONTEXT_CRM,
                array(
                    'error'        => $e->getMessage(),
                    'contact_data' => $contact_data,
                )
            );

            return array(
                'success'   => false,
                'error'     => $e->getMessage(),
                'platform'  => 'pipedrive',
                'timestamp' => time(),
            );
        }
    }

    /**
     * Update existing contact/person
     */
    public function updateContact(string $contact_id, array $contact_data): array
    {
        $standardized_data = $this->standardizeContactData($contact_data);

        $pd_person = $this->formatPersonData($standardized_data);

        try {
            $response = $this->makeApiRequest("persons/{$contact_id}", $pd_person, 'PUT');

            return array(
                'success'    => true,
                'contact_id' => $contact_id,
                'platform'   => 'pipedrive',
                'timestamp'  => time(),
            );
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'Pipedrive person update failed',
                PuntWorkLogger::CONTEXT_CRM,
                array(
                    'contact_id' => $contact_id,
                    'error'      => $e->getMessage(),
                )
            );

            return array(
                'success'   => false,
                'error'     => $e->getMessage(),
                'platform'  => 'pipedrive',
                'timestamp' => time(),
            );
        }
    }

    /**
     * Find contact/person by email
     */
    public function findContactByEmail(string $email): ?array
    {
        try {
            $response = $this->makeApiRequest(
                'persons/search',
                array(
                    'term'        => $email,
                    'fields'      => 'email',
                    'exact_match' => true,
                ),
                'GET'
            );

            if (! empty($response['data']['items'])) {
                $person = $response['data']['items'][0]['item'];
                return array(
                    'id'         => $person['id'],
                    'properties' => $person,
                );
            }

            return null;
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'Pipedrive person search failed',
                PuntWorkLogger::CONTEXT_CRM,
                array(
                    'email' => $email,
                    'error' => $e->getMessage(),
                )
            );

            return null;
        }
    }

    /**
     * Create a deal
     */
    public function createDeal(array $deal_data): array
    {
        $standardized_data = $this->standardizeDealData($deal_data);

        $pd_deal = array(
            'title'               => $standardized_data['title'],
            'value'               => $standardized_data['value'],
            'currency'            => $standardized_data['currency'],
            'status'              => $this->mapDealStatus($standardized_data['stage']),
            'expected_close_date' => $standardized_data['expected_close_date'] ?: date('Y-m-d', strtotime('+30 days')),
            'user_id'             => $this->config['user_id'] ?? null,
        );

        // Add contact association if contact_id provided
        if (! empty($standardized_data['contact_id'])) {
            $pd_deal['person_id'] = $standardized_data['contact_id'];
        }

        try {
            $response = $this->makeApiRequest('deals', $pd_deal, 'POST');

            return array(
                'success'   => true,
                'deal_id'   => $response['data']['id'],
                'platform'  => 'pipedrive',
                'timestamp' => time(),
            );
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'Pipedrive deal creation failed',
                PuntWorkLogger::CONTEXT_CRM,
                array(
                    'error'     => $e->getMessage(),
                    'deal_data' => $deal_data,
                )
            );

            return array(
                'success'   => false,
                'error'     => $e->getMessage(),
                'platform'  => 'pipedrive',
                'timestamp' => time(),
            );
        }
    }

    /**
     * Format person data for Pipedrive API
     */
    private function formatPersonData(array $contact_data): array
    {
        $pd_person = array();

        if (! empty($contact_data['first_name']) || ! empty($contact_data['last_name'])) {
            $pd_person['name'] = trim(( $contact_data['first_name'] ?? '' ) . ' ' . ( $contact_data['last_name'] ?? '' ));
        }

        if (! empty($contact_data['email'])) {
            $pd_person['email'] = array( $contact_data['email'] );
        }

        if (! empty($contact_data['phone'])) {
            $pd_person['phone'] = array( $contact_data['phone'] );
        }

        if (! empty($contact_data['company'])) {
            $pd_person['org_name'] = $contact_data['company'];
        }

        // Add address if available
        $address_parts = array_filter(
            array(
                $contact_data['address'] ?? '',
                $contact_data['city'] ?? '',
                $contact_data['state'] ?? '',
                $contact_data['zip'] ?? '',
                $contact_data['country'] ?? '',
            )
        );

        if (! empty($address_parts)) {
            $pd_person['address'] = implode(', ', $address_parts);
        }

        if (! empty($contact_data['website'])) {
            $pd_person['website'] = $contact_data['website'];
        }

        if (! empty($contact_data['notes'])) {
            $pd_person['notes'] = $contact_data['notes'];
        }

        // Add custom fields
        if (! empty($contact_data['custom_fields'])) {
            foreach ($contact_data['custom_fields'] as $key => $value) {
                $pd_person[ $key ] = $value;
            }
        }

        return $pd_person;
    }

    /**
     * Map deal stage to Pipedrive deal status
     */
    private function mapDealStatus(string $stage): string
    {
        $status_mapping = array(
            'lead'                 => 'open',
            'application_received' => 'open',
            'interview_scheduled'  => 'open',
            'interviewed'          => 'open',
            'offer_made'           => 'open',
            'hired'                => 'won',
            'rejected'             => 'lost',
        );

        return $status_mapping[ $stage ] ?? 'open';
    }

    /**
     * Get API base URL
     */
    protected function getApiBaseUrl(): string
    {
        return $this->api_base . '/';
    }

    /**
     * Get default headers for API requests
     */
    protected function getDefaultHeaders(): array
    {
        return array(
            'Content-Type' => 'application/json',
        );
    }

    /**
     * Make API request with Pipedrive-specific handling
     */
    protected function makeApiRequest(string $endpoint, array $params = array(), string $method = 'GET', array $headers = array()): array
    {
        // Add API token to query parameters
        if ($method === 'GET') {
            $params['api_token'] = $this->config['api_token'];
        } else {
            $endpoint .= '?api_token=' . $this->config['api_token'];
        }

        return parent::makeApiRequest($endpoint, $params, $method, $headers);
    }

    /**
     * Handle Pipedrive API errors
     */
    protected function handleApiError(array $response): void
    {
        if (isset($response['success']) && ! $response['success']) {
            $message = $response['error'] ?? 'Unknown error';
            throw new \Exception('Pipedrive API Error: ' . $message);
        }

        if (isset($response['error'])) {
            $message = $response['error'] ?? 'Unknown error';
            throw new \Exception('Pipedrive API Error: ' . $message);
        }
    }

    /**
     * Get platform name (static method for manager)
     */
    public static function getPlatformName(): string
    {
        return 'Pipedrive';
    }
}
