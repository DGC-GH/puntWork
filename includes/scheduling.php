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
    add_action('wp_ajax_get_import_run_history', __NAMESPACE__ . '\\get_import_run_history_ajax');

    // Register cron hook
    add_action('puntwork_scheduled_import', __NAMESPACE__ . '\\run_scheduled_import');

    // Register custom cron schedules
    add_filter('cron_schedules', __NAMESPACE__ . '\\register_custom_cron_schedules');

    // Schedule cleanup on plugin deactivation
    register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\cleanup_scheduled_imports');
}

/**
 * Register custom cron schedules
 */
function register_custom_cron_schedules($schedules) {
    $schedules['puntwork_3hours'] = [
        'interval' => 3 * HOUR_IN_SECONDS,
        'display' => __('Every 3 hours', 'puntwork')
    ];

    $schedules['puntwork_6hours'] = [
        'interval' => 6 * HOUR_IN_SECONDS,
        'display' => __('Every 6 hours', 'puntwork')
    ];

    $schedules['puntwork_12hours'] = [
        'interval' => 12 * HOUR_IN_SECONDS,
        'display' => __('Every 12 hours', 'puntwork')
    ];

    // Custom schedule for time-based scheduling
    $schedules['puntwork_custom_schedule'] = [
        'interval' => 60, // Will be rescheduled each time
        'display' => __('Custom schedule', 'puntwork')
    ];

    return $schedules;
}

/**
 * Calculate the next run time based on schedule settings
 */
function calculate_next_run_time($schedule_data) {
    $current_time = time();
    $hour = $schedule_data['hour'] ?? 9;
    $minute = $schedule_data['minute'] ?? 0;
    $frequency = $schedule_data['frequency'] ?? 'daily';

    // Create timestamp for today at the specified time
    $today_target = strtotime(date('Y-m-d', $current_time) . " {$hour}:{$minute}:00");

    // If today's target time has passed, calculate for tomorrow
    if ($today_target <= $current_time) {
        $today_target = strtotime('+1 day', $today_target);
    }

    // For non-daily frequencies, we need to find the next occurrence
    if ($frequency !== 'daily') {
        $interval_hours = 0;

        switch ($frequency) {
            case '3hours':
                $interval_hours = 3;
                break;
            case '6hours':
                $interval_hours = 6;
                break;
            case '12hours':
                $interval_hours = 12;
                break;
            case 'custom':
                $interval_hours = $schedule_data['interval'] ?? 24;
                break;
            default:
                $interval_hours = 24; // fallback to daily
        }

        // Find the next occurrence based on the interval
        $next_run = $today_target;

        // If the target time is in the past, find the next future occurrence
        while ($next_run <= $current_time) {
            $next_run = strtotime("+{$interval_hours} hours", $next_run);
        }

        return $next_run;
    }

    // For daily frequency, just return today's target (or tomorrow's if passed)
    return $today_target;
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
    $hour = intval($_POST['hour'] ?? 9);
    $minute = intval($_POST['minute'] ?? 0);

    // Validate frequency
    $valid_frequencies = ['3hours', '6hours', '12hours', 'daily', 'custom'];
    if (!in_array($frequency, $valid_frequencies)) {
        wp_send_json_error(['message' => 'Invalid frequency']);
    }

    // Validate time
    if ($hour < 0 || $hour > 23) {
        wp_send_json_error(['message' => 'Hour must be between 0 and 23']);
    }
    if ($minute < 0 || $minute > 59) {
        wp_send_json_error(['message' => 'Minute must be between 0 and 59']);
    }

    // Validate custom interval
    if ($frequency === 'custom' && ($interval < 1 || $interval > 168)) {
        wp_send_json_error(['message' => 'Custom interval must be between 1 and 168 hours']);
    }

    $schedule_data = [
        'enabled' => $enabled,
        'frequency' => $frequency,
        'interval' => $interval,
        'hour' => $hour,
        'minute' => $minute,
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
        'hour' => 9,
        'minute' => 0,
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
 * Get import run history
 */
function get_import_run_history_ajax() {
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $history = get_option('puntwork_import_run_history', []);

    wp_send_json_success([
        'history' => $history,
        'count' => count($history)
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
        $next_run_timestamp = calculate_next_run_time($schedule_data);

        if (wp_schedule_event($next_run_timestamp, 'puntwork_custom_schedule', $hook)) {
            error_log('[PUNTWORK] Scheduled import hook registered for: ' . date('Y-m-d H:i:s', $next_run_timestamp));
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
        case '3hours':
            return 'puntwork_3hours';
        case '6hours':
            return 'puntwork_6hours';
        case '12hours':
            return 'puntwork_12hours';
        case 'daily':
            return 'daily';
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

            // Log this run to history
            log_scheduled_run($details, $test_mode);
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

        // Log failed run to history
        log_scheduled_run([
            'success' => false,
            'duration' => $duration,
            'processed' => 0,
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'error_message' => $e->getMessage(),
            'timestamp' => time()
        ], $test_mode);

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
    delete_option('puntwork_import_run_history');
}

/**
 * Log a scheduled run to history
 */
function log_scheduled_run($details, $test_mode = false) {
    $run_entry = [
        'timestamp' => $details['timestamp'],
        'duration' => $details['duration'],
        'success' => $details['success'],
        'processed' => $details['processed'],
        'total' => $details['total'],
        'created' => $details['created'],
        'updated' => $details['updated'],
        'skipped' => $details['skipped'],
        'error_message' => $details['error_message'] ?? '',
        'test_mode' => $test_mode
    ];

    // Get existing history
    $history = get_option('puntwork_import_run_history', []);

    // Add new entry to the beginning
    array_unshift($history, $run_entry);

    // Keep only the last 20 runs to prevent the option from growing too large
    if (count($history) > 20) {
        $history = array_slice($history, 0, 20);
    }

    update_option('puntwork_import_run_history', $history);

    // Log to debug log
    $status = $details['success'] ? 'SUCCESS' : 'FAILED';
    $mode = $test_mode ? ' (TEST)' : '';
    error_log(sprintf(
        '[PUNTWORK] Scheduled import %s%s - Duration: %.2fs, Processed: %d/%d, Created: %d, Updated: %d, Skipped: %d',
        $status,
        $mode,
        $details['duration'],
        $details['processed'],
        $details['total'],
        $details['created'],
        $details['updated'],
        $details['skipped']
    ));
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