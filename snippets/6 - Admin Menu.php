add_action('admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=job',
        'Job Import Dashboard',
        'Import Jobs',
        'manage_options',
        'job-import-dashboard',
        'job_import_admin_page',
        1
    );
});
