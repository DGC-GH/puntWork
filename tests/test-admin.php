<?php
/**
 * Tests for admin functionality
 */

namespace Puntwork;

use Puntwork\TestCase;

/**
 * Admin test class
 */
class AdminTest extends TestCase {

    /**
     * Test admin menu setup
     */
    public function test_admin_menu_setup() {
        // Simulate admin_init
        do_action('admin_init');

        // Check that admin_menu action is hooked
        $this->assertTrue(has_action('admin_menu'));

        // Simulate admin_menu
        do_action('admin_menu');

        // Check that menus are added
        global $menu, $submenu;

        // Find puntwork menu
        $puntwork_menu = null;
        foreach ($menu as $item) {
            if (isset($item[2]) && $item[2] === 'puntwork-dashboard') {
                $puntwork_menu = $item;
                break;
            }
        }

        $this->assertNotNull($puntwork_menu);
        $this->assertEquals('.work', $puntwork_menu[0]);
        $this->assertStringContains('icon.svg', $puntwork_menu[6]);
    }

    /**
     * Test admin page rendering functions exist
     */
    public function test_admin_page_functions_exist() {
        $this->assertTrue(function_exists('Puntwork\\feeds_dashboard_page'));
        $this->assertTrue(function_exists('Puntwork\\jobs_dashboard_page'));
        $this->assertTrue(function_exists('Puntwork\\puntwork_dashboard_page'));
        $this->assertTrue(function_exists('Puntwork\\render_main_import_ui'));
        $this->assertTrue(function_exists('Puntwork\\render_scheduling_ui'));
    }

    /**
     * Test feeds dashboard page output
     */
    public function test_feeds_dashboard_page_output() {
        // Capture output
        ob_start();
        feeds_dashboard_page();
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContains('<div class="wrap"', $output);
        $this->assertStringContains('Feeds Dashboard', $output);
        $this->assertStringContains('Import Controls', $output);
    }

    /**
     * Test jobs dashboard page output
     */
    public function test_jobs_dashboard_page_output() {
        // Capture output
        ob_start();
        jobs_dashboard_page();
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContains('<div class="wrap"', $output);
        $this->assertStringContains('Jobs Dashboard', $output);
        $this->assertStringContains('Job Management', $output);
    }

    /**
     * Test main puntwork dashboard page output
     */
    public function test_puntwork_dashboard_page_output() {
        // Capture output
        ob_start();
        puntwork_dashboard_page();
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContains('<div class="wrap"', $output);
        $this->assertStringContains('puntWork Dashboard', $output);
        $this->assertStringContains('Job Feeds', $output);
        $this->assertStringContains('Jobs', $output);
        $this->assertStringContains('Scheduling', $output);
    }

    /**
     * Test render_main_import_ui output
     */
    public function test_render_main_import_ui_output() {
        // Capture output
        ob_start();
        render_main_import_ui();
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContains('Import Controls', $output);
        $this->assertStringContains('start-import', $output);
        $this->assertStringContains('cancel-import', $output);
        $this->assertStringContains('reset-import', $output);
    }

    /**
     * Test render_scheduling_ui output
     */
    public function test_render_scheduling_ui_output() {
        // Capture output
        ob_start();
        render_scheduling_ui();
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContains('Scheduled Imports', $output);
        $this->assertStringContains('schedule-enabled', $output);
        $this->assertStringContains('save-schedule', $output);
        $this->assertStringContains('test-schedule', $output);
    }

    /**
     * Test render_jobs_dashboard_ui output
     */
    public function test_render_jobs_dashboard_ui_output() {
        // Capture output
        ob_start();
        render_jobs_dashboard_ui();
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContains('Job Management', $output);
    }

    /**
     * Test JavaScript initialization functions
     */
    public function test_javascript_init_functions() {
        $this->assertTrue(function_exists('Puntwork\\render_javascript_init'));
        $this->assertTrue(function_exists('Puntwork\\render_jobs_javascript_init'));

        // Test render_javascript_init output
        ob_start();
        render_javascript_init();
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContains('<script', $output);
        $this->assertStringContains('JobImportEvents', $output);
        $this->assertStringContains('JobImportUI', $output);
    }

    /**
     * Test debug UI rendering
     */
    public function test_debug_ui_rendering() {
        $this->assertTrue(function_exists('Puntwork\\render_debug_ui'));

        // Capture output
        ob_start();
        render_debug_ui();
        $output = ob_get_clean();

        $this->assertIsString($output);
        // Debug UI might be conditional, but should not error
    }

    /**
     * Test admin UI components load
     */
    public function test_admin_ui_components_load() {
        // These requires are in admin-page-html.php
        // Test that the files exist and can be required
        $files_to_check = [
            PUNTWORK_PATH . 'includes/admin/admin-ui-main.php',
            PUNTWORK_PATH . 'includes/admin/admin-ui-scheduling.php',
            PUNTWORK_PATH . 'includes/admin/admin-ui-debug.php'
        ];

        foreach ($files_to_check as $file) {
            $this->assertFileExists($file);
        }
    }

    /**
     * Test admin menu icon
     */
    public function test_admin_menu_icon() {
        // Test that the icon file exists
        $icon_path = PUNTWORK_URL . 'assets/images/icon.svg';
        // Since it's a URL, we can't directly check, but we can check the file exists
        $file_path = PUNTWORK_PATH . 'assets/images/icon.svg';
        $this->assertFileExists($file_path);
    }
}