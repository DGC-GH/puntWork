<?php
/**
 * Heartbeat Control for Job Import Plugin
 * Ported from old WPCode snippets 1.4 - Heartbeat Control.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Check for running imports via heartbeat tick.
 */
function check_heartbeat_imports() {
    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $last_run = get_option( 'job_import_last_run' );
    if ( $last_run && ( time() - strtotime( $last_run ) ) < 300 ) { // <5min
        echo '<div class="notice notice-warning"><p>Job Import: Ongoing batch – check logs.</p></div>';
    }
}
add_action( 'admin_notices', 'check_heartbeat_imports' );

/**
 * Heartbeat tick for status (integrates with 1.5).
 */
add_action( 'heartbeat_tick', 'job_import_heartbeat_tick' );
function job_import_heartbeat_tick( $response ) {
    $response['job_import'] = array(
        'running' => ( get_transient( 'job_import_running' ) ? true : false ),
        'progress' => get_transient( 'job_import_progress' ) ?: 0,
    );
    return $response;
}
