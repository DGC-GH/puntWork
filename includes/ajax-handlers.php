?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
    global $wpdb;

    $lock_start = microtime(true);
    while (get_transient('job_import_purge_lock')) {
        usleep(50000);
        if (microtime(true) - $lock_start > 10) {
            error_log('Purge lock timeout');
            wp_send_json_error(['message' => 'Purge lock timeout']);
        }
    }
    set_transient('job_import_purge_lock', true, 10);

    try {
        $processed_guids = get_option('job_import_processed_guids') ?: [];
        $progress = get_option('job_import_status') ?: [];
        $progress['logs'] = is_array($progress['logs']) ? $progress['logs'] : [];
        if ($progress['processed'] < $progress['total'] || $progress['total'] == 0) {
            delete_transient('job_import_purge_lock');
            error_log('Purge skipped: import not complete');
            wp_send_json_error(['message' => 'Import not complete or empty; purge skipped']);
        }
        $drafted_old = 0;
        $all_jobs = get_option('job_existing_guids');
        if (false === $all_jobs) {
            $all_jobs = $wpdb->get_results("SELECT p.ID, pm.meta_value AS guid FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID = pm.post_id WHERE p.post_type = 'job' AND pm.meta_key = 'guid'");
        }
        $old_jobs = [];
        foreach ($all_jobs as $job) {
            if (!in_array($job->guid, $processed_guids)) {
                $old_jobs[] = $job;
            }
        }
        $old_ids = array_column($old_jobs, 'ID');
        if (!empty($old_ids)) {
            $placeholders = implode(',', array_fill(0, count($old_ids), '%d'));
            $wpdb->query($wpdb->prepare("UPDATE $wpdb->posts SET post_status = 'draft' WHERE ID IN ($placeholders)", ...$old_ids));
            $drafted_old = count($old_ids);
            foreach ($old_jobs as $job) {
                $progress['logs'][] = 'Drafted ID: ' . $job->ID . ' GUID: ' . $job->guid . ' - No longer in feed';
                error_log('Drafted ID: ' . $job->ID . ' GUID: ' . $job->guid . ' - No longer in feed');
            }
            wp_cache_flush();
        }
        $progress['drafted_old'] += $drafted_old;
        delete_option('job_import_processed_guids');
        delete_option('job_existing_guids');
        $progress['end_time'] = microtime(true);
        update_option('job_import_status', $progress, false);
        delete_transient('job_import_purge_lock');
        wp_send_json_success(['message' => 'Purge completed, drafted ' . $drafted_old . ' old jobs']);
    } catch (Exception $e) {
        delete_transient('job_import_purge_lock');
        error_log('Purge failed: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Purge failed: ' . $e->getMessage()]);
    }
}

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
