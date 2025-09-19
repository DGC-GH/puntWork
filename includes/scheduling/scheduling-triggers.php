<?php
/**
 * Scheduling and trigger utilities
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

// Schedule daily via WP Cron at 3:33 in WordPress timezone
// Uses wp_timezone() to respect WordPress timezone settings (Brussels time)
add_action('wp', function() {
    if (!wp_next_scheduled('fetch_combined_jobs_json')) {
        // Use WordPress configured timezone
        $wp_timezone = wp_timezone();
        $now = new DateTime('now', $wp_timezone);
        $target = new DateTime('today 03:33', $wp_timezone);
        if ($now > $target) $target->modify('+1 day');
        wp_schedule_event($target->getTimestamp(), 'daily', 'fetch_combined_jobs_json');
        error_log('[PUNTWORK] Combined jobs fetch scheduled for: ' . wp_date('Y-m-d H:i:s', $target->getTimestamp()) . ' (' . wp_timezone_string() . ')');
    }
});
add_action('fetch_combined_jobs_json', __NAMESPACE__ . '\\fetch_and_generate_combined_json');

// Register the scheduled import hook
add_action('puntwork_scheduled_import', __NAMESPACE__ . '\\run_scheduled_import');
