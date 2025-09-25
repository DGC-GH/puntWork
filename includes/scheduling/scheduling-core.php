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
 * Calculate the next run time based on schedule settings
 * Uses UTC for all time calculations (WordPress standard)
 */
function calculate_next_run_time($schedule_data) {
    // Get current time in UTC (Unix timestamp is always UTC)
    $current_time = time();
    $hour = $schedule_data['hour'] ?? 9;
    $minute = $schedule_data['minute'] ?? 0;
    $frequency = $schedule_data['frequency'] ?? 'daily';

    // Get WordPress timezone
    $wp_timezone = wp_timezone();

    // Create DateTime objects in WordPress timezone
    $now = new \DateTime('now', $wp_timezone);
    $today_target = new \DateTime('today', $wp_timezone);
    $today_target->setTime($hour, $minute, 0);

    // If today's target time has passed, calculate for tomorrow
    if ($today_target <= $now) {
        $today_target->modify('+1 day');
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

        // For interval schedules, run every X hours from now
        // Add the interval to the current time to get the next run
        $next_run = clone $now;
        $next_run->modify("+{$interval_hours} hours");

        return $next_run->getTimestamp();
    }

    // For daily frequency, just return today's target (or tomorrow's if passed)
    return $today_target->getTimestamp();
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
        $cron_interval = get_cron_interval($schedule_data);

        if (wp_schedule_event($next_run_timestamp, $cron_interval, $hook)) {
            error_log('[PUNTWORK] Scheduled import hook registered for: ' . wp_date('Y-m-d H:i:s', $next_run_timestamp) . ' (' . wp_timezone_string() . ') with interval: ' . $cron_interval);
        } else {
            error_log('[PUNTWORK] Failed to register scheduled import hook with interval: ' . $cron_interval);
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
            $interval_hours = intval($schedule_data['interval']);
            // Use predefined custom intervals
            if ($interval_hours >= 2 && $interval_hours <= 24) {
                return 'puntwork_' . $interval_hours . 'hours';
            }
            // Fallback to closest available interval
            return 'puntwork_6hours';
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
        $current_time = current_time('timestamp');
        $time_diff = $next_scheduled - $current_time;

        // Calculate relative time correctly
        if ($time_diff <= 0) {
            $relative = 'now';
        } elseif ($time_diff < 60) {
            $relative = 'in ' . $time_diff . ' second' . ($time_diff != 1 ? 's' : '');
        } elseif ($time_diff < 3600) {
            $minutes = round($time_diff / 60);
            $relative = 'in ' . $minutes . ' minute' . ($minutes != 1 ? 's' : '');
        } elseif ($time_diff < 86400) {
            $hours = round($time_diff / 3600);
            $relative = 'in ' . $hours . ' hour' . ($hours != 1 ? 's' : '');
        } else {
            $days = round($time_diff / 86400);
            $relative = 'in ' . $days . ' day' . ($days != 1 ? 's' : '');
        }

        return [
            'timestamp' => $next_scheduled,
            'formatted' => wp_date('M j, Y g:i A', $next_scheduled),
            'relative' => $relative
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