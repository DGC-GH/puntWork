<?php
/**
 * Tests for scheduling functionality
 */

namespace Puntwork;

use Puntwork\TestCase;

/**
 * Scheduling test class
 */
class SchedulingTest extends TestCase {

    /**
     * Test scheduling functions exist
     */
    public function test_scheduling_functions_exist() {
        $this->assertTrue(function_exists('Puntwork\\init_scheduling'));
        $this->assertTrue(function_exists('Puntwork\\get_scheduling_settings'));
        $this->assertTrue(function_exists('Puntwork\\update_scheduling_settings'));
        $this->assertTrue(function_exists('Puntwork\\get_scheduling_history'));
    }

    /**
     * Test init_scheduling
     */
    public function test_init_scheduling() {
        // Test that function runs without error
        $this->assertTrue(init_scheduling());
    }

    /**
     * Test get_scheduling_settings returns defaults
     */
    public function test_get_scheduling_settings_defaults() {
        $settings = get_scheduling_settings();

        $this->assertIsArray($settings);
        $this->assertArrayHasKey('enabled', $settings);
        $this->assertArrayHasKey('frequency', $settings);
        $this->assertArrayHasKey('hour', $settings);
        $this->assertArrayHasKey('minute', $settings);

        // Default values
        $this->assertFalse($settings['enabled']);
        $this->assertEquals('daily', $settings['frequency']);
        $this->assertEquals(9, $settings['hour']);
        $this->assertEquals(0, $settings['minute']);
    }

    /**
     * Test update_scheduling_settings
     */
    public function test_update_scheduling_settings() {
        $new_settings = [
            'enabled' => true,
            'frequency' => 'hourly',
            'hour' => 14,
            'minute' => 30
        ];

        $result = update_scheduling_settings($new_settings);
        $this->assertTrue($result);

        // Verify settings were saved
        $saved_settings = get_scheduling_settings();
        $this->assertEquals($new_settings['enabled'], $saved_settings['enabled']);
        $this->assertEquals($new_settings['frequency'], $saved_settings['frequency']);
        $this->assertEquals($new_settings['hour'], $saved_settings['hour']);
        $this->assertEquals($new_settings['minute'], $saved_settings['minute']);
    }

    /**
     * Test scheduling activation/deactivation
     */
    public function test_scheduling_activation() {
        // Enable scheduling
        update_scheduling_settings(['enabled' => true, 'frequency' => 'hourly']);
        init_scheduling();

        // Check that cron is scheduled
        $next_run = wp_next_scheduled('job_import_cron');
        $this->assertNotFalse($next_run);

        // Disable scheduling
        update_scheduling_settings(['enabled' => false]);
        init_scheduling();

        // Check that cron is cleared
        $this->assertFalse(wp_next_scheduled('job_import_cron'));
    }

    /**
     * Test get_scheduling_history
     */
    public function test_get_scheduling_history() {
        $history = get_scheduling_history();

        $this->assertIsArray($history);
        // Should return empty array initially
        $this->assertEmpty($history);
    }

    /**
     * Test scheduling history recording
     */
    public function test_scheduling_history_recording() {
        // Simulate a completed import
        $import_data = [
            'start_time' => time() - 3600,
            'end_time' => time(),
            'processed' => 100,
            'published' => 80,
            'updated' => 15,
            'skipped' => 5,
            'success' => true
        ];

        // This would normally be called by the import process
        // For testing, we'll simulate the history update
        $history = get_option('job_import_history', []);
        $history[] = $import_data;
        update_option('job_import_history', $history);

        $retrieved_history = get_scheduling_history();
        $this->assertNotEmpty($retrieved_history);
        $this->assertCount(1, $retrieved_history);

        $last_run = $retrieved_history[0];
        $this->assertEquals(100, $last_run['processed']);
        $this->assertEquals(80, $last_run['published']);
        $this->assertTrue($last_run['success']);
    }

    /**
     * Test cron schedules include puntwork schedules
     */
    public function test_cron_schedules_include_puntwork() {
        $schedules = wp_get_schedules();

        // Check that puntwork schedules are registered
        $this->assertArrayHasKey('puntwork_hourly', $schedules);
        $this->assertArrayHasKey('puntwork_3hours', $schedules);
        $this->assertArrayHasKey('puntwork_6hours', $schedules);
        $this->assertArrayHasKey('puntwork_12hours', $schedules);

        // Verify intervals
        $this->assertEquals(HOUR_IN_SECONDS, $schedules['puntwork_hourly']['interval']);
        $this->assertEquals(3 * HOUR_IN_SECONDS, $schedules['puntwork_3hours']['interval']);
        $this->assertEquals(6 * HOUR_IN_SECONDS, $schedules['puntwork_6hours']['interval']);
        $this->assertEquals(12 * HOUR_IN_SECONDS, $schedules['puntwork_12hours']['interval']);
    }

    /**
     * Test scheduling with different frequencies
     */
    public function test_scheduling_different_frequencies() {
        $frequencies = ['hourly', '3hours', '6hours', '12hours', 'daily'];

        foreach ($frequencies as $frequency) {
            update_scheduling_settings([
                'enabled' => true,
                'frequency' => $frequency
            ]);
            init_scheduling();

            // Check that cron is scheduled
            $this->assertNotFalse(wp_next_scheduled('job_import_cron'));

            // Disable for next iteration
            update_scheduling_settings(['enabled' => false]);
            init_scheduling();
            $this->assertFalse(wp_next_scheduled('job_import_cron'));
        }
    }

    /**
     * Test scheduling validation
     */
    public function test_scheduling_validation() {
        // Test invalid frequency
        $result = update_scheduling_settings([
            'enabled' => true,
            'frequency' => 'invalid_frequency'
        ]);

        // Should still work but use default
        $this->assertTrue($result);
        $settings = get_scheduling_settings();
        $this->assertEquals('daily', $settings['frequency']); // Should default

        // Test invalid hour
        $result = update_scheduling_settings([
            'enabled' => true,
            'hour' => 25 // Invalid hour
        ]);

        $this->assertTrue($result);
        $settings = get_scheduling_settings();
        $this->assertEquals(9, $settings['hour']); // Should use default
    }
}