<?php
/**
 * Import reset utilities
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
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

    // Clear all import-related transients and options for a fresh start
    delete_transient('import_cancel');
    delete_transient('puntwork_feeds');

    // Clear all import progress and status options
    delete_option('job_import_progress');
    delete_option('job_import_status');
    delete_option('job_import_processed_guids');
    delete_option('job_existing_guids');
    delete_option('job_import_time_per_job');
    delete_option('job_import_avg_time_per_job');
    delete_option('job_import_last_peak_memory');
    delete_option('job_import_batch_size');
    delete_option('job_json_total_count');

    // Clear any cached feed files
    $feeds_dir = ABSPATH . 'feeds/';
    if (is_dir($feeds_dir)) {
        $files = glob($feeds_dir . '*.jsonl');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $gz_files = glob($feeds_dir . '*.jsonl.gz');
        foreach ($gz_files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $xml_files = glob($feeds_dir . '*.xml');
        foreach ($xml_files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        // Remove combined file if it exists
        $combined_file = $feeds_dir . 'combined-jobs.jsonl';
        if (file_exists($combined_file)) {
            @unlink($combined_file);
        }
        $combined_gz = $combined_file . '.gz';
        if (file_exists($combined_gz)) {
            @unlink($combined_gz);
        }
    }

    PuntWorkLogger::info('Import reset complete - all previous data cleared', PuntWorkLogger::CONTEXT_BATCH);

    PuntWorkLogger::logAjaxResponse('reset_job_import', ['message' => 'Import reset successfully']);
    wp_send_json_success(['message' => 'Import reset successfully']);
}
