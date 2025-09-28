<?php

/**
 * AJAX handlers for feed processing operations.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/*
 * AJAX handlers for feed processing operations
 * Handles feed downloading, JSONL combination, and JSON generation
 */

// Explicitly load required utility classes for AJAX context
require_once __DIR__ . '/../utilities/SecurityUtils.php';
require_once __DIR__ . '/../utilities/AjaxErrorHandler.php';
require_once __DIR__ . '/../utilities/PuntWorkLogger.php';

add_action('wp_ajax_process_feed', __NAMESPACE__ . '\\process_feed_ajax');
function process_feed_ajax()
{
    PuntWorkLogger::logAjaxRequest('process_feed', $_POST);

    // Use comprehensive security validation with field validation
    $validation = SecurityUtils::validateAjaxRequest(
        'process_feed',
        'job_import_nonce',
        ['feed_key'], // required fields
        [
            'feed_key' => [
                'type' => 'key',
                'max_length' => 100,
            ], // validation rules
        ]
    );

    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        $feed_key = $_POST['feed_key'];
        $feeds = get_feeds();
        $url = $feeds[$feed_key] ?? '';

        // DETAILED SERVER-SIDE DEBUGGING
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: ==== SERVER process_feed DEBUG ===');
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Feed key received: ' . $feed_key);
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Available feeds: ' . print_r($feeds, true));
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Feed URL for key: ' . $url);
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: ABSPATH: ' . ABSPATH);
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Output directory: ' . ABSPATH . 'feeds/');
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Output directory exists: ' . (is_dir(ABSPATH . 'feeds/') ? 'yes' : 'no'));
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Output directory writable: ' . (is_writable(ABSPATH . 'feeds/') ? 'yes' : 'no'));

        if (empty($url)) {
            error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Feed URL is empty for key: ' . $feed_key . ' - checking if feed exists in array');
            error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Feed key exists in feeds array: ' . (array_key_exists($feed_key, $feeds) ? 'yes' : 'no'));
            if (array_key_exists($feed_key, $feeds)) {
                error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Feed value is: ' . var_export($feeds[$feed_key], true));
            }
            PuntWorkLogger::error("Invalid feed key: {$feed_key}", PuntWorkLogger::CONTEXT_FEED);
            AjaxErrorHandler::sendError('Invalid feed key: ' . $feed_key . ' - check feed configuration');

            return;
        }

        PuntWorkLogger::info("Processing feed: {$feed_key}", PuntWorkLogger::CONTEXT_FEED, ['url' => $url]);

        $output_dir = ABSPATH . 'feeds/';

        // Ensure output directory exists
        if (!wp_mkdir_p($output_dir) || !is_writable($output_dir)) {
            PuntWorkLogger::error("Feeds directory not writable: {$output_dir}", PuntWorkLogger::CONTEXT_FEED);
            AjaxErrorHandler::sendError('Feeds directory not writable');

            return;
        }

        $fallback_domain = 'belgiumjobs.work';
        $logs = [];

        error_log('[PUNTWORK] About to call process_one_feed with:');
        error_log('[PUNTWORK] - feed_key: ' . $feed_key);
        error_log('[PUNTWORK] - url: ' . $url);
        error_log('[PUNTWORK] - output_dir: ' . $output_dir);
        error_log('[PUNTWORK] - fallback_domain: ' . $fallback_domain);

        $count = process_one_feed($feed_key, $url, $output_dir, $fallback_domain, $logs);

        error_log('[PUNTWORK] process_one_feed returned count: ' . $count);
        error_log('[PUNTWORK] Logs from process_one_feed: ' . print_r($logs, true));

        PuntWorkLogger::logFeedProcessing($feed_key, $url, $count, true);

        PuntWorkLogger::logAjaxResponse(
            'process_feed',
            [
                'item_count' => $count,
                'logs_count' => count($logs),
            ]
        );
        AjaxErrorHandler::sendSuccess(
            [
                'item_count' => $count,
                'logs' => $logs,
            ]
        );
    } catch (\Exception $e) {
        error_log('[PUNTWORK] Exception in process_feed_ajax: ' . $e->getMessage());
        error_log('[PUNTWORK] Exception class: ' . get_class($e));
        error_log('[PUNTWORK] Exception file: ' . $e->getFile());
        error_log('[PUNTWORK] Exception line: ' . $e->getLine());
        error_log('[PUNTWORK] Exception trace: ' . $e->getTraceAsString());
        PuntWorkLogger::logFeedProcessing($feed_key ?? 'unknown', $url ?? '', 0, false);
        PuntWorkLogger::error("Feed processing failed: {$feed_key} - " . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse('process_feed', ['message' => 'Process feed failed: ' . $e->getMessage()], false);
        AjaxErrorHandler::sendError('Process feed failed: ' . $e->getMessage());
    }
}

