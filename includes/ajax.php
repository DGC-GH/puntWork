add_action('wp_ajax_run_job_import_batch', 'run_job_import_batch_ajax');
function run_job_import_batch_ajax() {
    error_log('run_job_import_batch_ajax called with data: ' . print_r($_POST, true));
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        error_log('Nonce verification failed for run_job_import_batch');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        error_log('Permission denied for run_job_import_batch');
        wp_send_json_error(['message' => 'Permission denied']);
    }
    $start = intval($_POST['start']);
    $result = import_jobs_from_json(true, $start);
    wp_send_json_success($result);
}

add_action('wp_ajax_cancel_job_import', 'cancel_job_import_ajax');
function cancel_job_import_ajax() {
    error_log('cancel_job_import_ajax called');
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        error_log('Nonce verification failed for cancel_job_import');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        error_log('Permission denied for cancel_job_import');
        wp_send_json_error(['message' => 'Permission denied']);
    }
    set_transient('import_cancel', true, 3600);
    wp_send_json_success();
}

add_action('wp_ajax_clear_import_cancel', 'clear_import_cancel_ajax');
function clear_import_cancel_ajax() {
    error_log('clear_import_cancel_ajax called');
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        error_log('Nonce verification failed for clear_import_cancel');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        error_log('Permission denied for clear_import_cancel');
        wp_send_json_error(['message' => 'Permission denied']);
    }
    delete_transient('import_cancel');
    wp_send_json_success();
}

add_action('wp_ajax_get_job_import_status', 'get_job_import_status_ajax');
function get_job_import_status_ajax() {
    error_log('get_job_import_status_ajax called');
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        error_log('Nonce verification failed for get_job_import_status');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        error_log('Permission denied for get_job_import_status');
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
    ];
    error_log('Progress before calculation: ' . print_r($progress, true));
    if (!isset($progress['start_time'])) {
        $progress['start_time'] = microtime(true);
    }
    // Keep the accumulated time_elapsed without recalculating to avoid including idle time
    $progress['time_elapsed'] = $progress['time_elapsed'] ?? 0;
    $progress['complete'] = ($progress['processed'] >= $progress['total']);
    error_log('Returning status: ' . print_r($progress, true));
    wp_send_json_success($progress);
}

add_action('wp_ajax_job_import_purge', 'job_import_purge_ajax');
function job_import_purge_ajax() {
    error_log('job_import_purge_ajax called');
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        error_log('Nonce verification failed for job_import_purge');
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) {
        error_log('Permission denied for job_import_purge');
        wp_send_json_error(['message' => 'Permission denied']);
    }
    // Purge logic (from snippet)
    wp_send_json_success();
}

add_action('wp_ajax_reset_job_import', 'reset_job_import_ajax');
function reset_job_import_ajax() {
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied']);
    delete_option('job_import_process');
    delete_option('job_import_status');
    delete_option('job_import_processed_guids');
    delete_option('job_import_batch_size');
    delete_option('job_existing_guids');
    delete_transient('import_cancel');
    delete_option('job_import_time_per_job');
    delete_option('job_json_total_count');
    wp_send_json_success(['message' => 'Import reset successfully']);
}
