<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Scheduling from snippet 1.3
add_action('wp', 'job_import_schedule_triggers');
function job_import_schedule_triggers() {
    if (!wp_next_scheduled('job_import_cron')) {
        wp_schedule_event(time(), 'hourly', 'job_import_cron');
    }
}

// Trigger on post save or other events
add_action('save_post_job', 'job_import_trigger_update');
function job_import_trigger_update($post_id) {
    if (wp_is_post_revision($post_id)) return;
    // Example trigger: Re-run import if job updated manually
    wp_schedule_single_event(time() + 300, 'job_import_single_run'); // 5 min delay
}

add_action('job_import_single_run', 'job_import_run_import');
?>
