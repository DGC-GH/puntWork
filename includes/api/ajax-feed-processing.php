<?php
/**
 * AJAX handlers for feed processing operations
 *
 * @package    Puntwork
 * @subpackage AJAX
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AJAX handlers for feed processing operations
 * Handles feed downloading, JSONL combination, and JSON generation
 */

add_action('wp_ajax_process_feed', __NAMESPACE__ . '\\process_feed_ajax');
function process_feed_ajax() {
    PuntWorkLogger::logAjaxRequest('process_feed', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for process_feed', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for process_feed', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $feed_key = sanitize_key($_POST['feed_key']);
    $feeds = get_feeds();
    $url = $feeds[$feed_key] ?? '';

    if (empty($url)) {
        PuntWorkLogger::error("Invalid feed key: {$feed_key}", PuntWorkLogger::CONTEXT_FEED);
        wp_send_json_error(['message' => 'Invalid feed key']);
    }

    PuntWorkLogger::info("Processing feed: {$feed_key}", PuntWorkLogger::CONTEXT_FEED, ['url' => $url]);

    $output_dir = PUNTWORK_PATH . 'feeds/';
    $fallback_domain = 'belgiumjobs.work';
    $logs = [];

    try {
        $count = process_one_feed($feed_key, $url, $output_dir, $fallback_domain, $logs);
        PuntWorkLogger::logFeedProcessing($feed_key, $url, $count, true);

        PuntWorkLogger::logAjaxResponse('process_feed', ['item_count' => $count, 'logs_count' => count($logs)]);
        wp_send_json_success(['item_count' => $count, 'logs' => $logs]);
    } catch (\Exception $e) {
        PuntWorkLogger::logFeedProcessing($feed_key, $url, 0, false);
        PuntWorkLogger::error("Feed processing failed: {$feed_key} - " . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse('process_feed', ['message' => 'Process feed failed: ' . $e->getMessage()], false);
        wp_send_json_error(['message' => 'Process feed failed: ' . $e->getMessage()]);
    }
}

add_action('wp_ajax_combine_jsonl', __NAMESPACE__ . '\\combine_jsonl_ajax');
function combine_jsonl_ajax() {
    PuntWorkLogger::logAjaxRequest('combine_jsonl', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for combine_jsonl', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for combine_jsonl', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $total_items = intval($_POST['total_items']);
    PuntWorkLogger::info("Combining JSONL files for {$total_items} items", PuntWorkLogger::CONTEXT_FEED);

    $feeds = get_feeds();
    $output_dir = PUNTWORK_PATH . 'feeds/';
    $logs = [];

    try {
        combine_jsonl_files($feeds, $output_dir, $total_items, $logs);
        PuntWorkLogger::info("JSONL files combined successfully", PuntWorkLogger::CONTEXT_FEED, ['total_items' => $total_items]);

        PuntWorkLogger::logAjaxResponse('combine_jsonl', ['logs_count' => count($logs)]);
        wp_send_json_success(['logs' => $logs]);
    } catch (\Exception $e) {
        PuntWorkLogger::error("JSONL combination failed: " . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse('combine_jsonl', ['message' => 'Combine JSONL failed: ' . $e->getMessage()], false);
        wp_send_json_error(['message' => 'Combine JSONL failed: ' . $e->getMessage()]);
    }
}

add_action('wp_ajax_generate_json', __NAMESPACE__ . '\\generate_json_ajax');
function generate_json_ajax() {
    PuntWorkLogger::logAjaxRequest('generate_json', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for generate_json', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for generate_json', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    PuntWorkLogger::info('Starting JSONL generation process', PuntWorkLogger::CONTEXT_FEED);

    try {
        $gen_logs = fetch_and_generate_combined_json();
        PuntWorkLogger::info('JSONL generation completed successfully', PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse('generate_json', ['message' => 'JSONL generated successfully', 'logs_count' => count($gen_logs)]);
        wp_send_json_success(['message' => 'JSONL generated successfully', 'logs' => $gen_logs]);
    } catch (\Exception $e) {
        PuntWorkLogger::error('JSONL generation failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse('generate_json', ['message' => 'JSONL generation failed: ' . $e->getMessage()], false);
        wp_send_json_error(['message' => 'JSONL generation failed: ' . $e->getMessage()]);
    }
}