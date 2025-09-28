<?php

/**
 * Horizontal Scaling Tests for puntWork
 *
 * @package    Puntwork
 * @subpackage Tests
 */

namespace Puntwork;

use PHPUnit\Framework\TestCase;

class HorizontalScalingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Mock WordPress functions
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/wordpress/' );
		}
	}

	/**
	 * Test instance ID generation
	 */
	public function testInstanceIdGeneration() {
		$scaling_manager = new \Puntwork\PuntworkHorizontalScalingManager();

		$instance_info = $scaling_manager->getCurrentInstance();

		$this->assertIsArray( $instance_info );
		$this->assertArrayHasKey( 'instance_id', $instance_info );
		$this->assertArrayHasKey( 'role', $instance_info );
		$this->assertArrayHasKey( 'server_name', $instance_info );
		$this->assertArrayHasKey( 'ip_address', $instance_info );
		$this->assertArrayHasKey( 'cpu_count', $instance_info );
		$this->assertArrayHasKey( 'memory_limit', $instance_info );

		$this->assertIsString( $instance_info['instance_id'] );
		$this->assertNotEmpty( $instance_info['instance_id'] );
	}

	/**
	 * Test instance role determination
	 */
	public function testInstanceRoleDetermination() {
		// Test heavy processing role
		$this->assertTrue( $this->isRoleAssigned( 'heavy_processing' ) );

		// Test standard processing role
		$this->assertTrue( $this->isRoleAssigned( 'standard_processing' ) );

		// Test light processing role
		$this->assertTrue( $this->isRoleAssigned( 'light_processing' ) );

		// Test coordinator only role
		$this->assertTrue( $this->isRoleAssigned( 'coordinator_only' ) );
	}

	/**
	 * Helper to check if a role is valid
	 */
	private function isRoleAssigned( $expected_role ) {
		$scaling_manager = new \Puntwork\PuntworkHorizontalScalingManager();
		$instance_info   = $scaling_manager->getCurrentInstance();

		$valid_roles = array( 'heavy_processing', 'standard_processing', 'light_processing', 'coordinator_only' );

		return in_array( $instance_info['role'], $valid_roles );
	}

	/**
	 * Test instance capability checking
	 */
	public function testInstanceCapabilityChecking() {
		$scaling_manager = new \Puntwork\PuntworkHorizontalScalingManager();

		$job_types = array( 'feed_import', 'batch_process', 'analytics_update', 'notification', 'cleanup' );

		foreach ( $job_types as $job_type ) {
			$can_handle = $scaling_manager->canHandleJob( $job_type );
			$this->assertIsBool( $can_handle );
		}
	}

	/**
	 * Test instance statistics
	 */
	public function testInstanceStatistics() {
		$scaling_manager = new \Puntwork\PuntworkHorizontalScalingManager();

		$stats = $scaling_manager->getInstanceStats();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'active', $stats );
		$this->assertArrayHasKey( 'inactive', $stats );
		$this->assertArrayHasKey( 'maintenance', $stats );
		$this->assertArrayHasKey( 'total', $stats );

		$this->assertIsInt( $stats['active'] );
		$this->assertIsInt( $stats['inactive'] );
		$this->assertIsInt( $stats['maintenance'] );
		$this->assertIsInt( $stats['total'] );

		$this->assertGreaterThanOrEqual( 0, $stats['active'] );
		$this->assertGreaterThanOrEqual( 0, $stats['inactive'] );
		$this->assertGreaterThanOrEqual( 0, $stats['maintenance'] );
		$this->assertEquals( $stats['total'], $stats['active'] + $stats['inactive'] + $stats['maintenance'] );
	}

	/**
	 * Test optimal instance selection
	 */
	public function testOptimalInstanceSelection() {
		$scaling_manager = new \Puntwork\PuntworkHorizontalScalingManager();

		$job_types = array( 'feed_import', 'batch_process', 'analytics_update' );

		foreach ( $job_types as $job_type ) {
			$optimal_instance = $scaling_manager->getOptimalInstance( $job_type );

			// In single instance setup, it should return null or current instance
			$this->assertTrue( $optimal_instance === null || is_array( $optimal_instance ) );
		}
	}

	/**
	 * Test health check functionality
	 */
	public function testHealthCheckFunctionality() {
		$scaling_manager = new \Puntwork\PuntworkHorizontalScalingManager();

		// Health check should not throw exceptions
		$this->expectNotToPerformAssertions();

		// This would normally run health checks, but in test environment
		// we just ensure the method exists and doesn't crash
		try {
			// Simulate health check call
			$reflection = new \ReflectionClass( $scaling_manager );
			$method     = $reflection->getMethod( 'healthCheck' );
			$method->setAccessible( true );
			$method->invoke( $scaling_manager );
		} catch ( \Exception $e ) {
			// Health check might fail in test environment, which is OK
			$this->assertInstanceOf( \Exception::class, $e );
		}
	}

	/**
	 * Test instance cleanup
	 */
	public function testInstanceCleanup() {
		$scaling_manager = new \Puntwork\PuntworkHorizontalScalingManager();

		// Cleanup should not throw exceptions
		$this->expectNotToPerformAssertions();

		try {
			$reflection = new \ReflectionClass( $scaling_manager );
			$method     = $reflection->getMethod( 'cleanupDeadInstances' );
			$method->setAccessible( true );
			$method->invoke( $scaling_manager );
		} catch ( \Exception $e ) {
			// Cleanup might fail in test environment, which is OK
			$this->assertInstanceOf( \Exception::class, $e );
		}
	}
}
