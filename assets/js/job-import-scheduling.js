/**
 * Job Import Admin - Scheduling Module
 * Handles scheduling functionality for automated imports
 */

(function($, window, document) {
    'use strict';

    var JobImportScheduling = {
        currentSchedule: null,

        /**
         * Initialize scheduling functionality
         */
        init: function() {
            console.log('[PUNTWORK] JobImportScheduling.init() called');
            console.log('[PUNTWORK] jQuery version:', $.fn.jquery);
            console.log('[PUNTWORK] Document ready state:', document.readyState);
            console.log('[PUNTWORK] Run Now button exists:', $('#run-now').length);

            this.bindEvents();
            this.loadScheduleSettings();
            this.loadRunHistory();
            PuntWorkJSLogger.info('Job Import Scheduling initialized', 'SCHEDULING');
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Schedule enable/disable toggle
            $('#schedule-enabled').on('change', function() {
                self.toggleScheduleEnabled($(this).is(':checked'));
            });

            // Frequency change
            $('#schedule-frequency').on('change', function() {
                self.handleFrequencyChange($(this).val());
            });

            // Save schedule settings
            $('#save-schedule').on('click', function(e) {
                e.preventDefault();
                self.saveScheduleSettings();
            });

            // Test schedule
            $('#test-schedule').on('click', function(e) {
                e.preventDefault();
                self.testSchedule();
            });

            // Run now
            $('#run-now').on('click', function(e) {
                e.preventDefault();
                self.runNow();
            });

            // Refresh schedule status periodically
            setInterval(function() {
                self.refreshScheduleStatus();
            }, 30000); // Refresh every 30 seconds

            // Refresh history button
            $('#refresh-history').on('click', function(e) {
                e.preventDefault();
                self.loadRunHistory();
            });
        },

        /**
         * Load current schedule settings
         */
        loadScheduleSettings: function() {
            var self = this;

            JobImportAPI.call('get_import_schedule', {}, function(response) {
                console.log('[SCHEDULING] loadScheduleSettings response:', response);
                if (response.success) {
                    self.currentSchedule = response.data.schedule;
                    console.log('[SCHEDULING] Loaded schedule.enabled:', response.data.schedule.enabled);
                    self.updateUI(response.data);
                    PuntWorkJSLogger.info('Schedule settings loaded', 'SCHEDULING', response.data);
                } else {
                    PuntWorkJSLogger.error('Failed to load schedule settings', 'SCHEDULING', response.data);
                }
            });
        },

        /**
         * Update UI with schedule data
         */
        updateUI: function(data) {
            console.log('[SCHEDULING] updateUI called with data:', data);
            var schedule = data.schedule;
            console.log('[SCHEDULING] schedule.enabled:', schedule.enabled);

            // Update form controls
            $('#schedule-enabled').prop('checked', schedule.enabled);
            console.log('[SCHEDULING] Checkbox set to:', $('#schedule-enabled').is(':checked'));

            $('#schedule-frequency').val(schedule.frequency);
            $('#schedule-interval').val(schedule.interval);
            $('#schedule-hour').val(schedule.hour || 9);
            $('#schedule-minute').val(schedule.minute || 0);

            // Show/hide custom interval
            this.handleFrequencyChange(schedule.frequency);

            // Update status display
            this.updateStatusDisplay(data);
        },

        /**
         * Handle frequency change
         */
        handleFrequencyChange: function(frequency) {
            if (frequency === 'custom') {
                $('#custom-schedule').show();
            } else {
                $('#custom-schedule').hide();
            }
        },

        /**
         * Toggle schedule enabled state
         */
        toggleScheduleEnabled: function(enabled) {
            var $status = $('#schedule-status');
            var $indicator = $status.find('.status-indicator');

            if (enabled) {
                $indicator.removeClass('status-disabled status-error').addClass('status-active');
                $status.find('span:last').text('Active');
            } else {
                $indicator.removeClass('status-active status-error').addClass('status-disabled');
                $status.find('span:last').text('Disabled');
            }

            PuntWorkJSLogger.info('Schedule ' + (enabled ? 'enabled' : 'disabled'), 'SCHEDULING');
        },

        /**
         * Update status display
         */
        updateStatusDisplay: function(data) {
            var schedule = data.schedule;
            var nextRun = data.next_run;
            var lastRun = data.last_run;
            var lastRunDetails = data.last_run_details;

            // Update status
            var $status = $('#schedule-status');
            var $indicator = $status.find('.status-indicator');

            if (schedule.enabled) {
                if (nextRun) {
                    $indicator.removeClass('status-disabled status-error').addClass('status-active');
                    $status.find('span:last').text('Active');
                } else {
                    $indicator.removeClass('status-active status-disabled').addClass('status-error');
                    $status.find('span:last').text('Error');
                }
            } else {
                $indicator.removeClass('status-active status-error').addClass('status-disabled');
                $status.find('span:last').text('Disabled');
            }

            // Update next run time
            var $nextRun = $('#next-run-time');
            if (nextRun) {
                $nextRun.text(nextRun.formatted);
                $nextRun.attr('title', nextRun.relative);
            } else {
                $nextRun.text('—');
            }

            // Update last run time
            var $lastRun = $('#last-run-time');
            if (lastRun && lastRun.timestamp) {
                // Use formatted date if available, otherwise fallback to browser formatting
                var lastRunDate = lastRun.formatted_date || new Date(lastRun.timestamp * 1000).toLocaleString();
                $lastRun.text(lastRunDate);
            } else {
                $lastRun.text('Never');
            }

            // Update last run details
            if (lastRunDetails) {
                this.updateLastRunDetails(lastRunDetails);
                $('#last-run-details').show();
            } else {
                $('#last-run-details').hide();
            }

            // Update debug information if available
            this.updateDebugInfo(data);
        },

        /**
         * Update debug information
         */
        updateDebugInfo: function(data) {
            if ($('#debug-schedule-status').length) {
                var schedule = data.schedule;
                var nextRun = data.next_run;
                var lastRun = data.last_run;

                $('#debug-schedule-status').text(schedule.enabled ? 'Enabled' : 'Disabled');
                $('#debug-next-run').text(nextRun ? nextRun.formatted : 'Not scheduled');
                $('#debug-last-run').text(lastRun ? (lastRun.formatted_date || new Date(lastRun.timestamp * 1000).toLocaleString()) : 'Never');
                $('#debug-schedule-time').text((schedule.hour || 9) + ':' + (schedule.minute || 0).toString().padStart(2, '0'));
                $('#debug-schedule-frequency').text(schedule.frequency + (schedule.frequency === 'custom' ? ' (' + schedule.interval + 'h)' : ''));
            }
        },

        /**
         * Update last run details display
         */
        updateLastRunDetails: function(details) {
            $('#last-run-duration').text(this.formatDuration(details.duration));
            $('#last-run-processed').text(details.processed + ' / ' + details.total);
            $('#last-run-success-rate').text(this.calculateSuccessRate(details));
            $('#last-run-status').text(details.success ? 'Success' : 'Failed')
                .css('color', details.success ? '#34c759' : '#ff3b30');
        },

        /**
         * Format duration in seconds to human readable
         */
        formatDuration: function(seconds) {
            if (!seconds || isNaN(seconds)) return '—';

            var minutes = Math.floor(seconds / 60);
            var remainingSeconds = Math.floor(seconds % 60);

            if (minutes > 0) {
                return minutes + 'm ' + remainingSeconds + 's';
            } else {
                return remainingSeconds + 's';
            }
        },

        /**
         * Calculate success rate
         */
        calculateSuccessRate: function(details) {
            if (!details.total || details.total === 0) return '—';

            var successRate = ((details.processed / details.total) * 100).toFixed(1);
            return successRate + '%';
        },

        /**
         * Save schedule settings
         */
        saveScheduleSettings: function() {
            var self = this;
            var $button = $('#save-schedule');

            // Get form data
            var settings = {
                enabled: $('#schedule-enabled').is(':checked') ? '1' : '0',
                frequency: $('#schedule-frequency').val(),
                interval: parseInt($('#schedule-interval').val()) || 24,
                hour: parseInt($('#schedule-hour').val()) || 9,
                minute: parseInt($('#schedule-minute').val()) || 0
            };

            // Disable button and show loading state
            $button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i>Saving...');

            JobImportAPI.call('save_import_schedule', settings, function(response) {
                console.log('[SCHEDULING] Raw response:', response);
                console.log('[SCHEDULING] Response success:', response.success);
                console.log('[SCHEDULING] Response data:', response.data);
                if (response.data && response.data.schedule) {
                    console.log('[SCHEDULING] Response schedule.enabled:', response.data.schedule.enabled);
                }

                // Re-enable button
                $button.prop('disabled', false).html('Save Settings');

                if (response.success) {
                    // Update currentSchedule with the response data (which has proper boolean values)
                    self.currentSchedule = response.data.schedule;
                    console.log('[SCHEDULING] Updated currentSchedule:', self.currentSchedule);
                    self.updateUI(response.data);

                    // Show success message
                    self.showNotification('Schedule settings saved successfully', 'success');

                    PuntWorkJSLogger.info('Schedule settings saved', 'SCHEDULING', settings);
                } else {
                    self.showNotification('Failed to save schedule settings: ' + (response.data.message || 'Unknown error'), 'error');
                    PuntWorkJSLogger.error('Failed to save schedule settings', 'SCHEDULING', response.data);
                }
            });
        },

        /**
         * Test schedule
         */
        testSchedule: function() {
            var self = this;
            var $button = $('#test-schedule');

            if (!confirm('This will run a test import. Continue?')) {
                return;
            }

            $button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i>Testing...');

            JobImportAPI.call('test_import_schedule', {}, function(response) {
                $button.prop('disabled', false).html('Test Schedule');

                if (response.success) {
                    self.showNotification('Test import completed successfully', 'success');
                    // Refresh status after test
                    setTimeout(function() {
                        self.loadScheduleSettings();
                        self.loadRunHistory();
                    }, 2000);
                } else {
                    self.showNotification('Test import failed: ' + (response.data.message || 'Unknown error'), 'error');
                }
            });
        },

        /**
         * Run import now
         */
        runNow: function() {
            console.log('[PUNTWORK] JobImportScheduling.runNow() called');

            var self = this;
            var $button = $('#run-now');

            if (!confirm('This will schedule an import to start in 3 seconds. You can monitor the progress in real-time. Continue?')) {
                console.log('[PUNTWORK] User cancelled runNow');
                return;
            }

            console.log('[PUNTWORK] User confirmed runNow, proceeding...');
            $button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i>Scheduling...');

            JobImportAPI.call('run_scheduled_import', {}, function(response) {
                console.log('[PUNTWORK] run_scheduled_import response:', response);

                if (response.success) {
                    console.log('[PUNTWORK] Import scheduled successfully, showing progress UI');
                    $button.prop('disabled', false).html('<i class="fas fa-clock" style="margin-right: 8px;"></i>Import Scheduled');
                    self.showNotification('Import scheduled - starting in 3 seconds', 'success');

                    // Show the progress UI immediately so user can see it's starting
                    console.log('[PUNTWORK] Calling JobImportUI.showImportUI()');
                    JobImportUI.showImportUI();
                    JobImportUI.clearProgress();
                    $('#status-message').text('Import scheduled - waiting to start...');

                    // Start monitoring the import progress immediately
                    console.log('[PUNTWORK] Starting monitoring with JobImportEvents.startStatusPolling()');
                    self.monitorImportProgress(response.data.scheduled_time, response.data.next_check);
                } else {
                    console.log('[PUNTWORK] Import scheduling failed:', response.data);
                    $button.prop('disabled', false).html('Run Now');
                    self.showNotification('Failed to schedule import: ' + (response.data.message || 'Unknown error'), 'error');
                }
            });
        },

        /**
         * Monitor import progress in real-time
         */
        monitorImportProgress: function(scheduledTime, nextCheckTime) {
            var self = this;
            var startTime = Date.now();

            // Start the status polling immediately to show progress as soon as import begins
            JobImportEvents.startStatusPolling();

            var checkInterval = setInterval(function() {
                // Check if it's time to look for results
                if (Date.now() / 1000 >= nextCheckTime) {
                    self.loadScheduleSettings();
                    self.loadRunHistory();

                    // Check if import has started by looking at the status
                    JobImportAPI.call('get_import_schedule', {}, function(response) {
                        if (response.success) {
                            // Check if there's a currently running import
                            if (typeof window.JobImport !== 'undefined' && window.JobImport.getStatus) {
                                window.JobImport.getStatus(function(statusResponse) {
                                    if (statusResponse.success && statusResponse.data && !statusResponse.data.complete) {
                                        // Import is currently running - ensure progress UI is visible
                                        JobImportUI.showImportUI();
                                        return;
                                    }
                                });
                            }

                            // Check if import has completed
                            if (response.data.last_run_details) {
                                var lastRun = response.data.last_run_details;
                                if (lastRun.success !== undefined) {
                                    // Import has completed
                                    clearInterval(checkInterval);
                                    JobImportEvents.stopStatusPolling();
                                    $('#run-now').html('Run Now');

                                    if (lastRun.success) {
                                        self.showNotification('Import completed successfully! Check history for details.', 'success');
                                    } else {
                                        self.showNotification('Import failed: ' + (lastRun.error_message || 'Unknown error'), 'error');
                                    }
                                    return;
                                }
                            }
                        }

                        // Continue monitoring if no results yet
                        var elapsed = Math.floor((Date.now() - startTime) / 1000);
                        $('#run-now').html('<i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i>Running... (' + elapsed + 's)');

                        // Also refresh the main progress section if it exists
                        if (typeof window.JobImport !== 'undefined' && window.JobImport.loadProgress) {
                            window.JobImport.loadProgress();
                        }
                    });
                } else {
                    // Still waiting for scheduled time
                    var remaining = Math.max(0, Math.floor(scheduledTime - Date.now() / 1000));
                    $('#run-now').html('<i class="fas fa-clock" style="margin-right: 8px;"></i>Starting in ' + remaining + 's');
                }
            }, 2000); // Check every 2 seconds instead of 1

            // Stop monitoring after 5 minutes to prevent infinite loops
            setTimeout(function() {
                clearInterval(checkInterval);
                JobImportEvents.stopStatusPolling();
                $('#run-now').html('Run Now');
                self.showNotification('Import monitoring timed out. Please refresh the page to check status.', 'error');
            }, 300000); // 5 minutes
        },

        /**
         * Refresh schedule status
         */
        refreshScheduleStatus: function() {
            // Only refresh if schedule is enabled
            if (this.currentSchedule && this.currentSchedule.enabled) {
                this.loadScheduleSettings();
                // Also refresh history when status is refreshed
                this.loadRunHistory();
            }
        },

        /**
         * Show notification
         */
        showNotification: function(message, type) {
            // Remove existing notifications
            $('.job-import-notification').remove();

            // Create new notification with Apple-style design
            var notification = $('<div>')
                .addClass('job-import-notification')
                .addClass(type)
                .css({
                    position: 'fixed',
                    top: '24px',
                    right: '24px',
                    background: '#ffffff',
                    borderRadius: '12px',
                    padding: '16px 20px',
                    boxShadow: '0 8px 24px rgba(0,0,0,0.15), 0 2px 8px rgba(0,0,0,0.1)',
                    border: '1px solid #e5e5e7',
                    zIndex: 10000,
                    fontFamily: '-apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Helvetica Neue", Helvetica, Arial, sans-serif',
                    fontSize: '15px',
                    fontWeight: '500',
                    color: '#1d1d1f',
                    maxWidth: '400px',
                    animation: 'slideIn 0.3s ease-out',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '12px'
                });

            // Add icon based on type
            var iconClass = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle';
            var iconColor = type === 'success' ? '#34c759' : '#ff3b30';

            notification.append($('<i>')
                .addClass(iconClass)
                .css({
                    color: iconColor,
                    fontSize: '18px',
                    flexShrink: 0
                })
            );

            notification.append($('<span>').text(message));

            // Apply type-specific styling
            if (type === 'success') {
                notification.css({
                    borderColor: '#34c759',
                    background: 'linear-gradient(135deg, #f8fff9 0%, #ffffff 100%)'
                });
            } else if (type === 'error') {
                notification.css({
                    borderColor: '#ff3b30',
                    background: 'linear-gradient(135deg, #fff8f7 0%, #ffffff 100%)'
                });
            }

            notification.appendTo('body');

            // Auto-remove after 4 seconds
            setTimeout(function() {
                notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 4000);

            // Allow click to dismiss
            notification.on('click', function() {
                $(this).fadeOut(200, function() {
                    $(this).remove();
                });
            });
        },

        /**
         * Load run history
         */
        loadRunHistory: function() {
            var self = this;
            var $button = $('#refresh-history');
            var $list = $('#run-history-list');

            // Show loading state
            $button.addClass('loading').prop('disabled', true);
            $list.html('<div style="color: #86868b; text-align: center; padding: 24px; font-style: italic;"><i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i>Loading history...</div>');

            JobImportAPI.call('get_import_run_history', {}, function(response) {
                // Remove loading state
                $button.removeClass('loading').prop('disabled', false);

                if (response.success) {
                    self.displayRunHistory(response.data.history);
                    PuntWorkJSLogger.info('Run history loaded', 'SCHEDULING', { count: response.data.count });
                } else {
                    $list.html('<div style="color: #ff3b30; text-align: center; padding: 24px;"><i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i>Failed to load history</div>');
                    PuntWorkJSLogger.error('Failed to load run history', 'SCHEDULING', response.data);
                }
            });
        },

        /**
         * Display run history
         */
        displayRunHistory: function(history) {
            var $container = $('#run-history-list');

            if (!history || history.length === 0) {
                $container.html('<div style="color: #86868b; text-align: center; padding: 32px; font-style: italic; font-size: 15px;"><i class="fas fa-history" style="margin-right: 8px; opacity: 0.6;"></i>No import history available</div>');
                return;
            }

            var html = '';
            history.forEach(function(run) {
                var date = run.formatted_date || new Date(run.timestamp * 1000).toLocaleString();
                var statusColor = run.success ? '#34c759' : '#ff3b30';
                var statusBg = run.success ? '#f8fff9' : '#fff8f7';
                var statusText = run.success ? 'Success' : 'Failed';
                var modeText = run.test_mode ? ' <span style="background: #007aff; color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600;">TEST</span>' : '';

                html += '<div style="border: 1px solid #e5e5e7; border-radius: 8px; padding: 16px; margin-bottom: 12px; background: #ffffff; transition: all 0.2s ease;">';
                html += '<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">';
                html += '<div style="font-size: 14px; font-weight: 600; color: #1d1d1f;">' + date + modeText + '</div>';
                html += '<div style="background: ' + statusBg + '; color: ' + statusColor + '; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; border: 1px solid ' + statusColor + '20;">' + statusText + '</div>';
                html += '</div>';
                html += '<div style="color: #86868b; font-size: 13px; line-height: 1.4;">';
                html += '<div style="margin-bottom: 4px;"><i class="fas fa-clock" style="margin-right: 6px;"></i>Duration: <strong>' + this.formatDuration(run.duration) + '</strong></div>';
                html += '<div style="margin-bottom: 4px;"><i class="fas fa-tasks" style="margin-right: 6px;"></i>Processed: <strong>' + run.processed + '/' + run.total + '</strong></div>';
                html += '<div><i class="fas fa-chart-line" style="margin-right: 6px;"></i>Published: <strong style="color: #34c759;">' + (run.published || 0) + '</strong>, Updated: <strong style="color: #007aff;">' + (run.updated || 0) + '</strong>, Skipped: <strong style="color: #ff9500;">' + (run.skipped || 0) + '</strong></div>';
                html += '</div>';
                if (run.error_message) {
                    html += '<div style="background: #fff8f7; border: 1px solid #ff3b30; border-radius: 6px; padding: 8px 12px; margin-top: 8px; font-size: 12px; color: #ff3b30;"><i class="fas fa-exclamation-triangle" style="margin-right: 6px;"></i>' + run.error_message + '</div>';
                }
                html += '</div>';
            }.bind(this));

            $container.html(html);
        }
    };

    // Expose to global scope
    window.JobImportScheduling = JobImportScheduling;

})(jQuery, window, document);