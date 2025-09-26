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

            // Refresh schedule status periodically (reduced frequency to prevent console spam)
            setInterval(function() {
                self.refreshScheduleStatus();
            }, 300000); // Refresh every 5 minutes instead of 30 seconds

            // Refresh history button
            $('#refresh-history').on('click', function(e) {
                e.preventDefault();
                $(this).addClass('manual-refresh');
                self.loadRunHistory();
            });
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
                    // Only log on initial load, not on periodic refreshes
                    if (!self.initialLoadComplete) {
                        PuntWorkJSLogger.info('Schedule settings loaded', 'SCHEDULING', response.data);
                        self.initialLoadComplete = true;
                    }
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

            if (!confirm('This will start the import immediately. Continue?')) {
                console.log('[PUNTWORK] User cancelled runNow');
                return;
            }

            console.log('[PUNTWORK] User confirmed runNow, proceeding...');
            $button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i>Starting Import...');

            // Show the progress UI immediately
            JobImportUI.showImportUI();
            JobImportUI.clearProgress();
            $('#status-message').text('Starting import...');

            // Update import controls to show running state
            $('#start-import').hide();
            $('#resume-import').hide();
            $('#cancel-import').show();
            $('#reset-import').show();

            // Start status polling immediately to show progress as soon as import begins
            JobImportEvents.startStatusPolling();

            JobImportAPI.call('run_scheduled_import', {}, function(response) {
                console.log('[PUNTWORK] run_scheduled_import response:', response);
                PuntWorkJSLogger.debug('Run scheduled import response', 'SCHEDULING', {
                    success: response.success,
                    async: response.data?.async,
                    message: response.data?.message
                });

                if (response.success) {
                    // Check if import ran asynchronously or synchronously
                    if (response.data.async === false) {
                        // Import completed synchronously - stop polling and show results
                        console.log('[PUNTWORK] Import completed synchronously');
                        PuntWorkJSLogger.info('Import completed synchronously', 'SCHEDULING');
                        JobImportEvents.stopStatusPolling();
                        $button.prop('disabled', false).html('Run Now');
                        
                        // Show success notification with detailed stats
                        var message = response.data.result.message || 'Import completed successfully! Check history for details.';
                        self.showNotification(message, 'success');
                        
                        // Refresh the schedule settings and history to show the new run
                        self.loadScheduleSettings();
                        self.loadRunHistory();
                    } else {
                        // Import started asynchronously - keep polling for progress
                        console.log('[PUNTWORK] Import started asynchronously, continuing to poll');
                        PuntWorkJSLogger.info('Import started asynchronously, polling for updates', 'SCHEDULING');
                        $button.prop('disabled', false).html('Run Now');
                        
                        // Show success notification
                        self.showNotification('Import started successfully - progress will update in real-time', 'success');
                        
                        // Refresh the schedule settings and history after a short delay
                        setTimeout(function() {
                            self.loadScheduleSettings();
                            self.loadRunHistory();
                        }, 2000);
                    }
                } else {
                    console.log('[PUNTWORK] Import failed to start:', response.data);
                    PuntWorkJSLogger.error('Import failed to start', 'SCHEDULING', response.data);
                    $button.prop('disabled', false).html('Run Now');
                    
                    // Stop status polling since import failed to start
                    JobImportEvents.stopStatusPolling();
                    
                    // Reset UI state
                    JobImportUI.hideImportUI();
                    $('#start-import').show();
                    $('#resume-import').hide();
                    $('#cancel-import').hide();
                    $('#reset-import').hide();
                    
                    self.showNotification('Failed to start import: ' + (response.data.message || 'Unknown error'), 'error');
                }
            });
        },

        /**
         * Monitor import progress in real-time
         */
        monitorImportProgress: function(scheduledTime, nextCheckTime) {
            console.log('[PUNTWORK] === MONITORING STARTED ===');
            console.log('[PUNTWORK] monitorImportProgress called with:', {scheduledTime, nextCheckTime});

            var self = this;
            var startTime = Date.now();

            // Start the status polling immediately to show progress as soon as import begins
            console.log('[PUNTWORK] Starting JobImportEvents.startStatusPolling()');
            JobImportEvents.startStatusPolling();

            var checkInterval = setInterval(function() {
                console.log('[PUNTWORK] Monitoring check interval fired');
                // Check if it's time to look for results
                if (Date.now() / 1000 >= nextCheckTime) {
                    console.log('[PUNTWORK] Time to check results, calling loadScheduleSettings');
                    self.loadScheduleSettings();
                    self.loadRunHistory();

                    // Check if import has started by looking at the status
                    JobImportAPI.call('get_import_schedule', {}, function(response) {
                        console.log('[PUNTWORK] get_import_schedule response:', response);
                        if (response.success) {
                            // Check if there's a currently running import
                            if (typeof window.JobImport !== 'undefined' && window.JobImport.getStatus) {
                                console.log('[PUNTWORK] Checking JobImport.getStatus');
                                window.JobImport.getStatus(function(statusResponse) {
                                    console.log('[PUNTWORK] JobImport.getStatus response:', statusResponse);
                                    if (statusResponse.success && statusResponse.data && !statusResponse.data.complete) {
                                        // Import is currently running - ensure progress UI is visible
                                        console.log('[PUNTWORK] Import is running, ensuring UI is visible');
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
                                    console.log('[PUNTWORK] Import completed, stopping monitoring');
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
                console.log('[PUNTWORK] Monitoring timeout reached, stopping');
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
                    // Only log on initial load or manual refresh, not periodic refreshes
                    if ($button.hasClass('manual-refresh') || !self.historyLoaded) {
                        PuntWorkJSLogger.info('Run history loaded', 'SCHEDULING', { count: response.data.count });
                        self.historyLoaded = true;
                        $button.removeClass('manual-refresh');
                    }
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
            var currentDate = null;

            history.forEach(function(run) {
                var runDate = new Date(run.timestamp * 1000);
                var dateString = runDate.toLocaleDateString();
                var timeString = runDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

                // Group by date
                if (currentDate !== dateString) {
                    if (currentDate !== null) {
                        html += '</div>'; // Close previous date group
                    }
                    html += '<div class="history-date-group" style="margin-bottom: 24px;">';
                    html += '<div class="history-date-header" style="font-size: 13px; font-weight: 600; color: #86868b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #e5e5e7;">' + dateString + '</div>';
                    currentDate = dateString;
                }

                var statusColor = run.success ? '#34c759' : '#ff3b30';
                var statusBg = run.success ? '#f0fdf4' : '#fef2f2';
                var statusText = run.success ? 'Success' : 'Failed';
                var modeText = run.test_mode ? '<span class="test-badge" style="background: #dbeafe; color: #1d4ed8; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; margin-left: 8px;">TEST</span>' : '';

                // Trigger type badge
                var triggerType = run.trigger_type || 'scheduled';
                var triggerColors = {
                    'manual': { bg: '#f3f4f6', color: '#374151', icon: 'fas fa-user' },
                    'scheduled': { bg: '#fef3c7', color: '#92400e', icon: 'fas fa-clock' },
                    'api': { bg: '#e0f2fe', color: '#0c4a6e', icon: 'fas fa-code' }
                };
                var triggerColor = triggerColors[triggerType] || triggerColors['scheduled'];
                var triggerText = '<span class="trigger-badge" style="background: ' + triggerColor.bg + '; color: ' + triggerColor.color + '; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; margin-left: 8px; display: inline-flex; align-items: center; gap: 4px;"><i class="' + triggerColor.icon + '" style="font-size: 10px;"></i>' + triggerType.charAt(0).toUpperCase() + triggerType.slice(1) + '</span>';

                // Calculate progress percentage
                var progressPercent = run.total > 0 ? Math.round((run.processed / run.total) * 100) : 0;

                html += '<div class="history-item" style="background: #ffffff; border-radius: 12px; padding: 20px; margin-bottom: 12px; border: 1px solid #e5e5e7; box-shadow: 0 1px 3px rgba(0,0,0,0.04); transition: all 0.2s ease; position: relative; overflow: hidden;">';

                // Status indicator stripe
                html += '<div style="position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: ' + statusColor + '; border-radius: 12px 0 0 12px;"></div>';

                html += '<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">';
                html += '<div style="display: flex; align-items: center; gap: 8px;">';
                html += '<div class="history-time" style="font-size: 15px; font-weight: 600; color: #1d1d1f;">' + timeString + '</div>';
                html += modeText;
                html += triggerText;
                html += '</div>';
                html += '<div class="status-badge" style="background: ' + statusBg + '; color: ' + statusColor + '; padding: 6px 12px; border-radius: 16px; font-size: 13px; font-weight: 600; border: 1px solid ' + statusColor + '20; display: flex; align-items: center; gap: 6px;">';
                html += '<div style="width: 8px; height: 8px; border-radius: 50%; background: ' + statusColor + ';"></div>';
                html += statusText;
                html += '</div>';
                html += '</div>';

                // Progress bar
                html += '<div class="progress-section" style="margin-bottom: 16px;">';
                html += '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">';
                html += '<span style="font-size: 13px; color: #86868b; font-weight: 500;">Progress</span>';
                html += '<span style="font-size: 13px; color: #1d1d1f; font-weight: 600;">' + run.processed + ' / ' + run.total + ' items</span>';
                html += '</div>';
                html += '<div class="progress-bar" style="width: 100%; height: 6px; background: #e5e5e7; border-radius: 3px; overflow: hidden;">';
                html += '<div style="width: ' + progressPercent + '%; height: 100%; background: linear-gradient(90deg, ' + statusColor + ', ' + statusColor + 'dd); border-radius: 3px; transition: width 0.3s ease;"></div>';
                html += '</div>';
                html += '</div>';

                // Metrics grid
                html += '<div class="metrics-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 16px; margin-bottom: 16px;">';
                html += '<div class="metric-item" style="display: flex; align-items: center; gap: 8px;">';
                html += '<div class="metric-icon" style="width: 32px; height: 32px; border-radius: 8px; background: #f2f2f7; display: flex; align-items: center; justify-content: center;"><i class="fas fa-clock" style="font-size: 14px; color: #86868b;"></i></div>';
                html += '<div><div style="font-size: 11px; color: #86868b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em;">Duration</div><div style="font-size: 14px; font-weight: 600; color: #1d1d1f;">' + this.formatDuration(run.duration) + '</div></div>';
                html += '</div>';

                html += '<div class="metric-item" style="display: flex; align-items: center; gap: 8px;">';
                html += '<div class="metric-icon" style="width: 32px; height: 32px; border-radius: 8px; background: #f0fdf4; display: flex; align-items: center; justify-content: center;"><i class="fas fa-plus-circle" style="font-size: 14px; color: #34c759;"></i></div>';
                html += '<div><div style="font-size: 11px; color: #86868b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em;">Published</div><div style="font-size: 14px; font-weight: 600; color: #34c759;">' + (run.published || 0) + '</div></div>';
                html += '</div>';

                html += '<div class="metric-item" style="display: flex; align-items: center; gap: 8px;">';
                html += '<div class="metric-icon" style="width: 32px; height: 32px; border-radius: 8px; background: #eff6ff; display: flex; align-items: center; justify-content: center;"><i class="fas fa-edit" style="font-size: 14px; color: #3b82f6;"></i></div>';
                html += '<div><div style="font-size: 11px; color: #86868b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em;">Updated</div><div style="font-size: 14px; font-weight: 600; color: #3b82f6;">' + (run.updated || 0) + '</div></div>';
                html += '</div>';

                html += '<div class="metric-item" style="display: flex; align-items: center; gap: 8px;">';
                html += '<div class="metric-icon" style="width: 32px; height: 32px; border-radius: 8px; background: #fef3c7; display: flex; align-items: center; justify-content: center;"><i class="fas fa-forward" style="font-size: 14px; color: #f59e0b;"></i></div>';
                html += '<div><div style="font-size: 11px; color: #86868b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em;">Skipped</div><div style="font-size: 14px; font-weight: 600; color: #f59e0b;">' + (run.skipped || 0) + '</div></div>';
                html += '</div>';
                html += '</div>';

                // Error message if present and actually indicates an error or important info
                if (run.error_message && (run.error_message.includes('failed') || run.error_message.includes('error') || run.error_message.includes('cancelled') || run.error_message.includes('paused') || !run.success)) {
                    var isError = run.error_message.includes('failed') || run.error_message.includes('error') || run.error_message.includes('cancelled') || !run.success;
                    var messageStyle = isError ?
                        'background: linear-gradient(135deg, #fef2f2, #fee2e2); border: 1px solid #fecaca; color: #dc2626;' :
                        'background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 1px solid #bbf7d0; color: #166534;';
                    var iconClass = isError ? 'fas fa-exclamation' : 'fas fa-check-circle';
                    var iconBg = isError ? '#dc2626' : '#16a34a';

                    html += '<div class="error-message" style="' + messageStyle + ' border-radius: 8px; padding: 12px 16px; margin-top: 16px; display: flex; align-items: flex-start; gap: 12px;">';
                    html += '<div style="flex-shrink: 0; width: 20px; height: 20px; border-radius: 50%; background: ' + iconBg + '; display: flex; align-items: center; justify-content: center;"><i class="' + iconClass + '" style="font-size: 10px; color: white;"></i></div>';
                    html += '<div style="font-size: 13px; line-height: 1.4;">' + run.error_message + '</div>';
                    html += '</div>';
                }

                html += '</div>';
            }.bind(this));

            if (currentDate !== null) {
                html += '</div>'; // Close last date group
            }

            $container.html(html);

            // Add hover effects
            $container.find('.history-item').hover(
                function() {
                    $(this).css({
                        'transform': 'translateY(-2px)',
                        'box-shadow': '0 4px 12px rgba(0,0,0,0.08)'
                    });
                },
                function() {
                    $(this).css({
                        'transform': 'translateY(0)',
                        'box-shadow': '0 1px 3px rgba(0,0,0,0.04)'
                    });
                }
            );
        }
    };

    // Expose to global scope
    window.JobImportScheduling = JobImportScheduling;

})(jQuery, window, document);