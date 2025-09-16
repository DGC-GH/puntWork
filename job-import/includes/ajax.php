<?php
// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==================== SNIPPET 4: AJAX Handlers ====================
add_action( 'wp_ajax_trigger_job_import', 'handle_ajax_import' );
function handle_ajax_import() {
    check_ajax_referer( 'job_import', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }
    trigger_import();
    wp_send_json_success( [ 'message' => 'Import triggered.' ] );
}
