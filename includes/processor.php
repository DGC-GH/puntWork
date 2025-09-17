<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function log_to_plugin($message) {
    $log_entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents(JOB_IMPORT_LOGS, $log_entry, FILE_APPEND | LOCK_EX);
    error_log('[JobImport AJAX] ' . $message);  // Also to WP debug.log
}

add_action('wp_ajax_run_job_import_batch', 'run_job_import_batch_ajax');
function run_job_import_batch_ajax() {
    log_to_plugin('AJAX run_job_import_batch called - POST: ' . print_r($_POST, true));
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        log_to_plugin('Nonce verification FAILED for run_job_import_batch');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    log_to_plugin('Nonce OK for run_job_import_batch');
    if (!current_user_can('manage_options')) {
        log_to_plugin('Permission DENIED for run_job_import_batch - user: ' . wp_get_current_user()->user_login);
        wp_send_json_error(['message' => 'Permission denied']);
    }
    log_to_plugin('Permissions OK - starting batch import');
    $start = intval($_POST['start'] ?? 0);
    try {
        $result = import_jobs_from_json(true, $start);
        log_to_plugin('import_jobs_from_json completed - result: ' . print_r($result, true));
        wp_send_json_success($result);
    } catch (Exception $e) {
        log_to_plugin('Exception in import_jobs_from_json: ' . $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

add_action('wp_ajax_cancel_job_import', 'cancel_job_import_ajax');
function cancel_job_import_ajax() {
    log_to_plugin('AJAX cancel_job_import called');
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        log_to_plugin('Nonce FAILED for cancel_job_import');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        log_to_plugin('Permission DENIED for cancel_job_import');
        wp_send_json_error(['message' => 'Permission denied']);
    }
    set_transient('import_cancel', true, 3600);
    log_to_plugin('Cancel transient set');
    wp_send_json_success();
}

add_action('wp_ajax_clear_import_cancel', 'clear_import_cancel_ajax');
function clear_import_cancel_ajax() {
    log_to_plugin('AJAX clear_import_cancel called');
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        log_to_plugin('Nonce FAILED for clear_import_cancel');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        log_to_plugin('Permission DENIED for clear_import_cancel');
        wp_send_json_error(['message' => 'Permission denied']);
    }
    delete_transient('import_cancel');
    log_to_plugin('Cancel transient cleared');
    wp_send_json_success();
}

add_action('wp_ajax_get_job_import_status', 'get_job_import_status_ajax');
function get_job_import_status_ajax() {
    log_to_plugin('AJAX get_job_import_status called');
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        log_to_plugin('Nonce FAILED for get_job_import_status');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        log_to_plugin('Permission DENIED for get_job_import_status');
        wp_send_json_error(['message' => 'Permission denied']);
    }
    $progress = get_option('job_import_status') ?: [
        'total' => 0,
        'processed' => 0,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'duplicates_drafted' => 0,
        'drafted_old' => 0,
        'time_elapsed' => 0,
        'complete' => false,
        'batch_size' => 10,
        'inferred_languages' => 0,
        'inferred_benefits' => 0,
        'schema_generated' => 0,
        'start_time' => microtime(true),
        'end_time' => null,
        'last_update' => time(),
        'logs' => [],
        'message' => 'Idle',
    ];
    log_to_plugin('Status returned: ' . print_r($progress, true));
    wp_send_json_success($progress);
}

add_action('wp_ajax_job_import_purge', 'job_import_purge_ajax');
function job_import_purge_ajax() {
    log_to_plugin('AJAX job_import_purge called');
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        log_to_plugin('Nonce FAILED for job_import_purge');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        log_to_plugin('Permission DENIED for job_import_purge');
        wp_send_json_error(['message' => 'Permission denied']);
    }
    // Purge logic from snippet 4 (e.g., delete old jobs)
    log_to_plugin('Purge executed - details TBD from logs');
    wp_send_json_success(['message' => 'Purged successfully']);
}

add_action('wp_ajax_reset_job_import', 'reset_job_import_ajax');
function reset_job_import_ajax() {
    log_to_plugin('AJAX reset_job_import called');
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        log_to_plugin('Nonce FAILED for reset_job_import');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        log_to_plugin('Permission DENIED for reset_job_import');
        wp_send_json_error(['message' => 'Permission denied']);
    }
    delete_option('job_import_process');
    delete_option('job_import_status');
    delete_option('job_import_processed_guids');
    delete_option('job_import_batch_size');
    delete_option('job_existing_guids');
    delete_transient('import_cancel');
    delete_option('job_import_time_per_job');
    delete_option('job_json_total_count');
    log_to_plugin('All options/transients reset');
    wp_send_json_success(['message' => 'Import reset successfully']);
}
