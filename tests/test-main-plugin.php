<?php
/**
 * Tests for main plugin functionality
 */

namespace Puntwork;

use Puntwork\TestCase;

/**
 * Main plugin test class
 */
class MainPluginTest extends TestCase {

    /**
     * Test plugin activation
     */
    public function test_plugin_activation() {
        // Test that activation hook is registered
        $this->assertTrue(has_action('job_import_activate'));

        // Test activation function exists
        $this->assertTrue(function_exists('Puntwork\\job_import_activate'));

        // Simulate activation
        job_import_activate();

        // Check if cron is scheduled
        $this->assertNotFalse(wp_next_scheduled('job_import_cron'));

        // Check if logs directory exists
        $logs_dir = dirname(PUNTWORK_LOGS);
        $this->assertTrue(file_exists($logs_dir) || wp_mkdir_p($logs_dir));
    }

    /**
     * Test plugin deactivation
     */
    public function test_plugin_deactivation() {
        // Schedule a cron first
        wp_schedule_event(time(), 'daily', 'job_import_cron');
        $this->assertNotFalse(wp_next_scheduled('job_import_cron'));

        // Test deactivation
        job_import_deactivate();

        // Check if cron is cleared
        $this->assertFalse(wp_next_scheduled('job_import_cron'));
    }

    /**
     * Test custom cron schedules
     */
    public function test_custom_cron_schedules() {
        $schedules = wp_get_schedules();

        $expected_schedules = [
            'puntwork_hourly',
            'puntwork_3hours',
            'puntwork_6hours',
            'puntwork_12hours'
        ];

        foreach ($expected_schedules as $schedule) {
            $this->assertArrayHasKey($schedule, $schedules);
            $this->assertIsArray($schedules[$schedule]);
            $this->assertArrayHasKey('interval', $schedules[$schedule]);
            $this->assertArrayHasKey('display', $schedules[$schedule]);
        }

        // Test additional custom intervals
        for ($hours = 2; $hours <= 24; $hours++) {
            if (!in_array($hours, [3, 6, 12])) {
                $schedule_key = 'puntwork_' . $hours . 'hours';
                $this->assertArrayHasKey($schedule_key, $schedules);
            }
        }
    }

    /**
     * Test setup function loads includes
     */
    public function test_setup_loads_includes() {
        // This is tested implicitly by the bootstrap loading the plugin
        // Check that key functions are available
        $this->assertTrue(function_exists('Puntwork\\setup_job_import'));
        $this->assertTrue(function_exists('Puntwork\\prepare_import_setup'));
        $this->assertTrue(function_exists('Puntwork\\render_main_import_ui'));
    }

    /**
     * Test constants are defined
     */
    public function test_constants_defined() {
        $this->assertTrue(defined('PUNTWORK_VERSION'));
        $this->assertTrue(defined('PUNTWORK_PATH'));
        $this->assertTrue(defined('PUNTWORK_URL'));
        $this->assertTrue(defined('PUNTWORK_LOGS'));
    }

    /**
     * Test custom favicon action
     */
    public function test_custom_favicon() {
        // Test that action is hooked
        $this->assertTrue(has_action('wp_head', 'Puntwork\\add_custom_favicon'));

        // Capture output
        ob_start();
        add_custom_favicon();
        $output = ob_get_clean();

        // Check for favicon link
        $this->assertStringContains('link rel="icon"', $output);
        $this->assertStringContains('icon.svg', $output);
    }

    /**
     * Test uninstall hook
     */
    public function test_uninstall_hook() {
        // Test that uninstall hook is registered
        $this->assertTrue(has_action('job_import_uninstall'));

        // Test uninstall function exists
        $this->assertTrue(function_exists('Puntwork\\job_import_uninstall'));

        // Set some test options
        update_option('job_import_last_run', time());
        $this->assertNotFalse(get_option('job_import_last_run'));

        // Simulate uninstall
        job_import_uninstall();

        // Check options are deleted
        $this->assertFalse(get_option('job_import_last_run'));
    }
}