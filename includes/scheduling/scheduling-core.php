<?php
/**
 * Core scheduling functionality for job import plugin
 * Handles time calculations, cron management, and scheduling logic
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
 * Register custom cron schedules
 */
function register_custom_cron_schedules($schedules) {
    $schedules['puntwork_hourly'] = [
        'interval' => HOUR_IN_SECONDS,
        'display' => __('Hourly', 'puntwork')
    ];

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
            case 'hourly':
                $interval_hours = 1;
                break;
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

function get_cron_interval($schedule_data) {
    switch ($schedule_data['frequency']) {
        case 'hourly':
            return 'puntwork_hourly';
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