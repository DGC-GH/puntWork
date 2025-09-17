<?php
/**
 * AJAX handlers for Job Import Plugin
 * Refactored from old WPCode snippet 4 - AJAX Handlers.php + 1.5 Heartbeat AJAX.
 * Enhanced: Added detailed log_import_event calls for full flow debugging.
 * Compat: Replaced ?? with isset() ternary for PHP 5.6+.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manual import for specific feed.
 */
function ajax_manual_feed_import() {
    $feed_id = isset( $_POST['feed_id'] ) ? $_POST['feed_id'] : 'unknown';
    log_import_event( "AJAX: Manual import STARTED for feed ID {$feed_id} by user " . get_current_user_id(), 'info' );

    if ( ! current_user_can( 'manage_options' ) ) {
        log_import_event( 'AJAX: Unauthorized access attempt to manual_feed_import', 'error' );
        wp_die( 'Unauthorized' );
    }

    $post_id = intval( $_POST['feed_id'] ?? 0 );
    if ( ! $post_id ) {
        log_import_event( 'AJAX: Invalid feed ID provided: ' . ( isset( $_POST['feed_id'] ) ? $_POST['feed_id'] : 'none' ), 'error' );
        wp_send_json_error( 'Invalid feed ID' );
    }

    $feed_url = get_field( 'feed_url', $post_id );
    if ( ! $feed_url ) {
        log_import_event( "AJAX: No feed URL found for post ID {$post_id}", 'error' );
        wp_send_json_error( 'No feed URL found' );
    }

    log_import_event( "AJAX: Starting process_single_feed for URL {$feed_url} (post {$post_id})", 'info' );

    set_transient( 'job_import_running', true, 300 ); // Mark running
    require_once __DIR__ . '/processor.php';
    $result = process_single_feed( $feed_url, $post_id );
    update_feed_last_run( $post_id );
    delete_transient( 'job_import_running' );

    log_import_event( "AJAX: Manual import COMPLETED for feed {$post_id}. Result: imported={$result['imported']}, errors={$result['errors']}", 'info' );

    wp_send_json_success( array(
        'imported' => $result['imported'],
        'errors' => $result['errors'],
        'message' => "Processed feed {$post_id}: {$result['imported']} jobs imported."
    ) );
}
add_action( 'wp_ajax_manual_feed_import', 'ajax_manual_feed_import' );

/**
 * Full batch import.
 */
function ajax_full_import() {
    log_import_event( 'AJAX: Full batch import STARTED by user ' . get_current_user_id(), 'info' );

    if ( ! current_user_can( 'manage_options' ) ) {
        log_import_event( 'AJAX: Unauthorized access to full_import', 'error' );
        wp_die( 'Unauthorized' );
    }
    set_transient( 'job_import_running', true, 300 );
    require_once __DIR__ . '/processor.php';
    $result = process_all_imports( true );
    delete_transient( 'job_import_running' );

    log_import_event( 'AJAX: Full batch import COMPLETED. Stats: ' . print_r( $result, true ), 'info' );

    wp_send_json_success( $result );
}
add_action( 'wp_ajax_full_import', 'ajax_full_import' );

/**
 * Heartbeat import status (ported from 1.5).
 */
function ajax_heartbeat_import_status() {
    log_import_event( 'AJAX: Heartbeat status check by user ' . get_current_user_id(), 'debug' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    $progress = get_transient( 'job_import_progress' ) ?: 0;
    $running = get_transient( 'job_import_running' );
    wp_send_json_success( array( 'progress' => $progress, 'running' => $running, 'errors' => 0 ) );
}
add_action( 'wp_ajax_heartbeat_import_status', 'ajax_heartbeat_import_status' );
?>
