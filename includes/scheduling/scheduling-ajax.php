<?php
/**
 * AJAX handlers for scheduling functionality
 * Handles all AJAX requests related to scheduling operations
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
 * Save import schedule settings via AJAX
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
 * Get current import schedule settings via AJAX
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
 * Get import run history via AJAX
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
 * Test import schedule via AJAX
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
 * Run scheduled import immediately via AJAX
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