<?php

/**
 * HubSpot CRM Integration
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
 * HubSpot CRM integration
 */
class HubSpotIntegration extends CRMIntegration
{
    /**
     * API base URL
     */
    private string $api_base = 'https://api.hubapi.com';

    /**
     * Constructor
     */
    public function __construct(array $config = array())
    {
        $this->platform_id   = 'hubspot';
        $this->platform_name = 'HubSpot';
        $this->rate_limits   = array(
            'requests_per_minute' => 100,  // HubSpot allows 100 requests per 10 seconds
            'requests_per_hour'   => 40000,  // 40k per day, but we'll be conservative
            'requests_per_day'    => 250000,   // HubSpot's daily limit
        );

        parent::__construct($config);
    }

    /**
     * Initialize HubSpot integration
     */
    protected function initialize(): void
    {
        // HubSpot specific initialization if needed
    }

    /**
     * Check if HubSpot is properly configured
     */
    public function isConfigured(): bool
    {
        return isset($this->config['access_token']) && ! empty($this->config['access_token']);
    }

    /**
     * Test HubSpot connection
     */
    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return array(
                'success' => false,
                'message' => 'HubSpot access token not configured',
            );
        }

        try {
            // Test by getting account info
            $response = $this->makeApiRequest('account-info/v3/details', array(), 'GET');

            return array(
                'success'      => true,
                'message'      => 'HubSpot connection successful',
                'account_info' => $response,
            );
        } catch (\Exception $e) {
            return array(
                'success' => false,
                'message' => 'HubSpot connection failed: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Create or update a contact
     */
    public function createContact(array $contact_data): array
    {
        $standardized_data = $this->standardizeContactData($contact_data);

        $properties = $this->formatContactProperties($standardized_data);

        try {
            $response = $this->makeApiRequest('crm/v3/objects/contacts', $properties, 'POST');

            return array(
                'success'    => true,
                'contact_id' => $response['id'],
                'platform'   => 'hubspot',
                'timestamp'  => time(),
            );
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'HubSpot contact creation failed',
                PuntWorkLogger::CONTEXT_CRM,
                array(
                    'error'        => $e->getMessage(),
                    'contact_data' => $contact_data,
                )
            );

            return array(
                'success'   => false,
                'error'     => $e->getMessage(),
                'platform'  => 'hubspot',
                'timestamp' => time(),
            );
        }
    }

    /**
     * Update existing contact
     */
    public function updateContact(string $contact_id, array $contact_data): array
    {
        $standardized_data = $this->standardizeContactData($contact_data);

        $properties = $this->formatContactProperties($standardized_data);

        try {
            $response = $this->makeApiRequest("crm/v3/objects/contacts/{$contact_id}", $properties, 'PATCH');

            return array(
                'success'    => true,
                'contact_id' => $contact_id,
                'platform'   => 'hubspot',
                'timestamp'  => time(),
            );
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'HubSpot contact update failed',
                PuntWorkLogger::CONTEXT_CRM,
                array(
                    'contact_id' => $contact_id,
                    'error'      => $e->getMessage(),
                )
            );

            return array(
                'success'   => false,
                'error'     => $e->getMessage(),
                'platform'  => 'hubspot',
                'timestamp' => time(),
            );
        }
    }

    /**
     * Find contact by email
     */
    public function findContactByEmail(string $email): ?array
    {
        try {
            $response = $this->makeApiRequest(
                'crm/v3/objects/contacts/search',
                array(
                    'filterGroups' => array(
                        array(
                            'filters' => array(
                                array(
                                    'propertyName' => 'email',
                                    'operator'     => 'EQ',
                                    'value'        => $email,
                                ),
                            ),
                        ),
                    ),
                ),
                'POST'
            );

            if (! empty($response['results'])) {
                    return array(
                        'id'         => $response['results'][0]['id'],
                        'properties' => $response['results'][0]['properties'],
                    );
            }

            return null;
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'HubSpot contact search failed',
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

        $properties = array(
            'dealname'           => $standardized_data['title'],
            'dealstage'          => $this->mapDealStage($standardized_data['stage']),
            'amount'             => $standardized_data['value'],
            'deal_currency_code' => $standardized_data['currency'],
            'closedate'          => $standardized_data['expected_close_date'] ? strtotime($standardized_data['expected_close_date']) * 1000 : null,
            'deal_description'   => $standardized_data['description'],
            'deal_source'        => $standardized_data['source'],
        );

        // Remove null values
        $properties = array_filter(
            $properties,
            function ($value) {
                return $value !== null;
            }
        );

        try {
            $response = $this->makeApiRequest('crm/v3/objects/deals', $properties, 'POST');

            // Associate deal with contact if contact_id provided
            if (! empty($standardized_data['contact_id'])) {
                $this->associateDealWithContact($response['id'], $standardized_data['contact_id']);
            }

            return array(
                'success'   => true,
                'deal_id'   => $response['id'],
                'platform'  => 'hubspot',
                'timestamp' => time(),
            );
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'HubSpot deal creation failed',
                PuntWorkLogger::CONTEXT_CRM,
                array(
                    'error'     => $e->getMessage(),
                    'deal_data' => $deal_data,
                )
            );

            return array(
                'success'   => false,
                'error'     => $e->getMessage(),
                'platform'  => 'hubspot',
                'timestamp' => time(),
            );
        }
    }

    /**
     * Format contact properties for HubSpot API
     */
    private function formatContactProperties(array $contact_data): array
    {
        $properties = array();

        if (! empty($contact_data['first_name'])) {
            $properties['firstname'] = $contact_data['first_name'];
        }

        if (! empty($contact_data['last_name'])) {
            $properties['lastname'] = $contact_data['last_name'];
        }

        if (! empty($contact_data['email'])) {
            $properties['email'] = $contact_data['email'];
        }

        if (! empty($contact_data['phone'])) {
            $properties['phone'] = $contact_data['phone'];
        }

        if (! empty($contact_data['company'])) {
            $properties['company'] = $contact_data['company'];
        }

        if (! empty($contact_data['job_title'])) {
            $properties['jobtitle'] = $contact_data['job_title'];
        }

        if (! empty($contact_data['address'])) {
            $properties['address'] = $contact_data['address'];
        }

        if (! empty($contact_data['city'])) {
            $properties['city'] = $contact_data['city'];
        }

        if (! empty($contact_data['state'])) {
            $properties['state'] = $contact_data['state'];
        }

        if (! empty($contact_data['zip'])) {
            $properties['zip'] = $contact_data['zip'];
        }

        if (! empty($contact_data['country'])) {
            $properties['country'] = $contact_data['country'];
        }

        if (! empty($contact_data['website'])) {
            $properties['website'] = $contact_data['website'];
        }

        if (! empty($contact_data['notes'])) {
            $properties['notes_last_contacted'] = $contact_data['notes'];
        }

        // Add custom fields
        if (! empty($contact_data['custom_fields'])) {
            foreach ($contact_data['custom_fields'] as $key => $value) {
                $properties[ $key ] = $value;
            }
        }

        return array( 'properties' => $properties );
    }

    /**
     * Map deal stage to HubSpot pipeline stages
     */
    private function mapDealStage(string $stage): string
    {
        $stage_mapping = array(
            'lead'                 => 'appointmentscheduled',
            'application_received' => 'qualifiedtobuy',
            'interview_scheduled'  => 'presentationscheduled',
            'interviewed'          => 'decisionmakerboughtin',
            'offer_made'           => 'contractsent',
            'hired'                => 'closedwon',
            'rejected'             => 'closedlost',
        );

        return $stage_mapping[ $stage ] ?? 'appointmentscheduled';
    }

    /**
     * Associate deal with contact
     */
    private function associateDealWithContact(string $deal_id, string $contact_id): void
    {
        try {
            $this->makeApiRequest("crm/v3/objects/deals/{$deal_id}/associations/contacts/{$contact_id}", array(), 'PUT');
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'HubSpot deal-contact association failed',
                PuntWorkLogger::CONTEXT_CRM,
                array(
                    'deal_id'    => $deal_id,
                    'contact_id' => $contact_id,
                    'error'      => $e->getMessage(),
                )
            );
        }
    }

    /**
     * Get API base URL
     */
    protected function getApiBaseUrl(): string
    {
        return $this->api_base;
    }

    /**
     * Get default headers for API requests
     */
    protected function getDefaultHeaders(): array
    {
        return array(
            'Authorization' => 'Bearer ' . ( $this->config['access_token'] ?? '' ),
            'Content-Type'  => 'application/json',
        );
    }

    /**
     * Handle HubSpot API errors
     */
    protected function handleApiError(array $response): void
    {
        if (isset($response['status']) && $response['status'] === 'error') {
            $message = $response['message'] ?? 'Unknown error';
            throw new \Exception('HubSpot API Error: ' . $message);
        }

        if (isset($response['error'])) {
            $message = $response['error'] ?? 'Unknown error';
            throw new \Exception('HubSpot API Error: ' . $message);
        }
    }

    /**
     * Get platform name (static method for manager)
     */
    public static function getPlatformName(): string
    {
        return 'HubSpot';
    }
}
