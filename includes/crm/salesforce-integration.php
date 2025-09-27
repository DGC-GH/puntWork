<?php

/**
 * Salesforce CRM Integration
 *
 * @package    Puntwork
 * @subpackage CRM
 * @since      2.0.0
 */

namespace Puntwork\CRM;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Salesforce CRM integration
 */
class SalesforceIntegration extends CRMIntegration
{
    /**
     * API base URL
     */
    private string $api_base = 'https://login.salesforce.com';

    /**
     * Instance URL (set after login)
     */
    private string $instance_url = '';

    /**
     * Access token
     */
    private string $access_token = '';

    /**
     * Constructor
     */
    public function __construct(array $config = [])
    {
        $this->platform_id = 'salesforce';
        $this->platform_name = 'Salesforce';
        $this->rate_limits = [
            'requests_per_minute' => 100,  // Conservative limit
            'requests_per_hour' => 5000,   // Salesforce allows more, but we'll be conservative
            'requests_per_day' => 100000   // Salesforce daily limit
        ];

        parent::__construct($config);
    }

    /**
     * Initialize Salesforce integration
     */
    protected function initialize(): void
    {
        if ($this->isConfigured()) {
            $this->authenticate();
        }
    }

    /**
     * Check if Salesforce is properly configured
     */
    public function isConfigured(): bool
    {
        return isset($this->config['client_id'], $this->config['client_secret'], $this->config['username'], $this->config['password'])
               && !empty($this->config['client_id']) && !empty($this->config['client_secret'])
               && !empty($this->config['username']) && !empty($this->config['password']);
    }

