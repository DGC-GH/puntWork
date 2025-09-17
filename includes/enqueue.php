<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action('admin_enqueue_scripts', function($hook) {
    if (isset($_GET['page']) && $_GET['page'] === 'job-import-dashboard') {
        // Font Awesome for icons (if not already in theme)
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', [], '5.15.4');
        
        // Enqueue admin JS (was missing!)
        wp_enqueue_script(
            'job-import-admin',
            JOB_IMPORT_URL . 'assets/admin.js',
            ['jquery'],
            JOB_IMPORT_VERSION,
            true
        );
        
        // Localize nonce data to the JS handle
        wp_localize_script('job-import-admin', 'jobImportData', [
            'nonce' => wp_create_nonce('job_import_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ]);
        
        // Optional inline for debugging
        wp_add_inline_script('job-import-admin', "
            console.log('Job Import JS loaded on page job-import-dashboard');
        ");
    }
});
