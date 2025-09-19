<?php
/**
 * Admin page HTML for job import plugin
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function job_import_admin_page() {
    wp_enqueue_script('jquery');
    ?>
    <div class="wrap" style="max-width: 800px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1d1d1f; padding: 0 20px;">
        <h1 style="font-size: 34px; font-weight: 600; text-align: center; margin: 40px 0 20px;">Job Import</h1>
        <div style="display: flex; justify-content: center; gap: 12px; margin-bottom: 32px;">
            <button id="start-import" class="button button-primary" style="border-radius: 8px; padding: 12px 24px; font-size: 16px; font-weight: 500; background-color: #007aff; border: none; color: white;">Start</button>
            <button id="resume-import" class="button button-secondary" style="display:none; border-radius: 8px; padding: 12px 24px; font-size: 16px; font-weight: 500; background-color: #f2f2f7; border: none; color: #007aff;">Continue</button>
            <button id="cancel-import" class="button button-secondary" style="display:none; border-radius: 8px; padding: 12px 24px; font-size: 16px; font-weight: 500; background-color: #ff3b30; border: none; color: white;">Stop</button>
        </div>
        <div id="import-progress" style="background-color: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: none;">
            <h2 id="progress-percent" style="font-size: 48px; font-weight: 600; text-align: center; margin: 0 0 16px; color: #007aff;">0%</h2>
            <div id="progress-bar" style="width: 100%; height: 6px; border-radius: 3px; background-color: #f2f2f7; display: flex; overflow: hidden;"></div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin: 16px 0;">
                <span id="time-elapsed" style="font-size: 16px; color: #8e8e93;">0s</span>
                <p id="status-message" style="font-size: 16px; color: #8e8e93; margin: 0;">Ready to start.</p>
                <span id="time-left" style="font-size: 16px; color: #8e8e93;">Calculating...</span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; font-size: 14px;">
                <p style="margin: 0;">Total: <span id="total-items" style="font-weight: 500;">0</span></p>
                <p style="margin: 0;">Processed: <span id="processed-items" style="font-weight: 500;">0</span></p>
                <p style="margin: 0;">Created: <span id="created-items" style="font-weight: 500;">0</span></p>
                <p style="margin: 0;">Updated: <span id="updated-items" style="font-weight: 500;">0</span></p>
                <p style="margin: 0;">Skipped: <span id="skipped-items" style="font-weight: 500;">0</span></p>
                <p style="margin: 0;">Duplicated: <span id="duplicates-drafted" style="font-weight: 500;">0</span></p>
                <p style="margin: 0;">Unpublished: <span id="drafted-old" style="font-weight: 500;">0</span></p>
                <p style="margin: 0;">Left: <span id="items-left" style="font-weight: 500;">0</span></p>
            </div>
        </div>
        <div id="import-log" style="margin-top: 32px; background-color: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: none;">
            <h2 style="font-size: 20px; font-weight: 600; margin: 0 0 16px;">Import Log</h2>
            <textarea id="log-textarea" rows="10" style="width: 100%; border: 1px solid #d1d1d6; border-radius: 8px; padding: 12px; font-family: SFMono-Regular, monospace; font-size: 13px; background-color: #f9f9f9; resize: vertical;" readonly></textarea>
        </div>
    </div>
    <script>
        // Initialize job import admin functionality
        if (typeof PuntWorkJobImportAdmin !== 'undefined') {
            PuntWorkJobImportAdmin.init();
        }
    </script>
    <?php
}
