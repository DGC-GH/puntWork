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
                JobImportEvents.handleResetImport();
            });

            // Database optimization events
            $('#optimize-database').on('click', function(e) {
                console.log('[PUNTWORK] Optimize database button clicked!');
                JobImportEvents.handleOptimizeDatabase();
            });
            $('#check-db-status').on('click', function(e) {
                console.log('[PUNTWORK] Check DB status button clicked!');
                JobImportEvents.handleCheckDbStatus();
            });

            // Async processing events
            $('#save-async-settings').on('click', function(e) {
                console.log('[PUNTWORK] Save async settings button clicked!');
                JobImportEvents.handleSaveAsyncSettings();
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
            if (confirm('This will completely reset the import system and clear all progress. Any ongoing import will be stopped. Continue?')) {
                console.log('[PUNTWORK] User confirmed reset');
                JobImportLogic.handleResetImport();
            } else {
                console.log('[PUNTWORK] User cancelled reset');
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

            // Load all status information progressively
            this.loadProgressiveStatus();
        },

        /**
         * Load all status information progressively for better UX
         */
        loadProgressiveStatus: function() {
            console.log('[PUNTWORK] Loading status information progressively');

            // Load database optimization status first
            JobImportAPI.getDbOptimizationStatus().then(function(dbResponse) {
                console.log('[PUNTWORK] DB status response:', dbResponse);
                if (dbResponse.success) {
                    JobImportEvents.updateDbStatusDisplay(dbResponse.data.status);
                } else {
                    // Show error state
                    $('#db-status-badge').removeClass('success warning error').addClass('error').html('<i class="fas fa-exclamation-triangle" style="margin-right: 4px;"></i>Error');
                    $('#db-indexes-list').html('<div style="color: #ff3b30;">Failed to load database status</div>');
                }
            }).catch(function(error) {
                console.log('[PUNTWORK] DB status load error:', error);
                $('#db-status-badge').removeClass('success warning error').addClass('error').html('<i class="fas fa-exclamation-triangle" style="margin-right: 4px;"></i>Error');
                $('#db-indexes-list').html('<div style="color: #ff3b30;">Failed to load database status</div>');
            });

            // Load async processing status
            JobImportAPI.getAsyncStatus().then(function(asyncResponse) {
                console.log('[PUNTWORK] Async status response:', asyncResponse);
                if (asyncResponse.success) {
                    JobImportEvents.updateAsyncStatusDisplay(asyncResponse.data);
                    // Update checkbox state
                    $('#enable-async-processing').prop('checked', asyncResponse.data.enabled);
                } else {
                    // Show error state
                    $('#async-status-badge').removeClass('success warning error').addClass('error').html('<i class="fas fa-exclamation-triangle" style="margin-right: 4px;"></i>Error');
                    $('#async-status-details').html('<div style="color: #ff3b30;">Failed to load async status</div>');
                }
            }).catch(function(error) {
                console.log('[PUNTWORK] Async status load error:', error);
                $('#async-status-badge').removeClass('success warning error').addClass('error').html('<i class="fas fa-exclamation-triangle" style="margin-right: 4px;"></i>Error');
                $('#async-status-details').html('<div style="color: #ff3b30;">Failed to load async status</div>');
            });

            // Load import status last (most important for user interaction)
            JobImportAPI.getImportStatus().then(function(response) {
                PuntWorkJSLogger.debug('Initial status response', 'EVENTS', response);
                console.log('[PUNTWORK] Initial status response:', response);

                // Handle both response formats: direct data or wrapped in .data
                var statusData = JobImportUI.normalizeResponseData(response);

                // Determine if there's actually an incomplete import to resume
                var hasIncompleteImport = response.success && (
                    (statusData.processed > 0 && !statusData.complete) || // Partially completed import
                    (statusData.resume_progress > 0) || // Has resume progress
                    (!statusData.complete && statusData.total > 0) // Incomplete with total set
                );

                if (hasIncompleteImport) {
                    JobImportUI.updateProgress(statusData);
                    JobImportUI.appendLogs(statusData.logs || []);
                    
                    // Check if import appears to be currently running (updated within last 5 minutes)
                    // This aligns with the PHP stuck import detection threshold
                    var currentTime = Math.floor(Date.now() / 1000);
                    var timeSinceLastUpdate = currentTime - (statusData.last_update || 0);
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
                        // Import was interrupted - show resume and reset options
                        $('#start-import').hide();
                        $('#resume-import').show();
                        $('#reset-import').show();
                        $('#cancel-import').hide();
                        JobImportUI.showImportUI();
                        $('#status-message').text('Previous import interrupted. Resume or reset?');
                        console.log('[PUNTWORK] Import was interrupted, showing resume and reset options');
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
                PuntWorkJSLogger.debug('Initial status response', 'EVENTS', response);
                console.log('[PUNTWORK] Initial status response:', response);

                // Handle both response formats: direct data or wrapped in .data
                var statusData = JobImportUI.normalizeResponseData(response);

                // Determine if there's actually an incomplete import to resume
                var hasIncompleteImport = response.success && (
                    (statusData.processed > 0 && !statusData.complete) || // Partially completed import
                    (statusData.resume_progress > 0) || // Has resume progress
                    (!statusData.complete && statusData.total > 0) // Incomplete with total set
                );

                if (hasIncompleteImport) {
                    JobImportUI.updateProgress(statusData);
                    JobImportUI.appendLogs(statusData.logs || []);
                    
                    // Check if import appears to be currently running (updated within last 5 minutes)
                    // This aligns with the PHP stuck import detection threshold
                    var currentTime = Math.floor(Date.now() / 1000);
                    var timeSinceLastUpdate = currentTime - (statusData.last_update || 0);
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
                        // Import was interrupted - show resume and reset options
                        $('#start-import').hide();
                        $('#resume-import').show();
                        $('#reset-import').show();
                        $('#cancel-import').hide();
                        JobImportUI.showImportUI();
                        $('#status-message').text('Previous import interrupted. Resume or reset?');
                        console.log('[PUNTWORK] Import was interrupted, showing resume and reset options');
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
         * Start polling for import status updates (used for scheduled imports and manual imports)
         */
        startStatusPolling: function() {
            console.log('[PUNTWORK] JobImportEvents.startStatusPolling() called');

            // Clear any existing polling interval
            if (this.statusPollingInterval) {
                console.log('[PUNTWORK] Clearing existing polling interval');
                clearInterval(this.statusPollingInterval);
            }

            // Initialize dynamic polling variables
            this.currentPollingInterval = 500; // Start with 500ms for fast initial updates
            this.lastProcessedCount = -1;
            this.unchangedCount = 0;
            this.maxUnchangedBeforeSlow = 50; // After 50 unchanged polls (25 seconds), slow down
            this.completeDetectedCount = 0; // Counter for complete detections
            this.maxCompletePolls = 3; // Continue polling for 3 more polls after detecting complete
            var totalZeroCount = 0; // Counter for polls where total remains 0
            this.maxTotalZeroPolls = 20; // Stop polling after 20 polls with total=0 (40 seconds)
            var isStartingNewImport = true; // Flag to prevent hiding UI when starting a new import
            var hasSeenImportRunning = false; // Flag to track if we've seen the import actually running

            // Show the progress UI immediately when starting polling
            console.log('[PUNTWORK] Showing import UI for import');
            JobImportUI.showImportUI();
            $('#start-import').hide();
            $('#resume-import').hide();
            $('#cancel-import').show();
            $('#reset-import').show();
            $('#status-message').text('Import in progress...');

            console.log('[PUNTWORK] Starting dynamic status polling (initial: 500ms)');

            // Store the polling function for reuse
            this.pollStatus = function() {
                console.log('[PUNTWORK] Polling for status update (interval: ' + JobImportEvents.currentPollingInterval + 'ms)...');
                JobImportAPI.getImportStatus().then(function(response) {
                    console.log('[PUNTWORK] Status polling response:', response);
                    PuntWorkJSLogger.debug('Status polling response', 'EVENTS', {
                        success: response.success,
                        total: response.data?.total,
                        processed: response.data?.processed,
                        complete: response.data?.complete,
                        hasLogs: response.data?.logs?.length > 0
                    });

                    if (response.success) {
                        var statusData = JobImportUI.normalizeResponseData(response);
                        var currentProcessed = statusData.processed || 0;

                        // Check if total is still 0 (import hasn't started)
                        if (statusData.total === 0) {
                            totalZeroCount++;
                            console.log('[PUNTWORK] Import total still 0, count:', totalZeroCount);
                        } else {
                            totalZeroCount = 0;
                        }

                        // Stop polling if total has been 0 for too many polls
                        if (totalZeroCount >= JobImportEvents.maxTotalZeroPolls) {
                            console.log('[PUNTWORK] Import failed to start after', JobImportEvents.maxTotalZeroPolls, 'polls, stopping polling');
                            PuntWorkJSLogger.warn('Import failed to start, stopping polling', 'EVENTS', {
                                totalZeroCount: totalZeroCount,
                                maxTotalZeroPolls: JobImportEvents.maxTotalZeroPolls
                            });
                            JobImportEvents.stopStatusPolling();
                            JobImportUI.resetButtons();
                            $('#status-message').text('Import failed to start - please try again');
                            return;
                        }

                        // Check if progress has changed
                        if (currentProcessed !== JobImportEvents.lastProcessedCount) {
                            JobImportEvents.lastProcessedCount = currentProcessed;
                            JobImportEvents.unchangedCount = 0;
                            
                            // If progress is happening, ensure fast polling
                            if (JobImportEvents.currentPollingInterval > 1000) {
                                JobImportEvents.adjustPollingInterval(1000);
                            }
                        } else {
                            JobImportEvents.unchangedCount++;
                            
                            // If no progress for several polls, slow down polling
                            if (JobImportEvents.unchangedCount >= JobImportEvents.maxUnchangedBeforeSlow && 
                                JobImportEvents.currentPollingInterval < 1000) {
                                JobImportEvents.adjustPollingInterval(1000);
                            }
                        }

                        // Update UI with progress data (including final completion status)
                        if (statusData.total > 0) {
                            console.log('[PUNTWORK] Updating progress with polling data:', statusData);
                            JobImportUI.updateProgress(statusData);
                            JobImportUI.appendLogs(statusData.logs || []);
                            // Import has started, clear the starting flag
                            isStartingNewImport = false;
                            hasSeenImportRunning = true; // Mark that we've seen the import running
                        } else if (statusData.total === 0 && !statusData.complete) {
                            console.log('[PUNTWORK] Import not yet started (total=0), continuing to poll');
                            // Import hasn't started yet, keep polling
                        }

                        // Check if import completed
                        if (statusData.complete && statusData.total > 0 && (statusData.processed >= statusData.total || statusData.complete)) {
                            JobImportEvents.completeDetectedCount++;
                            console.log('[PUNTWORK] Polled import completed - total:', statusData.total, 'processed:', statusData.processed, 'detection count:', JobImportEvents.completeDetectedCount);
                            PuntWorkJSLogger.info('Import completed via polling', 'EVENTS', {
                                total: statusData.total,
                                processed: statusData.processed,
                                success: statusData.success
                            });
                            
                            // Continue polling for a few more polls to ensure we get final status updates
                            if (JobImportEvents.completeDetectedCount >= JobImportEvents.maxCompletePolls) {
                                // Do one final progress update to ensure completion is displayed
                                JobImportUI.updateProgress(statusData);
                                
                                // Log the manual import run to history if this was a manual import
                                if (window.JobImportLogic && window.JobImportLogic.isImporting && window.JobImportLogic.logManualImportRun) {
                                    window.JobImportLogic.logManualImportRun(statusData);
                                }
                                
                                JobImportEvents.stopStatusPolling();
                                JobImportUI.resetButtons();
                                $('#status-message').text('Import Complete');
                            }
                        } else if (statusData.complete && statusData.total === 0 && hasSeenImportRunning && !isStartingNewImport) {
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
                        console.log('[PUNTWORK] Status polling failed:', response);
                        PuntWorkJSLogger.warn('Status polling failed', 'EVENTS', response);
                    }
                }).catch(function(error) {
                    console.log('[PUNTWORK] Status polling error:', error);
                    PuntWorkJSLogger.error('Status polling error', 'EVENTS', error);
                    // Continue polling on error
                });
            };

            // Start polling with initial interval
            JobImportEvents.statusPollingInterval = setInterval(JobImportEvents.pollStatus, JobImportEvents.currentPollingInterval);

            // Safety timeout: Stop polling after 30 minutes to prevent infinite polling
            JobImportEvents.statusPollingTimeout = setTimeout(function() {
                console.log('[PUNTWORK] Status polling timed out after 30 minutes');
                PuntWorkJSLogger.warn('Status polling timed out', 'EVENTS');
                JobImportEvents.stopStatusPolling();
                JobImportUI.resetButtons();
                $('#status-message').text('Import monitoring timed out - please refresh the page');
            }, 30 * 60 * 1000); // 30 minutes
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
        },

        /**
         * Handle optimize database button click
         */
        handleOptimizeDatabase: function() {
            console.log('[PUNTWORK] Optimize database handler called');

            if (confirm('This will create database indexes to improve import performance. This may take a few moments. Continue?')) {
                console.log('[PUNTWORK] User confirmed database optimization');
                $('#optimize-database').prop('disabled', true);
                $('#optimize-text').hide();
                $('#optimize-loading').show();
                $('#db-optimization-status-msg').text('Creating indexes...');

                JobImportAPI.createDatabaseIndexes().then(function(response) {
                    console.log('[PUNTWORK] Database optimization response:', response);

                    if (response.success) {
                        $('#db-optimization-status-msg').text(response.data.message || 'Database indexes created successfully!');
                        JobImportEvents.updateDbStatusDisplay(response.data.status);
                    } else {
                        $('#db-optimization-status-msg').text('Failed: ' + (response.data.message || 'Unknown error'));
                    }

                    $('#optimize-database').prop('disabled', false);
                    $('#optimize-text').show();
                    $('#optimize-loading').hide();
                }).catch(function(xhr, status, error) {
                    console.log('[PUNTWORK] Database optimization error:', error);
                    $('#db-optimization-status-msg').text('Error: ' + error);
                    $('#optimize-database').prop('disabled', false);
                    $('#optimize-text').show();
                    $('#optimize-loading').hide();
                });
            } else {
                console.log('[PUNTWORK] User cancelled database optimization');
            }
        },

        /**
         * Handle check database status button click
         */
        handleCheckDbStatus: function() {
            console.log('[PUNTWORK] Check DB status handler called');

            $('#check-db-status').prop('disabled', true);
            $('#check-text').hide();
            $('#check-loading').show();
            $('#db-optimization-status-msg').text('Checking status...');

            JobImportAPI.getDbOptimizationStatus().then(function(response) {
                console.log('[PUNTWORK] DB status response:', response);

                if (response.success) {
                    JobImportEvents.updateDbStatusDisplay(response.data.status);
                    $('#db-optimization-status-msg').text('Status updated');
                } else {
                    $('#db-optimization-status-msg').text('Failed to check status');
                }

                $('#check-db-status').prop('disabled', false);
                $('#check-text').show();
                $('#check-loading').hide();
            }).catch(function(xhr, status, error) {
                console.log('[PUNTWORK] DB status check error:', error);
                $('#db-optimization-status-msg').text('Error: ' + error);
                $('#check-db-status').prop('disabled', false);
                $('#check-text').show();
                $('#check-loading').hide();
            });
        },

        /**
         * Update the database status display
         */
        updateDbStatusDisplay: function(status) {
            console.log('[PUNTWORK] Updating DB status display:', status);

            // Update badge
            var badgeElement = $('#db-status-badge');
            var badgeClass = 'error';
            var badgeText = 'Not Optimized';

            if (status.optimization_complete) {
                badgeClass = 'success';
                badgeText = 'Optimized';
            } else if (status.indexes_created > 0) {
                badgeClass = 'warning';
                badgeText = 'Partial';
            }

            badgeElement.removeClass('success warning error').addClass(badgeClass);
            badgeElement.text(badgeText);

            // Update indexes list
            $('#db-indexes-list').html(status.indexes_html || 'Unable to load index status');
        },

        /**
         * Handle save async settings button click
         */
        handleSaveAsyncSettings: function() {
            console.log('[PUNTWORK] Save async settings handler called');

            $('#save-async-settings').prop('disabled', true);
            $('#save-async-text').hide();
            $('#save-async-loading').show();
            $('#async-settings-status').text('Saving settings...');

            var enableAsync = $('#enable-async-processing').is(':checked');

            JobImportAPI.saveAsyncSettings(enableAsync).then(function(response) {
                console.log('[PUNTWORK] Save async settings response:', response);

                if (response.success) {
                    $('#async-settings-status').text('Settings saved successfully!');
                    JobImportEvents.updateAsyncStatusDisplay(response.data);
                } else {
                    $('#async-settings-status').text('Failed to save settings');
                }

                $('#save-async-settings').prop('disabled', false);
                $('#save-async-text').show();
                $('#save-async-loading').hide();
            }).catch(function(xhr, status, error) {
                console.log('[PUNTWORK] Save async settings error:', error);
                $('#async-settings-status').text('Error: ' + error);
                $('#save-async-settings').prop('disabled', false);
                $('#save-async-text').show();
                $('#save-async-loading').hide();
            });
        },

        /**
         * Update the async processing status display
         */
        updateAsyncStatusDisplay: function(status) {
            console.log('[PUNTWORK] Updating async status display:', status);

            // Update badge
            var badgeElement = $('#async-status-badge');
            var badgeClass = 'error';
            var badgeText = 'Unavailable';

            if (status.available) {
                badgeClass = 'success';
                badgeText = 'Available';
            } else if (status.action_scheduler) {
                badgeClass = 'warning';
                badgeText = 'Limited';
            }

            badgeElement.removeClass('success warning error').addClass(badgeClass);
            badgeElement.text(badgeText);

            // Update status details
            var detailsHtml = '';
            if (status.available) {
                detailsHtml += '<div>• Async processing is available</div>';
                if (status.action_scheduler) {
                    detailsHtml += '<div>• Using Action Scheduler (recommended)</div>';
                } else {
                    detailsHtml += '<div>• Using WordPress Cron (fallback)</div>';
                }
                detailsHtml += '<div>• Large imports will be processed in background</div>';
            } else {
                detailsHtml += '<div>• Async processing is not available</div>';
                detailsHtml += '<div>• All imports will be processed synchronously</div>';
            }

            if (status.enabled) {
                detailsHtml += '<div>• Auto-async enabled for imports > 500 items</div>';
            } else {
                detailsHtml += '<div>• Auto-async disabled</div>';
            }

            $('#async-status-details').html(detailsHtml);
        }
    };

    // Expose to global scope
    window.JobImportEvents = JobImportEvents;

})(jQuery, window, document);