/**
 * Job Import Admin - Events Module
 * Handles event binding and user interactions
 */

console.log('[PUNTWORK] job-import-events.js loaded');

(function($, window, document) {
    'use strict';

    var JobImportEvents = {
        /**
         * Initialize event bindings
         */
        init: function() {
            this.bindEvents();
            this.checkInitialStatus();

            // Fallback: Re-bind events after a short delay to ensure DOM is ready
            setTimeout(function() {
                JobImportEvents.bindEvents();
            }, 1000);
        },

        /**
         * Bind all event handlers
         */
        bindEvents: function() {
            $('#start-import').on('click', function(e) {
                console.log('[PUNTWORK] Start import clicked');
                JobImportEvents.handleStartImport();
            });
            $('#resume-import').on('click', function(e) {
                console.log('[PUNTWORK] Resume import clicked');
                JobImportEvents.handleResumeImport();
            });
            $('#cancel-import').on('click', function(e) {
                console.log('[PUNTWORK] Cancel import clicked');
                JobImportEvents.handleCancelImport();
            });
            $('#reset-import').on('click', function(e) {
                console.log('[PUNTWORK] Reset import clicked');
                JobImportEvents.handleResetImport();
            });
            $('#resume-stuck-import').on('click', function(e) {
                console.log('[PUNTWORK] Resume stuck import clicked');
                JobImportEvents.handleResumeStuckImport();
            });

            // Cleanup buttons for jobs dashboard
            $('#cleanup-trashed').on('click', function(e) {
                console.log('[PUNTWORK] Cleanup trashed jobs clicked');
                e.preventDefault();
                JobImportEvents.handleCleanupTrashedJobs();
            });
            $('#cleanup-drafted').on('click', function(e) {
                console.log('[PUNTWORK] Cleanup drafted jobs clicked');
                e.preventDefault();
                JobImportEvents.handleCleanupDraftedJobs();
            });
            $('#cleanup-old-published').on('click', function(e) {
                console.log('[PUNTWORK] Cleanup old published jobs clicked');
                e.preventDefault();
                JobImportEvents.handleCleanupOldPublishedJobs();
            });
        },

        /**
         * Bind only cleanup event handlers (for jobs dashboard)
         */
        bindCleanupEvents: function() {
            // Bind cleanup buttons
            $('#cleanup-trashed').on('click', function(e) {
                console.log('[PUNTWORK] Cleanup trashed jobs clicked');
                e.preventDefault();
                JobImportEvents.handleCleanupTrashedJobs();
            });

            $('#cleanup-drafted').on('click', function(e) {
                console.log('[PUNTWORK] Cleanup drafted jobs clicked');
                e.preventDefault();
                JobImportEvents.handleCleanupDraftedJobs();
            });

            $('#cleanup-old-published').on('click', function(e) {
                console.log('[PUNTWORK] Cleanup old published jobs clicked');
                e.preventDefault();
                JobImportEvents.handleCleanupOldPublishedJobs();
            });
        },

        /**
         * Handle start import button click
         */
        handleStartImport: function() {
            JobImportLogic.handleStartImport();
        },

        /**
         * Handle resume import button click
         */
        handleResumeImport: function() {
            JobImportLogic.handleResumeImport();
        },

        /**
         * Handle cancel import button click
         */
        handleCancelImport: function() {
            JobImportLogic.handleCancelImport();
        },

        /**
         * Handle reset import button click
         */
        handleResetImport: function() {
            $('#reset-import').prop('disabled', true); // Disable immediately to prevent multiple clicks
            if (confirm('This will completely reset the import system and clear all progress. Any ongoing import will be stopped. Continue?')) {
                console.log('[PUNTWORK] User confirmed reset');
                JobImportLogic.handleResetImport();
            } else {
                console.log('[PUNTWORK] User cancelled reset');
                $('#reset-import').prop('disabled', false); // Re-enable if cancelled
            }
        },

        /**
         * Handle resume stuck import button click
         */
        handleResumeStuckImport: function() {
            console.log('[PUNTWORK] Resume stuck import handler called');

            if (confirm('This will attempt to manually resume a stuck import by clearing continuation schedules and restarting the import process. Continue?')) {
                console.log('[PUNTWORK] User confirmed resume stuck import');
                $('#resume-stuck-text').hide();
                $('#resume-stuck-loading').show();
                $('#import-status').text('Resuming stuck import...');
                JobImportLogic.handleResumeStuckImport();
            } else {
                console.log('[PUNTWORK] User cancelled resume stuck import');
            }
        },

        /**
         * Handle cleanup trashed jobs button click
         */
        handleCleanupTrashedJobs: function() {
            console.log('[PUNTWORK] Cleanup trashed jobs handler called');

            if (confirm('This will permanently delete all job posts that are in Trash status. This action cannot be undone. Continue?')) {
                console.log('[PUNTWORK] User confirmed cleanup trashed jobs');
                $('#cleanup-status').text('Removing trashed jobs...');
                JobImportLogic.handleCleanupTrashedJobs();
            } else {
                console.log('[PUNTWORK] User cancelled cleanup trashed jobs');
            }
        },

        /**
         * Handle cleanup drafted jobs button click
         */
        handleCleanupDraftedJobs: function() {
            console.log('[PUNTWORK] Cleanup drafted jobs handler called');

            if (confirm('This will permanently delete all job posts that are in Draft status. This action cannot be undone. Continue?')) {
                console.log('[PUNTWORK] User confirmed cleanup drafted jobs');
                $('#cleanup-status').text('Removing drafted jobs...');
                JobImportLogic.handleCleanupDraftedJobs();
            } else {
                console.log('[PUNTWORK] User cancelled cleanup drafted jobs');
            }
        },

        /**
         * Handle cleanup old published jobs button click
         */
        handleCleanupOldPublishedJobs: function() {
            console.log('[PUNTWORK] Cleanup old published jobs handler called');

            if (confirm('This will permanently delete all published job posts that are no longer present in current feeds. This action cannot be undone. Continue?')) {
                console.log('[PUNTWORK] User confirmed cleanup old published jobs');
                $('#cleanup-status').text('Removing old published jobs...');
                JobImportLogic.handleCleanupOldPublishedJobs();
            } else {
                console.log('[PUNTWORK] User cancelled cleanup old published jobs');
            }
        },

        /**
         * Check initial import status on page load
         */
        checkInitialStatus: function() {
            // Clear progress first to ensure clean state
            JobImportUI.clearProgress();

            // Hide all import buttons immediately to prevent flash of unwanted buttons
            $('#start-import').hide();
            $('#resume-import').hide();
            $('#cancel-import').hide();
            $('#reset-import').hide();

            JobImportAPI.getImportStatus().then(function(response) {
                PuntWorkJSLogger.debug('Initial status response', 'EVENTS', response);

                // Handle both response formats: direct data or wrapped in .data
                var statusData = JobImportUI.normalizeResponseData(response);

                // Check time since last update for detecting active imports
                var currentTime = Math.floor(Date.now() / 1000);
                var timeSinceLastUpdate = currentTime - (statusData.last_update || 0);

                // Determine if there's actually an incomplete import to resume
                var hasIncompleteImport = response.success && (
                    (statusData.processed > 0 && !statusData.complete) || // Partially completed import OR counting phase
                    (statusData.resume_progress > 0) || // Has resume progress
                    (!statusData.complete && statusData.total > 0) || // Incomplete with total set
                    (!statusData.complete && timeSinceLastUpdate < 600) // Incomplete and recently updated (for scheduled imports)
                );

                // Check if scheduling is enabled and for active scheduled imports
                var isScheduledImport = false;
                var activeScheduledImports = null;

                // Check active imports (schedule data is loaded by JobImportScheduling.init())
                var activeImportsCheckComplete = false;
                var activeImportsData = null;

                function processScheduleChecks() {
                    if (!activeImportsCheckComplete) {
                        return; // Wait for active imports check to complete
                    }

                    if (activeImportsData && activeImportsData.success && activeImportsData.data) {
                        activeScheduledImports = activeImportsData.data;

                        // If we have active scheduled imports, consider them as running
                        if (activeScheduledImports.has_active_imports) {
                            hasIncompleteImport = true;
                        }
                    }

                    // For scheduled imports, also consider imports active if they have a start_time and are recent
                    if (isScheduledImport && !hasIncompleteImport) {
                        var hasRecentStartTime = statusData.start_time && typeof statusData.start_time === 'number' && (currentTime - statusData.start_time) < 3600; // Started within last hour
                        var isCountingPhase = statusData.start_time && typeof statusData.start_time === 'number' && statusData.total === 0 && statusData.complete === false && (statusData.processed || 0) > 0; // Counting phase with processed items

                        if (hasRecentStartTime || isCountingPhase) {
                            hasIncompleteImport = true;
                        }
                    }

                    // Additional check: If we have an incomplete import with a start_time and scheduling is enabled,
                    // it's likely a scheduled import that's currently running
                    var isLikelyScheduledImport = false;
                    if (hasIncompleteImport && statusData.start_time && typeof statusData.start_time === 'number' && isScheduledImport) {
                        var timeSinceStart = currentTime - statusData.start_time;
                        isLikelyScheduledImport = timeSinceStart < 7200; // Within last 2 hours
                    }

                    // Now proceed with UI logic based on the results
                    finalizeInitialStatusCheck(isLikelyScheduledImport);
                }

                function finalizeInitialStatusCheck(isLikelyScheduledImport) {
                    if (hasIncompleteImport) {
                        JobImportUI.updateProgress(statusData);
                        JobImportUI.appendLogs(statusData.logs || []);

                        // Start heartbeat monitoring for real-time updates when import is active
                        JobImportEvents.startHeartbeatMonitoring();

                        // Determine import type for UI display
                        var importType = 'manual';
                        if (isLikelyScheduledImport || (activeScheduledImports && activeScheduledImports.has_active_imports)) {
                            importType = 'scheduled';
                        }

                        // Check if this might be a stuck import
                        var isStuckImport = false;
                        var currentTime = Math.floor(Date.now() / 1000);
                        var timeSinceLastUpdate = statusData.last_update && typeof statusData.last_update === 'number' ? currentTime - statusData.last_update : null;
                        var timeSinceStart = statusData.start_time && typeof statusData.start_time === 'number' ? currentTime - statusData.start_time : null;

                        // Consider it stuck if:
                        // 1. No progress for more than 10 minutes (600 seconds) AND we have a valid last_update time
                        // 2. Import has been running for more than 2 hours (7200 seconds) AND we have a valid start_time
                        // 3. Has continuation attempts but still paused
                        if ((timeSinceLastUpdate !== null && timeSinceLastUpdate > 600) ||
                            (timeSinceStart !== null && timeSinceStart > 7200) ||
                            (statusData.paused && (statusData.continuation_attempts || 0) > 0)) {
                            isStuckImport = true;
                        }

                        // Show the import progress section and update UI
                        JobImportUI.showImportUI();
                        JobImportUI.updateProgress(statusData.processed, statusData.total, statusData.complete);
                        $('#import-type-indicator').show();
                        $('#import-type-text').text(importType.charAt(0).toUpperCase() + importType.slice(1) + ' import is currently running');

                        // Show appropriate control buttons based on import type and state
                        if (importType === 'scheduled') {
                            // For scheduled imports, show cancel and reset buttons if active
                            if (hasIncompleteImport || (activeScheduledImports && activeScheduledImports.has_active_imports)) {
                                JobImportUI.showCancelButton();
                                JobImportUI.showResetButton();
                                JobImportUI.hideResumeButton();
                                JobImportUI.hideStartButton();
                                if (isStuckImport) {
                                    JobImportUI.showResumeStuckButton();
                                } else {
                                    JobImportUI.hideResumeStuckButton();
                                }
                            } else {
                                // Scheduled import completed or not running
                                JobImportUI.hideCancelButton();
                                JobImportUI.hideResumeButton();
                                JobImportUI.hideResetButton();
                                JobImportUI.hideResumeStuckButton();
                                JobImportUI.showStartButton();
                            }
                        } else {
                            // For manual imports, show resume/reset based on state
                            if (hasIncompleteImport) {
                                JobImportUI.showResumeButton();
                                JobImportUI.showResetButton();
                                JobImportUI.hideCancelButton();
                                JobImportUI.hideStartButton();
                                if (isStuckImport) {
                                    JobImportUI.showResumeStuckButton();
                                } else {
                                    JobImportUI.hideResumeStuckButton();
                                }
                            } else {
                                JobImportUI.showStartButton();
                                JobImportUI.hideResumeButton();
                                JobImportUI.hideResetButton();
                                JobImportUI.hideCancelButton();
                                JobImportUI.hideResumeStuckButton();
                            }
                        }
                    } else {
                        // Clean state - hide all import controls except start
                        $('#start-import').show().text('Start Import');
                        $('#resume-import').hide();
                        $('#cancel-import').hide();
                        $('#reset-import').hide();
                        $('#resume-stuck-import').hide();
                        $('#import-type-indicator').hide();
                        JobImportUI.hideImportUI();
                    }
                }

                JobImportAPI.call('get_active_scheduled_imports', {}, function(activeResponse) {
                    activeImportsData = activeResponse;
                    activeImportsCheckComplete = true;
                    processScheduleChecks();
                }).catch(function(activeError) {
                    console.log('[PUNTWORK] get_active_scheduled_imports error:', activeError);
                    activeImportsData = {success: false, error: activeError};
                    activeImportsCheckComplete = true;
                    processScheduleChecks();
                });
            }).catch(function(xhr, status, error) {
                console.log('[PUNTWORK] Initial status AJAX error:', error, 'xhr:', xhr, 'status:', status);
                PuntWorkJSLogger.error('Initial status AJAX error', 'EVENTS', error);
                JobImportUI.appendLogs(['Initial status AJAX error: ' + error]);
                // Ensure UI is in clean state even on error
                JobImportUI.clearProgress();
                JobImportUI.hideImportUI();
                $('#start-import').show().text('Start Import');
                $('#resume-import').hide();
                $('#cancel-import').hide();
                $('#reset-import').hide();
                $('#import-type-indicator').hide();
            });
        },

        /**
         * Handle restart import (special case for interrupted imports)
         */
        handleRestartImport: async function() {
            PuntWorkJSLogger.info('Restart clicked - resetting and starting over', 'EVENTS');

            try {
                const resetResponse = await JobImportAPI.resetImport();
                if (resetResponse.success) {
                    JobImportUI.appendLogs(['Import reset for restart']);
                }
                // Directly call start import instead of triggering click to prevent loops
                JobImportLogic.handleStartImport();
            } catch (error) {
                PuntWorkJSLogger.error('Restart error', 'EVENTS', error);
                JobImportUI.appendLogs(['Restart error: ' + error.message]);
            }
        },

        /**
         * Start polling for import status updates (used for scheduled imports and manual imports)
         */
        startStatusPolling: function(isBackgroundMode = false) {
            // Clear any existing polling interval
            if (this.statusPollingInterval) {
                clearInterval(this.statusPollingInterval);
            }

            // Initialize smart polling variables
            this.isBackgroundMode = isBackgroundMode;
            this.currentPollingInterval = isBackgroundMode ? 10000 : 1000;
            this.lastProcessedCount = -1;
            this.lastProgressTimestamp = Date.now();
            this.noProgressCount = 0;
            this.maxNoProgressBeforeSlow = isBackgroundMode ? 60 : 15;
            this.maxNoProgressBeforeStop = isBackgroundMode ? 720 : 300;
            this.completeDetectedCount = 0;
            this.maxCompletePolls = 2;
            var totalZeroCount = 0;
            this.maxTotalZeroPolls = isBackgroundMode ? 60 : 20;
            var isStartingNewImport = true;
            var hasSeenImportRunning = false;
            var lastLogTime = 0;
            var lastModifiedTimestamp = 0;
            this.lastTotalCount = 0;
            this.lastCompleteStatus = false;
            this.consecutiveFastUpdates = 0;

            var startTime = Date.now();

            // Store the polling function for reuse
            this.pollStatus = function() {
                var now = Date.now();
                var timeSinceLastLog = now - lastLogTime;
                var pollCount = Math.floor((Date.now() - startTime) / JobImportEvents.currentPollingInterval);

                JobImportAPI.getImportStatus().then(function(response) {
                    if (response.success) {
                        var statusData = JobImportUI.normalizeResponseData(response);
                        var currentProcessed = statusData.processed || 0;
                        var currentTotal = statusData.total || 0;
                        var serverLastModified = statusData.last_modified || 0;

                        // Check if server data has actually changed
                        var dataHasChanged = serverLastModified !== lastModifiedTimestamp ||
                                           currentProcessed !== JobImportEvents.lastProcessedCount ||
                                           currentTotal !== (JobImportEvents.lastTotalCount || 0) ||
                                           statusData.complete !== (JobImportEvents.lastCompleteStatus || false);

                        // Store current values for next comparison
                        JobImportEvents.lastTotalCount = currentTotal;
                        JobImportEvents.lastCompleteStatus = statusData.complete;

                        if (!dataHasChanged && lastModifiedTimestamp > 0) {
                            return; // Skip processing unchanged data
                        }

                        lastModifiedTimestamp = serverLastModified;

                        // Check if we need to switch from background to active polling mode
                        var importJustStarted = !hasSeenImportRunning && (currentProcessed > 0 || (currentTotal > 0 && !statusData.complete));
                        if (JobImportEvents.isBackgroundMode && importJustStarted) {
                            JobImportEvents.switchToActivePolling();
                            hasSeenImportRunning = true;
                            isStartingNewImport = false;
                        }

                        // Check if total is still 0 (import hasn't started)
                        var pollCount = Math.floor((Date.now() - startTime) / JobImportEvents.currentPollingInterval);
                        var isEarlyPoll = pollCount < 5;

                        var isActivelyProcessing = currentProcessed > 0 && currentProcessed !== JobImportEvents.lastProcessedCount;

                        if (currentTotal === 0 && !isActivelyProcessing) {
                            totalZeroCount++;
                        } else {
                            totalZeroCount = 0;
                        }

                        // Stop polling if total has been 0 for too many polls
                        var effectiveMaxZeroPolls = isEarlyPoll ? 25 : JobImportEvents.maxTotalZeroPolls;
                        if (totalZeroCount >= effectiveMaxZeroPolls) {
                            console.log('[PUNTWORK] Import failed to start, stopping polling');
                            JobImportEvents.stopStatusPolling();
                            JobImportUI.resetButtons();
                            $('#status-message').text('Import failed to start - please try again');
                            $('#import-type-indicator').hide();
                            return;
                        }

                        // Check if progress has changed
                        if (currentProcessed !== JobImportEvents.lastProcessedCount) {
                            var previousProcessed = JobImportEvents.lastProcessedCount;
                            JobImportEvents.lastProcessedCount = currentProcessed;
                            JobImportEvents.lastProgressTimestamp = now;
                            JobImportEvents.noProgressCount = 0;
                            JobImportEvents.consecutiveFastUpdates++;

                            // Progress detected - speed up polling for active processing
                            var itemsProcessedInPoll = currentProcessed - (previousProcessed || 0);
                            var timeSinceLastProgress = now - JobImportEvents.lastProgressTimestamp;

                            if (itemsProcessedInPoll > 0 && timeSinceLastProgress < 2000) {
                                var processingRate = itemsProcessedInPoll / (timeSinceLastProgress / 1000);
                                if (processingRate > 5) {
                                    JobImportEvents.adjustPollingInterval(500);
                                } else if (processingRate > 2) {
                                    JobImportEvents.adjustPollingInterval(1000);
                                } else {
                                    JobImportEvents.adjustPollingInterval(1500);
                                }
                            } else {
                                if (JobImportEvents.currentPollingInterval > 1500) {
                                    JobImportEvents.adjustPollingInterval(1500);
                                }
                            }
                        } else {
                            JobImportEvents.consecutiveFastUpdates = 0;
                            JobImportEvents.noProgressCount++;

                            // Implement smart backoff when no progress
                            if (JobImportEvents.noProgressCount >= JobImportEvents.maxNoProgressBeforeSlow) {
                                var newInterval = Math.min(JobImportEvents.currentPollingInterval * 1.5, 8000);
                                if (newInterval !== JobImportEvents.currentPollingInterval) {
                                    JobImportEvents.adjustPollingInterval(newInterval);
                                }
                            }

                            // Stop polling if no progress for too long
                            var progressPercent = currentTotal > 0 ? (currentProcessed / currentTotal) * 100 : 0;
                            var effectiveMaxNoProgress = progressPercent > 90 ? 600 : JobImportEvents.maxNoProgressBeforeStop;
                            if (JobImportEvents.noProgressCount >= effectiveMaxNoProgress) {
                                console.log('[PUNTWORK] No progress detected, stopping polling');
                                JobImportEvents.stopStatusPolling();
                                JobImportUI.resetButtons();
                                $('#status-message').text('Import appears stalled - please check logs or try again');
                                return;
                            }
                        }

                        // Update UI with progress data
                        var isCountingPhase = currentTotal === 0 && currentProcessed > 0 && !statusData.complete;

                        if (currentTotal > 0 || isCountingPhase) {
                            if (!hasSeenImportRunning) {
                                JobImportUI.showImportUI();
                                $('#start-import').hide();
                                $('#resume-import').hide();
                                $('#cancel-import').show();
                                $('#reset-import').show();
                                $('#status-message').text('Import in progress...');

                                var importType = 'scheduled';
                                $('#import-type-indicator').show();
                                $('#import-type-text').text(importType.charAt(0).toUpperCase() + importType.slice(1) + ' import is currently running');
                            }

                            JobImportUI.updateProgress(statusData);
                            JobImportUI.appendLogs(statusData.logs || []);
                            isStartingNewImport = false;
                            hasSeenImportRunning = true;
                        }

                        // Check if import completed
                        if (statusData.complete && currentTotal > 0 && (currentProcessed >= currentTotal || statusData.complete)) {
                            JobImportEvents.completeDetectedCount++;
                            if (JobImportEvents.completeDetectedCount >= JobImportEvents.maxCompletePolls) {
                                JobImportUI.updateProgress(statusData);
                                JobImportEvents.stopStatusPolling();
                                JobImportUI.resetButtons();
                                $('#status-message').text('Import Complete');
                                $('#import-type-indicator').hide();
                            }
                        } else if (statusData.complete && currentTotal === 0 && hasSeenImportRunning && !isStartingNewImport) {
                            JobImportEvents.stopStatusPolling();
                            JobImportUI.clearProgress();
                            JobImportUI.hideImportUI();
                            JobImportUI.resetButtons();
                            $('#status-message').text('Ready to start.');
                            $('#import-type-indicator').hide();
                        } else {
                            JobImportEvents.completeDetectedCount = 0;

                            // FALLBACK CONTINUATION: Check if import is paused and try AJAX continuation
                            if (statusData.paused && !statusData.complete && currentTotal > 0) {
                                var timeSincePause = statusData.pause_time ? (now / 1000) - statusData.pause_time : null;
                                var continuationAttempts = statusData.continuation_attempts || 0;

                                // Only attempt AJAX continuation if:
                                // 1. Import has been paused for more than 30 seconds (give cron time to work)
                                // 2. We haven't already tried AJAX continuation recently
                                // 3. We haven't exceeded max attempts
                                if (timeSincePause && timeSincePause > 30 && continuationAttempts < 5) {
                                    var lastAttempt = statusData.last_continuation_attempt || 0;
                                    var timeSinceLastAttempt = (now / 1000) - lastAttempt;

                                    if (timeSinceLastAttempt > 60) { // Wait at least 1 minute between attempts
                                        console.log('[PUNTWORK] Attempting AJAX continuation of paused import');
                                        $('#status-message').text('Attempting to resume paused import...');

                                        JobImportAPI.continuePausedImportAjax().then(function(continueResponse) {
                                            if (continueResponse.success) {
                                                console.log('[PUNTWORK] AJAX continuation successful');
                                                $('#status-message').text('Import resumed successfully');
                                                // Force a status refresh
                                                setTimeout(function() {
                                                    JobImportEvents.pollStatus();
                                                }, 1000);
                                            } else {
                                                console.log('[PUNTWORK] AJAX continuation failed:', continueResponse.message);
                                                $('#status-message').text('Failed to resume import automatically');
                                            }
                                        }).catch(function(continueError) {
                                            console.log('[PUNTWORK] AJAX continuation error:', continueError);
                                            $('#status-message').text('Error resuming import');
                                        });
                                    }
                                }
                            }
                        }
                    }
                }).catch(function(error) {
                    // Continue polling on error, but increase interval
                    if (JobImportEvents.currentPollingInterval < 5000) {
                        JobImportEvents.adjustPollingInterval(Math.min(JobImportEvents.currentPollingInterval * 2, 5000));
                    }
                });
            };

            // Start polling with initial interval
            JobImportEvents.statusPollingInterval = setInterval(JobImportEvents.pollStatus, JobImportEvents.currentPollingInterval);

            // Safety timeout: Stop polling after 45 minutes
            JobImportEvents.statusPollingTimeout = setTimeout(function() {
                console.log('[PUNTWORK] Status polling timed out');
                JobImportEvents.stopStatusPolling();
                JobImportUI.resetButtons();
                $('#status-message').text('Import monitoring timed out - please refresh the page');
            }, 45 * 60 * 1000);
        },

        /**
         * Adjust the polling interval dynamically
         */
        adjustPollingInterval: function(newInterval) {
            if (JobImportEvents.currentPollingInterval === newInterval) {
                return;
            }

            // Clear current interval
            if (JobImportEvents.statusPollingInterval) {
                clearInterval(JobImportEvents.statusPollingInterval);
            }

            // Update interval and restart with the stored polling function
            JobImportEvents.currentPollingInterval = newInterval;
            JobImportEvents.statusPollingInterval = setInterval(JobImportEvents.pollStatus, JobImportEvents.currentPollingInterval);
        },

        /**
         * Switch from background polling mode to active polling mode
         */
        switchToActivePolling: function() {
            this.isBackgroundMode = false;
            this.maxNoProgressBeforeSlow = 15;
            this.maxNoProgressBeforeStop = 300;
            this.maxTotalZeroPolls = 20;
            this.adjustPollingInterval(1000);
        },

        /**
         * Start heartbeat monitoring for import status (replaces polling)
         */
        startHeartbeatMonitoring: function() {
            // Ensure JobImportHeartbeat is available and initialized
            if (typeof JobImportHeartbeat !== 'undefined') {
                JobImportHeartbeat.forceStatusRefresh();
            } else {
                // Fallback: periodic manual checks every 30 seconds
                this.heartbeatFallbackInterval = setInterval(function() {
                    JobImportAPI.getImportStatus().then(function(response) {
                        if (response.success) {
                            var status = JobImportUI.normalizeResponseData(response);
                            JobImportUI.updateProgress(status);
                            if (status.logs && status.logs.length > 0) {
                                JobImportUI.appendLogs(status.logs);
                            }
                        }
                    });
                }, 30000);
            }
        },

        /**
         * Stop heartbeat monitoring
         */
        stopHeartbeatMonitoring: function() {
            if (this.heartbeatFallbackInterval) {
                clearInterval(this.heartbeatFallbackInterval);
                this.heartbeatFallbackInterval = null;
            }
        },

        /**
         * Stop polling for import status updates (legacy function for compatibility)
         */
        stopStatusPolling: function() {
            JobImportEvents.stopHeartbeatMonitoring();
        },
    };

    // Expose to global scope
    window.JobImportEvents = JobImportEvents;

})(jQuery, window, document);