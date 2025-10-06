<?php

/**
 * Core scheduling functionality for job import plugin
 * Handles time calculations, cron management, and scheduling logic.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Register custom cron intervals
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['puntwork_hourly']  = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => __( 'Every Hour (Puntwork)', 'puntwork' ),
		);
		$schedules['puntwork_3hours']  = array(
			'interval' => 3 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 3 Hours (Puntwork)', 'puntwork' ),
		);
		$schedules['puntwork_6hours']  = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 Hours (Puntwork)', 'puntwork' ),
		);
		$schedules['puntwork_12hours'] = array(
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 12 Hours (Puntwork)', 'puntwork' ),
		);
		$schedules['puntwork_5min']    = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 Minutes (Puntwork)', 'puntwork' ),
		);

		// Add custom intervals
		for ( $i = 2; $i <= 24; $i++ ) {
			$schedules[ 'puntwork_' . $i . 'hours' ] = array(
				'interval' => $i * HOUR_IN_SECONDS,
				'display'  => sprintf( __( 'Every %d Hours (Puntwork)', 'puntwork' ), $i ),
			);
		}

		return $schedules;
	}
);

/**
 * Calculate the next run time based on schedule settings
 * Uses UTC for all time calculations (WordPress standard).
 */
function calculate_next_run_time( $schedule_data ) {
	// Get current time in UTC (Unix timestamp is always UTC)
	$current_time = time();
	$hour         = $schedule_data['hour'] ?? 9;
	$minute       = $schedule_data['minute'] ?? 0;
	$frequency    = $schedule_data['frequency'] ?? 'daily';

	// Get WordPress timezone
	$wp_timezone = wp_timezone();

	// Create DateTime objects in WordPress timezone
	$now          = new \DateTime( 'now', $wp_timezone );
	$today_target = new \DateTime( 'today', $wp_timezone );
	$today_target->setTime( $hour, $minute, 0 );

	// If today's target time has passed, calculate for tomorrow
	if ( $today_target <= $now ) {
		$today_target->modify( '+1 day' );
	}

	// For non-daily frequencies, we need to find the next occurrence
	if ( $frequency !== 'daily' ) {
		$interval_hours = 0;

		switch ( $frequency ) {
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

		// For interval schedules, find the next time that matches the specified hour/minute pattern
		// within the interval schedule (hour = base_hour + N * interval_hours)
		$base_hour      = $hour;
		$current_hour   = (int) $now->format( 'H' );
		$current_minute = (int) $now->format( 'i' );

		// Find the next valid hour that is >= current_hour and satisfies the pattern
		$next_valid_hour = $base_hour;
		while ( $next_valid_hour < $current_hour ) {
			$next_valid_hour += $interval_hours;
		}

		// Initialize $next_run as a DateTime object
		$next_run = new \DateTime( 'now', $wp_timezone );
		$next_run->setTime( $next_valid_hour, $minute, 0 );

		// If this exact time has already passed, move to the next interval
		if ( $next_run <= $now ) {
			$next_valid_hour += $interval_hours;
			$next_run->setTime( $next_valid_hour, $minute, 0 );
		}

		return $next_run->getTimestamp();
	}

	// For daily frequency, just return today's target (or tomorrow's if passed)
	return $today_target->getTimestamp();
}

/**
 * Update WordPress cron schedule based on settings.
 */
function update_cron_schedule( $schedule_data ) {
	$hook = 'puntwork_scheduled_import';

	// Clear existing schedule
	wp_clear_scheduled_hook( $hook );

	if ( $schedule_data['enabled'] ) {
		$next_run_timestamp = calculate_next_run_time( $schedule_data );
		$cron_interval      = get_cron_interval( $schedule_data );

		// Re-enabled: Background processing restored for automated imports
		if ( wp_schedule_event( $next_run_timestamp, $cron_interval, $hook ) ) {
			error_log( '[PUNTWORK] Scheduled import hook registered for: ' . wp_date( 'Y-m-d H:i:s', $next_run_timestamp ) . ' (' . wp_timezone_string() . ') with interval: ' . $cron_interval );
		} else {
			error_log( '[PUNTWORK] Failed to register scheduled import hook with interval: ' . $cron_interval );
		}
	}
}

function get_cron_interval( $schedule_data ) {
	switch ( $schedule_data['frequency'] ) {
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
			$interval_hours = intval( $schedule_data['interval'] );
			// Use predefined custom intervals
			if ( $interval_hours >= 2 && $interval_hours <= 24 ) {
				return 'puntwork_' . $interval_hours . 'hours';
			}

			// Fallback to closest available interval
			return 'puntwork_6hours';
		default:
			return 'daily';
	}
}

/**
 * Get next scheduled run time.
 */
function get_next_scheduled_time() {
	$next_scheduled = wp_next_scheduled( 'puntwork_scheduled_import' );

	if ( $next_scheduled ) {
		$current_time = current_time( 'timestamp' );
		$time_diff    = $next_scheduled - $current_time;

		// Calculate relative time correctly
		if ( $time_diff <= 0 ) {
			$relative = 'now';
		} elseif ( $time_diff < 60 ) {
			$relative = 'in ' . $time_diff . ' second' . ( $time_diff !== 1 ? 's' : '' );
		} elseif ( $time_diff < 3600 ) {
			$minutes  = round( $time_diff / 60 );
			$relative = 'in ' . $minutes . ' minute' . ( $minutes !== 1 ? 's' : '' );
		} elseif ( $time_diff < 86400 ) {
			$hours    = round( $time_diff / 3600 );
			$relative = 'in ' . $hours . ' hour' . ( $hours !== 1 ? 's' : '' );
		} else {
			$days     = round( $time_diff / 86400 );
			$relative = 'in ' . $days . ' day' . ( $days !== 1 ? 's' : '' );
		}

		return array(
			'timestamp' => $next_scheduled,
			'formatted' => wp_date( 'M j, Y H:i', $next_scheduled ),
			'relative'  => $relative,
		);
	}

	return null;
}

/**
 * Get schedule status information.
 */
function get_schedule_status() {
	$schedule = get_option( 'puntwork_import_schedule', array( 'enabled' => false ) );
	$next_run = get_next_scheduled_time();
	$last_run = get_option( 'puntwork_last_import_run', null );

	$status = 'Disabled';
	if ( $schedule['enabled'] ) {
		if ( $next_run ) {
			$status = 'Active';
		} else {
			$status = 'Error';
		}
	}

	return array(
		'status'    => $status,
		'enabled'   => $schedule['enabled'],
		'next_run'  => $next_run,
		'last_run'  => $last_run,
		'frequency' => $schedule['frequency'] ?? 'daily',
		'interval'  => $schedule['interval'] ?? 24,
	);
}

/**
 * Initialize scheduling system
 * Called during plugin setup to ensure scheduling is properly configured.
 */
function init_scheduling() {
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SCHEDULING-INIT] Scheduling system ENABLED - Background processing restored' );
	}

	// Initialize scheduling functionality

	// Check if automated schedules are already configured
	$existing_schedule = get_option( 'puntwork_import_schedule', false );
	if ( ! $existing_schedule || ! isset( $existing_schedule['enabled'] ) || ! $existing_schedule['enabled'] ) {
		// Set up automated import schedules for the first time
		setup_automated_import_schedules();
	} else {
		// Load current schedule settings and update cron
		update_cron_schedule( $existing_schedule );
	}

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [SCHEDULING-INIT] Scheduling system initialized successfully' );
	}
}

/**
 * Set up automated import schedules for multiple daily runs
 * Configures the system to run imports several times per day automatically
 */
function setup_automated_import_schedules() {
	// Set up a schedule for multiple daily imports
	$schedule_data = array(
		'enabled'   => true,
		'frequency' => '6hours', // Run every 6 hours = 4 times per day
		'hour'      => 6,        // Start at 6 AM
		'minute'    => 0,        // At the top of the hour
		'interval'  => 6,        // Every 6 hours
	);

	update_option( 'puntwork_import_schedule', $schedule_data );
	update_cron_schedule( $schedule_data );

	error_log( '[PUNTWORK] Automated import schedules configured: 4 times per day (every 6 hours starting at 6 AM)' );
}
