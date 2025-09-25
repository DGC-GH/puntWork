<?php
/**
 * AJAX handlers for import control operations
 * Handles batch processing, cancellation, and status retrieval
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
 * AJAX handlers for import control operations
 * Handles batch processing, cancellation, and status retrieval
 */

add_action('wp_ajax_run_job_import_batch', __NAMESPACE__ . '\\run_job_import_batch_ajax');
function run_job_import_batch_ajax() {
    PuntWorkLogger::logAjaxRequest('run_job_import_batch', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for run_job_import_batch', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for run_job_import_batch', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $start = intval($_POST['start']);
    PuntWorkLogger::info("Starting batch import at index: {$start}", PuntWorkLogger::CONTEXT_BATCH);

    $result = import_jobs_from_json(true, $start);

    // Log summary instead of full result to prevent large debug logs
    $log_summary = [
        'success' => isset($result['success']) && $result['success'],
        'processed' => $result['processed'] ?? 0,
        'total' => $result['total'] ?? 0,
        'published' => $result['published'] ?? 0,
        'updated' => $result['updated'] ?? 0,
        'skipped' => $result['skipped'] ?? 0,
        'complete' => $result['complete'] ?? false,
        'logs_count' => isset($result['logs']) && is_array($result['logs']) ? count($result['logs']) : 0,
        'has_error' => !empty($result['message'])
    ];

    PuntWorkLogger::logAjaxResponse('run_job_import_batch', $log_summary, isset($result['success']) && $result['success']);
    wp_send_json_success($result);
}

add_action('wp_ajax_cancel_job_import', __NAMESPACE__ . '\\cancel_job_import_ajax');
function cancel_job_import_ajax() {
    PuntWorkLogger::logAjaxRequest('cancel_job_import', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for cancel_job_import', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for cancel_job_import', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    set_transient('import_cancel', true, 3600);
    // Also clear the import status to reset the UI
    delete_option('job_import_status');
    delete_option('job_import_batch_size');
    PuntWorkLogger::info('Import cancelled and status cleared', PuntWorkLogger::CONTEXT_BATCH);

    PuntWorkLogger::logAjaxResponse('cancel_job_import', ['message' => 'Import cancelled']);
    wp_send_json_success();
}

add_action('wp_ajax_clear_import_cancel', __NAMESPACE__ . '\\clear_import_cancel_ajax');
function clear_import_cancel_ajax() {
    PuntWorkLogger::logAjaxRequest('clear_import_cancel', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for clear_import_cancel', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for clear_import_cancel', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    delete_transient('import_cancel');
    PuntWorkLogger::info('Import cancellation flag cleared', PuntWorkLogger::CONTEXT_BATCH);

    PuntWorkLogger::logAjaxResponse('clear_import_cancel', ['message' => 'Cancellation cleared']);
    wp_send_json_success();
}

add_action('wp_ajax_reset_job_import', __NAMESPACE__ . '\\reset_job_import_ajax');
function reset_job_import_ajax() {
    PuntWorkLogger::logAjaxRequest('reset_job_import', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for reset_job_import', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for reset_job_import', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    // Clear all import-related data
    delete_option('job_import_status');
    delete_option('job_import_progress');
    delete_option('job_import_processed_guids');
    delete_option('job_import_last_batch_time');
    delete_option('job_import_last_batch_processed');
    delete_option('job_import_batch_size');
    delete_option('job_import_consecutive_small_batches');
    delete_transient('import_cancel');

    PuntWorkLogger::info('Import system completely reset', PuntWorkLogger::CONTEXT_BATCH);

    PuntWorkLogger::logAjaxResponse('reset_job_import', ['message' => 'Import system reset']);
    wp_send_json_success();
}

add_action('wp_ajax_get_job_import_status', __NAMESPACE__ . '\\get_job_import_status_ajax');
function get_job_import_status_ajax() {
    PuntWorkLogger::logAjaxRequest('get_job_import_status', $_POST);

    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        PuntWorkLogger::error('Nonce verification failed for get_job_import_status', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        PuntWorkLogger::error('Permission denied for get_job_import_status', PuntWorkLogger::CONTEXT_AJAX);
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $progress = get_option('job_import_status') ?: [
        'total' => 0,
        'processed' => 0,
        'published' => 0,
        'updated' => 0,
        'skipped' => 0,
        'duplicates_drafted' => 0,
        'time_elapsed' => 0,
        'complete' => true, // Fresh state is complete
        'success' => false, // Add success status
        'error_message' => '', // Add error message for failures
        'batch_size' => 10,
        'inferred_languages' => 0,
        'inferred_benefits' => 0,
        'schema_generated' => 0,
        'start_time' => microtime(true),
        'end_time' => null,
        'last_update' => time(),
        'logs' => [],
    ];

    PuntWorkLogger::debug('Retrieved import status', PuntWorkLogger::CONTEXT_BATCH, [
        'total' => $progress['total'],
        'processed' => $progress['processed'],
        'complete' => $progress['complete']
    ]);

    // Check for stuck imports and clear them
    if (isset($progress['complete']) && !$progress['complete'] && isset($progress['total']) && $progress['total'] > 0) {
        $time_elapsed = 0;
        if (isset($progress['start_time']) && $progress['start_time'] > 0) {
            $time_elapsed = microtime(true) - $progress['start_time'];
        } elseif (isset($progress['time_elapsed'])) {
            $time_elapsed = $progress['time_elapsed'];
        }
        
        $is_stuck = ($progress['processed'] == 0) && ($time_elapsed > 300);
        
        if ($is_stuck) {
            PuntWorkLogger::info('Detected stuck import in status check, clearing status', PuntWorkLogger::CONTEXT_BATCH, [
                'processed' => $progress['processed'],
                'time_elapsed' => $time_elapsed
            ]);
            delete_option('job_import_status');
            delete_option('job_import_progress');
            delete_option('job_import_processed_guids');
            delete_option('job_import_last_batch_time');
            delete_option('job_import_last_batch_processed');
            delete_option('job_import_batch_size');
            delete_option('job_import_consecutive_small_batches');
            delete_transient('import_cancel');
            
            // Return fresh status
            $progress = [
                'total' => 0,
                'processed' => 0,
                'published' => 0,
                'updated' => 0,
                'skipped' => 0,
                'duplicates_drafted' => 0,
                'time_elapsed' => 0,
                'complete' => true, // Fresh state is complete
                'success' => false,
                'error_message' => '',
                'batch_size' => 10,
                'inferred_languages' => 0,
                'inferred_benefits' => 0,
                'schema_generated' => 0,
                'start_time' => microtime(true),
                'end_time' => null,
                'last_update' => time(),
                'logs' => [],
            ];
        }
    }

    if (!isset($progress['start_time'])) {
        $progress['start_time'] = microtime(true);
    }
    // Calculate elapsed time properly - if we have a start time, use it
    if (isset($progress['start_time']) && $progress['start_time'] > 0) {
        $current_time = microtime(true);
        $progress['time_elapsed'] = $current_time - $progress['start_time'];
    } else {
        $progress['time_elapsed'] = $progress['time_elapsed'] ?? 0;
    }
    $progress['complete'] = ($progress['processed'] >= $progress['total'] && $progress['total'] > 0);

    // Add resume_progress for JavaScript
    $progress['resume_progress'] = (int) get_option('job_import_progress', 0);

    // Track job importing start time
    if ($progress['total'] > 1 && !isset($progress['job_import_start_time'])) {
        $progress['job_import_start_time'] = microtime(true);
        update_option('job_import_status', $progress);
    }

    // Calculate job importing elapsed time
    $progress['job_importing_time_elapsed'] = isset($progress['job_import_start_time']) ? microtime(true) - $progress['job_import_start_time'] : $progress['time_elapsed'];

    // Add batch timing data for accurate time calculations
    $progress['batch_time'] = (float) get_option('job_import_last_batch_time', 0);
    $progress['batch_processed'] = (int) get_option('job_import_last_batch_processed', 0);

    // Add estimated time remaining calculation from PHP
    $progress['estimated_time_remaining'] = calculate_estimated_time_remaining($progress);

    // Log response summary instead of full data to prevent large debug logs
    $log_summary = [
        'total' => $progress['total'],
        'processed' => $progress['processed'],
        'published' => $progress['published'],
        'updated' => $progress['updated'],
        'skipped' => $progress['skipped'],
        'complete' => $progress['complete'],
        'success' => $progress['success'],
        'time_elapsed' => $progress['time_elapsed'],
        'job_importing_time_elapsed' => $progress['job_importing_time_elapsed'],
        'estimated_time_remaining' => $progress['estimated_time_remaining'],
        'batch_time' => $progress['batch_time'],
        'batch_processed' => $progress['batch_processed'],
        'logs_count' => is_array($progress['logs']) ? count($progress['logs']) : 0,
        'has_error' => !empty($progress['error_message'])
    ];

    PuntWorkLogger::logAjaxResponse('get_job_import_status', $log_summary);
    wp_send_json_success($progress);
}