    /**
     * Authenticate with Salesforce
     */
    private function authenticate(): bool
    {
        try {
            $response = wp_remote_post($this->api_base . '/services/oauth2/token', [
                'body' => [
                    'grant_type' => 'password',
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                    'username' => $this->config['username'],
                    'password' => $this->config['password'] . ($this->config['security_token'] ?? '')
                ],
                'timeout' => 30
            ]);

            if (is_wp_error($response)) {
                throw new \Exception('Authentication request failed: ' . $response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON response from Salesforce authentication');
            }

            if (isset($data['error'])) {
                throw new \Exception('Salesforce authentication error: ' . $data['error_description']);
            }

            $this->access_token = $data['access_token'];
            $this->instance_url = $data['instance_url'];

            return true;
        } catch (\Exception $e) {
            PuntWorkLogger::error('Salesforce authentication failed', PuntWorkLogger::CONTEXT_CRM, [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Test Salesforce connection
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Salesforce credentials not configured'
            ];
        }

        try {
            if (!$this->authenticate()) {
                return [
                    'success' => false,
                    'message' => 'Salesforce authentication failed'
                ];
            }

            // Test by getting user info
            $response = $this->makeApiRequest('sobjects/User/describe', [], 'GET');

            return [
                'success' => true,
                'message' => 'Salesforce connection successful',
                'instance_url' => $this->instance_url
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Salesforce connection failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create or update a contact
     */
    public function createContact(array $contact_data): array
    {
        $standardized_data = $this->standardizeContactData($contact_data);

        $sf_contact = $this->formatContactData($standardized_data);

        try {
            $response = $this->makeApiRequest('sobjects/Contact', $sf_contact, 'POST');

            return [
                'success' => true,
                'contact_id' => $response['id'],
                'platform' => 'salesforce',
                'timestamp' => time()
            ];
        } catch (\Exception $e) {
            PuntWorkLogger::error('Salesforce contact creation failed', PuntWorkLogger::CONTEXT_CRM, [
                'error' => $e->getMessage(),
                'contact_data' => $contact_data
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'platform' => 'salesforce',
                'timestamp' => time()
            ];
        }
    }

    /**
     * Update existing contact
     */
    public function updateContact(string $contact_id, array $contact_data): array
    {
        $standardized_data = $this->standardizeContactData($contact_data);

        $sf_contact = $this->formatContactData($standardized_data);

        try {
            $response = $this->makeApiRequest("sobjects/Contact/{$contact_id}", $sf_contact, 'PATCH');

            return [
                'success' => true,
                'contact_id' => $contact_id,
                'platform' => 'salesforce',
                'timestamp' => time()
            ];
        } catch (\Exception $e) {
            PuntWorkLogger::error('Salesforce contact update failed', PuntWorkLogger::CONTEXT_CRM, [
                'contact_id' => $contact_id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'platform' => 'salesforce',
                'timestamp' => time()
            ];
        }
    }

    /**
     * Find contact by email
     */
    public function findContactByEmail(string $email): ?array
    {
        try {
            $query = "SELECT Id, FirstName, LastName, Email, Phone, Account.Name FROM Contact WHERE Email = '{$email}'";
            $response = $this->makeApiRequest('query', ['q' => $query], 'GET');

            if (!empty($response['records'])) {
                return [
                    'id' => $response['records'][0]['Id'],
                    'properties' => $response['records'][0]
                ];
            }

            return null;
        } catch (\Exception $e) {
            PuntWorkLogger::error('Salesforce contact search failed', PuntWorkLogger::CONTEXT_CRM, [
                'email' => $email,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Create a deal/opportunity
     */
    public function createDeal(array $deal_data): array
    {
        $standardized_data = $this->standardizeDealData($deal_data);

        $sf_opportunity = [
            'Name' => $standardized_data['title'],
            'StageName' => $this->mapOpportunityStage($standardized_data['stage']),
            'Amount' => $standardized_data['value'],
            'CloseDate' => $standardized_data['expected_close_date'] ?: date('Y-m-d', strtotime('+30 days')),
            'Description' => $standardized_data['description'],
            'LeadSource' => $standardized_data['source']
        ];

        try {
            $response = $this->makeApiRequest('sobjects/Opportunity', $sf_opportunity, 'POST');

            // Associate opportunity with contact if contact_id provided
            if (!empty($standardized_data['contact_id'])) {
                $this->associateOpportunityWithContact($response['id'], $standardized_data['contact_id']);
            }

            return [
                'success' => true,
                'deal_id' => $response['id'],
                'platform' => 'salesforce',
                'timestamp' => time()
            ];
        } catch (\Exception $e) {
            PuntWorkLogger::error('Salesforce opportunity creation failed', PuntWorkLogger::CONTEXT_CRM, [
                'error' => $e->getMessage(),
                'deal_data' => $deal_data
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'platform' => 'salesforce',
                'timestamp' => time()
            ];
        }
    }

    /**
     * Format contact data for Salesforce API
     */
    private function formatContactData(array $contact_data): array
    {
        $sf_contact = [];

        if (!empty($contact_data['first_name'])) {
            $sf_contact['FirstName'] = $contact_data['first_name'];
        }

        if (!empty($contact_data['last_name'])) {
            $sf_contact['LastName'] = $contact_data['last_name'];
        }

        if (!empty($contact_data['email'])) {
            $sf_contact['Email'] = $contact_data['email'];
        }

        if (!empty($contact_data['phone'])) {
            $sf_contact['Phone'] = $contact_data['phone'];
        }

        if (!empty($contact_data['company'])) {
            $sf_contact['Account'] = ['Name' => $contact_data['company']];
        }

        if (!empty($contact_data['job_title'])) {
            $sf_contact['Title'] = $contact_data['job_title'];
        }

        if (!empty($contact_data['address'])) {
            $sf_contact['MailingStreet'] = $contact_data['address'];
        }

        if (!empty($contact_data['city'])) {
            $sf_contact['MailingCity'] = $contact_data['city'];
        }

        if (!empty($contact_data['state'])) {
            $sf_contact['MailingState'] = $contact_data['state'];
        }

        if (!empty($contact_data['zip'])) {
            $sf_contact['MailingPostalCode'] = $contact_data['zip'];
        }

        if (!empty($contact_data['country'])) {
            $sf_contact['MailingCountry'] = $contact_data['country'];
        }

        // Add custom fields
        if (!empty($contact_data['custom_fields'])) {
            foreach ($contact_data['custom_fields'] as $key => $value) {
                $sf_contact[$key] = $value;
            }
        }

        return $sf_contact;
    }

    /**
     * Map deal stage to Salesforce opportunity stages
     */
    private function mapOpportunityStage(string $stage): string
    {
        $stage_mapping = [
            'lead' => 'Prospecting',
            'application_received' => 'Qualification',
            'interview_scheduled' => 'Needs Analysis',
            'interviewed' => 'Value Proposition',
            'offer_made' => 'Proposal/Price Quote',
            'hired' => 'Closed Won',
            'rejected' => 'Closed Lost'
        ];

        return $stage_mapping[$stage] ?? 'Prospecting';
    }

    /**
     * Associate opportunity with contact
     */
    private function associateOpportunityWithContact(string $opportunity_id, string $contact_id): void
    {
        try {
            // Create Opportunity Contact Role
            $ocr_data = [
                'OpportunityId' => $opportunity_id,
                'ContactId' => $contact_id,
                'Role' => 'Decision Maker',
                'IsPrimary' => true
            ];

            $this->makeApiRequest('sobjects/OpportunityContactRole', $ocr_data, 'POST');
        } catch (\Exception $e) {
            PuntWorkLogger::error('Salesforce opportunity-contact association failed', PuntWorkLogger::CONTEXT_CRM, [
                'opportunity_id' => $opportunity_id,
                'contact_id' => $contact_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get API base URL
     */
    protected function getApiBaseUrl(): string
    {
        return rtrim($this->instance_url, '/') . '/services/data/v57.0/';
    }

    /**
     * Get default headers for API requests
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->access_token,
            'Content-Type' => 'application/json'
        ];
    }

    /**
     * Handle Salesforce API errors
     */
    protected function handleApiError(array $response): void
    {
        if (isset($response[0]['errorCode'])) {
            $message = $response[0]['message'] ?? 'Unknown error';
            throw new \Exception('Salesforce API Error: ' . $message);
        }

        if (isset($response['error'])) {
            $message = $response['error']['message'] ?? 'Unknown error';
            throw new \Exception('Salesforce API Error: ' . $message);
        }
    }

    /**
     * Get platform name (static method for manager)
     */
    public static function getPlatformName(): string
    {
        return 'Salesforce';
    }
}
