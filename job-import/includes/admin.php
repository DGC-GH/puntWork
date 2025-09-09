//
//  admin.php
//  
//
//  Created by Dimitri Gulla on 09/09/2025.
//

<?php
if (!defined('ABSPATH')) {
    exit;
}

// Admin menu (from snippet 6).
add_action('admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=job',
        'Job Import Dashboard',
        'Import Jobs',
        'manage_options',
        'job-import-dashboard',
        'job_import_admin_page', // Callback to output HTML.
        1
    );
});

// Admin page HTML (from snippet 2).
function job_import_admin_page() {
    // Paste the HTML output here (the <div class="wrap">...).
    // Remove the inline <script> – move to assets/js/admin.js.
    // Add nonce: echo wp_nonce_field('job_import_nonce');
}

// Enqueue scripts/styles (from snippet 3).
add_action('admin_enqueue_scripts', function($hook) {
    if (isset($_GET['page']) && $_GET['page'] === 'job-import-dashboard') {
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', [], '5.15.4');
        wp_enqueue_style('job-import-admin-css', JOB_IMPORT_URL . 'assets/css/admin.css'); // If you extract styles.
        wp_enqueue_script('job-import-admin-js', JOB_IMPORT_URL . 'assets/js/admin.js', ['jquery'], '1.0', true);
        wp_localize_script('job-import-admin-js', 'jobImportData', ['nonce' => wp_create_nonce('job_import_nonce'), 'ajaxurl' => admin_url('admin-ajax.php')]);
    }
});

// Shortcode (from snippet 5).
add_shortcode('job_update_status', function($atts, $content, $tag) {
    global $post;
    if ($post->post_modified > $post->post_date) {
        return '<span class="updated-badge">Updated ' . human_time_diff(strtotime($post->post_modified)) . ' ago</span>';
    }
    return '';
});<?php
if (!defined('ABSPATH')) {
    exit;
}

// Admin menu (from snippet 6).
add_action('admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=job',
        'Job Import Dashboard',
        'Import Jobs',
        'manage_options',
        'job-import-dashboard',
        'job_import_admin_page', // Callback to output HTML.
        1
    );
});

// Admin page HTML (from snippet 2).
function job_import_admin_page() {
    // Paste the HTML output here (the <div class="wrap">...).
    // Remove the inline <script> – move to assets/js/admin.js.
    // Add nonce: echo wp_nonce_field('job_import_nonce');
}

// Enqueue scripts/styles (from snippet 3).
add_action('admin_enqueue_scripts', function($hook) {
    if (isset($_GET['page']) && $_GET['page'] === 'job-import-dashboard') {
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', [], '5.15.4');
        wp_enqueue_style('job-import-admin-css', JOB_IMPORT_URL . 'assets/css/admin.css'); // If you extract styles.
        wp_enqueue_script('job-import-admin-js', JOB_IMPORT_URL . 'assets/js/admin.js', ['jquery'], '1.0', true);
        wp_localize_script('job-import-admin-js', 'jobImportData', ['nonce' => wp_create_nonce('job_import_nonce'), 'ajaxurl' => admin_url('admin-ajax.php')]);
    }
});

// Shortcode (from snippet 5).
add_shortcode('job_update_status', function($atts, $content, $tag) {
    global $post;
    if ($post->post_modified > $post->post_date) {
        return '<span class="updated-badge">Updated ' . human_time_diff(strtotime($post->post_modified)) . ' ago</span>';
    }
    return '';
});
