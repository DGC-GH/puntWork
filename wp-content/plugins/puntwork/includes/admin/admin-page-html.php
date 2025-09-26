<?php
/**
 * Admin page HTML for job import plugin
 * Main entry point that loads all admin UI components
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

// Load admin UI components
require_once __DIR__ . '/admin-ui-main.php';
require_once __DIR__ . '/admin-ui-scheduling.php';

function feeds_dashboard_page() {
    error_log('[PUNTWORK] feeds_dashboard_page() called');
    wp_enqueue_script('jquery');

    // Render main import UI
    render_main_import_ui();

    // Render scheduling UI
    render_scheduling_ui();

    // Render import history UI
    render_import_history_ui();

    // Render JavaScript initialization
    render_javascript_init();
}

/**
 * Render JavaScript initialization for the admin page
 */
function render_javascript_init() {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            console.log('[PUNTWORK] Inline script: Document ready, checking modules...');
            console.log('[PUNTWORK] Inline script: JobImportEvents available:', typeof JobImportEvents);
            console.log('[PUNTWORK] Inline script: JobImportUI available:', typeof JobImportUI);
            console.log('[PUNTWORK] Inline script: JobImportAPI available:', typeof JobImportAPI);
            console.log('[PUNTWORK] Inline script: JobImportLogic available:', typeof JobImportLogic);
            console.log('[PUNTWORK] Inline script: jobImportInitialized:', typeof window.jobImportInitialized);

            // Check if buttons exist
            console.log('[PUNTWORK] Inline script: cleanup-duplicates button exists:', $('#cleanup-duplicates').length);

            // Add a simple test function to global scope
            window.testButtons = function() {
                console.log('[PUNTWORK] Testing buttons...');
                console.log('Cleanup button found:', $('#cleanup-duplicates').length);

                if ($('#cleanup-duplicates').length > 0) {
                    console.log('Cleanup button HTML:', $('#cleanup-duplicates')[0].outerHTML);
                }

                // Test click events
                $('#cleanup-duplicates').trigger('click');
            };

            console.log('[PUNTWORK] Run testButtons() in console to test button functionality');

            // Only initialize if not already initialized
            if (typeof window.jobImportInitialized === 'undefined') {
                console.log('[PUNTWORK] Inline script: Initializing job import system...');

                // Initialize the job import system
                if (typeof JobImportEvents !== 'undefined') {
                    console.log('[PUNTWORK] Inline script: Calling JobImportEvents.init()');
                    JobImportEvents.init();
                } else {
                    console.error('[PUNTWORK] Inline script: JobImportEvents not available!');
                }

                // Initialize UI components
                if (typeof JobImportUI !== 'undefined') {
                    console.log('[PUNTWORK] Inline script: Calling JobImportUI.clearProgress()');
                    JobImportUI.clearProgress();
                }

                // Note: Import status checking is now handled by JobImportEvents.checkInitialStatus()
                // to avoid duplicate checks and potential race conditions

                // Initialize scheduling if available
                if (typeof JobImportScheduling !== 'undefined') {
                    console.log('[PUNTWORK] Inline script: Calling JobImportScheduling.init()');
                    JobImportScheduling.init();
                }

                // Bind refresh button for main import history section
                $('#refresh-history-main').on('click', function(e) {
                    e.preventDefault();
                    console.log('[PUNTWORK] Main history refresh clicked');
                    if (typeof JobImportScheduling !== 'undefined' && typeof JobImportScheduling.loadRunHistory === 'function') {
                        $(this).addClass('manual-refresh');
                        JobImportScheduling.loadRunHistory('#refresh-history-main');
                    }
                });

                // Load import history on page load
                if (typeof JobImportScheduling !== 'undefined' && typeof JobImportScheduling.loadRunHistory === 'function') {
                    console.log('[PUNTWORK] Loading import history on page load');
                    JobImportScheduling.loadRunHistory('#refresh-history-main');
                }

                // Mark as initialized to prevent double initialization
                window.jobImportInitialized = true;
                console.log('[PUNTWORK] Inline script: Admin page JavaScript initialized');
            } else {
                console.log('[PUNTWORK] Inline script: Job import already initialized, skipping...');
            }
        });
    </script>
    <?php
}