add_action('wp_ajax_combine_jsonl', __NAMESPACE__ . '\\combine_jsonl_ajax');
function combine_jsonl_ajax()
{
    PuntWorkLogger::logAjaxRequest('combine_jsonl', $_POST);

    // Use comprehensive security validation with field validation
    $validation = SecurityUtils::validateAjaxRequest(
        'combine_jsonl',
        'job_import_nonce',
        ['total_items'], // required fields
        [
            'total_items' => [
                'type' => 'int',
                'min' => 0,
                'max' => 1000000,
            ], // validation rules
        ]
    );

    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        $total_items = $_POST['total_items'];
        PuntWorkLogger::info("Combining JSONL files for {$total_items} items", PuntWorkLogger::CONTEXT_FEED);

        $feeds = get_feeds();
        $output_dir = ABSPATH . 'feeds/';

        // Ensure output directory exists
        if (!wp_mkdir_p($output_dir) || !is_writable($output_dir)) {
            PuntWorkLogger::error("Feeds directory not writable: {$output_dir}", PuntWorkLogger::CONTEXT_FEED);
            AjaxErrorHandler::sendError('Feeds directory not writable');

            return;
        }

        // Update import status to show JSONL combination in progress
        update_option('job_import_status', [
            'total' => $total_items,
            'processed' => 0,
            'published' => 0,
            'updated' => 0,
            'skipped' => 0,
            'duplicates_drafted' => 0,
            'time_elapsed' => 0,
            'complete' => false,
            'success' => false,
            'error_message' => '',
            'batch_size' => 10,
            'inferred_languages' => 0,
            'inferred_benefits' => 0,
            'schema_generated' => 0,
            'start_time' => microtime(true),
            'end_time' => null,
            'last_update' => time(),
            'logs' => ['Starting JSONL combination...'],
        ]);

        error_log('[PUNTWORK] [JSONL-COMBINE] Starting JSONL combination for ' . $total_items . ' items');
        $logs = [];

        combine_jsonl_files($feeds, $output_dir, $total_items, $logs);
        PuntWorkLogger::info('JSONL files combined successfully', PuntWorkLogger::CONTEXT_FEED, ['total_items' => $total_items]);
        error_log('[PUNTWORK] [JSONL-COMBINE] JSONL files combined successfully, total_items=' . $total_items);

        // Update import status to show JSONL combination complete
        update_option('job_import_status', [
            'total' => $total_items,
            'processed' => 0,
            'published' => 0,
            'updated' => 0,
            'skipped' => 0,
            'duplicates_drafted' => 0,
            'time_elapsed' => microtime(true) - (get_option('job_import_status')['start_time'] ?? microtime(true)),
            'complete' => false,
            'success' => false,
            'error_message' => '',
            'batch_size' => 10,
            'inferred_languages' => 0,
            'inferred_benefits' => 0,
            'schema_generated' => 0,
            'start_time' => get_option('job_import_status')['start_time'] ?? microtime(true),
            'end_time' => null,
            'last_update' => time(),
            'logs' => array_merge(get_option('job_import_status')['logs'] ?? [], ['JSONL combination completed, starting import...']),
        ]);

        // Automatically start the import after successful JSONL combination
        PuntWorkLogger::info('Scheduling automatic import start after JSONL combination', PuntWorkLogger::CONTEXT_FEED);
        error_log('[PUNTWORK] [AUTO-IMPORT] Scheduling automatic import after JSONL combination for ' . $total_items . ' items');

        // Clear import cancel flag
        delete_transient('import_cancel');
        PuntWorkLogger::info('Import cancellation flag cleared for automatic start', PuntWorkLogger::CONTEXT_FEED);
        error_log('[PUNTWORK] [AUTO-IMPORT] Import cancel flag cleared');

        // Schedule the import to start asynchronously
        if (!wp_next_scheduled('puntwork_start_scheduled_import')) {
            wp_schedule_single_event(time() + 2, 'puntwork_start_scheduled_import'); // Start in 2 seconds
            PuntWorkLogger::info('Import start scheduled via WordPress cron', PuntWorkLogger::CONTEXT_FEED);
            error_log('[PUNTWORK] [AUTO-IMPORT] Import start scheduled via cron');
        }

        PuntWorkLogger::info('JSONL combination completed, import will start automatically', PuntWorkLogger::CONTEXT_FEED);
        $logs[] = 'JSONL combination completed, import starting automatically';
        error_log('[PUNTWORK] [AUTO-IMPORT] JSONL combination completed, import scheduled to start automatically');

        PuntWorkLogger::logAjaxResponse('combine_jsonl', ['logs_count' => count($logs)]);
        AjaxErrorHandler::sendSuccess(['logs' => $logs]);
    } catch (\Exception $e) {
        PuntWorkLogger::error('JSONL combination failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse('combine_jsonl', ['message' => 'Combine JSONL failed: ' . $e->getMessage()], false);
        AjaxErrorHandler::sendError('Combine JSONL failed: ' . $e->getMessage());
    }
}

add_action('wp_ajax_generate_json', __NAMESPACE__ . '\\generate_json_ajax');
function generate_json_ajax()
{
    PuntWorkLogger::logAjaxRequest('generate_json', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('generate_json', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        PuntWorkLogger::info('Starting JSONL generation process', PuntWorkLogger::CONTEXT_FEED);

        $gen_logs = fetch_and_generate_combined_json();
        PuntWorkLogger::info('JSONL generation completed successfully', PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse(
            'generate_json',
            [
                'message' => 'JSONL generated successfully',
                'logs_count' => count($gen_logs),
            ]
        );
        AjaxErrorHandler::sendSuccess(
            [
                'message' => 'JSONL generated successfully',
                'logs' => $gen_logs,
            ]
        );
    } catch (\Exception $e) {
        PuntWorkLogger::error('JSONL generation failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse('generate_json', ['message' => 'JSONL generation failed: ' . $e->getMessage()], false);
        AjaxErrorHandler::sendError('JSONL generation failed: ' . $e->getMessage());
    }
}
