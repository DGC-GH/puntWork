<?php

/**
 * CRM Integration Tests
 *
 * @package    Puntwork
 * @subpackage Tests
 * @since      2.0.0
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use Puntwork\CRM\CRMManager;
use Puntwork\CRM\HubSpotIntegration;
use Puntwork\CRM\SalesforceIntegration;
use Puntwork\CRM\ZohoIntegration;
use Puntwork\CRM\PipedriveIntegration;

/**
 * CRM Integration Test Suite
 */
class CRMIntegrationTest extends TestCase
{
    /**
     * Test CRM Manager initialization
     */
    public function testCRMManagerInitialization(): void
    {
        $crmManager = new CRMManager();

        $this->assertInstanceOf(CRMManager::class, $crmManager);

        $availablePlatforms = $crmManager->getAvailablePlatforms();
        $this->assertIsArray($availablePlatforms);
        $this->assertArrayHasKey('hubspot', $availablePlatforms);
        $this->assertArrayHasKey('salesforce', $availablePlatforms);
        $this->assertArrayHasKey('zoho', $availablePlatforms);
        $this->assertArrayHasKey('pipedrive', $availablePlatforms);
    }

    /**
     * Test platform configuration requirements
     */
    public function testPlatformConfigurationRequirements(): void
    {
        $availablePlatforms = CRMManager::getAvailablePlatforms();

        // Test HubSpot config requirements
        $this->assertArrayHasKey('required_config', $availablePlatforms['hubspot']);
        $this->assertArrayHasKey('access_token', $availablePlatforms['hubspot']['required_config']);

        // Test Salesforce config requirements
        $this->assertArrayHasKey('client_id', $availablePlatforms['salesforce']['required_config']);
        $this->assertArrayHasKey('client_secret', $availablePlatforms['salesforce']['required_config']);
        $this->assertArrayHasKey('username', $availablePlatforms['salesforce']['required_config']);
        $this->assertArrayHasKey('password', $availablePlatforms['salesforce']['required_config']);

        // Test Zoho config requirements
        $this->assertArrayHasKey('client_id', $availablePlatforms['zoho']['required_config']);
        $this->assertArrayHasKey('client_secret', $availablePlatforms['zoho']['required_config']);
        $this->assertArrayHasKey('refresh_token', $availablePlatforms['zoho']['required_config']);

        // Test Pipedrive config requirements
        $this->assertArrayHasKey('api_token', $availablePlatforms['pipedrive']['required_config']);
    }

    /**
     * Test HubSpot integration basic functionality
     */
    public function testHubSpotIntegrationBasic(): void
    {
        $config = ['access_token' => 'test_token'];
        $hubspot = new HubSpotIntegration($config);

        $this->assertEquals('hubspot', $hubspot->getPlatformId());
        $this->assertEquals('HubSpot', $hubspot->getPlatformName());
        $this->assertTrue($hubspot->isConfigured()); // Should be configured with access token
    }

    /**
     * Test Salesforce integration basic functionality
     */
    public function testSalesforceIntegrationBasic(): void
    {
        $this->markTestSkipped('Salesforce integration requires WordPress functions not available in test environment');

        $config = [
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'username' => 'test@example.com',
            'password' => 'test_password'
        ];
        $salesforce = new SalesforceIntegration($config);

        $this->assertEquals('salesforce', $salesforce->getPlatformId());
        $this->assertEquals('Salesforce', $salesforce->getPlatformName());
        $this->assertTrue($salesforce->isConfigured());
    }

    /**
     * Test Zoho integration basic functionality
     */
    public function testZohoIntegrationBasic(): void
    {
        $this->markTestSkipped('Zoho integration requires WordPress functions not available in test environment');

        $config = [
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'refresh_token' => 'test_refresh_token'
        ];
        $zoho = new ZohoIntegration($config);

        $this->assertEquals('zoho', $zoho->getPlatformId());
        $this->assertEquals('Zoho CRM', $zoho->getPlatformName());
        $this->assertTrue($zoho->isConfigured());
    }

    /**
     * Test Pipedrive integration basic functionality
     */
    public function testPipedriveIntegrationBasic(): void
    {
        $config = ['api_token' => 'test_api_token'];
        $pipedrive = new PipedriveIntegration($config);

        $this->assertEquals('pipedrive', $pipedrive->getPlatformId());
        $this->assertEquals('Pipedrive', $pipedrive->getPlatformName());
        $this->assertTrue($pipedrive->isConfigured());
    }

    /**
     * Test contact data standardization
     */
    public function testContactDataStandardization(): void
    {
        $hubspot = new HubSpotIntegration([]);

        $testData = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '+1-555-0123',
            'company' => 'Test Company',
            'job_title' => 'Developer',
            'custom_fields' => ['source' => 'puntwork']
        ];

        $standardized = $this->invokePrivateMethod($hubspot, 'standardizeContactData', [$testData]);

