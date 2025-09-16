<?php
// includes/ajax.php
// AJAX handlers. Added nonce verification and error responses for security/debug.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_trigger_import', 'job_import_ajax_trigger' );
/**
 * AJAX: Trigger import. Verify nonce, log errors.
 */
function job_import_ajax_trigger() {
    // Security check.
    if ( ! wp_verify_nonce( $_POST['nonce'], 'job_import_ajax' ) ) {
        wp_send_json_error( 'Nonce failed.' );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    // Trigger with error handling.
    try {
        trigger_import();
        wp_send_json_success( 'Import triggered!' );
    } catch ( Exception $e ) {
        log_message( 'AJAX Trigger Error: ' . $e->getMessage() );
        wp_send_json_error( 'Trigger failed: ' . $e->getMessage() );
    }
}
?>
