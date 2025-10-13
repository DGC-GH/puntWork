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

require_once __DIR__ . '/../utilities/ajax-utilities.php';
require_once __DIR__ . '/../import/download-feed.php';
require_once __DIR__ . '/../import/combine-jsonl.php';

/**
 * AJAX handlers for feed processing operations
 * Handles feed downloading, JSONL combination, and JSON generation
 */

add_action('wp_ajax_process_feed', __NAMESPACE__ . '\\process_feed_ajax');
function process_feed_ajax() {
    if (!validate_ajax_request('process_feed')) {
        return;
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

        send_ajax_success('process_feed', ['item_count' => $count, 'logs' => $logs], ['item_count' => $count, 'logs_count' => count($logs)]);
    } catch (\Exception $e) {
        PuntWorkLogger::logFeedProcessing($feed_key, $url, 0, false);
        PuntWorkLogger::error("Feed processing failed: {$feed_key} - " . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        send_ajax_error('process_feed', 'Process feed failed: ' . $e->getMessage());
    }
}

add_action('wp_ajax_combine_jsonl', __NAMESPACE__ . '\\combine_jsonl_ajax');
function combine_jsonl_ajax() {
    if (!validate_ajax_request('combine_jsonl')) {
        return;
    }

    $total_items = intval($_POST['total_items']);
    PuntWorkLogger::info("Combining JSONL files for {$total_items} items", PuntWorkLogger::CONTEXT_FEED);

    $feeds = get_feeds();
    $output_dir = PUNTWORK_PATH . 'feeds/';
    $logs = [];

    try {
        combine_jsonl_files($feeds, $output_dir, $total_items, $logs);
        PuntWorkLogger::info("JSONL files combined successfully", PuntWorkLogger::CONTEXT_FEED, ['total_items' => $total_items]);

        send_ajax_success('combine_jsonl', ['logs' => $logs], ['logs_count' => count($logs)]);
    } catch (\Exception $e) {
        PuntWorkLogger::error("JSONL combination failed: " . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        send_ajax_error('combine_jsonl', 'Combine JSONL failed: ' . $e->getMessage());
    }
}

add_action('wp_ajax_generate_json', __NAMESPACE__ . '\\generate_json_ajax');
function generate_json_ajax() {
    if (!validate_ajax_request('generate_json')) {
        return;
    }

    PuntWorkLogger::info('Starting JSONL generation process', PuntWorkLogger::CONTEXT_FEED);

    try {
        $gen_logs = fetch_and_generate_combined_json();
        PuntWorkLogger::info('JSONL generation completed successfully', PuntWorkLogger::CONTEXT_FEED);

        send_ajax_success('generate_json', ['message' => 'JSONL generated successfully', 'logs' => $gen_logs], ['message' => 'JSONL generated successfully', 'logs_count' => count($gen_logs)]);
    } catch (\Exception $e) {
        PuntWorkLogger::error('JSONL generation failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        send_ajax_error('generate_json', 'JSONL generation failed: ' . $e->getMessage());
    }
}