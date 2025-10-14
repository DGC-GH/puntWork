<?php
/**
 * Import Status Reset Tool
 * Access this file via web browser to reset stuck import status
 */

// Prevent direct access issues
if (!defined('ABSPATH')) {
    // Try to load WordPress
    $wp_load_path = dirname(__FILE__) . '/wordpress/wp-load.php';
    if (file_exists($wp_load_path)) {
        require_once $wp_load_path;
    } else {
        die('WordPress not found. Please ensure this script is in the correct location.');
    }
}

$message = '';
$status = '';

if (isset($_POST['reset_import'])) {
    // Clear all import-related options
    delete_option('job_import_status');
    delete_option('job_import_progress');
    delete_option('job_import_processed_guids');
    delete_option('job_import_last_batch_time');
    delete_option('job_import_last_batch_processed');
    delete_option('job_import_batch_size');
    delete_option('job_import_consecutive_small_batches');
    delete_transient('import_cancel');

    $message = '<div style="color: green; font-weight: bold;">✓ Import status reset successfully!</div>';

    // Verify the status is cleared
    $current_status = get_option('job_import_status', null);
    if ($current_status === null) {
        $status = '<div style="color: green;">✓ Import status confirmed cleared</div>';
    } else {
        $status = '<div style="color: red;">✗ Import status still exists: ' . esc_html(json_encode($current_status)) . '</div>';
    }
} else {
    // Check current status
    $current_status = get_option('job_import_status', null);
    if ($current_status === null) {
        $status = '<div style="color: green;">✓ Import status is already clear</div>';
    } else {
        $status = '<div style="color: orange;">⚠ Import status exists: <pre style="background: #f5f5f5; padding: 10px; margin: 5px 0; max-height: 200px; overflow: auto;">' . esc_html(json_encode($current_status, JSON_PRETTY_PRINT)) . '</pre></div>';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>PuntWork Import Status Reset</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .container { max-width: 800px; margin: 0 auto; }
        .reset-btn { background: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .reset-btn:hover { background: #c82333; }
        .status-box { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>PuntWork Import Status Reset Tool</h1>
        <p>This tool resets the import status when imports get stuck with "An import is already running" errors.</p>

        <div class="status-box">
            <h3>Current Status:</h3>
            <?php echo $status; ?>
        </div>

        <?php if ($message): ?>
            <div class="status-box">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <p><strong>Warning:</strong> This will clear all import progress and allow new imports to start. Any paused imports will be lost.</p>
            <button type="submit" name="reset_import" value="1" class="reset-btn" onclick="return confirm('Are you sure you want to reset the import status? This will clear all current import progress.')">Reset Import Status</button>
        </form>

        <hr>
        <p><small>If this tool doesn't work, you can also try the "Reset Import" button in the PuntWork admin panel, or contact support.</small></p>
    </div>
</body>
</html>