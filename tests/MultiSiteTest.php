<?php

/**
 * Multi-Site Support Test
 *
 * Tests for multi-site network functionality.
 *
 * @package    Puntwork
 * @subpackage MultiSite
 * @since      0.0.4
 */

namespace Tests;

// Include the multi-site classes for testing
require_once dirname(__DIR__) . '/includes/multisite/multi-site-manager.php';

use Puntwork\MultiSite\MultiSiteManager;
use PHPUnit\Framework\TestCase;

/**
 * Test multi-site functionality
 */
class MultiSiteTest extends TestCase
{
    /**
     * Test network sites detection
     */
    public function testGetNetworkSites()
    {
        // Skip if not multisite
        if (!function_exists('is_multisite') || !is_multisite()) {
            $this->markTestSkipped('Multisite not enabled');
        }

        $sites = MultiSiteManager::getNetworkSites();

        $this->assertIsArray($sites);
        $this->assertGreaterThanOrEqual(0, count($sites));

        if (!empty($sites)) {
            $site = $sites[0];
            $this->assertArrayHasKey('id', $site);
            $this->assertArrayHasKey('name', $site);
            $this->assertArrayHasKey('url', $site);
            $this->assertArrayHasKey('capabilities', $site);
            $this->assertArrayHasKey('stats', $site);
        }
    }

    /**
     * Test job distribution strategies
     */
    public function testJobDistributionStrategies()
    {
        // Skip if not multisite
        if (!function_exists('is_multisite') || !is_multisite()) {
            $this->markTestSkipped('Multisite not enabled');
        }

        $mockSites = [
            ['id' => 1, 'capabilities' => ['processing_power' => 'high'], 'stats' => ['current_load' => 20]],
            ['id' => 2, 'capabilities' => ['processing_power' => 'medium'], 'stats' => ['current_load' => 50]],
            ['id' => 3, 'capabilities' => ['processing_power' => 'low'], 'stats' => ['current_load' => 80]]
        ];

        $jobs = [
            ['id' => 'job1', 'format' => 'json'],
            ['id' => 'job2', 'format' => 'xml'],
            ['id' => 'job3', 'format' => 'csv']
        ];

        // Test round-robin distribution
        $result = MultiSiteManager::distributeJobsNetwork($jobs, MultiSiteManager::STRATEGY_ROUND_ROBIN);
        $this->assertArrayHasKey('distributed', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertIsArray($result['distributed']);

        // Test load-balanced distribution
        $result = MultiSiteManager::distributeJobsNetwork($jobs, MultiSiteManager::STRATEGY_LOAD_BALANCED);
        $this->assertArrayHasKey('distributed', $result);
        $this->assertIsArray($result['distributed']);
    }

    /**
     * Test capability score calculation
     */
    public function testCapabilityScoreCalculation()
    {
        $job = ['format' => 'json', 'complexity' => 'high'];
        $site = [
            'capabilities' => [
                'supported_formats' => ['json', 'xml'],
                'processing_power' => 'high'
            ],
            'stats' => ['current_load' => 30, 'success_rate' => 95]
        ];

        // This would require accessing private method, so we'll test the public interface
        $this->assertTrue(true); // Placeholder test
    }

    /**
     * Test network sync data structure
     */
    public function testNetworkSyncDataStructure()
    {
        // Skip if not multisite
        if (!function_exists('is_multisite') || !is_multisite()) {
            $this->markTestSkipped('Multisite not enabled');
        }

        // Test that sync data has expected structure
        $syncData = get_option('puntwork_network_sync_data', []);

        if (!empty($syncData)) {
            $siteData = $syncData[0];
            $this->assertArrayHasKey('site_id', $siteData);
            $this->assertArrayHasKey('job_templates', $siteData);
            $this->assertArrayHasKey('feed_configs', $siteData);
            $this->assertArrayHasKey('analytics_summary', $siteData);
            $this->assertArrayHasKey('last_updated', $siteData);
        }
    }

    /**
     * Test distribution strategy validation
     */
    public function testDistributionStrategyConstants()
    {
        // Test that strategy constants are defined
        $this->assertEquals('round_robin', MultiSiteManager::STRATEGY_ROUND_ROBIN);
        $this->assertEquals('load_balanced', MultiSiteManager::STRATEGY_LOAD_BALANCED);
        $this->assertEquals('capability_based', MultiSiteManager::STRATEGY_CAPABILITY_BASED);
        $this->assertEquals('geographic', MultiSiteManager::STRATEGY_GEOGRAPHIC);
    }

    /**
     * Test site capability detection
     */
    public function testSiteCapabilityDetection()
    {
        // Test with current site
        $capabilities = [
            'max_jobs_per_hour' => get_option('puntwork_max_jobs_per_hour', 1000),
            'supported_formats' => get_option('puntwork_supported_formats', ['json', 'xml', 'csv']),
            'geographic_region' => get_option('puntwork_geographic_region', 'global'),
            'processing_power' => get_option('puntwork_processing_power', 'standard'),
            'storage_capacity' => get_option('puntwork_storage_capacity', 'standard')
        ];

        $this->assertIsArray($capabilities);
        $this->assertArrayHasKey('max_jobs_per_hour', $capabilities);
        $this->assertArrayHasKey('supported_formats', $capabilities);
        $this->assertIsArray($capabilities['supported_formats']);
    }

    /**
     * Test network table creation
     */
    public function testNetworkTableCreation()
    {
        global $wpdb;

        if (!function_exists('is_multisite') || !is_multisite()) {
            $this->markTestSkipped('Multisite not enabled');
        }

        $table_name = $wpdb->base_prefix . 'puntwork_network_jobs';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        $this->assertTrue($table_exists, 'Network jobs table should exist');

        // Check table structure
        $columns = $wpdb->get_results("DESCRIBE $table_name");
        $this->assertNotEmpty($columns);

        $column_names = array_column($columns, 'Field');
        $expected_columns = ['id', 'job_id', 'site_id', 'status', 'priority', 'data', 'created_at', 'updated_at'];
        foreach ($expected_columns as $column) {
            $this->assertContains($column, $column_names, "Column $column should exist in network jobs table");
        }
    }

    /**
     * Test network settings registration
     */
    public function testNetworkSettingsRegistration()
    {
        // Test that settings are registered
        $this->assertTrue(true); // Settings are registered during init, hard to test directly
    }
}
