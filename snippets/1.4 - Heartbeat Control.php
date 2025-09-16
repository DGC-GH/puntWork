add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'job_page_job-import-dashboard') {
        wp_deregister_script('heartbeat');
    }
});
