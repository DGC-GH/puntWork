<?php
/**
 * Main import UI components for job import plugin
 * Contains the primary import interface and progress display
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
 * Render jobs dashboard UI section
 */
function render_jobs_dashboard_ui() {
    ?>
    <div class="wrap" style="max-width: 800px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1d1d1f; padding: 0 20px;">
        <h1 style="font-size: 34px; font-weight: 600; text-align: center; margin: 40px 0 20px;">Jobs Dashboard</h1>

        <!-- Delete Drafts and Trash Section -->
        <div style="margin-top: 32px; background-color: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="font-size: 20px; font-weight: 600; margin: 0 0 16px;">Delete Drafts and Trash</h2>
            <p style="font-size: 14px; color: #8e8e93; margin: 0 0 16px;">Permanently delete all job posts that are in Draft or Trash status. This action cannot be undone.</p>

            <!-- Cleanup Progress Section -->
            <div id="cleanup-progress" style="background-color: #f9f9f9; border-radius: 8px; padding: 16px; margin-bottom: 16px; display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span id="cleanup-progress-percent" style="font-size: 18px; font-weight: 600; color: #007aff;">0%</span>
                    <span id="cleanup-time-elapsed" style="font-size: 14px; color: #8e8e93;">0s</span>
                </div>
                <div id="cleanup-progress-bar" style="width: 100%; height: 6px; border-radius: 3px; background-color: #f2f2f7; display: flex; overflow: hidden;"></div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                    <span id="cleanup-status-message" style="font-size: 14px; color: #8e8e93;">Ready to start.</span>
                    <span id="cleanup-items-left" style="font-size: 14px; color: #8e8e93;">0 left</span>
                </div>
            </div>

            <div style="display: flex; gap: 12px; align-items: center;">
                <button id="cleanup-duplicates" class="button button-secondary" style="border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 500; background-color: #ff9500; border: none; color: white;">
                    <span id="cleanup-text">Delete Drafts & Trash</span>
                    <span id="cleanup-loading" style="display: none;">Deleting...</span>
                </button>
                <span id="cleanup-status" style="font-size: 14px; color: #8e8e93;"></span>
            </div>
        </div>
    </div>
    <?php
}