function jobs_dashboard_page() {
    error_log('[PUNTWORK] jobs_dashboard_page() called');
    wp_enqueue_script('jquery');

    // Render jobs dashboard UI
    render_jobs_dashboard_ui();

    // Render JavaScript initialization for jobs dashboard
    render_jobs_javascript_init();
}

/**
 * Render the main puntWork dashboard page
 */
function puntwork_dashboard_page() {
    ?>
    <div class="wrap" style="max-width: 1200px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1d1d1f; padding: 0 20px;">
        <h1 style="font-size: 34px; font-weight: 600; text-align: center; margin: 40px 0 20px;">puntWork Dashboard</h1>
        <p style="font-size: 16px; color: #8e8e93; text-align: center; margin-bottom: 40px;">Manage your job feeds and content with ease</p>

        <!-- Overview Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; margin-bottom: 40px;">
            <!-- Feeds Card -->
            <div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: pointer;" onclick="window.location.href='admin.php?page=job-feed-dashboard'">
                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #007aff, #5856d6); display: flex; align-items: center; justify-content: center; margin-right: 16px;">
                        <span style="font-size: 24px; color: white;">📡</span>
                    </div>
                    <div>
                        <h3 style="font-size: 20px; font-weight: 600; margin: 0;">Job Feeds</h3>
                        <p style="font-size: 14px; color: #8e8e93; margin: 4px 0 0;">Import and manage job feeds</p>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 14px; color: #8e8e93;">Manage feeds →</span>
                    <span style="font-size: 18px; color: #007aff;">→</span>
                </div>
            </div>

            <!-- Jobs Card -->
            <div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: pointer;" onclick="window.location.href='admin.php?page=jobs-dashboard'">
                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #34c759, #30d158); display: flex; align-items: center; justify-content: center; margin-right: 16px;">
                        <span style="font-size: 24px; color: white;">💼</span>
                    </div>
                    <div>
                        <h3 style="font-size: 20px; font-weight: 600; margin: 0;">Jobs</h3>
                        <p style="font-size: 14px; color: #8e8e93; margin: 4px 0 0;">View and manage job posts</p>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 14px; color: #8e8e93;">Browse jobs →</span>
                    <span style="font-size: 18px; color: #34c759;">→</span>
                </div>
            </div>

            <!-- Scheduling Card -->
            <div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: pointer;" onclick="window.location.href='admin.php?page=job-feed-dashboard'">
                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #ff9500, #ff9f0a); display: flex; align-items: center; justify-content: center; margin-right: 16px;">
                        <span style="font-size: 24px; color: white;">⏰</span>
                    </div>
                    <div>
                        <h3 style="font-size: 20px; font-weight: 600; margin: 0;">Scheduling</h3>
                        <p style="font-size: 14px; color: #8e8e93; margin: 4px 0 0;">Automated import schedules</p>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 14px; color: #8e8e93;">Configure schedules →</span>
                    <span style="font-size: 18px; color: #ff9500;">→</span>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 40px;">
            <h3 style="font-size: 24px; font-weight: 600; margin: 0 0 20px;">Quick Overview</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px;">
                <div style="text-align: center;">
                    <div style="font-size: 32px; font-weight: 700; color: #007aff; margin-bottom: 8px;">0</div>
                    <div style="font-size: 14px; color: #8e8e93;">Active Feeds</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 32px; font-weight: 700; color: #34c759; margin-bottom: 8px;">0</div>
                    <div style="font-size: 14px; color: #8e8e93;">Total Jobs</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 32px; font-weight: 700; color: #ff9500; margin-bottom: 8px;">0</div>
                    <div style="font-size: 14px; color: #8e8e93;">Scheduled Imports</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 32px; font-weight: 700; color: #ff3b30; margin-bottom: 8px;">0</div>
                    <div style="font-size: 14px; color: #8e8e93;">Failed Imports</div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <h3 style="font-size: 24px; font-weight: 600; margin: 0 0 20px;">Recent Activity</h3>
            <div style="text-align: center; padding: 40px 20px; color: #8e8e93;">
                <div style="font-size: 48px; margin-bottom: 16px;">📊</div>
                <p style="font-size: 16px; margin: 0;">Activity feed will appear here once you start importing jobs</p>
            </div>
        </div>
    </div>

    <style>
        .wrap > div:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
    </style>
    <?php
}

