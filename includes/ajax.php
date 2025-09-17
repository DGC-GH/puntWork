<?php
/**
 * AJAX handlers for Job Import Plugin
 * Refactored from old WPCode snippet 4 - AJAX Handlers.php + 1.5 Heartbeat AJAX.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manual import for specific feed.
 */
function ajax_manual_feed_import() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }

    $post_id = intval( $_POST['feed_id'] ?? 0 );
    if ( ! $post_id ) {
        wp_send_json_error( 'Invalid feed ID' );
    }

    $feed_url = get_field( 'feed_url', $post_id );
    if ( ! $feed_url ) {
        wp_send_json_error( 'No feed URL found' );
    }

    set_transient( 'job_import_running', true, 300 ); // Mark running
    require_once __DIR__ . '/processor.php';
    $result = process_single_feed( $feed_url, $post_id );
    update_feed_last_run( $post_id );
    delete_transient( 'job_import_running' );

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
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }
    set_transient( 'job_import_running', true, 300 );
    require_once __DIR__ . '/processor.php';
    $result = process_all_imports( true );
    delete_transient( 'job_import_running' );
    wp_send_json_success( $result );
}
add_action( 'wp_ajax_full_import', 'ajax_full_import' );

/**
 * Heartbeat import status (ported from 1.5).
 */
function ajax_heartbeat_import_status() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    $progress = get_transient( 'job_import_progress' ) ?: 0;
    $running = get_transient( 'job_import_running' );
    wp_send_json_success( array( 'progress' => $progress, 'running' => $running, 'errors' => 0 ) );
}
add_action( 'wp_ajax_heartbeat_import_status', 'ajax_heartbeat_import_status' );
