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
            console.log('[PUNTWORK] Cleanup button exists:', $('#cleanup-duplicates').length);

            // Check if buttons exist before binding
            if ($('#cleanup-duplicates').length > 0) {
                console.log('[PUNTWORK] Found cleanup button, binding click handler');
                $('#cleanup-duplicates').on('click', function(e) {
                    console.log('[PUNTWORK] Cleanup button clicked!');
                    e.preventDefault(); // Prevent any default form submission
                    JobImportEvents.handleCleanupDuplicates();
                });
            } else {
                console.log('[PUNTWORK] Cleanup button NOT found!');
            }

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
            console.log('[PUNTWORK] Cleanup button exists:', $('#cleanup-duplicates').length);

            // Check if cleanup button exists before binding
            if ($('#cleanup-duplicates').length > 0) {
                console.log('[PUNTWORK] Found cleanup button, binding click handler');
                $('#cleanup-duplicates').on('click', function(e) {
                    console.log('[PUNTWORK] Cleanup button clicked!');
                    e.preventDefault(); // Prevent any default form submission
                    JobImportEvents.handleCleanupDuplicates();
                });
            } else {
                console.log('[PUNTWORK] Cleanup button NOT found!');
            }

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
         * Handle cleanup duplicates button click
         */
        handleCleanupDuplicates: function() {
            console.log('[PUNTWORK] Cleanup duplicates handler called');
            console.log('[PUNTWORK] jobImportData available:', typeof jobImportData);
            console.log('[PUNTWORK] JobImportAPI available:', typeof JobImportAPI);
            console.log('[PUNTWORK] JobImportUI available:', typeof JobImportUI);

            if (confirm('This will permanently delete all job posts that are in Draft or Trash status. This action cannot be undone. Continue?')) {
                console.log('[PUNTWORK] User confirmed cleanup');
                $('#cleanup-duplicates').prop('disabled', true);
                $('#cleanup-text').hide();
                $('#cleanup-loading').show();
                $('#cleanup-status').text('Starting cleanup...');

                // Show progress UI immediately
                JobImportUI.showCleanupUI();
                JobImportUI.clearCleanupProgress();

                console.log('[PUNTWORK] Calling cleanup API');
                JobImportEvents.processCleanupBatch(0, 50); // Start with first batch
            } else {
                console.log('[PUNTWORK] User cancelled cleanup');
            }
        },        /**
         * Process cleanup batch and continue if needed
         * @param {number} offset - Current offset for batch processing
         * @param {number} batchSize - Size of batch to process
         */
        processCleanupBatch: function(offset, batchSize) {
            console.log('[PUNTWORK] Processing cleanup batch - offset:', offset, 'batchSize:', batchSize);
            var isContinue = offset > 0;
            var action = isContinue ? JobImportAPI.continueCleanup(offset, batchSize) : JobImportAPI.cleanupDuplicates();

            action.then(function(response) {
                console.log('[PUNTWORK] Cleanup API response:', response);
                PuntWorkJSLogger.debug('Cleanup response', 'EVENTS', response);

                if (response.success) {
                    console.log('[PUNTWORK] Cleanup response successful, complete:', response.data.complete);
                    JobImportUI.appendLogs(response.data.logs || []);

                    if (response.data.complete) {
                        // Operation completed
                        $('#cleanup-status').text('Cleanup completed: ' + response.data.total_deleted + ' duplicates removed');
                        $('#cleanup-duplicates').prop('disabled', false);
                        $('#cleanup-text').show();
                        $('#cleanup-loading').hide();
                        JobImportUI.clearCleanupProgress();
                    } else {
                        // Update progress and continue with next batch
                        JobImportUI.updateCleanupProgress(response.data);
                        $('#cleanup-status').text('Progress: ' + response.data.progress_percentage + '% (' +
                            response.data.total_processed + '/' + response.data.total_jobs + ' jobs processed)');
                        JobImportEvents.processCleanupBatch(response.data.next_offset, batchSize);
                    }
                } else {
                    console.log('[PUNTWORK] Cleanup response failed:', response.data);
                    $('#cleanup-status').text('Cleanup failed: ' + (response.data || 'Unknown error'));
                    $('#cleanup-duplicates').prop('disabled', false);
                    $('#cleanup-text').show();
                    $('#cleanup-loading').hide();
                    JobImportUI.clearCleanupProgress();
                }
            }).catch(function(xhr, status, error) {
                console.log('[PUNTWORK] Cleanup API error:', error);
                console.log('[PUNTWORK] XHR status:', xhr.status, 'response:', xhr.responseText);
                PuntWorkJSLogger.error('Cleanup AJAX error', 'EVENTS', error);
                $('#cleanup-status').text('Cleanup failed: ' + error);
                JobImportUI.appendLogs(['Cleanup AJAX error: ' + error]);
                $('#cleanup-duplicates').prop('disabled', false);
                $('#cleanup-text').show();
                $('#cleanup-loading').hide();
                JobImportUI.clearCleanupProgress();
            });
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
                console.log('[PUNTWORK] Initial status response:', response);

                // Handle both response formats: direct data or wrapped in .data
                var statusData = JobImportUI.normalizeResponseData(response);

                // Check time since last update for detecting active imports
                var currentTime = Math.floor(Date.now() / 1000);
                var timeSinceLastUpdate = currentTime - (statusData.last_update || 0);

                // Determine if there's actually an incomplete import to resume
                var hasIncompleteImport = response.success && (
                    (statusData.processed > 0 && !statusData.complete) || // Partially completed import
                    (statusData.resume_progress > 0) || // Has resume progress
                    (!statusData.complete && statusData.total > 0) || // Incomplete with total set
                    (!statusData.complete && timeSinceLastUpdate < 600) // Incomplete and recently updated (for scheduled imports)
                );

                if (hasIncompleteImport) {
                    JobImportUI.updateProgress(statusData);
                    JobImportUI.appendLogs(statusData.logs || []);
                    
                    // Always start polling for incomplete imports to catch scheduled imports
                    JobImportEvents.startStatusPolling();
                    
                    // Check if import appears to be currently running (updated within last 5 minutes)
                    var isRecentlyActive = timeSinceLastUpdate < 300; // 5 minutes
                    
                    if (isRecentlyActive && statusData.processed > 0) {
                        // Import appears to be currently running - show progress UI with cancel and reset
                        $('#start-import').hide();
                        $('#resume-import').hide();
                        $('#cancel-import').show();
                        $('#reset-import').show();
                        JobImportUI.showImportUI();
                        $('#status-message').text('Import in progress...');
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
                    JobImportUI.hideImportUI();
                    console.log('[PUNTWORK] Clean state detected - showing start button only');
                }
            }).catch(function(xhr, status, error) {
                PuntWorkJSLogger.error('Initial status AJAX error', 'EVENTS', error);
                JobImportUI.appendLogs(['Initial status AJAX error: ' + error]);
                // Ensure UI is in clean state even on error
                JobImportUI.clearProgress();
                JobImportUI.hideImportUI();
                $('#start-import').show().text('Start Import');
                $('#resume-import').hide();
                $('#cancel-import').hide();
                $('#reset-import').hide();
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
            console.log('[PUNTWORK] JobImportEvents.startStatusPolling() called');

            // Clear any existing polling interval
            if (this.statusPollingInterval) {
                console.log('[PUNTWORK] Clearing existing polling interval');
                clearInterval(this.statusPollingInterval);
            }

            // Initialize smart polling variables
            this.currentPollingInterval = 2000; // Start with 2 seconds for less aggressive polling
            this.lastProcessedCount = -1;
            this.lastProgressTimestamp = Date.now();
            this.noProgressCount = 0;
            this.maxNoProgressBeforeSlow = 10; // After 10 polls with no progress (20 seconds), slow down significantly
            this.maxNoProgressBeforeStop = 300; // After 300 polls with no progress (10 minutes at 2s intervals), stop polling
            this.completeDetectedCount = 0;
            this.maxCompletePolls = 2; // Continue polling for 2 more polls after detecting complete
            var totalZeroCount = 0;
            this.maxTotalZeroPolls = 15; // Stop polling after 15 polls with total=0 (30 seconds)
            var isStartingNewImport = true;
            var hasSeenImportRunning = false;
            var lastLogTime = 0; // Track when we last logged to reduce log spam
            var lastModifiedTimestamp = 0; // Track server-side last modified timestamp
            this.lastTotalCount = 0; // Track total count for change detection
            this.lastCompleteStatus = false; // Track completion status for change detection

            // Show the progress UI immediately when starting polling
            console.log('[PUNTWORK] Showing import UI for import');
            JobImportUI.showImportUI();
            $('#start-import').hide();
            $('#resume-import').hide();
            $('#cancel-import').show();
            $('#reset-import').show();
            $('#status-message').text('Import in progress...');

            console.log('[PUNTWORK] Starting smart status polling (initial: 2000ms)');

            var startTime = Date.now(); // Track when polling started for early poll detection

            // Store the polling function for reuse
            this.pollStatus = function() {
                var now = Date.now();
                var timeSinceLastLog = now - lastLogTime;

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
                            return;
                        }

                        // Check if progress has changed
                        if (currentProcessed !== JobImportEvents.lastProcessedCount) {
                            JobImportEvents.lastProcessedCount = currentProcessed;
                            JobImportEvents.lastProgressTimestamp = now;
                            JobImportEvents.noProgressCount = 0;

                            // Progress detected - ensure reasonable polling speed
                            if (JobImportEvents.currentPollingInterval > 2000) {
                                JobImportEvents.adjustPollingInterval(2000);
                            }
                        } else {
                            JobImportEvents.noProgressCount++;

                            // Implement smart backoff when no progress
                            if (JobImportEvents.noProgressCount >= JobImportEvents.maxNoProgressBeforeSlow) {
                                var newInterval = Math.min(JobImportEvents.currentPollingInterval * 1.5, 10000); // Exponential backoff, max 10 seconds
                                if (newInterval !== JobImportEvents.currentPollingInterval) {
                                    JobImportEvents.adjustPollingInterval(newInterval);
                                }
                            }

                            // Stop polling if no progress for too long
                            // Be more patient when import is close to completion
                            var progressPercent = currentTotal > 0 ? (currentProcessed / currentTotal) * 100 : 0;
                            var effectiveMaxNoProgress = progressPercent > 90 ? 600 : JobImportEvents.maxNoProgressBeforeStop; // 20 minutes for final 10%, 10 minutes otherwise
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
                            }
                        } else if (statusData.complete && currentTotal === 0 && hasSeenImportRunning && !isStartingNewImport) {
                            console.log('[PUNTWORK] Import status reset to empty state, stopping polling and resetting UI');
                            PuntWorkJSLogger.info('Import status reset to empty state', 'EVENTS', statusData);
                            JobImportEvents.stopStatusPolling();
                            JobImportUI.clearProgress();
                            JobImportUI.hideImportUI();
                            JobImportUI.resetButtons();
                            $('#status-message').text('Ready to start.');
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