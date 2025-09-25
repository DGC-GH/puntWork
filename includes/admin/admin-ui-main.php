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

/**
 * Render main import UI section
 */
function render_main_import_ui() {
    ?>
    <div class="wrap" style="max-width: 800px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1d1d1f; padding: 0 20px;">
        <h1 style="font-size: 34px; font-weight: 600; text-align: center; margin: 40px 0 20px;">Job Import Dashboard</h1>

        <!-- Import Controls Section -->
        <div style="margin-top: 32px; background-color: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="font-size: 20px; font-weight: 600; margin: 0 0 16px;">Import Controls</h2>
            <p style="font-size: 14px; color: #8e8e93; margin: 0 0 16px;">Start, pause, or resume job imports from configured feeds.</p>

            <div style="display: flex; gap: 12px; align-items: center;">
                <button id="start-import" class="button button-primary" style="border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 500; background-color: #007aff; border: none; color: white;">
                    <span id="start-text">Start Import</span>
                    <span id="start-loading" style="display: none;">Starting...</span>
                </button>
                <button id="cancel-import" class="button button-secondary" style="border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 500; background-color: #ff3b30; border: none; color: white; display: none;">Cancel Import</button>
                <button id="resume-import" class="button button-secondary" style="border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 500; background-color: #34c759; border: none; color: white; display: none;">Resume Import</button>
                <span id="import-status" style="font-size: 14px; color: #8e8e93;"></span>
            </div>
        </div>

        <!-- Import Progress Section -->
        <div id="import-progress" style="max-width: 800px; margin: 0 auto; margin-top: 32px; background-color: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <h2 style="font-size: 20px; font-weight: 600; margin: 0;">Import Progress</h2>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span id="progress-percent" style="font-size: 24px; font-weight: 600; color: #007aff;">0%</span>
                    <div style="display: flex; flex-direction: column; align-items: flex-end;">
                        <span id="time-elapsed" style="font-size: 14px; color: #8e8e93;">0s</span>
                        <span id="time-left" style="font-size: 12px; color: #8e8e93;">Calculating...</span>
                    </div>
                </div>
            </div>

            <!-- Progress Bar -->
            <div id="progress-bar" style="width: 100%; height: 6px; border-radius: 3px; background-color: #f2f2f7; display: flex; margin-bottom: 24px; overflow: hidden;"></div>

            <!-- Statistics Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 16px; margin-bottom: 16px;">
                <div style="text-align: center;">
                    <div style="font-size: 12px; font-weight: 500; color: #8e8e93; margin-bottom: 4px;">Total</div>
                    <div id="total-items" style="font-size: 18px; font-weight: 600;">0</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 12px; font-weight: 500; color: #8e8e93; margin-bottom: 4px;">Processed</div>
                    <div id="processed-items" style="font-size: 18px; font-weight: 600;">0</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 12px; font-weight: 500; color: #8e8e93; margin-bottom: 4px;">Published</div>
                    <div id="published-items" style="font-size: 18px; font-weight: 600;">0</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 12px; font-weight: 500; color: #8e8e93; margin-bottom: 4px;">Updated</div>
                    <div id="updated-items" style="font-size: 18px; font-weight: 600;">0</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 12px; font-weight: 500; color: #8e8e93; margin-bottom: 4px;">Skipped</div>
                    <div id="skipped-items" style="font-size: 18px; font-weight: 600;">0</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 12px; font-weight: 500; color: #8e8e93; margin-bottom: 4px;">Drafts</div>
                    <div id="duplicates-drafted" style="font-size: 18px; font-weight: 600;">0</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 12px; font-weight: 500; color: #8e8e93; margin-bottom: 4px;">Old Drafts</div>
                    <div id="drafted-old" style="font-size: 18px; font-weight: 600;">0</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 12px; font-weight: 500; color: #8e8e93; margin-bottom: 4px;">Left</div>
                    <div id="items-left" style="font-size: 18px; font-weight: 600;">0</div>
                </div>
            </div>

            <!-- Status Message -->
            <div style="background-color: #f9f9f9; border-radius: 8px; padding: 12px; text-align: center;">
                <span id="status-message" style="font-size: 14px; color: #8e8e93;">Ready to start.</span>
            </div>
        </div>

        <!-- Import Log Section -->
        <div id="import-log" style="max-width: 800px; margin: 0 auto; margin-top: 32px; background-color: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: none;">
            <h2 style="font-size: 20px; font-weight: 600; margin: 0 0 16px 0;">Import Log</h2>
            <textarea id="log-textarea" readonly style="width: 100%; height: 200px; padding: 12px; border: 1px solid #d1d1d6; border-radius: 8px; font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, 'Courier New', monospace; font-size: 12px; line-height: 1.4; resize: vertical; background-color: #f9f9f9;"></textarea>
        </div>
    </div>
    <?php
}