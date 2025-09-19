<?php
/**
 * Test script for scheduling functionality
 * This file can be used to test the scheduling features
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Only run if explicitly requested
if (!isset($_GET['test_scheduling'])) {
    return;
}

echo '<h2>Scheduling Test</h2>';

// Test schedule status
$schedule_status = get_schedule_status();
echo '<h3>Current Schedule Status:</h3>';
echo '<pre>' . print_r($schedule_status, true) . '</pre>';

// Test next scheduled time
$next_run = get_next_scheduled_time();
echo '<h3>Next Scheduled Run:</h3>';
echo '<pre>' . print_r($next_run, true) . '</pre>';

// Test last run data
$last_run = get_option('puntwork_last_import_run');
echo '<h3>Last Run Data:</h3>';
echo '<pre>' . print_r($last_run, true) . '</pre>';

// Test last run details
$last_details = get_option('puntwork_last_import_details');
echo '<h3>Last Run Details:</h3>';
echo '<pre>' . print_r($last_details, true) . '</pre>';

echo '<h3>Test Actions:</h3>';
echo '<p><a href="?page=job-import-dashboard&test_scheduling=1&action=run_test" class="button">Run Test Import</a></p>';
echo '<p><a href="?page=job-import-dashboard&test_scheduling=1&action=clear_schedule" class="button">Clear Schedule</a></p>';

// Handle test actions
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'run_test':
            echo '<h3>Test Import Result:</h3>';
            $result = Puntwork\run_scheduled_import(true);
            echo '<pre>' . print_r($result, true) . '</pre>';
            break;

        case 'clear_schedule':
            wp_clear_scheduled_hook('puntwork_scheduled_import');
            delete_option('puntwork_import_schedule');
            delete_option('puntwork_last_import_run');
            delete_option('puntwork_last_import_details');
            echo '<p>Schedule cleared!</p>';
            break;
    }
}