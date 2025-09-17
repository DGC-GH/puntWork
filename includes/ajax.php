<?php
/**
 * AJAX handlers for Job Import Plugin
 * Refactored from old WPCode snippet 4 - AJAX Handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manual import for specific feed (new: per-feed trigger).
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

    require_once __DIR__ . '/processor.php';
    $result = process_single_feed( $feed_url, $post_id ); // Force = true implicit in manual
    update_feed_last_run( $post_id );

    wp_send_json_success( array(
        'imported' => $result['imported'],
        'errors' => $result['errors'],
        'message' => "Processed feed {$post_id}: {$result['imported']} jobs imported."
    ) );
}
add_action( 'wp_ajax_manual_feed_import', 'ajax_manual_feed_import' );

// Existing handlers (e.g., full batch)...
function ajax_full_import() {
    // Prior logic: process_all_imports( true );
}
add_action( 'wp_ajax_full_import', 'ajax_full_import' );
