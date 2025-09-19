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
            this.bindEvents();
            this.loadScheduleSettings();
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
        },

        /**
         * Load current schedule settings
         */
        loadScheduleSettings: function() {
            var self = this;

            JobImportAPI.call('get_import_schedule', {}, function(response) {
                if (response.success) {
                    self.currentSchedule = response.data.schedule;
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
            var schedule = data.schedule;

            // Update form controls
            $('#schedule-enabled').prop('checked', schedule.enabled);
            $('#schedule-frequency').val(schedule.frequency);
            $('#schedule-interval').val(schedule.interval);

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
                var lastRunDate = new Date(lastRun.timestamp * 1000);
                $lastRun.text(lastRunDate.toLocaleString());
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
                $('#debug-last-run').text(lastRun ? new Date(lastRun.timestamp * 1000).toLocaleString() : 'Never');
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
                enabled: $('#schedule-enabled').is(':checked'),
                frequency: $('#schedule-frequency').val(),
                interval: parseInt($('#schedule-interval').val()) || 24
            };

            // Disable button during save
            $button.prop('disabled', true).text('Saving...');

            JobImportAPI.call('save_import_schedule', settings, function(response) {
                $button.prop('disabled', false).text('Save Settings');

                if (response.success) {
                    self.currentSchedule = settings;
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

            $button.prop('disabled', true).text('Testing...');

            JobImportAPI.call('test_import_schedule', {}, function(response) {
                $button.prop('disabled', false).text('Test Schedule');

                if (response.success) {
                    self.showNotification('Test import completed successfully', 'success');
                    // Refresh status after test
                    setTimeout(function() {
                        self.loadScheduleSettings();
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
            var self = this;
            var $button = $('#run-now');

            if (!confirm('This will start an import immediately. Continue?')) {
                return;
            }

            $button.prop('disabled', true).text('Starting...');

            JobImportAPI.call('run_scheduled_import', {}, function(response) {
                $button.prop('disabled', false).text('Run Now');

                if (response.success) {
                    self.showNotification('Import started successfully', 'success');
                    // Refresh status
                    self.loadScheduleSettings();
                } else {
                    self.showNotification('Failed to start import: ' + (response.data.message || 'Unknown error'), 'error');
                }
            });
        },

        /**
         * Refresh schedule status
         */
        refreshScheduleStatus: function() {
            // Only refresh if schedule is enabled
            if (this.currentSchedule && this.currentSchedule.enabled) {
                this.loadScheduleSettings();
            }
        },

        /**
         * Show notification
         */
        showNotification: function(message, type) {
            // Simple notification - could be enhanced with a proper notification system
            var color = type === 'success' ? '#34c759' : '#ff3b30';
            var notification = $('<div>')
                .css({
                    position: 'fixed',
                    top: '20px',
                    right: '20px',
                    background: color,
                    color: 'white',
                    padding: '12px 16px',
                    borderRadius: '8px',
                    boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                    zIndex: 10000,
                    fontSize: '14px',
                    fontWeight: '500'
                })
                .text(message)
                .appendTo('body');

            setTimeout(function() {
                notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        }
    };

    // Expose to global scope
    window.JobImportScheduling = JobImportScheduling;

})(jQuery, window, document);