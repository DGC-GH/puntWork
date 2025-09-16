<?php
/**
 * Plugin Name: Job Import
 * Plugin URI: https://github.com/DGC-GH/puntWork
 * Description: A custom WordPress plugin to import job listings from XML feeds, process batches, handle duplicates, and display via shortcode.
 * Version: 1.0.0
 * Author: DGC-GH
 * License: GPL v2 or later
 * Text Domain: job-import
 * Domain Path: /languages
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants.
define( 'JOB_IMPORT_VERSION', '1.0.0' );
define( 'JOB_IMPORT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JOB_IMPORT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JOB_IMPORT_POST_TYPE', 'job_listing' );

// Activation hook: Register post type and schedule cron.
register_activation_hook( __FILE__, 'job_import_activate' );
function job_import_activate() {
    job_import_register_post_type();
    if ( ! wp_next_scheduled( 'job_import_cron' ) ) {
        wp_schedule_event( time(), 'hourly', 'job_import_cron' );
    }
}

// Deactivation hook: Clear cron.
register_deactivation_hook( __FILE__, 'job_import_deactivate' );
function job_import_deactivate() {
    wp_clear_scheduled_hook( 'job_import_cron' );
}

// Register custom post type for jobs.
function job_import_register_post_type() {
    $labels = array(
        'name'               => 'Jobs',
        'singular_name'      => 'Job',
        'menu_name'          => 'Jobs',
        'add_new'            => 'Add New',
        'add_new_item'       => 'Add New Job',
        'edit_item'          => 'Edit Job',
        'new_item'           => 'New Job',
        'view_item'          => 'View Job',
        'search_items'       => 'Search Jobs',
        'not_found'          => 'No jobs found',
        'not_found_in_trash' => 'No jobs found in trash',
    );
    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array( 'slug' => 'job' ),
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => 20,
        'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
        'taxonomies'         => array( 'category' ),
    );
    register_post_type( JOB_IMPORT_POST_TYPE, $args );
}
add_action( 'init', 'job_import_register_post_type' );

// Include core files.
require_once JOB_IMPORT_PLUGIN_DIR . 'includes/core.php';
require_once JOB_IMPORT_PLUGIN_DIR . 'includes/admin.php';
require_once JOB_IMPORT_PLUGIN_DIR . 'includes/ajax.php';
