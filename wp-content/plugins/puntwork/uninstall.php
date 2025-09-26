<?php
// uninstall.php (NEW FILE: Add to root of job-import/ for cleanup on uninstall.)
// Handles CPT flush, option cleanup.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Delete options.
delete_option( 'job_feed_url' );
delete_option( 'job_batch_size' );
delete_option( 'job_import_last_run' );

// Flush rewrite rules.
flush_rewrite_rules();

// Clear cron.
wp_clear_scheduled_hook( 'job_import_cron' );

// Optional: Delete logs dir.
$logs_dir = plugin_dir_path( __FILE__ ) . 'logs/';
if ( file_exists( $logs_dir ) ) {
    array_map( 'unlink', glob( $logs_dir . '*' ) );
    rmdir( $logs_dir );
}
?>
