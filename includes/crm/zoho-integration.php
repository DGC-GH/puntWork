<?php

/**
 * Zoho CRM Integration
 *
 * @package    Puntwork
 * @subpackage CRM
 * @since      2.0.0
 */

namespace Puntwork\CRM;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Zoho CRM integration
 */
class ZohoIntegration extends CRMIntegration
{
    /**
     * API base URL
     */
    private string $api_base = 'https://www.zohoapis.com';

    /**
     * Access token
     */
    private string $access_token = '';

    /**
     * Token expiry time
     */
    private int $token_expiry = 0;

    /**
     * Constructor
     */
    public function __construct(array $config = array())
    {
        $this->platform_id   = 'zoho';
        $this->platform_name = 'Zoho CRM';
        $this->rate_limits   = array(
        'requests_per_minute' => 60,   // Zoho allows 60 requests per minute
        'requests_per_hour'   => 1000,   // 1000 per hour
        'requests_per_day'    => 25000,    // 25k per day for most plans
        );

        parent::__construct($config);
    }

    /**
     * Initialize Zoho integration
     */
    protected function initialize(): void
    {
        if ($this->isConfigured()) {
            $this->ensureValidToken();
        }
    }

    /**
     * Check if Zoho is properly configured
     */
    public function isConfigured(): bool
    {
        return isset($this->config['client_id'], $this->config['client_secret'], $this->config['refresh_token'])
        && ! empty($this->config['client_id']) && ! empty($this->config['client_secret'])
        && ! empty($this->config['refresh_token']);
    }

    /**
     * Ensure we have a valid access token
     */
    private function ensureValidToken(): bool
    {
        if (empty($this->access_token) || time() >= $this->token_expiry) {
            return $this->refreshAccessToken();
        }
        return true;
    }

    /**
     * Refresh access token using refresh token
     */
    private function refreshAccessToken(): bool
    {
        try {
            $response = wp_remote_post(
                'https://accounts.zoho.com/oauth/v2/token',
                array(
                'body'    => array(
                'grant_type'    => 'refresh_token',
                'client_id'     => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'refresh_token' => $this->config['refresh_token'],
                ),
                'timeout' => 30,
                )
            );

            if (is_wp_error($response)) {
                   throw new \Exception('Token refresh request failed: ' . $response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON response from Zoho token refresh');
            }

            if (isset($data['error'])) {
                throw new \Exception('Zoho token refresh error: ' . $data['error']);
            }

            $this->access_token = $data['access_token'];
            $this->token_expiry = time() + ( $data['expires_in'] ?? 3600 ) - 300; // 5 minutes buffer

            return true;
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'Zoho token refresh failed',
                PuntWorkLogger::CONTEXT_CRM,
                array(
                'error' => $e->getMessage(),
                )
            );
            return false;
        }
    }

