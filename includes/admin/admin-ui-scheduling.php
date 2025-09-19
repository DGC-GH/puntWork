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
 * Render scheduling UI section
 */
function render_scheduling_ui() {
    ?>
    <!-- Scheduling Section -->
    <div id="import-scheduling" style="margin-top: 32px; background-color: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
            <h2 style="font-size: 20px; font-weight: 600; margin: 0;">Scheduled Imports</h2>
            <label style="display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 500;">
                <span>Enable automatic imports</span>
                <label class="schedule-toggle">
                    <input type="checkbox" id="schedule-enabled">
                    <span class="schedule-slider"></span>
                </label>
            </label>
        </div>

        <!-- Schedule Configuration -->
        <div style="margin-bottom: 24px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label for="schedule-frequency" style="display: block; font-size: 14px; font-weight: 500; color: #8e8e93; margin-bottom: 8px;">Frequency</label>
                    <select id="schedule-frequency" style="width: 100%; padding: 12px; border: 1px solid #d1d1d6; border-radius: 8px; font-size: 16px; background-color: white;">
                        <option value="3hours">Every 3 hours</option>
                        <option value="6hours">Every 6 hours</option>
                        <option value="12hours">Every 12 hours</option>
                        <option value="daily">Daily</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>
                <div>
                    <label for="schedule-hour" style="display: block; font-size: 14px; font-weight: 500; color: #8e8e93; margin-bottom: 8px;">Start Time - Hour</label>
                    <select id="schedule-hour" style="width: 100%; padding: 12px; border: 1px solid #d1d1d6; border-radius: 8px; font-size: 16px; background-color: white;">
                        <?php for ($i = 0; $i < 24; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $i == 9 ? 'selected' : ''; ?>><?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?>:00</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label for="schedule-minute" style="display: block; font-size: 14px; font-weight: 500; color: #8e8e93; margin-bottom: 8px;">Minute</label>
                    <select id="schedule-minute" style="width: 100%; padding: 12px; border: 1px solid #d1d1d6; border-radius: 8px; font-size: 16px; background-color: white;">
                        <option value="0" selected>00</option>
                        <option value="15">15</option>
                        <option value="30">30</option>
                        <option value="45">45</option>
                    </select>
                </div>
            </div>
            <div id="custom-schedule" style="display: none;">
                <label for="schedule-interval" style="display: block; font-size: 14px; font-weight: 500; color: #8e8e93; margin-bottom: 8px;">Custom Interval (hours)</label>
                <input type="number" id="schedule-interval" min="1" max="168" placeholder="24" style="width: 100%; padding: 12px; border: 1px solid #d1d1d6; border-radius: 8px; font-size: 16px;">
            </div>
        </div>

        <!-- Schedule Status -->
        <div style="background-color: #f9f9f9; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                <div>
                    <div style="font-size: 12px; font-weight: 500; color: #8e8e93; margin-bottom: 4px;">Status</div>
                    <div id="schedule-status" style="font-size: 14px; font-weight: 500; display: flex; align-items: center;">
                        <span class="status-indicator status-disabled"></span>
                        <span>Disabled</span>
                    </div>
                </div>
                <div>
                    <div style="font-size: 12px; font-weight: 500; color: #8e8e93; margin-bottom: 4px;">Next Run</div>
                    <div id="next-run-time" style="font-size: 14px; font-weight: 500;">—</div>
                </div>
                <div>
                    <div style="font-size: 12px; font-weight: 500; color: #8e8e93; margin-bottom: 4px;">Last Run</div>
                    <div id="last-run-time" style="font-size: 14px; font-weight: 500;">Never</div>
                </div>
            </div>
        </div>

        <!-- Last Run Details -->
        <div id="last-run-details" style="background-color: #f2f2f7; border-radius: 8px; padding: 16px; display: none;">
            <h3 style="font-size: 16px; font-weight: 600; margin: 0 0 12px 0;">Last Import Details</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; font-size: 13px;">
                <div>
                    <div style="color: #8e8e93; margin-bottom: 2px;">Duration</div>
                    <div id="last-run-duration" style="font-weight: 500;">—</div>
                </div>
                <div>
                    <div style="color: #8e8e93; margin-bottom: 2px;">Items Processed</div>
                    <div id="last-run-processed" style="font-weight: 500;">—</div>
                </div>
                <div>
                    <div style="color: #8e8e93; margin-bottom: 2px;">Success Rate</div>
                    <div id="last-run-success-rate" style="font-weight: 500;">—</div>
                </div>
                <div>
                    <div style="color: #8e8e93; margin-bottom: 2px;">Status</div>
                    <div id="last-run-status" style="font-weight: 500;">—</div>
                </div>
            </div>
        </div>

        <!-- Run History -->
        <div id="run-history" style="background-color: #f9f9f9; border-radius: 8px; padding: 16px; margin-top: 16px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                <h3 style="font-size: 16px; font-weight: 600; margin: 0;">Import History</h3>
                <button id="refresh-history" class="button button-secondary" style="border-radius: 6px; padding: 6px 12px; font-size: 12px; font-weight: 500;">Refresh</button>
            </div>
            <div id="run-history-list" style="max-height: 200px; overflow-y: auto; font-size: 12px;">
                <div style="color: #8e8e93; text-align: center; padding: 20px;">Loading history...</div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 12px; margin-top: 24px;">
            <button id="save-schedule" class="button button-primary" style="border-radius: 8px; padding: 12px 24px; font-size: 16px; font-weight: 500; background-color: #007aff; border: none; color: white;">Save Settings</button>
            <button id="test-schedule" class="button button-secondary" style="border-radius: 8px; padding: 12px 24px; font-size: 16px; font-weight: 500; background-color: #f2f2f7; border: none; color: #007aff;">Test Schedule</button>
            <button id="run-now" class="button button-secondary" style="border-radius: 8px; padding: 12px 24px; font-size: 16px; font-weight: 500; background-color: #34c759; border: none; color: white;">Run Now</button>
        </div>
    </div>
    <?php
}