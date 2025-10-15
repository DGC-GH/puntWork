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

        <!-- Cleanup Controls Section -->
        <div style="margin-top: 32px; background-color: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="font-size: 20px; font-weight: 600; margin: 0 0 16px;">Cleanup Controls</h2>
            <p style="font-size: 14px; color: #8e8e93; margin: 0 0 16px;">Remove unwanted job posts from the database.</p>

            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <button id="cleanup-trashed" class="button button-secondary" style="border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 500; background-color: #ff3b30; border: none; color: white;">
                    <span id="cleanup-trashed-text">Remove Trashed Jobs</span>
                    <span id="cleanup-trashed-loading" style="display: none;">Removing...</span>
                </button>
                <button id="cleanup-drafted" class="button button-secondary" style="border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 500; background-color: #ff9500; border: none; color: white;">
                    <span id="cleanup-drafted-text">Remove Drafted Jobs</span>
                    <span id="cleanup-drafted-loading" style="display: none;">Removing...</span>
                </button>
                <button id="cleanup-old-published" class="button button-secondary" style="border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 500; background-color: #af52de; border: none; color: white;">
                    <span id="cleanup-old-published-text">Remove Old Published Jobs</span>
                    <span id="cleanup-old-published-loading" style="display: none;">Removing...</span>
                </button>
                <span id="cleanup-status" style="font-size: 14px; color: #8e8e93;"></span>
            </div>
        </div>

        <!-- Cleanup Progress Section -->
        <div id="cleanup-progress" style="max-width: 800px; margin: 0 auto; margin-top: 32px; background-color: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <h2 style="font-size: 20px; font-weight: 600; margin: 0;">Cleanup Progress</h2>
                <span id="cleanup-progress-percent" style="font-size: 24px; font-weight: 600; color: #007aff;">0%</span>
            </div>

            <!-- Progress Bar -->
            <div id="cleanup-progress-bar" style="width: 100%; height: 6px; border-radius: 3px; background-color: #f2f2f7; display: flex; margin-bottom: 16px; overflow: hidden;"></div>

            <!-- Time Counters -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; font-size: 14px; color: #8e8e93;">
                <span>Elapsed: <span id="cleanup-time-elapsed" style="font-weight: 500;">0s</span></span>
                <span>Remaining: <span id="cleanup-items-left" style="font-weight: 500;">0 left</span></span>
            </div>

            <!-- Statistics Grid -->
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 20px;">
                <!-- Progress Overview -->
                <div style="background: linear-gradient(135deg, #007aff 0%, #5856d6 100%); border-radius: 12px; padding: 16px; color: white; box-shadow: 0 2px 8px rgba(0,122,255,0.2);">
                    <div style="display: flex; align-items: center; margin-bottom: 8px;">
                        <div style="width: 8px; height: 8px; border-radius: 50%; background-color: rgba(255,255,255,0.8); margin-right: 8px;"></div>
                        <span style="font-size: 13px; font-weight: 500; opacity: 0.9;">Progress</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline;">
                        <div>
                            <div style="font-size: 24px; font-weight: 700; margin-bottom: 2px;" id="cleanup-processed-items">0</div>
                            <div style="font-size: 11px; opacity: 0.8;">of <span id="cleanup-total-items">0</span> processed</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 14px; font-weight: 600; margin-bottom: 2px;" id="cleanup-deleted-items">0</div>
                            <div style="font-size: 11px; opacity: 0.8;">deleted</div>
                        </div>
                    </div>
                </div>

                <!-- Status -->
                <div style="background: linear-gradient(135deg, #32d74b 0%, #34c759 100%); border-radius: 12px; padding: 16px; color: white; box-shadow: 0 2px 8px rgba(52,199,89,0.2);">
                    <div style="display: flex; align-items: center; margin-bottom: 8px;">
                        <div style="width: 8px; height: 8px; border-radius: 50%; background-color: rgba(255,255,255,0.8); margin-right: 8px;"></div>
                        <span style="font-size: 13px; font-weight: 500; opacity: 0.9;">Status</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline;">
                        <div>
                            <div style="font-size: 18px; font-weight: 700; margin-bottom: 2px;" id="cleanup-current-operation">Ready</div>
                            <div style="font-size: 11px; opacity: 0.8;">current operation</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 14px; font-weight: 600; margin-bottom: 2px; opacity: 0.7;"><i class="fas fa-check-circle"></i></div>
                            <div style="font-size: 11px; opacity: 0.8;">active</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Message -->
            <div style="background-color: #f9f9f9; border-radius: 8px; padding: 12px; text-align: center;">
                <span id="cleanup-status-message" style="font-size: 14px; color: #8e8e93;">Ready to start cleanup.</span>
            </div>

            <!-- Cleanup Log Section -->
            <div id="cleanup-log" style="margin-top: 12px;">
                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                    <div style="width: 6px; height: 6px; border-radius: 50%; background-color: #ff9500; margin-right: 10px;"></div>
                    <h3 style="font-size: 16px; font-weight: 600; margin: 0; color: #1d1d1f;">Cleanup Details</h3>
                    <div style="margin-left: auto; font-size: 12px; color: #8e8e93;">
                        <i class="fas fa-terminal" style="margin-right: 4px;"></i>
                        Live Log
                    </div>
                </div>
                <textarea id="cleanup-log-textarea" readonly style="width: 100%; height: 120px; padding: 12px; border: 1px solid #d1d1d6; border-radius: 8px; font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, 'Courier New', monospace; font-size: 12px; line-height: 1.4; resize: vertical; background-color: #f9f9f9; transition: all 0.3s ease;"></textarea>
            </div>
        </div>

        <!-- Job Management Section -->
        <div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-top: 32px;">
            <h2 style="font-size: 20px; font-weight: 600; margin: 0 0 16px;">Job Management</h2>
            <p style="font-size: 14px; color: #8e8e93; margin: 0;">Jobs are automatically managed during import. Duplicates are handled and drafts are cleaned up as needed.</p>
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
        <h1 style="font-size: 34px; font-weight: 600; text-align: center; margin: 40px 0 20px;">Feeds Dashboard</h1>

        <!-- Import Controls Section -->
        <div style="margin-top: 32px; background-color: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="font-size: 20px; font-weight: 600; margin: 0 0 16px;">Import Controls</h2>
            <p style="font-size: 14px; color: #8e8e93; margin: 0 0 16px;">Start, pause, or resume job imports from configured feeds.</p>

            <!-- Import Type Indicator -->
            <div id="import-type-indicator" style="display: none; margin-bottom: 16px; padding: 8px 12px; background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border: 1px solid #0ea5e9; border-radius: 8px; font-size: 13px; color: #0c4a6e;">
                <i class="fas fa-clock" style="margin-right: 6px;"></i>
                <span id="import-type-text">Scheduled import is currently running</span>
            </div>

            <div style="display: flex; gap: 12px; align-items: center;">
                <button id="start-import" class="button button-primary" style="border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 500; background-color: #007aff; border: none; color: white;">
                    <span id="start-text">Start Import</span>
                    <span id="start-loading" style="display: none;">Starting...</span>
                </button>
                <button id="cancel-import" class="button button-secondary" style="border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 500; background-color: #ff3b30; border: none; color: white; display: none;">Cancel Import</button>
                <button id="resume-import" class="button button-secondary" style="border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 500; background-color: #34c759; border: none; color: white; display: none;">Resume Import</button>
                <button id="resume-stuck-import" class="button button-secondary" style="border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 500; background-color: #ff9500; border: none; color: white; display: none;">
                    <span id="resume-stuck-text">Resume Stuck Import</span>
                    <span id="resume-stuck-loading" style="display: none;">Resuming...</span>
                </button>
                <button id="reset-import" class="button button-outline" style="border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 500; background-color: transparent; border: 1px solid #d1d1d6; color: #424245; transition: all 0.2s ease;">
                    <i class="fas fa-undo" style="margin-right: 6px;"></i>Reset Import
                </button>
                <span id="import-status" style="font-size: 14px; color: #8e8e93;"></span>
            </div>
        </div>

        <!-- Import Progress Section -->
        <div id="import-progress" style="max-width: 800px; margin: 0 auto; margin-top: 32px; background-color: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <h2 style="font-size: 20px; font-weight: 600; margin: 0;">Import Progress</h2>
                <span id="progress-percent" style="font-size: 24px; font-weight: 600; color: #007aff;">0%</span>
            </div>

            <!-- Progress Bar -->
            <div id="progress-bar" style="width: 100%; height: 6px; border-radius: 3px; background-color: #f2f2f7; display: flex; margin-bottom: 16px; overflow: hidden;"></div>

            <!-- Time Counters -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; font-size: 14px; color: #8e8e93;">
                <span>Elapsed: <span id="time-elapsed" style="font-weight: 500;">0s</span></span>
                <span>Remaining: <span id="time-left" style="font-weight: 500;">Calculating...</span></span>
            </div>

            <!-- Statistics Grid -->
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 20px;">
                <!-- Progress Overview -->
                <div style="background: linear-gradient(135deg, #007aff 0%, #5856d6 100%); border-radius: 12px; padding: 16px; color: white; box-shadow: 0 2px 8px rgba(0,122,255,0.2);">
                    <div style="display: flex; align-items: center; margin-bottom: 8px;">
                        <div style="width: 8px; height: 8px; border-radius: 50%; background-color: rgba(255,255,255,0.8); margin-right: 8px;"></div>
                        <span style="font-size: 13px; font-weight: 500; opacity: 0.9;">Progress</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline;">
                        <div>
                            <div style="font-size: 24px; font-weight: 700; margin-bottom: 2px;" id="processed-items">0</div>
                            <div style="font-size: 11px; opacity: 0.8;">of <span id="total-items">0</span> processed</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 14px; font-weight: 600; margin-bottom: 2px;" id="items-left">0</div>
                            <div style="font-size: 11px; opacity: 0.8;">remaining</div>
                        </div>
                    </div>
                </div>

                <!-- Success Metrics -->
                <div style="background: linear-gradient(135deg, #32d74b 0%, #34c759 100%); border-radius: 12px; padding: 16px; color: white; box-shadow: 0 2px 8px rgba(52,199,89,0.2);">
                    <div style="display: flex; align-items: center; margin-bottom: 8px;">
                        <div style="width: 8px; height: 8px; border-radius: 50%; background-color: rgba(255,255,255,0.8); margin-right: 8px;"></div>
                        <span style="font-size: 13px; font-weight: 500; opacity: 0.9;">Success</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline;">
                        <div>
                            <div style="font-size: 24px; font-weight: 700; margin-bottom: 2px;" id="published-items">0</div>
                            <div style="font-size: 11px; opacity: 0.8;">published</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 14px; font-weight: 600; margin-bottom: 2px;" id="updated-items">0</div>
                            <div style="font-size: 11px; opacity: 0.8;">updated</div>
                        </div>
                    </div>
                </div>

                <!-- Skipped Items -->
                <div style="background: linear-gradient(135deg, #af52de 0%, #8e5de8 100%); border-radius: 12px; padding: 16px; color: white; box-shadow: 0 2px 8px rgba(175,82,222,0.2);">
                    <div style="display: flex; align-items: center; margin-bottom: 8px;">
                        <div style="width: 8px; height: 8px; border-radius: 50%; background-color: rgba(255,255,255,0.8); margin-right: 8px;"></div>
                        <span style="font-size: 13px; font-weight: 500; opacity: 0.9;">Up to Date</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline;">
                        <div>
                            <div style="font-size: 24px; font-weight: 700; margin-bottom: 2px;" id="skipped-items">0</div>
                            <div style="font-size: 11px; opacity: 0.8;">already current</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 14px; font-weight: 600; margin-bottom: 2px; opacity: 0.7;"><i class="fas fa-check"></i></div>
                            <div style="font-size: 11px; opacity: 0.8;">no changes</div>
                        </div>
                    </div>
                </div>

                <!-- Issues & Actions -->
                <div style="background: linear-gradient(135deg, #ff9500 0%, #ff6b35 100%); border-radius: 12px; padding: 16px; color: white; box-shadow: 0 2px 8px rgba(255,149,0,0.2);">
                    <div style="display: flex; align-items: center; margin-bottom: 8px;">
                        <div style="width: 8px; height: 8px; border-radius: 50%; background-color: rgba(255,255,255,0.8); margin-right: 8px;"></div>
                        <span style="font-size: 13px; font-weight: 500; opacity: 0.8;">Issues</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline;">
                        <div>
                            <div style="font-size: 24px; font-weight: 700; margin-bottom: 2px;" id="duplicates-drafted">0</div>
                            <div style="font-size: 11px; opacity: 0.8;">drafted</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 14px; font-weight: 600; margin-bottom: 2px; opacity: 0.7;"><i class="fas fa-exclamation-triangle"></i></div>
                            <div style="font-size: 11px; opacity: 0.8;">needs review</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Message -->
            <div style="background-color: #f9f9f9; border-radius: 8px; padding: 12px; text-align: center;">
                <span id="status-message" style="font-size: 14px; color: #8e8e93;">Ready to start.</span>
            </div>

            <!-- Integrated Log Section -->
            <div id="integrated-log" style="margin-top: 12px;">
                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                    <div style="width: 6px; height: 6px; border-radius: 50%; background-color: #007aff; margin-right: 10px;"></div>
                    <h3 style="font-size: 16px; font-weight: 600; margin: 0; color: #1d1d1f;">Import Details</h3>
                    <div style="margin-left: auto; font-size: 12px; color: #8e8e93;">
                        <i class="fas fa-terminal" style="margin-right: 4px;"></i>
                        Live Log
                    </div>
                </div>
                <textarea id="log-textarea" readonly style="width: 100%; height: 180px; padding: 12px; border: 1px solid #d1d1d6; border-radius: 8px; font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, 'Courier New', monospace; font-size: 12px; line-height: 1.4; resize: vertical; background-color: #f9f9f9; transition: all 0.3s ease;"></textarea>
            </div>
        </div>
    </div>

    <style>
        /* Reset Import Button Styles */
        #reset-import:hover {
            background-color: #f2f2f7;
            border-color: #007aff;
            color: #007aff;
        }
        #reset-import:active {
            background-color: #e5e5e7;
            transform: translateY(1px);
        }
        #reset-import i {
            transition: transform 0.2s ease;
        }
        #reset-import:hover i {
            transform: rotate(-180deg);
        }
    </style>
    <?php
}