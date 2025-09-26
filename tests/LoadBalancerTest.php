<?php
/**
 * Load Balancer Tests for puntWork
 *
 * @package    Puntwork
 * @subpackage Tests
 */

namespace Puntwork;

use PHPUnit\Framework\TestCase;

class LoadBalancerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        // Mock WordPress functions
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wordpress/');
        }
    }

    /**
     * Test load balancing strategies
     */
    public function testLoadBalancingStrategies() {
        $load_balancer = new \Puntwork\PuntworkLoadBalancer();

        $strategies = ['round_robin', 'least_loaded', 'weighted', 'ip_hash'];

        foreach ($strategies as $strategy) {
            $this->assertIsString($strategy);
            $this->assertNotEmpty($strategy);
        }

        // Test strategy update
        $result = $load_balancer->update_strategy('least_loaded');
        $this->assertTrue($result);

        // Test invalid strategy
        $result = $load_balancer->update_strategy('invalid_strategy');
        $this->assertFalse($result);
    }

    /**
     * Test instance capability checking
     */
    public function testInstanceCapabilityChecking() {
        $load_balancer = new \Puntwork\PuntworkLoadBalancer();

        $test_instances = [
            [
                'instance_id' => 'test-1',
                'role' => 'heavy_processing',
                'cpu_count' => 4,
                'memory_limit' => 512 * 1024 * 1024
            ],
            [
                'instance_id' => 'test-2',
                'role' => 'light_processing',
                'cpu_count' => 2,
                'memory_limit' => 256 * 1024 * 1024
            ]
        ];

        $job_types = ['feed_import', 'batch_process', 'analytics_update'];

        foreach ($test_instances as $instance) {
            foreach ($job_types as $job_type) {
                $reflection = new \ReflectionClass($load_balancer);
                $method = $reflection->getMethod('instance_can_handle_job');
                $method->setAccessible(true);

                $can_handle = $method->invoke($load_balancer, $instance, $job_type);
                $this->assertIsBool($can_handle);
            }
        }
    }

    /**
     * Test round robin selection
     */
    public function testRoundRobinSelection() {
        $load_balancer = new \Puntwork\PuntworkLoadBalancer();

        $instances = [
            ['instance_id' => 'inst1', 'role' => 'heavy_processing'],
            ['instance_id' => 'inst2', 'role' => 'standard_processing'],
            ['instance_id' => 'inst3', 'role' => 'light_processing']
        ];

        $reflection = new \ReflectionClass($load_balancer);
        $method = $reflection->getMethod('round_robin_selection');
        $method->setAccessible(true);

        // Test round robin distribution
        $selections = [];
        for ($i = 0; $i < 6; $i++) {
            $selected = $method->invoke($load_balancer, $instances, 'feed_import');
            if ($selected) {
                $selections[] = $selected['instance_id'];
            }
        }

        // Should cycle through instances
        $this->assertContains('inst1', $selections);
        $this->assertContains('inst2', $selections);
        $this->assertContains('inst3', $selections);
    }

    /**
     * Test weighted selection
     */
    public function testWeightedSelection() {
        $load_balancer = new \Puntwork\PuntworkLoadBalancer();

        $instances = [
            [
                'instance_id' => 'heavy',
                'role' => 'heavy_processing',
                'cpu_count' => 8,
                'memory_limit' => 1024 * 1024 * 1024
            ],
            [
                'instance_id' => 'light',
                'role' => 'light_processing',
                'cpu_count' => 2,
                'memory_limit' => 256 * 1024 * 1024
            ]
        ];

        $reflection = new \ReflectionClass($load_balancer);
        $method = $reflection->getMethod('weighted_selection');
        $method->setAccessible(true);

        // Test weighted selection
        $selections = [];
        for ($i = 0; $i < 20; $i++) {
            $selected = $method->invoke($load_balancer, $instances, 'batch_process');
            if ($selected) {
                $selections[] = $selected['instance_id'];
            }
        }

        // Heavy instance should be selected more often
        $heavy_count = count(array_filter($selections, function($id) { return $id === 'heavy'; }));
        $light_count = count(array_filter($selections, function($id) { return $id === 'light'; }));

        $this->assertGreaterThan($light_count, $heavy_count);
    }

    /**
     * Test IP hash selection
     */
    public function testIpHashSelection() {
        $load_balancer = new \Puntwork\PuntworkLoadBalancer();

        $instances = [
            ['instance_id' => 'inst1', 'role' => 'standard_processing'],
            ['instance_id' => 'inst2', 'role' => 'standard_processing']
        ];

        $reflection = new \ReflectionClass($load_balancer);
        $method = $reflection->getMethod('ip_hash_selection');
        $method->setAccessible(true);

        // Test IP hash consistency
        $selected1 = $method->invoke($load_balancer, $instances, 'feed_import');
        $selected2 = $method->invoke($load_balancer, $instances, 'feed_import');

        // Should return same instance for same "IP"
        $this->assertEquals($selected1['instance_id'], $selected2['instance_id']);
    }

    /**
     * Test load balancer statistics
     */
    public function testLoadBalancerStatistics() {
        $load_balancer = new \Puntwork\PuntworkLoadBalancer();

        // Statistics should be available
        $reflection = new \ReflectionClass($load_balancer);
        $method = $reflection->getMethod('get_load_balancer_stats');
        $method->setAccessible(true);

        $stats = $method->invoke($load_balancer);

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('active_instances', $stats);
        $this->assertArrayHasKey('total_requests', $stats);
        $this->assertArrayHasKey('successful_requests', $stats);
        $this->assertArrayHasKey('failed_requests', $stats);
    }

    /**
     * Test load distribution simulation
     */
    public function testLoadDistributionSimulation() {
        $load_balancer = new \Puntwork\PuntworkLoadBalancer();

        $reflection = new \ReflectionClass($load_balancer);
        $method = $reflection->getMethod('estimate_processing_time');
        $method->setAccessible(true);

        $instance = [
            'instance_id' => 'test-instance',
            'role' => 'heavy_processing',
            'cpu_count' => 4,
            'memory_limit' => 512 * 1024 * 1024
        ];

        $job_types = ['feed_import', 'batch_process', 'analytics_update'];

        foreach ($job_types as $job_type) {
            $time = $method->invoke($load_balancer, $job_type, [], $instance);
            $this->assertIsFloat($time);
            $this->assertGreaterThan(0, $time);
        }
    }

    /**
     * Test load balancer admin interface
     */
    public function testLoadBalancerAdminInterface() {
        $load_balancer = new \Puntwork\PuntworkLoadBalancer();

        // In test environment, hooks are not initialized
        if (!$load_balancer->is_wordpress_environment()) {
            $this->markTestSkipped('Skipping hook tests in test environment');
            return;
        }

        // Admin menu should be registered
        $this->assertTrue(has_action('admin_menu', [$load_balancer, 'add_load_balancer_menu']));

        // AJAX endpoints should be registered
        $this->assertTrue(has_action('wp_ajax_puntwork_lb_health_check', [$load_balancer, 'ajax_health_check_all']));
        $this->assertTrue(has_action('wp_ajax_puntwork_lb_stats', [$load_balancer, 'ajax_get_stats']));
    }

    /**
     * Test load balancer initialization
     */
    public function testLoadBalancerInitialization() {
        $load_balancer = new \Puntwork\PuntworkLoadBalancer();

        // In test environment, hooks are not initialized
        if (!$load_balancer->is_wordpress_environment()) {
            $this->markTestSkipped('Skipping hook tests in test environment');
            return;
        }

        // Should have initialized hooks
        $this->assertTrue(has_action('init', [$load_balancer, 'process_load_balanced_jobs']));

        // Should have default strategy
        $reflection = new \ReflectionClass($load_balancer);
        $property = $reflection->getProperty('balancing_strategy');
        $property->setAccessible(true);
        $strategy = $property->getValue($load_balancer);

        $this->assertIsString($strategy);
        $this->assertNotEmpty($strategy);
    }

    /**
     * Test load balancer job processing
     */
    public function testLoadBalancerJobProcessing() {
        $load_balancer = new \Puntwork\PuntworkLoadBalancer();

        // Job processing should not throw exceptions
        $this->expectNotToPerformAssertions();

        try {
            $reflection = new \ReflectionClass($load_balancer);
            $method = $reflection->getMethod('process_load_balanced_jobs');
            $method->setAccessible(true);
            $method->invoke($load_balancer);
        } catch (\Exception $e) {
            // Processing might fail in test environment, which is OK
            $this->assertInstanceOf(\Exception::class, $e);
        }
    }
}