    /**
     * Test Zoho connection
     */
    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return array(
            'success' => false,
            'message' => 'Zoho credentials not configured',
            );
        }

        try {
            if (! $this->ensureValidToken()) {
                return array(
                'success' => false,
                'message' => 'Failed to obtain access token',
                );
            }

            // Test by getting user info
            $response = $this->makeApiRequest('crm/v2/users?type=CurrentUser', array(), 'GET');

            return array(
            'success' => true,
            'message' => 'Zoho CRM connection successful',
            );
        } catch (\Exception $e) {
            return array(
            'success' => false,
            'message' => 'Zoho CRM connection failed: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Create or update a contact
     */
    public function createContact(array $contact_data): array
    {
        $standardized_data = $this->standardizeContactData($contact_data);

        $zoho_contact = $this->formatContactData($standardized_data);

        try {
            $response = $this->makeApiRequest('crm/v2/Contacts', array( 'data' => array( $zoho_contact ) ), 'POST');

            if (! empty($response['data'][0]['code']) && $response['data'][0]['code'] === 'SUCCESS') {
                return array(
                 'success'    => true,
                 'contact_id' => $response['data'][0]['details']['id'],
                 'platform'   => 'zoho',
                 'timestamp'  => time(),
                );
            }

            throw new \Exception('Contact creation failed');
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'Zoho contact creation failed',
                PuntWorkLogger::CONTEXT_CRM,
                array(
                'error'        => $e->getMessage(),
                'contact_data' => $contact_data,
                )
            );

            return array(
            'success'   => false,
            'error'     => $e->getMessage(),
            'platform'  => 'zoho',
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

        $zoho_contact = $this->formatContactData($standardized_data);

        try {
            $response = $this->makeApiRequest("crm/v2/Contacts/{$contact_id}", array( 'data' => array( $zoho_contact ) ), 'PUT');

            if (! empty($response['data'][0]['code']) && $response['data'][0]['code'] === 'SUCCESS') {
                return array(
                 'success'    => true,
                 'contact_id' => $contact_id,
                 'platform'   => 'zoho',
                 'timestamp'  => time(),
                );
            }

            throw new \Exception('Contact update failed');
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'Zoho contact update failed',
                PuntWorkLogger::CONTEXT_CRM,
                array(
                'contact_id' => $contact_id,
                'error'      => $e->getMessage(),
                )
            );

            return array(
            'success'   => false,
            'error'     => $e->getMessage(),
            'platform'  => 'zoho',
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
                'crm/v2/Contacts/search',
                array(
                'criteria' => "Email:equals:{$email}",
                ),
                'GET'
            );

            if (! empty($response['data'])) {
                   return array(
                    'id'         => $response['data'][0]['id'],
                    'properties' => $response['data'][0],
                   );
            }

            return null;
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'Zoho contact search failed',
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

        $zoho_deal = array(
        'Deal_Name'    => $standardized_data['title'],
        'Stage'        => $this->mapDealStage($standardized_data['stage']),
        'Amount'       => $standardized_data['value'],
        'Closing_Date' => $standardized_data['expected_close_date'] ?: date('Y-m-d', strtotime('+30 days')),
        'Description'  => $standardized_data['description'],
        'Lead_Source'  => $standardized_data['source'],
        );

        try {
            $response = $this->makeApiRequest('crm/v2/Deals', array( 'data' => array( $zoho_deal ) ), 'POST');

            if (! empty($response['data'][0]['code']) && $response['data'][0]['code'] === 'SUCCESS') {
                $deal_id = $response['data'][0]['details']['id'];

                // Associate deal with contact if contact_id provided
                if (! empty($standardized_data['contact_id'])) {
                    $this->associateDealWithContact($deal_id, $standardized_data['contact_id']);
                }

                return array(
                'success'   => true,
                'deal_id'   => $deal_id,
                'platform'  => 'zoho',
                'timestamp' => time(),
                );
            }

            throw new \Exception('Deal creation failed');
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'Zoho deal creation failed',
                PuntWorkLogger::CONTEXT_CRM,
                array(
                'error'     => $e->getMessage(),
                'deal_data' => $deal_data,
                )
            );

            return array(
            'success'   => false,
            'error'     => $e->getMessage(),
            'platform'  => 'zoho',
            'timestamp' => time(),
            );
        }
    }

    /**
     * Format contact data for Zoho API
     */
    private function formatContactData(array $contact_data): array
    {
        $zoho_contact = array();

        if (! empty($contact_data['first_name'])) {
            $zoho_contact['First_Name'] = $contact_data['first_name'];
        }

        if (! empty($contact_data['last_name'])) {
            $zoho_contact['Last_Name'] = $contact_data['last_name'];
        }

        if (! empty($contact_data['email'])) {
            $zoho_contact['Email'] = $contact_data['email'];
        }

        if (! empty($contact_data['phone'])) {
            $zoho_contact['Phone'] = $contact_data['phone'];
        }

        if (! empty($contact_data['company'])) {
            $zoho_contact['Company'] = $contact_data['company'];
        }

        if (! empty($contact_data['job_title'])) {
            $zoho_contact['Designation'] = $contact_data['job_title'];
        }

        if (! empty($contact_data['address'])) {
            $zoho_contact['Street'] = $contact_data['address'];
        }

        if (! empty($contact_data['city'])) {
            $zoho_contact['City'] = $contact_data['city'];
        }

        if (! empty($contact_data['state'])) {
            $zoho_contact['State'] = $contact_data['state'];
        }

        if (! empty($contact_data['zip'])) {
            $zoho_contact['Zip_Code'] = $contact_data['zip'];
        }

        if (! empty($contact_data['country'])) {
            $zoho_contact['Country'] = $contact_data['country'];
        }

        if (! empty($contact_data['website'])) {
            $zoho_contact['Website'] = $contact_data['website'];
        }

        if (! empty($contact_data['notes'])) {
            $zoho_contact['Description'] = $contact_data['notes'];
        }

        // Add custom fields
        if (! empty($contact_data['custom_fields'])) {
            foreach ($contact_data['custom_fields'] as $key => $value) {
                $zoho_contact[ $key ] = $value;
            }
        }

        return $zoho_contact;
    }

    /**
     * Map deal stage to Zoho CRM stages
     */
    private function mapDealStage(string $stage): string
    {
        $stage_mapping = array(
        'lead'                 => 'Qualification',
        'application_received' => 'Needs Analysis',
        'interview_scheduled'  => 'Value Proposition',
        'interviewed'          => 'Proposal/Price Quote',
        'offer_made'           => 'Negotiation/Review',
        'hired'                => 'Closed Won',
        'rejected'             => 'Closed Lost',
        );

        return $stage_mapping[ $stage ] ?? 'Qualification';
    }

    /**
     * Associate deal with contact
     */
    private function associateDealWithContact(string $deal_id, string $contact_id): void
    {
        try {
            $this->makeApiRequest("crm/v2/Deals/{$deal_id}/Contacts/{$contact_id}", array(), 'PUT');
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'Zoho deal-contact association failed',
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
        return $this->api_base . '/';
    }

    /**
     * Get default headers for API requests
     */
    protected function getDefaultHeaders(): array
    {
        return array(
        'Authorization' => 'Zoho-oauthtoken ' . $this->access_token,
        'Content-Type'  => 'application/json',
        );
    }

    /**
     * Handle Zoho API errors
     */
    protected function handleApiError(array $response): void
    {
        if (isset($response['data'][0]['code']) && $response['data'][0]['code'] !== 'SUCCESS') {
            $message = $response['data'][0]['message'] ?? 'Unknown error';
            throw new \Exception('Zoho API Error: ' . $message);
        }

        if (isset($response['status']) && isset($response['code'])) {
            $message = $response['message'] ?? 'Unknown error';
            throw new \Exception('Zoho API Error: ' . $message);
        }
    }

    /**
     * Get platform name (static method for manager)
     */
    public static function getPlatformName(): string
    {
        return 'Zoho CRM';
    }
}
