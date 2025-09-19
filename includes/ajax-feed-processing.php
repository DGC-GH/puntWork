<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

namespace Puntwork;

/**
 * AJAX handlers for feed processing operations
 * Handles feed downloading, JSONL combination, and JSON generation
 */

add_action('wp_ajax_process_feed', 'process_feed_ajax');
function process_feed_ajax() {
    error_log('process_feed_ajax called');
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        error_log('Nonce verification failed for process_feed');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        error_log('Permission denied for process_feed');
        wp_send_json_error(['message' => 'Permission denied']);
    }
    $feed_key = sanitize_key($_POST['feed_key']);
    $feeds = get_feeds();
    $url = $feeds[$feed_key] ?? '';
    if (empty($url)) {
        wp_send_json_error(['message' => 'Invalid feed key']);
    }
    $output_dir = ABSPATH . 'feeds/';
    $fallback_domain = 'belgiumjobs.work';
    $logs = [];
    try {
        $count = process_one_feed($feed_key, $url, $output_dir, $fallback_domain, $logs);
        wp_send_json_success(['item_count' => $count, 'logs' => $logs]);
    } catch (Exception $e) {
        error_log('Process feed failed: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Process feed failed: ' . $e->getMessage()]);
    }
}

add_action('wp_ajax_combine_jsonl', 'combine_jsonl_ajax');
function combine_jsonl_ajax() {
    error_log('combine_jsonl_ajax called');
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        error_log('Nonce verification failed for combine_jsonl');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        error_log('Permission denied for combine_jsonl');
        wp_send_json_error(['message' => 'Permission denied']);
    }
    $total_items = intval($_POST['total_items']);
    $feeds = get_feeds();
    $output_dir = ABSPATH . 'feeds/';
    $logs = [];
    try {
        combine_jsonl_files($feeds, $output_dir, $total_items, $logs);
        wp_send_json_success(['logs' => $logs]);
    } catch (Exception $e) {
        error_log('Combine JSONL failed: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Combine JSONL failed: ' . $e->getMessage()]);
    }
}

add_action('wp_ajax_generate_json', 'generate_json_ajax');
function generate_json_ajax() {
    error_log('generate_json_ajax called');
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        error_log('Nonce verification failed for generate_json');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        error_log('Permission denied for generate_json');
        wp_send_json_error(['message' => 'Permission denied']);
    }
    try {
        error_log('Starting JSONL generation');
        $gen_logs = fetch_and_generate_combined_json();
        error_log('JSONL generation completed');
        wp_send_json_success(['message' => 'JSONL generated successfully', 'logs' => $gen_logs]);
    } catch (Exception $e) {
        error_log('JSONL generation failed: ' . $e->getMessage());
        wp_send_json_error(['message' => 'JSONL generation failed: ' . $e->getMessage()]);
    }
}