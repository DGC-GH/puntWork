<?php
/**
 * Plugin Name: Job Import
 * Plugin URI: https://github.com/DGC-GH/puntWork
 * Description: Imports jobs from JSONL feeds into WordPress posts.
 * Version: 1.2.0
 * Author: DGC-GH
 * Requires at least: 5.0
 * Tested up to: 6.6
 * Requires PHP: 7.4
 * Text Domain: job-import
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define constants
define( 'JOB_IMPORT_VERSION', '1.2.0' );
define( 'JOB_IMPORT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JOB_IMPORT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JOB_IMPORT_TEXT_DOMAIN', 'job-import' );

// Activation hook
register_activation_hook( __FILE__, 'job_import_activate' );
function job_import_activate() {
    // Flush rewrite rules if needed, set defaults
    if ( ! get_option( 'job_import_batch_size' ) ) {
        update_option( 'job_import_batch_size', 20 );
    }
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook( __FILE__, 'job_import_deactivate' );
function job_import_deactivate() {
    flush_rewrite_rules();
}

// Internationalization
add_action( 'plugins_loaded', 'job_import_load_textdomain' );
function job_import_load_textdomain() {
    load_plugin_textdomain( JOB_IMPORT_TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

// Include all core files on plugins_loaded
add_action( 'plugins_loaded', 'job_import_include_files' );
function job_import_include_files() {
    $includes = array(
        'mappings-constants.php',
        'core-structure-logic.php',
        'utility-helpers.php',
        'download-feed.php',
        'combine-jsonl.php',
        'gzip-file.php',
        'item-cleaning.php',
        'item-inference.php',
        'process-batch-items.php',
        'handle-duplicates.php',
        'import-batch.php',
        'process-xml-batch.php',
        'reset-import.php',
        'scheduling-triggers.php',
        'heartbeat-control.php',
        'shortcode.php',
        'admin-menu.php',
        'admin-page-html.php',
        'enqueue-scripts-js.php',
        'ajax-handlers.php',  // Ensures AJAX handlers load
    );

    foreach ( $includes as $file ) {
        $path = JOB_IMPORT_PLUGIN_DIR . 'includes/' . $file;
        if ( file_exists( $path ) ) {
            require_once $path;
        } else {
            error_log( 'Job Import: Missing include file - ' . $path );
        }
    }
}

// Enqueue admin scripts/styles (if not in enqueue-scripts-js.php)
add_action( 'admin_enqueue_scripts', 'job_import_admin_enqueue' );
function job_import_admin_enqueue( $hook ) {
    if ( 'toplevel_page_job-import-dashboard' !== $hook && 'job-import_page_job-import-dashboard' !== $hook ) {
        return;
    }
    wp_enqueue_script( 'jquery' );
    // enqueue-scripts-js.php will handle the rest
}

// Register post type if in core-structure-logic.php (stub if needed)
if ( ! function_exists( 'job_import_register_post_type' ) ) {
    add_action( 'init', 'job_import_register_post_type' );
    function job_import_register_post_type() {
        register_post_type( 'job', array(
            'labels' => array( 'name' => 'Jobs', 'singular_name' => 'Job' ),
            'public' => true,
            'has_archive' => true,
            'supports' => array( 'title', 'editor', 'custom-fields' ),
            'show_in_rest' => true,
        ) );
    }
}

// Uninstall hook
if ( defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    require_once JOB_IMPORT_PLUGIN_DIR . 'uninstall.php';
}
