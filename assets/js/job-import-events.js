/**
 * Job Import Admin - Events Module
 * Handles event binding and user interactions
 */

console.log('[PUNTWORK] job-import-events.js loaded - DEBUG MODE');

(function($, window, document) {
    'use strict';

    var JobImportEvents = {
        /**
         * Initialize event bindings
         */
        init: function() {
            console.log('[PUNTWORK] JobImportEvents.init() called');
            console.log('[PUNTWORK] jQuery version:', $.fn.jquery);
            console.log('[PUNTWORK] Document ready state:', document.readyState);

            this.bindEvents();
            this.checkInitialStatus();

            // Fallback: Re-bind events after a short delay to ensure DOM is ready
            setTimeout(function() {
                console.log('[PUNTWORK] Re-binding events after delay');
                JobImportEvents.bindEvents();
            }, 1000);
        },

        /**
         * Bind all event handlers
         */
        bindEvents: function() {
            console.log('[PUNTWORK] Binding events...');
            console.log('[PUNTWORK] Start button exists:', $('#start-import').length);

            $('#start-import').on('click', function(e) {
                console.log('[PUNTWORK] Start button clicked!');
                JobImportEvents.handleStartImport();
            });
            $('#resume-import').on('click', function(e) {
                console.log('[PUNTWORK] Resume button clicked!');
                JobImportEvents.handleResumeImport();
            });
            $('#cancel-import').on('click', function(e) {
                console.log('[PUNTWORK] Cancel button clicked!');
                JobImportEvents.handleCancelImport();
            });
            $('#reset-import').on('click', function(e) {
                console.log('[PUNTWORK] Reset button clicked!');
                e.preventDefault(); // Prevent any default action
                if ($(this).prop('disabled')) return; // Prevent multiple clicks
                JobImportEvents.handleResetImport();
            });

            // Cleanup buttons for jobs dashboard
            $('#cleanup-trashed').on('click', function(e) {
                console.log('[PUNTWORK] Cleanup trashed jobs button clicked!');
                e.preventDefault();
                JobImportEvents.handleCleanupTrashedJobs();
            });
            $('#cleanup-drafted').on('click', function(e) {
                console.log('[PUNTWORK] Cleanup drafted jobs button clicked!');
                e.preventDefault();
                JobImportEvents.handleCleanupDraftedJobs();
            });
            $('#cleanup-old-published').on('click', function(e) {
                console.log('[PUNTWORK] Cleanup old published jobs button clicked!');
                e.preventDefault();
                JobImportEvents.handleCleanupOldPublishedJobs();
            });

            // Log toggle button
            // $('#toggle-log').on('click', function(e) {
            //     console.log('[PUNTWORK] Toggle log button clicked!');
            //     JobImportUI.toggleLog();
            // });

            console.log('[PUNTWORK] Events bound successfully');
        },

        /**
         * Bind only cleanup event handlers (for jobs dashboard)
         */
        bindCleanupEvents: function() {
            console.log('[PUNTWORK] Binding cleanup events only...');

            // Bind cleanup buttons
            $('#cleanup-trashed').on('click', function(e) {
                console.log('[PUNTWORK] Cleanup trashed jobs button clicked!');
                e.preventDefault();
                JobImportEvents.handleCleanupTrashedJobs();
            });

            $('#cleanup-drafted').on('click', function(e) {
                console.log('[PUNTWORK] Cleanup drafted jobs button clicked!');
                e.preventDefault();
                JobImportEvents.handleCleanupDraftedJobs();
            });

            $('#cleanup-old-published').on('click', function(e) {
                console.log('[PUNTWORK] Cleanup old published jobs button clicked!');
                e.preventDefault();
                JobImportEvents.handleCleanupOldPublishedJobs();
            });

            console.log('[PUNTWORK] Cleanup events bound successfully');
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
            console.log('[PUNTWORK] === CHECKING INITIAL STATUS ===');
            // Clear progress first to ensure clean state
            JobImportUI.clearProgress();

            // Hide all import buttons immediately to prevent flash of unwanted buttons
            $('#start-import').hide();
            $('#resume-import').hide();
            $('#cancel-import').hide();
            $('#reset-import').hide();

            JobImportAPI.getImportStatus().then(function(response) {
                PuntWorkJSLogger.debug('Initial status response', 'EVENTS', response);
                console.log('[PUNTWORK] Initial status response:', response);

                // Handle both response formats: direct data or wrapped in .data
                var statusData = JobImportUI.normalizeResponseData(response);
                console.log('[PUNTWORK] Normalized status data:', statusData);

                // Check time since last update for detecting active imports
                var currentTime = Math.floor(Date.now() / 1000);
                var timeSinceLastUpdate = currentTime - (statusData.last_update || 0);
                console.log('[PUNTWORK] Current time:', currentTime, 'Last update:', statusData.last_update, 'Time since update:', timeSinceLastUpdate);

                // Determine if there's actually an incomplete import to resume
                var hasIncompleteImport = response.success && (
                    (statusData.processed > 0 && !statusData.complete) || // Partially completed import
                    (statusData.resume_progress > 0) || // Has resume progress
                    (!statusData.complete && statusData.total > 0) || // Incomplete with total set
                    (!statusData.complete && timeSinceLastUpdate < 600) // Incomplete and recently updated (for scheduled imports)
                );
                console.log('[PUNTWORK] Has incomplete import:', hasIncompleteImport, 'processed:', statusData.processed, 'complete:', statusData.complete, 'total:', statusData.total, 'resume_progress:', statusData.resume_progress);

                // Check if scheduling is enabled to determine if we should consider scheduled imports
                var isScheduledImport = false;
                console.log('[PUNTWORK] Checking schedule status...');
                JobImportAPI.call('get_import_schedule', {}, function(scheduleResponse) {
                    console.log('[PUNTWORK] Schedule response:', scheduleResponse);
                    if (scheduleResponse.success && scheduleResponse.data.schedule) {
                        isScheduledImport = scheduleResponse.data.schedule.enabled;
                        console.log('[PUNTWORK] Schedule enabled:', isScheduledImport);
                        
                        // For scheduled imports, also consider imports active if they have a start_time and are recent
                        if (isScheduledImport && !hasIncompleteImport) {
                            var hasRecentStartTime = statusData.start_time && (currentTime - statusData.start_time) < 3600; // Started within last hour
                            var isCountingPhase = statusData.start_time && statusData.total === 0 && !statusData.complete;
                            console.log('[PUNTWORK] Checking scheduled import conditions - start_time:', statusData.start_time, 'hasRecentStartTime:', hasRecentStartTime, 'isCountingPhase:', isCountingPhase);
                            
                            if (hasRecentStartTime || isCountingPhase) {
                                hasIncompleteImport = true;
                                console.log('[PUNTWORK] Detected active scheduled import in counting phase or recently started');
                            }
                        }
                    } else {
                        console.log('[PUNTWORK] Schedule check failed or no schedule data');
                    }

                    console.log('[PUNTWORK] Final decision - hasIncompleteImport:', hasIncompleteImport, 'isScheduledImport:', isScheduledImport);

                    if (hasIncompleteImport) {
                        console.log('[PUNTWORK] Showing incomplete import UI');
                        JobImportUI.updateProgress(statusData);
                        JobImportUI.appendLogs(statusData.logs || []);
                        
                        // Always start polling for incomplete imports to catch scheduled imports
                        JobImportEvents.startStatusPolling();
                        
                        // Check if import appears to be currently running (updated within last 5 minutes)
                        var isRecentlyActive = timeSinceLastUpdate < 300; // 5 minutes
                        console.log('[PUNTWORK] Is recently active:', isRecentlyActive, 'timeSinceLastUpdate:', timeSinceLastUpdate);
                        
                        if (isRecentlyActive) {
                            // Import appears to be currently running - show progress UI with cancel and reset
                            $('#start-import').hide();
                            $('#resume-import').hide();
                            $('#cancel-import').show();
                            $('#reset-import').show();
                            JobImportUI.showImportUI();
                            $('#status-message').text('Import in progress...');
                            
                            // Show import type indicator if this appears to be a scheduled import
                            if (isScheduledImport) {
                                $('#import-type-indicator').show();
                                $('#import-type-text').text('Scheduled import is currently running');
                                console.log('[PUNTWORK] Showing scheduled import indicator');
                            }
                            
                            console.log('[PUNTWORK] Import appears to be currently running - starting status polling');
                            
                            // Start polling for status updates
                            JobImportEvents.startStatusPolling();
                        } else {
                            // Import was interrupted or completed - show resume and reset options, but also start polling in case it's a scheduled import
                            $('#start-import').hide();
                            $('#resume-import').show();
                            $('#reset-import').show();
                            $('#cancel-import').hide();
                            JobImportUI.showImportUI();
                            $('#status-message').text('Previous import interrupted. Resume or reset?');
                            console.log('[PUNTWORK] Import was interrupted, showing resume and reset options');
                            
                            // Start polling anyway in case a scheduled import is running in the background
                            JobImportEvents.startStatusPolling();
                        }
                    } else {
                        // Clean state - hide all import controls except start
                        $('#start-import').show().text('Start Import');
                        $('#resume-import').hide();
                        $('#cancel-import').hide();
                        $('#reset-import').hide();
                        $('#import-type-indicator').hide();
                        JobImportUI.hideImportUI();
                        console.log('[PUNTWORK] Clean state detected - showing start button only');
                        
                        // If scheduling is enabled, start polling anyway to catch scheduled imports
                        if (isScheduledImport) {
                            JobImportEvents.startStatusPolling();
                            console.log('[PUNTWORK] Started polling for potential scheduled imports in clean state');
                        }
                    }
                }).catch(function(scheduleError) {
                    console.log('[PUNTWORK] Schedule check error:', scheduleError);
                    // Continue with manual import logic if schedule check fails
                    if (hasIncompleteImport) {
                        JobImportUI.updateProgress(statusData);
                        JobImportUI.appendLogs(statusData.logs || []);
                        JobImportEvents.startStatusPolling();
                        
                        var isRecentlyActive = timeSinceLastUpdate < 300;
                        if (isRecentlyActive) {
                            $('#start-import').hide();
                            $('#resume-import').hide();
                            $('#cancel-import').show();
                            $('#reset-import').show();
                            JobImportUI.showImportUI();
                            $('#status-message').text('Import in progress...');
                        } else {
                            $('#start-import').hide();
                            $('#resume-import').show();
                            $('#reset-import').show();
                            $('#cancel-import').hide();
                            JobImportUI.showImportUI();
                            $('#status-message').text('Previous import interrupted. Resume or reset?');
                        }
                    } else {
                        $('#start-import').show().text('Start Import');
                        $('#resume-import').hide();
                        $('#cancel-import').hide();
                        $('#reset-import').hide();
                        $('#import-type-indicator').hide();
                        JobImportUI.hideImportUI();
                    }
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
        startStatusPolling: function() {
            console.log('[PUNTWORK] === STARTING STATUS POLLING ===');

            // Clear any existing polling interval
            if (this.statusPollingInterval) {
                console.log('[PUNTWORK] Clearing existing polling interval');
                clearInterval(this.statusPollingInterval);
            }

            // Initialize smart polling variables
            this.currentPollingInterval = 1000; // Start with 1 second for more responsive polling during large batches
            this.lastProcessedCount = -1;
            this.lastProgressTimestamp = Date.now();
            this.noProgressCount = 0;
            this.maxNoProgressBeforeSlow = 15; // After 15 polls with no progress (15 seconds), slow down
            this.maxNoProgressBeforeStop = 300; // After 300 polls with no progress (5 minutes at 1s intervals), stop polling
            this.completeDetectedCount = 0;
            this.maxCompletePolls = 2; // Continue polling for 2 more polls after detecting complete
            var totalZeroCount = 0;
            this.maxTotalZeroPolls = 20; // Stop polling after 20 polls with total=0 (20 seconds)
            var isStartingNewImport = true;
            var hasSeenImportRunning = false;
            var lastLogTime = 0; // Track when we last logged to reduce log spam
            var lastModifiedTimestamp = 0; // Track server-side last modified timestamp
            this.lastTotalCount = 0; // Track total count for change detection
            this.lastCompleteStatus = false; // Track completion status for change detection
            this.consecutiveFastUpdates = 0; // Track consecutive fast progress updates

            // Show the progress UI immediately when starting polling
            console.log('[PUNTWORK] Showing import UI for import');
            JobImportUI.showImportUI();
            $('#start-import').hide();
            $('#resume-import').hide();
            $('#cancel-import').show();
            $('#reset-import').show();
            $('#status-message').text('Import in progress...');

            console.log('[PUNTWORK] Starting smart status polling (initial: 1000ms)');

            var startTime = Date.now(); // Track when polling started for early poll detection

            // Store the polling function for reuse
            this.pollStatus = function() {
                var now = Date.now();
                var timeSinceLastLog = now - lastLogTime;
                var pollCount = Math.floor((Date.now() - startTime) / JobImportEvents.currentPollingInterval);

                console.log('[PUNTWORK] POLL #' + pollCount + ' - Interval: ' + JobImportEvents.currentPollingInterval + 'ms, Time since start: ' + Math.floor((now - startTime) / 1000) + 's');

                // Only log every 10 seconds to reduce spam, or on significant events
                if (timeSinceLastLog > 10000) {
                    console.log('[PUNTWORK] Polling for status update (interval: ' + JobImportEvents.currentPollingInterval + 'ms)...');
                    lastLogTime = now;
                }

                JobImportAPI.getImportStatus().then(function(response) {
                    if (timeSinceLastLog > 10000) {
                        console.log('[PUNTWORK] Status polling response received');
                        PuntWorkJSLogger.debug('Status polling response', 'EVENTS', {
                            success: response.success,
                            total: response.data?.total,
                            processed: response.data?.processed,
                            complete: response.data?.complete,
                            hasLogs: response.data?.logs?.length > 0
                        });
                    }

                    if (response.success) {
                        var statusData = JobImportUI.normalizeResponseData(response);
                        var currentProcessed = statusData.processed || 0;
                        var currentTotal = statusData.total || 0;
                        var serverLastModified = statusData.last_modified || 0;

                        console.log('[PUNTWORK] Polling status data:', {
                            processed: currentProcessed,
                            total: currentTotal,
                            complete: statusData.complete,
                            start_time: statusData.start_time,
                            last_update: statusData.last_update,
                            pollCount: pollCount
                        });

                        // Check if server data has actually changed since last poll
                        // Use a combination of last_modified and actual data changes to be more robust
                        var dataHasChanged = serverLastModified !== lastModifiedTimestamp ||
                                           currentProcessed !== JobImportEvents.lastProcessedCount ||
                                           currentTotal !== (JobImportEvents.lastTotalCount || 0) ||
                                           statusData.complete !== (JobImportEvents.lastCompleteStatus || false);

                        // Store current values for next comparison
                        JobImportEvents.lastTotalCount = currentTotal;
                        JobImportEvents.lastCompleteStatus = statusData.complete;

                        if (!dataHasChanged && lastModifiedTimestamp > 0) {
                            // Server data hasn't changed, skip processing but continue polling
                            if (timeSinceLastLog > 30000) { // Log every 30 seconds for unchanged data
                                console.log('[PUNTWORK] Server data unchanged, skipping processing (last modified: ' + serverLastModified + ')');
                            }
                            return; // Exit early, don't process unchanged data
                        }

                        // Update our last modified timestamp
                        lastModifiedTimestamp = serverLastModified;

                        // Check if total is still 0 (import hasn't started)
                        // Be more tolerant in the first few polls after starting an import
                        var pollCount = Math.floor((Date.now() - startTime) / JobImportEvents.currentPollingInterval);
                        var isEarlyPoll = pollCount < 5; // First 5 polls are more tolerant

                        if (timeSinceLastLog > 10000) {
                            console.log('[PUNTWORK] Polling debug - startTime:', startTime, 'pollCount:', pollCount, 'isEarlyPoll:', isEarlyPoll);
                        }
                        
                        if (currentTotal === 0) {
                            totalZeroCount++;
                            if (timeSinceLastLog > 10000) {
                                console.log('[PUNTWORK] Import total still 0, count:', totalZeroCount, 'early poll:', isEarlyPoll);
                            }
                        } else {
                            totalZeroCount = 0;
                        }

                        // Stop polling if total has been 0 for too many polls
                        // But be more lenient for early polls (allow more time for initialization)
                        var effectiveMaxZeroPolls = isEarlyPoll ? 25 : JobImportEvents.maxTotalZeroPolls;
                        if (totalZeroCount >= effectiveMaxZeroPolls) {
                            console.log('[PUNTWORK] Import failed to start after', effectiveMaxZeroPolls, 'polls, stopping polling');
                            PuntWorkJSLogger.warn('Import failed to start, stopping polling', 'EVENTS', {
                                totalZeroCount: totalZeroCount,
                                effectiveMaxZeroPolls: effectiveMaxZeroPolls,
                                isEarlyPoll: isEarlyPoll
                            });
                            JobImportEvents.stopStatusPolling();
                            JobImportUI.resetButtons();
                            $('#status-message').text('Import failed to start - please try again');
                            $('#import-type-indicator').hide();
                            return;
                        }

                        // Check if a scheduled import is running by looking at schedule status and import state
                        // Only check this periodically to avoid too many API calls
                        if (pollCount % 5 === 0) { // Check every 5 polls for more responsive detection
                            console.log('[PUNTWORK] Checking for scheduled import at poll', pollCount, '- currentTotal:', currentTotal, 'complete:', statusData.complete, 'start_time:', statusData.start_time);
                            JobImportAPI.call('get_import_schedule', {}, function(scheduleResponse) {
                                console.log('[PUNTWORK] Schedule check response:', scheduleResponse);
                                if (scheduleResponse.success && scheduleResponse.data.schedule) {
                                    var isScheduledEnabled = scheduleResponse.data.schedule.enabled;
                                    var hasActiveImport = currentTotal > 0 && !statusData.complete;
                                    var hasRecentStartTime = statusData.start_time && (Date.now() / 1000 - statusData.start_time) < 3600; // Started within last hour
                                    var isCountingPhase = statusData.start_time && currentTotal === 0 && !statusData.complete;
                                    
                                    console.log('[PUNTWORK] Schedule check details:', {
                                        isScheduledEnabled: isScheduledEnabled,
                                        hasActiveImport: hasActiveImport,
                                        hasRecentStartTime: hasRecentStartTime,
                                        isCountingPhase: isCountingPhase,
                                        start_time: statusData.start_time,
                                        currentTime: Date.now() / 1000,
                                        timeDiff: Date.now() / 1000 - statusData.start_time
                                    });
                                    
                                    if (isScheduledEnabled && (hasActiveImport || hasRecentStartTime || isCountingPhase)) {
                                        $('#import-type-indicator').show();
                                        $('#import-type-text').text('Scheduled import is currently running');
                                        console.log('[PUNTWORK] SHOWING scheduled import indicator');
                                    } else if (!isScheduledEnabled && hasActiveImport) {
                                        $('#import-type-indicator').show();
                                        $('#import-type-text').text('Manual import is currently running');
                                        console.log('[PUNTWORK] SHOWING manual import indicator');
                                    } else {
                                        $('#import-type-indicator').hide();
                                        console.log('[PUNTWORK] HIDING import type indicator');
                                    }
                                } else {
                                    console.log('[PUNTWORK] Schedule check failed');
                                }
                            }).catch(function(scheduleError) {
                                console.log('[PUNTWORK] Schedule check error:', scheduleError);
                                // Ignore schedule check errors, continue with polling
                            });
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

                            // If we're processing items quickly (more than 10 items per second), keep polling fast
                            if (itemsProcessedInPoll > 0 && timeSinceLastProgress < 2000) {
                                var processingRate = itemsProcessedInPoll / (timeSinceLastProgress / 1000); // items per second
                                if (processingRate > 5) { // Processing more than 5 items/second
                                    JobImportEvents.adjustPollingInterval(500); // Poll every 0.5 seconds for very active processing
                                } else if (processingRate > 2) { // Processing more than 2 items/second
                                    JobImportEvents.adjustPollingInterval(1000); // Poll every 1 second for active processing
                                } else {
                                    JobImportEvents.adjustPollingInterval(1500); // Poll every 1.5 seconds for moderate processing
                                }
                            } else {
                                // Normal progress - keep reasonable polling speed
                                if (JobImportEvents.currentPollingInterval > 1500) {
                                    JobImportEvents.adjustPollingInterval(1500);
                                }
                            }
                        } else {
                            JobImportEvents.consecutiveFastUpdates = 0;
                            JobImportEvents.noProgressCount++;

                            // Implement smart backoff when no progress
                            if (JobImportEvents.noProgressCount >= JobImportEvents.maxNoProgressBeforeSlow) {
                                var newInterval = Math.min(JobImportEvents.currentPollingInterval * 1.5, 8000); // Exponential backoff, max 8 seconds
                                if (newInterval !== JobImportEvents.currentPollingInterval) {
                                    JobImportEvents.adjustPollingInterval(newInterval);
                                }
                            }

                            // Stop polling if no progress for too long
                            // Be more patient when import is close to completion
                            var progressPercent = currentTotal > 0 ? (currentProcessed / currentTotal) * 100 : 0;
                            var effectiveMaxNoProgress = progressPercent > 90 ? 600 : JobImportEvents.maxNoProgressBeforeStop; // 10 minutes for final 10%, 5 minutes otherwise
                            if (JobImportEvents.noProgressCount >= effectiveMaxNoProgress) {
                                console.log('[PUNTWORK] No progress detected for extended period, stopping polling (progress: ' + progressPercent.toFixed(1) + '%)');
                                PuntWorkJSLogger.warn('No progress for extended period, stopping polling', 'EVENTS', {
                                    noProgressCount: JobImportEvents.noProgressCount,
                                    effectiveMaxNoProgress: effectiveMaxNoProgress,
                                    progressPercent: progressPercent
                                });
                                JobImportEvents.stopStatusPolling();
                                JobImportUI.resetButtons();
                                $('#status-message').text('Import appears stalled - please check logs or try again');
                                return;
                            }
                        }

                        // Update UI with progress data (including final completion status)
                        if (currentTotal > 0) {
                            // Only update UI if we have meaningful progress data
                            if (timeSinceLastLog > 10000 || currentProcessed !== JobImportEvents.lastProcessedCount) {
                                console.log('[PUNTWORK] Updating progress with polling data:', {
                                    total: currentTotal,
                                    processed: currentProcessed,
                                    percent: Math.round((currentProcessed / currentTotal) * 100)
                                });
                            }
                            JobImportUI.updateProgress(statusData);
                            JobImportUI.appendLogs(statusData.logs || []);
                            isStartingNewImport = false;
                            hasSeenImportRunning = true;
                        } else if (currentTotal === 0 && !statusData.complete) {
                            // Import hasn't started yet, keep polling
                            if (timeSinceLastLog > 10000) {
                                console.log('[PUNTWORK] Import not yet started (total=0), continuing to poll');
                            }
                        }

                        // Check if import completed
                        if (statusData.complete && currentTotal > 0 && (currentProcessed >= currentTotal || statusData.complete)) {
                            JobImportEvents.completeDetectedCount++;
                            console.log('[PUNTWORK] Polled import completed - total:', currentTotal, 'processed:', currentProcessed, 'detection count:', JobImportEvents.completeDetectedCount);
                            PuntWorkJSLogger.info('Import completed via polling', 'EVENTS', {
                                total: currentTotal,
                                processed: currentProcessed,
                                success: statusData.success
                            });

                            // Continue polling for a few more polls to ensure we get final status updates
                            if (JobImportEvents.completeDetectedCount >= JobImportEvents.maxCompletePolls) {
                                JobImportUI.updateProgress(statusData);
                                JobImportEvents.stopStatusPolling();
                                JobImportUI.resetButtons();
                                $('#status-message').text('Import Complete');
                                $('#import-type-indicator').hide();
                            }
                        } else if (statusData.complete && currentTotal === 0 && hasSeenImportRunning && !isStartingNewImport) {
                            console.log('[PUNTWORK] Import status reset to empty state, stopping polling and resetting UI');
                            PuntWorkJSLogger.info('Import status reset to empty state', 'EVENTS', statusData);
                            JobImportEvents.stopStatusPolling();
                            JobImportUI.clearProgress();
                            JobImportUI.hideImportUI();
                            JobImportUI.resetButtons();
                            $('#status-message').text('Ready to start.');
                            $('#import-type-indicator').hide();
                        } else {
                            // Reset complete detection counter if import is not complete
                            JobImportEvents.completeDetectedCount = 0;
                        }
                    } else {
                        if (timeSinceLastLog > 10000) {
                            console.log('[PUNTWORK] Status polling failed:', response);
                            PuntWorkJSLogger.warn('Status polling failed', 'EVENTS', response);
                        }
                    }
                }).catch(function(error) {
                    if (timeSinceLastLog > 10000) {
                        console.log('[PUNTWORK] Status polling error:', error);
                        PuntWorkJSLogger.error('Status polling error', 'EVENTS', error);
                    }
                    // Continue polling on error, but increase interval to be less aggressive
                    if (JobImportEvents.currentPollingInterval < 5000) {
                        JobImportEvents.adjustPollingInterval(Math.min(JobImportEvents.currentPollingInterval * 2, 5000));
                    }
                });
            };

            // Start polling with initial interval
            JobImportEvents.statusPollingInterval = setInterval(JobImportEvents.pollStatus, JobImportEvents.currentPollingInterval);

            // Safety timeout: Stop polling after 45 minutes to prevent infinite polling
            JobImportEvents.statusPollingTimeout = setTimeout(function() {
                console.log('[PUNTWORK] Status polling timed out after 45 minutes');
                PuntWorkJSLogger.warn('Status polling timed out', 'EVENTS');
                JobImportEvents.stopStatusPolling();
                JobImportUI.resetButtons();
                $('#status-message').text('Import monitoring timed out - please refresh the page');
            }, 45 * 60 * 1000); // 45 minutes
        },

        /**
         * Adjust the polling interval dynamically
         */
        adjustPollingInterval: function(newInterval) {
            if (JobImportEvents.currentPollingInterval === newInterval) {
                return; // No change needed
            }

            console.log('[PUNTWORK] Adjusting polling interval from ' + JobImportEvents.currentPollingInterval + 'ms to ' + newInterval + 'ms');
            
            // Clear current interval
            if (JobImportEvents.statusPollingInterval) {
                clearInterval(JobImportEvents.statusPollingInterval);
            }

            // Update interval and restart with the stored polling function
            JobImportEvents.currentPollingInterval = newInterval;
            JobImportEvents.statusPollingInterval = setInterval(JobImportEvents.pollStatus, JobImportEvents.currentPollingInterval);
        },

        /**
         * Stop polling for import status updates
         */
        stopStatusPolling: function() {
            if (JobImportEvents.statusPollingInterval) {
                clearInterval(JobImportEvents.statusPollingInterval);
                JobImportEvents.statusPollingInterval = null;
                console.log('[PUNTWORK] Stopped status polling');
            }
            if (JobImportEvents.statusPollingTimeout) {
                clearTimeout(JobImportEvents.statusPollingTimeout);
                JobImportEvents.statusPollingTimeout = null;
                console.log('[PUNTWORK] Cleared status polling timeout');
            }
        }
    };

    // Expose to global scope
    window.JobImportEvents = JobImportEvents;

})(jQuery, window, document);