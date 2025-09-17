<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Schedule daily via WP Cron at 3:33 Brussels time
add_action('wp', function() {
    if (!wp_next_scheduled('fetch_combined_jobs_json')) {
        $brussels_tz = new DateTimeZone('Europe/Brussels');
        $now = new DateTime('now', $brussels_tz);
        $target = new DateTime('today 03:33', $brussels_tz);
        if ($now > $target) $target->modify('+1 day');
        wp_schedule_event($target->getTimestamp(), 'daily', 'fetch_combined_jobs_json');
    }
});
add_action('fetch_combined_jobs_json', 'fetch_and_generate_combined_json');
