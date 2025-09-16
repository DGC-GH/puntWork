<?php
// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==================== SNIPPET 6: Admin Menu ====================
add_action( 'admin_menu', 'job_import_admin_menu' );
function job_import_admin_menu() {
    add_menu_page(
        'Job Import',
        'Job Import',
        'manage_options',
        'job-import',
        'job_import_admin_page',
        'dashicons-update',
        30
    );
}

// ==================== SNIPPET 3: Enqueue Scripts and JS ====================
add_action( 'admin_enqueue_scripts', 'job_import_enqueue_admin' );
function job_import_enqueue_admin( $hook ) {
    if ( $hook !== 'toplevel_page_job-import' ) return;
    wp_enqueue_style( 'job-import-admin-css', JOB_IMPORT_PLUGIN_URL . 'assets/css/admin.css', [], JOB_IMPORT_VERSION );
    wp_enqueue_script( 'job-import-admin-js', JOB_IMPORT_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], JOB_IMPORT_VERSION, true );
    wp_localize_script( 'job-import-admin-js', 'job_ajax', [ 'ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'job_import' ) ] );
}

// ==================== SNIPPET 2: Admin Page HTML ====================
function job_import_admin_page() {
    ?>
    <div class="wrap">
        <h1># Job Import</h1> <!-- From snippet 2 -->
        <p>Manage job imports here.</p>
        <button id="trigger-import" class="button button-primary">Run Import Now</button>
        <div id="import-status"></div>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>Job Title</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
                <?php
                $jobs = get_posts( [ 'post_type' => JOB_IMPORT_POST_TYPE, 'posts_per_page' => 20 ] );
                foreach ( $jobs as $job ) {
                    echo '<tr><td>' . esc_html( get_the_title( $job->ID ) ) . '</td><td>Imported</td><td>' . esc_html( get_the_date( '', $job->ID ) ) . '</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}
