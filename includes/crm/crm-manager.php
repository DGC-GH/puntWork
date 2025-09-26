<?php

/**
 * CRM Manager
 *
 * @package    Puntwork
 * @subpackage CRM
 * @since      2.3.0
 */

namespace Puntwork\CRM;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages CRM integrations and data synchronization
 */
class CRMManager
{
    /**
     * Available CRM platforms
     */
    private static array $available_platforms = [
        'hubspot' => HubSpotIntegration::class,
        'salesforce' => SalesforceIntegration::class,
        'zoho' => ZohoIntegration::class,
        'pipedrive' => PipedriveIntegration::class
    ];

    /**
     * Configured platform instances
     */
    private array $platforms = [];

    /**
     * Constructor - loads configured platforms
     */
    public function __construct()
    {
        $this->loadConfiguredPlatforms();
    }

    /**
     * Load configured CRM platforms
     */
    private function loadConfiguredPlatforms(): void
    {
        $platform_configs = get_option('puntwork_crm_platforms', []);

        foreach ($platform_configs as $platform_id => $config) {
            if (isset(self::$available_platforms[$platform_id]) && isset($config['enabled']) && $config['enabled']) {
                try {
                    $platform_class = self::$available_platforms[$platform_id];
                    $this->platforms[$platform_id] = new $platform_class($config);
                } catch (\Exception $e) {
                    PuntWorkLogger::error('Failed to initialize CRM platform', PuntWorkLogger::CONTEXT_CRM, [
                        'platform_id' => $platform_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Get all available CRM platforms
     */
    public static function getAvailablePlatforms(): array
    {
        $platforms = [];

        foreach (self::$available_platforms as $platform_id => $platform_class) {
            $platforms[$platform_id] = [
                'name' => $platform_class::getPlatformName(),
                'class' => $platform_class
            ];
        }

        return $platforms;
    }

    /**
     * Get configured platforms
     */
    public function getConfiguredPlatforms(): array
    {
        return array_keys($this->platforms);
    }

    /**
     * Check if a platform is configured
     */
    public function isPlatformConfigured(string $platform_id): bool
    {
        return isset($this->platforms[$platform_id]);
    }

    /**
     * Get a specific platform instance
     */
    public function getPlatform(string $platform_id): ?CRMIntegration
    {
        return $this->platforms[$platform_id] ?? null;
    }

    /**
     * Sync job application data to CRM platforms
     */
    public function syncJobApplication(array $application_data, array $platforms = []): array
    {
        $results = [];
        $target_platforms = empty($platforms) ? $this->platforms : array_intersect_key($this->platforms, array_flip($platforms));

        foreach ($target_platforms as $platform_id => $platform) {
            try {
                $result = $this->syncApplicationToPlatform($platform, $application_data);
                $results[$platform_id] = $result;

                PuntWorkLogger::info('Synced job application to CRM platform', PuntWorkLogger::CONTEXT_CRM, [
                    'platform_id' => $platform_id,
                    'application_id' => $application_data['id'] ?? '',
                    'success' => $result['success']
                ]);
            } catch (\Exception $e) {
                PuntWorkLogger::error('Failed to sync job application to CRM platform', PuntWorkLogger::CONTEXT_CRM, [
                    'platform_id' => $platform_id,
                    'error' => $e->getMessage()
                ]);

                $results[$platform_id] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'platform' => $platform_id,
                    'timestamp' => time()
                ];
            }
        }

        return $results;
    }

    /**
     * Sync application data to a specific CRM platform
     */
    private function syncApplicationToPlatform(CRMIntegration $platform, array $application_data): array
    {
        // Create or update contact
        $contact_data = $this->formatApplicationAsContact($application_data);
        $contact_result = $this->syncContact($platform, $contact_data);

        if (!$contact_result['success']) {
            return $contact_result;
        }

        // Create deal/opportunity if configured
        $deal_result = ['success' => true];
        if ($this->shouldCreateDeal($application_data)) {
            $deal_data = $this->formatApplicationAsDeal($application_data, $contact_result['contact_id']);
            $deal_result = $platform->createDeal($deal_data);
        }

        return [
            'success' => true,
            'contact_id' => $contact_result['contact_id'],
            'deal_id' => $deal_result['deal_id'] ?? null,
            'platform' => $platform->getPlatformId(),
            'timestamp' => time()
        ];
    }

    /**
     * Sync contact data
     */
    private function syncContact(CRMIntegration $platform, array $contact_data): array
    {
        // Check if contact already exists
        $existing_contact = $platform->findContactByEmail($contact_data['email']);

        if ($existing_contact) {
            // Update existing contact
            return $platform->updateContact($existing_contact['id'], $contact_data);
        } else {
            // Create new contact
            return $platform->createContact($contact_data);
        }
    }

    /**
     * Format job application data as CRM contact
     */
    private function formatApplicationAsContact(array $application_data): array
    {
        return [
            'first_name' => $application_data['first_name'] ?? '',
            'last_name' => $application_data['last_name'] ?? '',
            'email' => $application_data['email'] ?? '',
            'phone' => $application_data['phone'] ?? '',
            'company' => $application_data['current_company'] ?? '',
            'job_title' => $application_data['current_position'] ?? '',
            'address' => $application_data['address'] ?? '',
            'city' => $application_data['city'] ?? '',
            'state' => $application_data['state'] ?? '',
            'zip' => $application_data['zip'] ?? '',
            'country' => $application_data['country'] ?? '',
            'website' => $application_data['linkedin'] ?? $application_data['portfolio'] ?? '',
            'notes' => $application_data['cover_letter'] ?? $application_data['notes'] ?? '',
            'tags' => ['job_application', 'puntwork', $application_data['job_title'] ?? ''],
            'custom_fields' => [
                'job_id' => $application_data['job_id'] ?? '',
                'job_title' => $application_data['job_title'] ?? '',
                'application_date' => $application_data['application_date'] ?? date('Y-m-d'),
                'source' => $application_data['source'] ?? 'puntwork',
                'experience_years' => $application_data['experience_years'] ?? '',
                'education' => $application_data['education'] ?? '',
                'skills' => is_array($application_data['skills'] ?? null) ? implode(', ', $application_data['skills']) : ($application_data['skills'] ?? ''),
                'salary_expectation' => $application_data['salary_expectation'] ?? '',
                'availability' => $application_data['availability'] ?? ''
            ]
        ];
    }

    /**
     * Format job application data as CRM deal
     */
    private function formatApplicationAsDeal(array $application_data, string $contact_id): array
    {
        return [
            'title' => 'Job Application: ' . ($application_data['job_title'] ?? 'Unknown Position'),
            'value' => 0, // No monetary value for job applications
            'currency' => 'USD',
            'stage' => 'application_received',
            'contact_id' => $contact_id,
            'expected_close_date' => '', // No close date for applications
            'description' => 'Job application received via puntWork for ' . ($application_data['job_title'] ?? 'position'),
            'source' => 'puntwork_job_application',
            'tags' => ['job_application', $application_data['job_title'] ?? '']
        ];
    }

    /**
     * Determine if a deal should be created for this application
     */
    private function shouldCreateDeal(array $application_data): bool
    {
        // Create deals for applications that seem promising or have specific criteria
        return true; // For now, create deals for all applications
    }

    /**
     * Configure a CRM platform
     */
    public static function configurePlatform(string $platform_id, array $config): bool
    {
        if (!isset(self::$available_platforms[$platform_id])) {
            return false;
        }

        $platform_configs = get_option('puntwork_crm_platforms', []);
        $platform_configs[$platform_id] = $config;

        return update_option('puntwork_crm_platforms', $platform_configs);
    }

    /**
     * Remove a platform configuration
     */
    public static function removePlatform(string $platform_id): bool
    {
        $platform_configs = get_option('puntwork_crm_platforms', []);

        if (isset($platform_configs[$platform_id])) {
            unset($platform_configs[$platform_id]);
            return update_option('puntwork_crm_platforms', $platform_configs);
        }

        return false;
    }

    /**
     * Get platform configuration
     */
    public static function getPlatformConfig(string $platform_id): ?array
    {
        $platform_configs = get_option('puntwork_crm_platforms', []);
        return $platform_configs[$platform_id] ?? null;
    }

    /**
     * Get all platform configurations
     */
    public static function getAllPlatformConfigs(): array
    {
        return get_option('puntwork_crm_platforms', []);
    }

    /**
     * Test platform configuration
     */
    public function testPlatform(string $platform_id): array
    {
        $platform = $this->getPlatform($platform_id);

        if (!$platform) {
            return [
                'success' => false,
                'message' => 'Platform not configured'
            ];
        }

        try {
            return $platform->testConnection();
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Platform test failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get CRM statistics
     */
    public function getStatistics(): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'puntwork_crm_sync_log';

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_syncs,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_syncs,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_syncs,
                MAX(created_at) as last_sync
            FROM $table_name
            WHERE created_at >= %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ), ARRAY_A);

        return $stats ?: [
            'total_syncs' => 0,
            'successful_syncs' => 0,
            'failed_syncs' => 0,
            'last_sync' => null
        ];
    }

    /**
     * Log CRM sync operation
     */
    public function logSyncOperation(string $platform_id, string $operation, bool $success, array $data = []): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'puntwork_crm_sync_log';

        $wpdb->insert(
            $table_name,
            [
                'platform_id' => $platform_id,
                'operation' => $operation,
                'success' => $success ? 1 : 0,
                'data' => json_encode($data),
                'created_at' => current_time('mysql')
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );
    }
}
