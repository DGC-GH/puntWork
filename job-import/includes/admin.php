<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Add admin menu from snippet 6
function job_import_add_admin_menu() {
    add_menu_page(
        'Job Import',
        'Job Import',
        'manage_options',
        'job-import',
        'job_import_admin_page',
        'dashicons-download',
        30
    );
}

// Admin page HTML from snippet 2
function job_import_admin_page() {
    if (!current_user_can('manage_options')) return;

    // Nonce for security
    wp_nonce_field('job_import_nonce', 'job_import_nonce');

    // Form for manual import
    ?>
    <div class="wrap">
        <h1>Job Import Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('job_import_options'); ?>
            <table class="form-table">
                <tr>
                    <th>Feed URL</th>
                    <td><input type="url" name="job_feed_url" value="<?php echo esc_attr(get_option('job_feed_url', JOB_IMPORT_FEED_URL)); ?>" /></td>
                </tr>
                <tr>
                    <th>Batch Size</th>
                    <td><input type="number" name="job_batch_size" value="<?php echo esc_attr(get_option('job_batch_size', JOB_IMPORT_BATCH_SIZE)); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <h2>Actions</h2>
        <button id="start-import" class="button button-primary">Start Import</button>
        <div id="progress-bar" style="display:none; width:100%; height:20px; background:#ddd;">
            <div id="progress" style="height:100%; background:#0073aa; width:0%;"></div>
        </div>
        <p id="status"></p>
    </div>
    <?php
}

// Register settings
add_action('admin_init', 'job_import_register_settings');
function job_import_register_settings() {
    register_setting('job_import_options', 'job_feed_url');
    register_setting('job_import_options', 'job_batch_size');
}
?>