/**
 * Render import history UI section
 */
function render_import_history_ui() {
    ?>
    <!-- Import History Section -->
    <div class="wrap" style="max-width: 900px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #1d1d1f; padding: 0 24px; background-color: #f5f5f7;">
        <div id="import-history" style="max-width: 900px; margin: 0 auto; margin-top: 40px; background-color: #ffffff; border-radius: 16px; padding: 32px; box-shadow: 0 2px 10px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04); position: relative; overflow: hidden;">

            <!-- Header Section -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; padding-bottom: 24px; border-bottom: 1px solid #e5e5e7;">
                <div>
                    <h2 style="font-size: 28px; font-weight: 700; margin: 0 0 4px 0; color: #1d1d1f; letter-spacing: -0.02em;">Import History</h2>
                    <p style="font-size: 15px; color: #86868b; margin: 0; font-weight: 400;">View all import runs including manual, scheduled, and API-triggered imports</p>
                </div>
                <button id="refresh-history-main" class="secondary-button" style="border-radius: 8px; padding: 8px 16px; font-size: 14px; font-weight: 500; background-color: #f2f2f7; border: 1px solid #d1d1d6; color: #424245; transition: all 0.2s ease; cursor: pointer;" aria-label="Refresh import history">
                    <i class="fas fa-sync-alt" style="margin-right: 6px;"></i>Refresh
                </button>
            </div>

            <!-- Import History Content -->
            <div id="run-history-list" style="max-height: 600px; overflow-y: auto; font-size: 14px; border-radius: 8px; background-color: #fafbfc; padding: 20px;">
                <div style="color: #86868b; text-align: center; padding: 24px; font-style: italic;">Loading history...</div>
            </div>
        </div>
    </div>

    <style>
        /* Import History specific styles */
        #import-history .secondary-button:hover {
            background-color: #e5e5e7;
            border-color: #007aff;
        }

        /* Loading animation for refresh button */
        #import-history #refresh-history-main.loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive design */
        @media (max-width: 768px) {
            #import-history {
                margin: 20px 16px;
                padding: 24px 20px;
            }

            #import-history h2 {
                font-size: 24px;
            }
        }
    </style>
    <?php
}

/**
 * Render JavaScript initialization for the jobs dashboard page
 */
function render_jobs_javascript_init() {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            console.log('[PUNTWORK] Jobs Dashboard: Document ready, checking modules...');

            // Check if buttons exist
            console.log('[PUNTWORK] Jobs Dashboard: cleanup-duplicates button exists:', $('#cleanup-duplicates').length);

            // Add a simple test function to global scope
            window.testJobsButtons = function() {
                console.log('[PUNTWORK] Testing jobs buttons...');
                console.log('Cleanup button found:', $('#cleanup-duplicates').length);

                if ($('#cleanup-duplicates').length > 0) {
                    console.log('Cleanup button HTML:', $('#cleanup-duplicates')[0].outerHTML);
                }

                // Test click events
                $('#cleanup-duplicates').trigger('click');
            };

            console.log('[PUNTWORK] Run testJobsButtons() in console to test button functionality');

            // Initialize jobs dashboard
            if (typeof JobImportEvents !== 'undefined') {
                console.log('[PUNTWORK] Jobs Dashboard: Initializing events...');
                // Only bind cleanup events, not the full import system
                JobImportEvents.bindCleanupEvents();
            } else {
                console.error('[PUNTWORK] Jobs Dashboard: JobImportEvents not available!');
            }

            // Initialize UI components
            if (typeof JobImportUI !== 'undefined') {
                console.log('[PUNTWORK] Jobs Dashboard: Clearing cleanup progress...');
                JobImportUI.clearCleanupProgress();
            }
        });
    </script>
    <?php
}
