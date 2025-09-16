<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

function job_import_enqueue_assets($hook) {
    if ($hook !== 'toplevel_page_job-import') return;

    wp_enqueue_script('job-import-js', JOB_IMPORT_URL . 'assets/admin.js', ['jquery'], '1.0', true);
    wp_localize_script('job-import-js', 'jobImportAjax', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('job_import_ajax'),
    ]);

    wp_enqueue_style('job-import-css', JOB_IMPORT_URL . 'assets/admin.css', [], '1.0');
}
?>
