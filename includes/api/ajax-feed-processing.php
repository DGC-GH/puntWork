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

    // Use comprehensive security validation with field validation
    $validation = SecurityUtils::validate_ajax_request(
        'process_feed',
        'job_import_nonce',
        ['feed_key'], // required fields
        [
            'feed_key' => ['type' => 'key', 'max_length' => 100] // validation rules
        ]
    );

    if (is_wp_error($validation)) {
        AjaxErrorHandler::send_error($validation);
        return;
    }

    try {
        $feed_key = $_POST['feed_key'];
        $feeds = get_feeds();
        $url = $feeds[$feed_key] ?? '';

        if (empty($url)) {
            PuntWorkLogger::error("Invalid feed key: {$feed_key}", PuntWorkLogger::CONTEXT_FEED);
            AjaxErrorHandler::send_error('Invalid feed key');
            return;
        }

        PuntWorkLogger::info("Processing feed: {$feed_key}", PuntWorkLogger::CONTEXT_FEED, ['url' => $url]);

        $output_dir = ABSPATH . 'feeds/';
        $fallback_domain = 'belgiumjobs.work';
        $logs = [];

        $count = process_one_feed($feed_key, $url, $output_dir, $fallback_domain, $logs);
        PuntWorkLogger::logFeedProcessing($feed_key, $url, $count, true);

        PuntWorkLogger::logAjaxResponse('process_feed', ['item_count' => $count, 'logs_count' => count($logs)]);
        AjaxErrorHandler::send_success(['item_count' => $count, 'logs' => $logs]);

    } catch (\Exception $e) {
        PuntWorkLogger::logFeedProcessing($feed_key ?? 'unknown', $url ?? '', 0, false);
        PuntWorkLogger::error("Feed processing failed: {$feed_key} - " . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse('process_feed', ['message' => 'Process feed failed: ' . $e->getMessage()], false);
        AjaxErrorHandler::send_error('Process feed failed: ' . $e->getMessage());
    }
}

add_action('wp_ajax_combine_jsonl', __NAMESPACE__ . '\\combine_jsonl_ajax');
function combine_jsonl_ajax() {
    PuntWorkLogger::logAjaxRequest('combine_jsonl', $_POST);

    // Use comprehensive security validation with field validation
    $validation = SecurityUtils::validate_ajax_request(
        'combine_jsonl',
        'job_import_nonce',
        ['total_items'], // required fields
        [
            'total_items' => ['type' => 'int', 'min' => 0, 'max' => 1000000] // validation rules
        ]
    );

    if (is_wp_error($validation)) {
        AjaxErrorHandler::send_error($validation);
        return;
    }

    try {
        $total_items = $_POST['total_items'];
        PuntWorkLogger::info("Combining JSONL files for {$total_items} items", PuntWorkLogger::CONTEXT_FEED);

        $feeds = get_feeds();
        $output_dir = ABSPATH . 'feeds/';
        $logs = [];

        combine_jsonl_files($feeds, $output_dir, $total_items, $logs);
        PuntWorkLogger::info('JSONL files combined successfully', PuntWorkLogger::CONTEXT_FEED, ['total_items' => $total_items]);

        PuntWorkLogger::logAjaxResponse('combine_jsonl', ['logs_count' => count($logs)]);
        AjaxErrorHandler::send_success(['logs' => $logs]);

    } catch (\Exception $e) {
        PuntWorkLogger::error("JSONL combination failed: " . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse('combine_jsonl', ['message' => 'Combine JSONL failed: ' . $e->getMessage()], false);
        AjaxErrorHandler::send_error('Combine JSONL failed: ' . $e->getMessage());
    }
}

add_action('wp_ajax_generate_json', __NAMESPACE__ . '\\generate_json_ajax');
function generate_json_ajax() {
    PuntWorkLogger::logAjaxRequest('generate_json', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validate_ajax_request('generate_json', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::send_error($validation);
        return;
    }

    try {
        PuntWorkLogger::info('Starting JSONL generation process', PuntWorkLogger::CONTEXT_FEED);

        $gen_logs = fetch_and_generate_combined_json();
        PuntWorkLogger::info('JSONL generation completed successfully', PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse('generate_json', ['message' => 'JSONL generated successfully', 'logs_count' => count($gen_logs)]);
        AjaxErrorHandler::send_success(['message' => 'JSONL generated successfully', 'logs' => $gen_logs]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('JSONL generation failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse('generate_json', ['message' => 'JSONL generation failed: ' . $e->getMessage()], false);
        AjaxErrorHandler::send_error('JSONL generation failed: ' . $e->getMessage());
    }
}