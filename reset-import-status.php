<?php
/**
 * Reset Import Status Script
 * This script clears the job import status to allow for a fresh import test
 */

// Include WordPress
require_once( dirname( __FILE__ ) . '/wp-load.php' );

// Check if user is admin
if ( ! current_user_can( 'manage_options' ) ) {
    die( 'Access denied' );
}

echo "<h1>Reset Import Status</h1>";

// Clear the import status
delete_option( 'job_import_status' );

// Clear any batch hash transients
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'puntwork_batch_hash_%'" );

// Clear any import-related transients
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_puntwork_batch_hash_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_puntwork_batch_hash_%'" );

echo "<p>Import status has been reset.</p>";
echo "<p>All batch hash caches have been cleared.</p>";
echo "<p>You can now run a fresh import test.</p>";

echo "<p><a href='" . admin_url( 'admin.php?page=puntwork-jobs' ) . "'>Return to Jobs Admin</a></p>";
?>