add_action('admin_enqueue_scripts', function($hook) {
    if (isset($_GET['page']) && $_GET['page'] === 'job-import-dashboard') {
        // Font Awesome for icons (if not already in theme)
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', [], '5.15.4');
        
        // Register handle without src (for inline only)
        wp_register_script('job-import-js', false, ['jquery'], false, true);
        wp_enqueue_script('job-import-js');
        wp_localize_script('job-import-js', 'jobImportData', ['nonce' => wp_create_nonce('job_import_nonce')]);
        wp_add_inline_script('job-import-js', "
            console.log('Job Import JS loaded on page job-import-dashboard');
        ");
    }
});
