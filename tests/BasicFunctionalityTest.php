<?php
/**
 * Basic functionality tests that don't require WordPress
 */

use PHPUnit\Framework\TestCase;

class BasicFunctionalityTest extends TestCase {

    public function test_plugin_file_exists() {
        $this->assertFileExists(__DIR__ . '/../puntwork.php');
    }

    public function test_includes_directory_exists() {
        $this->assertDirectoryExists(__DIR__ . '/../includes');
    }

    public function test_core_files_exist() {
        $core_files = [
            'puntwork.php',
            'includes/core/core-structure-logic.php',
            'includes/admin/admin-menu.php',
            'includes/import/import-setup.php',
            'includes/batch/batch-processing.php',
            'includes/scheduling/scheduling-core.php',
            'includes/mappings/mappings-fields.php',
            'includes/utilities/puntwork-logger.php'
        ];

        foreach ($core_files as $file) {
            $this->assertFileExists(__DIR__ . '/../' . $file, "File $file should exist");
        }
    }

    public function test_constants_defined_in_main_file() {
        // Include the main file to check constants
        $main_file = __DIR__ . '/../puntwork.php';

        // Read the file content
        $content = file_get_contents($main_file);

        // Check for key constants
        $this->assertStringContainsString('PUNTWORK_VERSION', $content);
        $this->assertStringContainsString('PUNTWORK_PATH', $content);
        $this->assertStringContainsString('PUNTWORK_URL', $content);
        $this->assertStringContainsString('PUNTWORK_LOGS', $content);
    }

    public function test_namespace_usage() {
        $main_file = __DIR__ . '/../puntwork.php';
        $content = file_get_contents($main_file);

        $this->assertStringContainsString('namespace Puntwork;', $content);
    }

    public function test_hook_registrations() {
        $main_file = __DIR__ . '/../puntwork.php';
        $content = file_get_contents($main_file);

        $this->assertStringContainsString('register_activation_hook', $content);
        $this->assertStringContainsString('register_deactivation_hook', $content);
        $this->assertStringContainsString("add_action( 'init'", $content);
    }

    public function test_cron_schedule_filter() {
        $main_file = __DIR__ . '/../puntwork.php';
        $content = file_get_contents($main_file);

        $this->assertStringContainsString('add_filter(\'cron_schedules\'', $content);
    }

    public function test_includes_loading() {
        $main_file = __DIR__ . '/../puntwork.php';
        $content = file_get_contents($main_file);

        $this->assertStringContainsString('foreach ( $includes as $include )', $content);
        $this->assertStringContainsString('require_once $file', $content);
    }

    public function test_favicon_action() {
        $main_file = __DIR__ . '/../puntwork.php';
        $content = file_get_contents($main_file);

        $this->assertStringContainsString('add_action( \'wp_head\'', $content);
        $this->assertStringContainsString('add_custom_favicon', $content);
    }

    public function test_uninstall_hook() {
        $main_file = __DIR__ . '/../puntwork.php';
        $content = file_get_contents($main_file);

        $this->assertStringContainsString('register_uninstall_hook', $content);
        $this->assertStringContainsString('job_import_uninstall', $content);
    }
}