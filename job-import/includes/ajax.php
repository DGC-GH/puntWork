<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// AJAX start import from snippet 4
function job_import_ajax_start() {
    check_ajax_referer('job_import_ajax', 'nonce');
    if (!current_user_can('manage_options')) wp_die();

    // Start background import (use wp_schedule_single_event for async)
    wp_schedule_single_event(time(), 'job_import_ajax_run');
    wp_send_json_success('Import started');
}

add_action('job_import_ajax_run', 'job_process_xml_batch');

// AJAX progress
function job_import_ajax_progress() {
    check_ajax_referer('job_import_ajax', 'nonce');
    $progress = get_transient('job_import_progress');
    wp_send_json_success($progress ?: ['progress' => 0, 'status' => 'idle']);
}
?>
