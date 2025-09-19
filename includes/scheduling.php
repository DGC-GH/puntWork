<?php
/**
 * Scheduling functionality for job import plugin
 *
 * @package    Puntwork
 * @subpackage Scheduling
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Scheduling module for automated job imports
 */

/**
 * Initialize scheduling functionality
 */
function init_scheduling() {
    add_action('wp_ajax_save_import_schedule', __NAMESPACE__ . '\\save_import_schedule_ajax');
    add_action('wp_ajax_get_import_schedule', __NAMESPACE__ . '\\get_import_schedule_ajax');
    add_action('wp_ajax_test_import_schedule', __NAMESPACE__ . '\\test_import_schedule_ajax');
    add_action('wp_ajax_run_scheduled_import', __NAMESPACE__ . '\\run_scheduled_import_ajax');

    // Register cron hook
    add_action('puntwork_scheduled_import', __NAMESPACE__ . '\\run_scheduled_import');

    // Schedule cleanup on plugin deactivation
    register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\cleanup_scheduled_imports');
}

/**
 * Save import schedule settings
 */
function save_import_schedule_ajax() {
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $enabled = isset($_POST['enabled']) ? (bool) $_POST['enabled'] : false;
    $frequency = sanitize_text_field($_POST['frequency'] ?? 'daily');
    $interval = intval($_POST['interval'] ?? 24);

    // Validate frequency
    $valid_frequencies = ['hourly', 'daily', 'weekly', 'monthly', 'custom'];
    if (!in_array($frequency, $valid_frequencies)) {
        wp_send_json_error(['message' => 'Invalid frequency']);
    }

    // Validate custom interval
    if ($frequency === 'custom' && ($interval < 1 || $interval > 168)) {
        wp_send_json_error(['message' => 'Custom interval must be between 1 and 168 hours']);
    }

    $schedule_data = [
        'enabled' => $enabled,
        'frequency' => $frequency,
        'interval' => $interval,
        'updated_at' => time(),
        'updated_by' => get_current_user_id()
    ];

    update_option('puntwork_import_schedule', $schedule_data);

    // Update WordPress cron
    update_cron_schedule($schedule_data);

    wp_send_json_success([
        'message' => 'Schedule saved successfully',
        'schedule' => $schedule_data,
        'next_run' => get_next_scheduled_time()
    ]);
}

/**
 * Get current import schedule settings
 */
function get_import_schedule_ajax() {
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $schedule = get_option('puntwork_import_schedule', [
        'enabled' => false,
        'frequency' => 'daily',
        'interval' => 24,
        'updated_at' => null,
        'updated_by' => null
    ]);

    $last_run = get_option('puntwork_last_import_run', null);
    $last_run_details = get_option('puntwork_last_import_details', null);

    wp_send_json_success([
        'schedule' => $schedule,
        'next_run' => get_next_scheduled_time(),
        'last_run' => $last_run,
        'last_run_details' => $last_run_details
    ]);
}

/**
 * Test import schedule (run immediately for testing)
 */
function test_import_schedule_ajax() {
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    // Run a test import
    $result = run_scheduled_import(true); // true = test mode

    wp_send_json_success([
        'message' => 'Test import completed',
        'result' => $result
    ]);
}

/**
 * Run scheduled import immediately
 */
function run_scheduled_import_ajax() {
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    // Run the import
    $result = run_scheduled_import();

    wp_send_json_success([
        'message' => 'Import started',
        'result' => $result
    ]);
}

/**
 * Update WordPress cron schedule based on settings
 */
function update_cron_schedule($schedule_data) {
    $hook = 'puntwork_scheduled_import';

    // Clear existing schedule
    wp_clear_scheduled_hook($hook);

    if ($schedule_data['enabled']) {
        $interval = get_cron_interval($schedule_data);

        if (wp_schedule_event(time(), $interval, $hook)) {
            error_log('[PUNTWORK] Scheduled import hook registered with interval: ' . $interval);
        } else {
            error_log('[PUNTWORK] Failed to register scheduled import hook');
        }
    }
}

