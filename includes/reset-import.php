?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action('wp_ajax_reset_job_import', 'reset_job_import_ajax');
function reset_job_import_ajax() {
    if (!check_ajax_referer('job_import_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied']);
    delete_option('job_import_progress');
    delete_option('job_import_status');
    delete_option('job_import_processed_guids');
    delete_option('job_import_batch_size');
    delete_option('job_existing_guids');
    delete_transient('import_cancel');
    delete_option('job_import_time_per_job');
    delete_option('job_json_total_count');
    wp_send_json_success(['message' => 'Import reset successfully']);
}
