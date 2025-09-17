<?php
/**
 * Enqueue Scripts and Styles for Job Import Plugin
 * Ported from old WPCode snippet 3 - Enqueue Scripts and JS.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueue admin assets.
 */
function job_import_enqueue_admin() {
    if ( ! is_admin() ) return;

    wp_enqueue_style( 'job-import-admin', JOB_IMPORT_URL . 'assets/admin.css', array(), JOB_IMPORT_VERSION );
    wp_enqueue_script( 'job-import-admin', JOB_IMPORT_URL . 'assets/admin.js', array( 'jquery' ), JOB_IMPORT_VERSION, true );

    // Localize for AJAX
    wp_localize_script( 'job-import-admin', 'jobImportAjax', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'job_import_nonce' ),
    ) );
}
add_action( 'admin_enqueue_scripts', 'job_import_enqueue_admin' );

/**
 * Enqueue frontend shortcode assets if needed.
 */
function job_import_enqueue_frontend() {
    if ( ! is_admin() && has_shortcode( get_post()->post_content ?? '', 'job_import_stats' ) ) {
        wp_enqueue_script( 'job-import-frontend', JOB_IMPORT_URL . 'assets/frontend.js', array( 'jquery' ), JOB_IMPORT_VERSION, true );
    }
}
add_action( 'wp_enqueue_scripts', 'job_import_enqueue_frontend' );
