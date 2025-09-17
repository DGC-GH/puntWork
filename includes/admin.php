<?php
/**
 * Admin interface for Job Import Plugin
 * Refactored from old WPCode snippets 2, 6.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add admin menu.
 */
function job_import_admin_menu() {
    add_options_page(
        'Job Import',
        'Job Import',
        'manage_options',
        'job-import',
        'job_import_admin_page'
    );
}
add_action( 'admin_menu', 'job_import_admin_menu' );

/**
 * Render admin page.
 */
function job_import_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Existing stats/shortcode output here...
    echo '<div class="wrap"><h1>Job Import Dashboard</h1>';
    echo '<p>Total Jobs: ' . wp_count_posts( 'job' )->publish . '</p>';
    echo '<p>Last Run: ' . get_option( 'job_import_last_run', 'Never' ) . '</p>';

    // New section: List job-feeds
    echo '<h2>Job Feeds Management</h2>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ID</th><th>Title</th><th>Feed URL</th><th>Last Run</th><th>Actions</th></tr></thead><tbody>';

    $feeds_query = new WP_Query( array(
        'post_type' => 'job-feed',
        'post_status' => 'publish',
        'posts_per_page' => -1,
    ) );

    if ( $feeds_query->have_posts() ) {
        foreach ( $feeds_query->posts as $post ) {
            $feed_url = get_field( 'feed_url', $post->ID );
            $last_run = get_feed_last_run( $post->ID );
            echo '<tr>';
            echo '<td>' . $post->ID . '</td>';
            echo '<td><a href="' . get_edit_post_link( $post->ID ) . '">' . esc_html( $post->post_title ) . '</a></td>';
            echo '<td>' . esc_url( $feed_url ) . '</td>';
            echo '<td>' . ( $last_run ? date( 'Y-m-d H:i', strtotime( $last_run ) ) : 'Never' ) . '</td>';
            echo '<td><button class="button manual-import-btn" data-feed-id="' . $post->ID . '">Manual Import</button></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5">No published job-feeds found. <a href="' . admin_url( 'post-new.php?post_type=job-feed' ) . '">Add one</a>.</td></tr>';
    }

    echo '</tbody></table></div>';

    // Enqueue JS for buttons (via enqueue.php)
    wp_enqueue_script( 'job-import-admin' );
    wp_localize_script( 'job-import-admin', 'jobImportAjax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );

    wp_reset_postdata();
}