/**
 * Get cron interval based on schedule settings
 */
function get_cron_interval($schedule_data) {
    switch ($schedule_data['frequency']) {
        case 'hourly':
            return 'hourly';
        case 'daily':
            return 'daily';
        case 'weekly':
            return 'weekly';
        case 'monthly':
            return 'monthly';
        case 'custom':
            // Register custom interval if needed
            $interval_hours = $schedule_data['interval'];
            $interval_seconds = $interval_hours * HOUR_IN_SECONDS;

            // Check if custom interval is already registered
            $schedules = wp_get_schedules();
            $custom_key = 'puntwork_custom_' . $interval_hours . 'h';

            if (!isset($schedules[$custom_key])) {
                add_filter('cron_schedules', function($schedules) use ($interval_seconds, $interval_hours, $custom_key) {
                    $schedules[$custom_key] = [
                        'interval' => $interval_seconds,
                        'display' => sprintf(__('Every %d hours', 'puntwork'), $interval_hours)
                    ];
                    return $schedules;
                });
            }

            return $custom_key;
        default:
            return 'daily';
    }
}

/**
 * Get next scheduled run time
 */
function get_next_scheduled_time() {
    $next_scheduled = wp_next_scheduled('puntwork_scheduled_import');

    if ($next_scheduled) {
        return [
            'timestamp' => $next_scheduled,
            'formatted' => date_i18n('M j, Y g:i A', $next_scheduled),
            'relative' => human_time_diff($next_scheduled, time()) . ' from now'
        ];
    }

    return null;
}

/**
 * Run the scheduled import
 */
function run_scheduled_import($test_mode = false) {
    $start_time = microtime(true);

    try {
        // Log the scheduled run
        $log_message = $test_mode ? 'Test import started' : 'Scheduled import started';
        error_log('[PUNTWORK] ' . $log_message);

        // Run the import
        $result = import_jobs_from_json();

        $end_time = microtime(true);
        $duration = $end_time - $start_time;

        // Store last run information
        $last_run_data = [
            'timestamp' => time(),
            'duration' => $duration,
            'test_mode' => $test_mode,
            'result' => $result
        ];

        update_option('puntwork_last_import_run', $last_run_data);

        // Store detailed run information
        if (isset($result['success'])) {
            $details = [
                'success' => $result['success'],
                'duration' => $duration,
                'processed' => $result['processed'] ?? 0,
                'total' => $result['total'] ?? 0,
                'created' => $result['created'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
                'error_message' => $result['message'] ?? '',
                'timestamp' => time()
            ];

            update_option('puntwork_last_import_details', $details);
        }

        return $result;

    } catch (\Exception $e) {
        $end_time = microtime(true);
        $duration = $end_time - $start_time;

        $error_data = [
            'timestamp' => time(),
            'duration' => $duration,
            'test_mode' => $test_mode,
            'error' => $e->getMessage()
        ];

        update_option('puntwork_last_import_run', $error_data);

        error_log('[PUNTWORK] Scheduled import failed: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Scheduled import failed: ' . $e->getMessage()
        ];
    }
}

/**
 * Cleanup scheduled imports on plugin deactivation
 */
function cleanup_scheduled_imports() {
    wp_clear_scheduled_hook('puntwork_scheduled_import');
    delete_option('puntwork_import_schedule');
    delete_option('puntwork_last_import_run');
    delete_option('puntwork_last_import_details');
}

/**
 * Get schedule status information
 */
function get_schedule_status() {
    $schedule = get_option('puntwork_import_schedule', ['enabled' => false]);
    $next_run = get_next_scheduled_time();
    $last_run = get_option('puntwork_last_import_run', null);

    $status = 'Disabled';
    if ($schedule['enabled']) {
        if ($next_run) {
            $status = 'Active';
        } else {
            $status = 'Error';
        }
    }

    return [
        'status' => $status,
        'enabled' => $schedule['enabled'],
        'next_run' => $next_run,
        'last_run' => $last_run,
        'frequency' => $schedule['frequency'] ?? 'daily',
        'interval' => $schedule['interval'] ?? 24
    ];
}