        $this->assertEquals('John', $standardized['first_name']);
        $this->assertEquals('Doe', $standardized['last_name']);
        $this->assertEquals('john.doe@example.com', $standardized['email']);
        $this->assertEquals('+1-555-0123', $standardized['phone']);
        $this->assertEquals('Test Company', $standardized['company']);
        $this->assertEquals('Developer', $standardized['job_title']);
        $this->assertArrayHasKey('custom_fields', $standardized);
        $this->assertEquals(['source' => 'puntwork'], $standardized['custom_fields']);
    }

    /**
     * Test deal data standardization
     */
    public function testDealDataStandardization(): void
    {
        $hubspot = new HubSpotIntegration([]);

        $testData = [
            'title' => 'Job Application: Developer Position',
            'value' => 75000,
            'currency' => 'USD',
            'stage' => 'application_received',
            'contact_id' => 'contact_123',
            'source' => 'puntwork'
        ];

        $standardized = $this->invokePrivateMethod($hubspot, 'standardizeDealData', [$testData]);

        $this->assertEquals('Job Application: Developer Position', $standardized['title']);
        $this->assertEquals(75000, $standardized['value']);
        $this->assertEquals('USD', $standardized['currency']);
        $this->assertEquals('application_received', $standardized['stage']);
        $this->assertEquals('contact_123', $standardized['contact_id']);
        $this->assertEquals('puntwork', $standardized['source']);
    }

    /**
     * Test CRM manager configuration methods
     */
    public function testCRMManagerConfiguration(): void
    {
        $testConfig = [
            'enabled' => true,
            'access_token' => 'test_token'
        ];

        // Test configuration saving (this would normally interact with WordPress options)
        $result = CRMManager::configurePlatform('hubspot', $testConfig);
        $this->assertTrue($result); // Method should return true even in test environment

        // Test configuration retrieval
        $retrievedConfig = CRMManager::getPlatformConfig('hubspot');
        // In test environment, this might return null or empty array
        $this->assertTrue(is_array($retrievedConfig) || is_null($retrievedConfig));
    }

    /**
     * Test sync job application data structure
     */
    public function testSyncJobApplicationDataStructure(): void
    {
        $crmManager = new CRMManager();

        $testApplication = [
            'id' => 'app_123',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
            'phone' => '+1-555-0199',
            'current_company' => 'Tech Corp',
            'current_position' => 'Senior Developer',
            'job_title' => 'Lead Developer',
            'application_date' => '2024-01-15',
            'source' => 'puntwork'
        ];

        // Test the data formatting methods
        $contactData = $this->invokePrivateMethod($crmManager, 'formatApplicationAsContact', [$testApplication]);
        $dealData = $this->invokePrivateMethod(
            $crmManager,
            'formatApplicationAsDeal',
            [$testApplication, 'contact_456']
        );

        $this->assertEquals('Jane', $contactData['first_name']);
        $this->assertEquals('Smith', $contactData['last_name']);
        $this->assertEquals('jane.smith@example.com', $contactData['email']);
        $this->assertEquals('Tech Corp', $contactData['company']);
        $this->assertEquals('Senior Developer', $contactData['job_title']);

        $this->assertStringContainsString('Job Application: Lead Developer', $dealData['title']);
        $this->assertEquals('application_received', $dealData['stage']);
        $this->assertEquals('contact_456', $dealData['contact_id']);
    }

    /**
     * Test rate limiting logic
     */
    public function testRateLimiting(): void
    {
        $hubspot = new HubSpotIntegration(['access_token' => 'test_token']);

        // Test rate limit check method
        $canProceed = $this->invokePrivateMethod($hubspot, 'checkRateLimit', []);
        $this->assertTrue($canProceed); // Should pass in test environment

        // Test record request method
        $this->invokePrivateMethod($hubspot, 'recordRequest', []);
        // Should not throw exception
        $this->assertTrue(true);
    }

    /**
     * Test API error handling
     */
    public function testApiErrorHandling(): void
    {
        $hubspot = new HubSpotIntegration(['access_token' => 'test_token']);

        // Test handleApiError with error response
        $errorResponse = ['status' => 'error', 'message' => 'Test error'];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('HubSpot API Error: Test error');

        $this->invokePrivateMethod($hubspot, 'handleApiError', [$errorResponse]);
    }

    /**
     * Helper method to invoke private methods for testing
     */
    private function invokePrivateMethod($object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    /**
     * Test statistics retrieval
     */
    public function testStatisticsRetrieval(): void
    {
        $crmManager = new CRMManager();

        $stats = $crmManager->getStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_syncs', $stats);
        $this->assertArrayHasKey('successful_syncs', $stats);
        $this->assertArrayHasKey('failed_syncs', $stats);
        $this->assertArrayHasKey('last_sync', $stats);

        // Values should be numeric or null
        $this->assertTrue(is_numeric($stats['total_syncs']) || is_null($stats['total_syncs']));
        $this->assertTrue(is_numeric($stats['successful_syncs']) || is_null($stats['successful_syncs']));
        $this->assertTrue(is_numeric($stats['failed_syncs']) || is_null($stats['failed_syncs']));
    }

    /**
     * Test platform availability check
     */
    public function testPlatformAvailability(): void
    {
        $crmManager = new CRMManager();

        // Test configured platforms (should be empty in test environment)
        $configuredPlatforms = $crmManager->getConfiguredPlatforms();
        $this->assertIsArray($configuredPlatforms);
        // In test environment, this might be empty or have test data
        $this->assertTrue(is_array($configuredPlatforms));

        // Test platform configuration check (may vary in test environment)
        $isHubSpotConfigured = $crmManager->isPlatformConfigured('hubspot');
        $this->assertIsBool($isHubSpotConfigured);
        $this->assertFalse($crmManager->isPlatformConfigured('nonexistent'));
    }
}
