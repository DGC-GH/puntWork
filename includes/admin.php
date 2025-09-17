<?php
/**
 * Admin page for Job Import plugin.
 * Handles the settings/UI for manual imports.
 *
 * @package puntWork
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    <div id="job-import-admin">
        <p>Manual import will process all published Job Feeds and pull jobs from their configured feed URLs.</p>
        <div id="manual-import-section">
            <button type="button" id="manual-import" class="button button-primary" disabled>
                <?php _e('Manual Import', 'puntwork'); ?>
            </button>
            <p id="import-status" style="display: none;"></p>
        </div>
    </div>
</div>
