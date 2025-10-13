<?php
/**
 * Scheduling UI components for job import plugin
 * Contains the scheduling interface and controls
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
 * Render import history UI section
 */
function render_import_history_ui() {
    ?>
    <!-- Import History Section -->
    <div class="wrap" style="max-width: 800px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #1d1d1f; padding: 0 24px;">
        <div id="import-history" style="max-width: 900px; margin: 0 auto; margin-top: 40px; background-color: #ffffff; border-radius: 16px; padding: 32px; box-shadow: 0 2px 10px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04); position: relative; overflow: hidden;">

            <!-- Header Section -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; padding-bottom: 24px; border-bottom: 1px solid #e5e5e7;">
                <div>
                    <h2 style="font-size: 28px; font-weight: 700; margin: 0 0 4px 0; color: #1d1d1f; letter-spacing: -0.02em;">Import History</h2>
                    <p style="font-size: 15px; color: #86868b; margin: 0; font-weight: 400;">View past import runs and their results</p>
                </div>
                <button id="refresh-history" class="secondary-button" style="border-radius: 8px; padding: 10px 16px; font-size: 14px; font-weight: 500; background-color: #f2f2f7; border: 1px solid #d1d1d6; color: #424245; transition: all 0.2s ease; cursor: pointer;" aria-label="Refresh import history">
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
        /* Loading animation for refresh button */
        #import-history #refresh-history.loading i {
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
 * Render scheduling UI section
 */
function render_scheduling_ui() {
    ?>
    <!-- Scheduling Section -->
    <div class="wrap" style="max-width: 800px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #1d1d1f; padding: 0 24px;">
        <div id="import-scheduling" style="max-width: 900px; margin: 0 auto; margin-top: 40px; background-color: #ffffff; border-radius: 16px; padding: 32px; box-shadow: 0 2px 10px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04); position: relative; overflow: hidden;">

            <!-- Header Section -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; padding-bottom: 24px; border-bottom: 1px solid #e5e5e7;">
                <div>
                    <h2 style="font-size: 28px; font-weight: 700; margin: 0 0 4px 0; color: #1d1d1f; letter-spacing: -0.02em;">Scheduled Imports</h2>
                    <p style="font-size: 15px; color: #86868b; margin: 0; font-weight: 400;">Automate your job feed imports with custom schedules</p>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="font-size: 15px; font-weight: 500; color: #1d1d1f;">Enable automatic imports</span>
                    <label class="schedule-toggle">
                        <input type="checkbox" id="schedule-enabled" aria-label="Enable automatic imports">
                        <span class="schedule-slider" role="presentation"></span>
                    </label>
                </div>
            </div>

            <!-- Schedule Configuration Card -->
            <div class="scheduling-card" style="background-color: #f9f9fa; border-radius: 12px; padding: 24px; margin-bottom: 24px; border: 1px solid #e5e5e7;">
                <h3 style="font-size: 20px; font-weight: 600; margin: 0 0 20px 0; color: #1d1d1f;">Schedule Configuration</h3>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label for="schedule-frequency" style="display: block; font-size: 14px; font-weight: 500; color: #424245; margin-bottom: 8px; letter-spacing: -0.01em;">Frequency</label>
                        <select id="schedule-frequency" style="width: 100%; padding: 12px 16px; border: 1px solid #d1d1d6; border-radius: 10px; font-size: 16px; background-color: #ffffff; color: #1d1d1f; font-weight: 400; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">
                            <option value="hourly">Hourly</option>
                            <option value="3hours">Every 3 hours</option>
                            <option value="6hours">Every 6 hours</option>
                            <option value="12hours">Every 12 hours</option>
                            <option value="daily" selected>Daily</option>
                            <option value="custom">Custom</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="schedule-hour" style="display: block; font-size: 14px; font-weight: 500; color: #424245; margin-bottom: 8px; letter-spacing: -0.01em;">Start Time - Hour</label>
                        <select id="schedule-hour" style="width: 100%; padding: 12px 16px; border: 1px solid #d1d1d6; border-radius: 10px; font-size: 16px; background-color: #ffffff; color: #1d1d1f; font-weight: 400; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">
                            <?php for ($i = 0; $i < 24; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $i == 9 ? 'selected' : ''; ?>><?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?>:00</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="schedule-minute" style="display: block; font-size: 14px; font-weight: 500; color: #424245; margin-bottom: 8px; letter-spacing: -0.01em;">Minute</label>
                        <select id="schedule-minute" style="width: 100%; padding: 12px 16px; border: 1px solid #d1d1d6; border-radius: 10px; font-size: 16px; background-color: #ffffff; color: #1d1d1f; font-weight: 400; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">
                            <?php for ($i = 0; $i < 60; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $i == 0 ? 'selected' : ''; ?>><?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div id="custom-schedule" style="display: none; margin-top: 16px;">
                    <div class="form-group">
                        <label for="schedule-interval" style="display: block; font-size: 14px; font-weight: 500; color: #424245; margin-bottom: 8px; letter-spacing: -0.01em;">Custom Interval (hours)</label>
                        <input type="number" id="schedule-interval" min="1" max="168" placeholder="24" style="width: 100%; padding: 12px 16px; border: 1px solid #d1d1d6; border-radius: 10px; font-size: 16px; background-color: #ffffff; color: #1d1d1f; font-weight: 400; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.04);" aria-label="Custom interval in hours">
                    </div>
                </div>
            </div>

            <!-- Status Overview Card -->
            <div class="scheduling-card" style="background-color: #ffffff; border-radius: 12px; padding: 24px; margin-bottom: 24px; border: 1px solid #e5e5e7; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                <h3 style="font-size: 20px; font-weight: 600; margin: 0 0 20px 0; color: #1d1d1f;">Status Overview</h3>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 24px;">
                    <div class="status-item">
                        <div style="font-size: 13px; font-weight: 500; color: #86868b; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.05em;">Status</div>
                        <div id="schedule-status" style="font-size: 17px; font-weight: 600; display: flex; align-items: center;">
                            <span class="status-indicator status-disabled" aria-hidden="true"></span>
                            <span>Disabled</span>
                        </div>
                    </div>
                    <div class="status-item">
                        <div style="font-size: 13px; font-weight: 500; color: #86868b; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.05em;">Next Run</div>
                        <div id="next-run-time" style="font-size: 17px; font-weight: 600; color: #1d1d1f;">—</div>
                    </div>
                    <div class="status-item">
                        <div style="font-size: 13px; font-weight: 500; color: #86868b; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.05em;">Last Run</div>
                        <div id="last-run-time" style="font-size: 17px; font-weight: 600; color: #1d1d1f;">Never</div>
                    </div>
                </div>
            </div>

            <!-- Last Run Details Card -->
            <div id="last-run-details" class="scheduling-card" style="background-color: #f2f2f7; border-radius: 12px; padding: 24px; margin-bottom: 24px; border: 1px solid #e5e5e7; display: none;">
                <h3 style="font-size: 20px; font-weight: 600; margin: 0 0 20px 0; color: #1d1d1f;">Last Import Details</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 20px; font-size: 15px;">
                    <div class="metric-item">
                        <div style="color: #86868b; margin-bottom: 4px; font-weight: 500;">Duration</div>
                        <div id="last-run-duration" style="font-weight: 600; color: #1d1d1f;">—</div>
                    </div>
                    <div class="metric-item">
                        <div style="color: #86868b; margin-bottom: 4px; font-weight: 500;">Items Processed</div>
                        <div id="last-run-processed" style="font-weight: 600; color: #1d1d1f;">—</div>
                    </div>
                    <div class="metric-item">
                        <div style="color: #86868b; margin-bottom: 4px; font-weight: 500;">Success Rate</div>
                        <div id="last-run-success-rate" style="font-weight: 600; color: #1d1d1f;">—</div>
                    </div>
                    <div class="metric-item">
                        <div style="color: #86868b; margin-bottom: 4px; font-weight: 500;">Status</div>
                        <div id="last-run-status" style="font-weight: 600;">—</div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; gap: 16px; justify-content: flex-end; padding-top: 24px; border-top: 1px solid #e5e5e7;">
                <button id="save-schedule" class="primary-button" style="border-radius: 12px; padding: 14px 28px; font-size: 16px; font-weight: 600; background-color: #007aff; border: none; color: #ffffff; transition: all 0.2s ease; cursor: pointer; box-shadow: 0 2px 4px rgba(0,122,255,0.2);" aria-label="Save schedule settings">
                    Save Settings
                </button>
                <button id="test-schedule" class="secondary-button" style="border-radius: 12px; padding: 14px 28px; font-size: 16px; font-weight: 600; background-color: #f2f2f7; border: 1px solid #d1d1d6; color: #007aff; transition: all 0.2s ease; cursor: pointer;" aria-label="Test schedule configuration">
                    Test Schedule
                </button>
                <button id="run-now" class="success-button" style="border-radius: 12px; padding: 14px 28px; font-size: 16px; font-weight: 600; background-color: #34c759; border: none; color: #ffffff; transition: all 0.2s ease; cursor: pointer; box-shadow: 0 2px 4px rgba(52,199,89,0.2);" aria-label="Run import immediately">
                    Run Now
                </button>
            </div>
        </div>
    </div>

    <style>
        /* Enhanced Apple-style form interactions */
        #import-scheduling select:focus,
        #import-scheduling input:focus {
            outline: none;
            border-color: #007aff;
            box-shadow: 0 0 0 3px rgba(0,122,255,0.1);
        }

        #import-scheduling .form-group select:hover,
        #import-scheduling .form-group input:hover {
            border-color: #007aff;
        }

        /* Button hover states */
        #import-scheduling .primary-button:hover {
            background-color: #0056cc;
            box-shadow: 0 4px 8px rgba(0,122,255,0.3);
            transform: translateY(-1px);
        }

        #import-scheduling .secondary-button:hover {
            background-color: #e5e5e7;
            border-color: #007aff;
        }

        #import-scheduling .success-button:hover {
            background-color: #28a745;
            box-shadow: 0 4px 8px rgba(52,199,89,0.3);
            transform: translateY(-1px);
        }

        #import-scheduling .primary-button:active,
        #import-scheduling .success-button:active {
            transform: translateY(0);
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        /* Card hover effects */
        #import-scheduling .scheduling-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            transform: translateY(-1px);
            transition: all 0.2s ease;
        }

        /* Status indicator animations */
        #import-scheduling .status-indicator {
            transition: all 0.3s ease;
        }

        /* Loading animation for refresh button */
        #import-scheduling #refresh-history.loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive design */
        @media (max-width: 768px) {
            #import-scheduling {
                margin: 20px 16px;
                padding: 24px 20px;
            }

            #import-scheduling .scheduling-card {
                padding: 20px 16px;
            }

            #import-scheduling h2 {
                font-size: 24px;
            }

            #import-scheduling .action-buttons {
                flex-direction: column;
            }

            #import-scheduling .action-buttons button {
                width: 100%;
                margin-bottom: 12px;
            }
        }
    </style>
    <?php
}