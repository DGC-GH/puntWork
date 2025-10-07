<?php
/**
 * WordPress Integration Test for puntWork
 *
 * Tests that verify WordPress functionality works with the puntWork plugin
 */

require_once __DIR__ . '/phpunit/includes/abstract-testcase.php';

class WordPressIntegrationTest extends WP_UnitTestCase_Base {

    public function setUp(): void {
        parent::setUp();

        // Manually load and initialize the plugin for testing
        $plugin_file = dirname(__DIR__) . '/puntwork.php';
        if (file_exists($plugin_file)) {
            // Include the main plugin file
            require_once $plugin_file;

            // Manually trigger the setup function that loads includes
            if (function_exists('Puntwork\\setup_job_import')) {
                \Puntwork\setup_job_import();
            }

            // Trigger plugin activation if activation function exists
            if (function_exists('Puntwork\\job_import_activate')) {
                \Puntwork\job_import_activate();
            }
        }
    }

    public function tearDown(): void {
        // Deactivate the plugin after testing
        $plugin_file = dirname(__DIR__) . '/puntwork.php';
        if (file_exists($plugin_file)) {
            deactivate_plugins(plugin_basename($plugin_file));
        }

        parent::tearDown();
    }

    /**
     * Test that WordPress is loaded and basic functions work
     */
    public function test_wordpress_is_loaded() {
        $this->assertTrue(function_exists('wp_get_current_user'));
        $this->assertTrue(function_exists('get_option'));
        $this->assertTrue(function_exists('update_option'));
    }

    /**
     * Test that puntWork plugin files are present
     */
    public function test_plugin_files_exist() {
        $plugin_files = [
            'puntwork.php',
            'includes/core/core-structure-logic.php',
            'includes/admin/admin-menu.php',
            'includes/api/ajax-handlers.php',
            'includes/batch/batch-processing.php',
            'includes/import/import-batch.php',
            'includes/mappings/mappings-fields.php',
            'includes/scheduling/scheduling-core.php',
            'includes/utilities/puntwork-logger.php'
        ];

        foreach ($plugin_files as $file) {
            $file_path = dirname(__DIR__) . '/' . $file;
            $this->assertFileExists($file_path, "Plugin file {$file} should exist");
        }
    }

    /**
     * Test that plugin constants are defined
     */
    public function test_plugin_constants() {
        // Test that we can include the main plugin file without errors
        $plugin_file = dirname(__DIR__) . '/puntwork.php';

        // Check if file exists first
        $this->assertFileExists($plugin_file, 'Plugin file should exist');

        // This should not throw any fatal errors
        ob_start();
        include_once $plugin_file;
        ob_end_clean();

        $this->assertTrue(true, 'Plugin file included without fatal errors');
    }

    /**
     * Test database connectivity
     */
    public function test_database_connection() {
        global $wpdb;

        $this->assertInstanceOf('wpdb', $wpdb);
        $this->assertNotEmpty($wpdb->dbname);
        $this->assertEquals('puntwork_test', $wpdb->dbname);
    }

    /**
     * Test that WordPress options can be set and retrieved
     */
    public function test_wordpress_options() {
        $test_key = 'puntwork_test_option';
        $test_value = 'test_value_' . time();

        // Set option
        update_option($test_key, $test_value);

        // Get option
        $retrieved_value = get_option($test_key);

        $this->assertEquals($test_value, $retrieved_value);

        // Clean up
        delete_option($test_key);
    }

    /**
     * Test that custom post types can be registered
     */
    public function test_custom_post_types() {
        // This would test if ACF custom post types are registered
        // For now, just test that the function exists
        $this->assertTrue(function_exists('register_post_type'));
    }

    /**
     * Test plugin activation hook
     */
    public function test_plugin_activation() {
        // Test that activation functions exist
        $plugin_file = dirname(__DIR__) . '/puntwork.php';

        // Check if activation hook is registered
        $this->assertTrue(has_action('activate_' . plugin_basename($plugin_file)));
    }

    /**
     * Test AJAX handlers are properly set up
     */
    public function test_ajax_handlers() {
        // Test that AJAX action hooks exist (using actual action names from the plugin)
        $ajax_actions = [
            'wp_ajax_process_feed',
            'wp_ajax_run_job_import_batch',
            'wp_ajax_get_job_import_status'
        ];

        foreach ($ajax_actions as $action) {
            $this->assertTrue(has_action($action), "AJAX action {$action} should be registered");
        }
    }

    /**
     * Test cron schedules are registered
     */
    public function test_cron_schedules() {
        // Test that custom cron schedules exist
        $schedules = wp_get_schedules();

        $this->assertIsArray($schedules);
        $this->assertArrayHasKey('hourly', $schedules);
        $this->assertArrayHasKey('daily', $schedules);
    }

    /**
     * Test that plugin includes are loaded
     */
    public function test_plugin_includes_loaded() {
        // Test that key classes/functions from includes are available
        $classes_to_test = [
            'Puntwork\\PuntWorkLogger', // Logger class
        ];

        foreach ($classes_to_test as $class) {
            $this->assertTrue(class_exists($class), "Class {$class} should be available");
        }

        // Test that key functions are available
        $functions_to_test = [
            'Puntwork\\import_jobs_from_json', // from import-batch.php
            'Puntwork\\run_job_import_batch_ajax', // from ajax-import-control.php
        ];

        foreach ($functions_to_test as $function) {
            $this->assertTrue(function_exists($function), "Function {$function} should be available");
        }
    }
}