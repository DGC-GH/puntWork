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
if (! defined('ABSPATH') ) {
    exit;
}

/**
 * AJAX handlers for feed processing operations
 * Handles feed downloading, JSONL combination, and JSON generation
 */

add_action('wp_ajax_process_feed', __NAMESPACE__ . '\\process_feed_ajax');
function process_feed_ajax()
{
    PuntWorkLogger::logAjaxRequest('process_feed', $_POST);

    // Use comprehensive security validation with field validation
    $validation = SecurityUtils::validateAjaxRequest(
        'process_feed',
        'job_import_nonce',
        array( 'feed_key' ), // required fields
        array(
        'feed_key' => array(
                'type'       => 'key',
                'max_length' => 100,
        ), // validation rules
        )
    );

    if (is_wp_error($validation) ) {
        AjaxErrorHandler::sendError($validation);
        return;
    }

    try {
        $feed_key = $_POST['feed_key'];
        $feeds    = get_feeds();
        $url      = $feeds[ $feed_key ] ?? '';

        // DETAILED SERVER-SIDE DEBUGGING
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: ===== SERVER process_feed DEBUG =====');
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Feed key received: ' . $feed_key);
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Available feeds: ' . print_r($feeds, true));
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Feed URL for key: ' . $url);
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: ABSPATH: ' . ABSPATH);
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Output directory: ' . ABSPATH . 'feeds/');
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Output directory exists: ' . ( is_dir(ABSPATH . 'feeds/') ? 'yes' : 'no' ));
        error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Output directory writable: ' . ( is_writable(ABSPATH . 'feeds/') ? 'yes' : 'no' ));

        if (empty($url) ) {
            error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Feed URL is empty for key: ' . $feed_key . ' - checking if feed exists in array');
            error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Feed key exists in feeds array: ' . ( array_key_exists($feed_key, $feeds) ? 'yes' : 'no' ));
            if (array_key_exists($feed_key, $feeds) ) {
                error_log('[PUNTWORK] [DEBUG] process_feed_ajax: Feed value is: ' . var_export($feeds[ $feed_key ], true));
            }
            PuntWorkLogger::error("Invalid feed key: {$feed_key}", PuntWorkLogger::CONTEXT_FEED);
            AjaxErrorHandler::sendError('Invalid feed key: ' . $feed_key . ' - check feed configuration');
            return;
        }

        PuntWorkLogger::info("Processing feed: {$feed_key}", PuntWorkLogger::CONTEXT_FEED, array( 'url' => $url ));

        $output_dir = ABSPATH . 'feeds/';

        // Ensure output directory exists
        if (! wp_mkdir_p($output_dir) || ! is_writable($output_dir) ) {
            PuntWorkLogger::error("Feeds directory not writable: {$output_dir}", PuntWorkLogger::CONTEXT_FEED);
            AjaxErrorHandler::sendError('Feeds directory not writable');
            return;
        }

        $fallback_domain = 'belgiumjobs.work';
        $logs            = array();

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
            array(
            'item_count' => $count,
            'logs_count' => count($logs),
            )
        );
        AjaxErrorHandler::sendSuccess(
            array(
            'item_count' => $count,
            'logs'       => $logs,
            )
        );
    } catch ( \Exception $e ) {
        error_log('[PUNTWORK] Exception in process_feed_ajax: ' . $e->getMessage());
        error_log('[PUNTWORK] Exception trace: ' . $e->getTraceAsString());
        PuntWorkLogger::logFeedProcessing($feed_key ?? 'unknown', $url ?? '', 0, false);
        PuntWorkLogger::error("Feed processing failed: {$feed_key} - " . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse('process_feed', array( 'message' => 'Process feed failed: ' . $e->getMessage() ), false);
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
        array( 'total_items' ), // required fields
        array(
        'total_items' => array(
                'type' => 'int',
                'min'  => 0,
                'max'  => 1000000,
        ), // validation rules
        )
    );

    if (is_wp_error($validation) ) {
        AjaxErrorHandler::sendError($validation);
        return;
    }

    try {
        $total_items = $_POST['total_items'];
        PuntWorkLogger::info("Combining JSONL files for {$total_items} items", PuntWorkLogger::CONTEXT_FEED);

        $feeds      = get_feeds();
        $output_dir = ABSPATH . 'feeds/';

        // Ensure output directory exists
        if (! wp_mkdir_p($output_dir) || ! is_writable($output_dir) ) {
            PuntWorkLogger::error("Feeds directory not writable: {$output_dir}", PuntWorkLogger::CONTEXT_FEED);
            AjaxErrorHandler::sendError('Feeds directory not writable');
            return;
        }

        $logs = array();

        combine_jsonl_files($feeds, $output_dir, $total_items, $logs);
        PuntWorkLogger::info('JSONL files combined successfully', PuntWorkLogger::CONTEXT_FEED, array( 'total_items' => $total_items ));

        PuntWorkLogger::logAjaxResponse('combine_jsonl', array( 'logs_count' => count($logs) ));
        AjaxErrorHandler::sendSuccess(array( 'logs' => $logs ));
    } catch ( \Exception $e ) {
        PuntWorkLogger::error('JSONL combination failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse('combine_jsonl', array( 'message' => 'Combine JSONL failed: ' . $e->getMessage() ), false);
        AjaxErrorHandler::sendError('Combine JSONL failed: ' . $e->getMessage());
    }
}

add_action('wp_ajax_generate_json', __NAMESPACE__ . '\\generate_json_ajax');
function generate_json_ajax()
{
    PuntWorkLogger::logAjaxRequest('generate_json', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('generate_json', 'job_import_nonce');
    if (is_wp_error($validation) ) {
        AjaxErrorHandler::sendError($validation);
        return;
    }

    try {
        PuntWorkLogger::info('Starting JSONL generation process', PuntWorkLogger::CONTEXT_FEED);

        $gen_logs = fetch_and_generate_combined_json();
        PuntWorkLogger::info('JSONL generation completed successfully', PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse(
            'generate_json',
            array(
            'message'    => 'JSONL generated successfully',
            'logs_count' => count($gen_logs),
            )
        );
        AjaxErrorHandler::sendSuccess(
            array(
            'message' => 'JSONL generated successfully',
            'logs'    => $gen_logs,
            )
        );
    } catch ( \Exception $e ) {
        PuntWorkLogger::error('JSONL generation failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED);

        PuntWorkLogger::logAjaxResponse('generate_json', array( 'message' => 'JSONL generation failed: ' . $e->getMessage() ), false);
        AjaxErrorHandler::sendError('JSONL generation failed: ' . $e->getMessage());
    }
}
