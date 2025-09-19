<?php
/**
 * Debug UI components for job import plugin
 * Contains debug information and testing tools
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

/**
 * Render debug UI section (only in development)
 */
function render_debug_ui() {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    ?>
    <!-- Debug Section (only in development) -->
    <div style="max-width: 800px; margin: 0 auto; margin-top: 32px; background-color: #f9f9f9; border-radius: 12px; padding: 16px; border: 1px solid #e0e0e0;">
        <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 12px 0; color: #666;">Debug Information</h3>
        <div style="font-size: 12px; color: #666;">
            <p><strong>Schedule Status:</strong> <span id="debug-schedule-status">Loading...</span></p>
            <p><strong>Next Run:</strong> <span id="debug-next-run">Loading...</span></p>
            <p><strong>Last Run:</strong> <span id="debug-last-run">Loading...</span></p>
            <p><strong>Schedule Time:</strong> <span id="debug-schedule-time">Loading...</span></p>
            <p><strong>Frequency:</strong> <span id="debug-schedule-frequency">Loading...</span></p>
            <p><a href="?page=job-import-dashboard&test_scheduling=1" target="_blank" style="color: #007aff;">Open Test Page</a></p>
        </div>
    </div>
    <?php
}