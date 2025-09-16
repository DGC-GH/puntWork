<?php
// includes/admin.php
// Admin settings page and UI. Enhanced with nonces and validation for security.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'job_import_admin_menu' );
/**
 * Add admin menu page.
 */
function job_import_admin_menu() {
    add_options_page(
        'Job Import Settings',
        'Job Import',
        'manage_options',
        'job-import',
        'job_import_admin_page'
    );
}

/**
 * Render admin page with form.
 */
function job_import_admin_page() {
    // Handle form submission with nonce.
    if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['job_import_nonce'], 'job_import_save' ) ) {
        update_option( 'job_feed_url', esc_url_raw( $_POST['feed_url'] ) );
        update_option( 'job_batch_size', absint( $_POST['batch_size'] ) );
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }

    $feed_url = get_option( 'job_feed_url', JOB_FEED_URL );
    $batch_size = get_option( 'job_batch_size', 50 );
    ?>
    <div class="wrap">
        <h1>Job Import Settings</h1>
        <form method="post">
            <?php wp_nonce_field( 'job_import_save', 'job_import_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th>Feed URL</th>
                    <td><input type="url" name="feed_url" value="<?php echo esc_attr( $feed_url ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th>Batch Size</th>
                    <td><input type="number" name="batch_size" value="<?php echo esc_attr( $batch_size ); ?>" min="1" max="100" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <h2>Import Status</h2>
        <p>Last Run: <?php echo esc_html( date( 'Y-m-d H:i', get_option( 'job_import_last_run', 0 ) ) ); ?></p>
        <button id="trigger-import" class="button button-primary">Manual Import</button>
    </div>
    <?php
}
?>
