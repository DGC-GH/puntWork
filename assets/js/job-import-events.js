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

            JobImportAPI.getImportStatus().then(function(response) {
                PuntWorkJSLogger.debug('Initial status response', 'EVENTS', response);
                console.log('[PUNTWORK] Initial status response:', response);

                // Handle both response formats: direct data or wrapped in .data
                var statusData = JobImportUI.normalizeResponseData(response);

                if (response.success && statusData.processed > 0 && !statusData.complete) {
                    JobImportUI.updateProgress(statusData);
                    JobImportUI.appendLogs(statusData.logs || []);
                    
                    // Check if import appears to be currently running (updated within last 60 seconds)
                    var currentTime = Math.floor(Date.now() / 1000);
                    var timeSinceLastUpdate = currentTime - (statusData.last_update || 0);
                    var isRecentlyActive = timeSinceLastUpdate < 60;
                    
                    if (isRecentlyActive) {
                        // Import appears to be currently running - show progress UI with cancel and start polling
                        $('#start-import').hide();
                        $('#resume-import').hide();
                        $('#cancel-import').show();
                        JobImportUI.showImportUI();
                        $('#status-message').text('Import in progress...');
                        console.log('[PUNTWORK] Import appears to be currently running - starting status polling');
                        
                        // Start polling for status updates
                        JobImportEvents.startStatusPolling();
                    } else {
                        // Import was interrupted - show resume option
                        $('#resume-import').show();
                        $('#start-import').text('Restart').on('click', function() {
                            JobImportEvents.handleRestartImport();
                        });
                        JobImportUI.showImportUI();
                        $('#status-message').text('Previous import interrupted. Continue or restart?');
                        console.log('[PUNTWORK] Import was interrupted, showing resume option');
                    }
                } else if (response.success && !statusData.complete) {
                    // Check if this might be a scheduled import that just started (processed = 0 but recently updated)
                    var currentTime = Math.floor(Date.now() / 1000);
                    var timeSinceLastUpdate = currentTime - (statusData.last_update || 0);
                    var isRecentlyActive = timeSinceLastUpdate < 60;
                    
                    if (isRecentlyActive && statusData.logs && statusData.logs.length > 0) {
                        // Likely a scheduled import that just started - show progress UI and start polling
                        JobImportUI.updateProgress(statusData);
                        JobImportUI.appendLogs(statusData.logs || []);
                        $('#start-import').hide();
                        $('#resume-import').hide();
                        $('#cancel-import').show();
                        JobImportUI.showImportUI();
                        $('#status-message').text('Import in progress...');
                        console.log('[PUNTWORK] Scheduled import detected - starting status polling');
                        
                        // Start polling for status updates
                        JobImportEvents.startStatusPolling();
                    } else {
                        $('#resume-import').hide();
                        $('#start-import').show().text('Start');
                        JobImportUI.hideImportUI();
                    }
                } else {
                    $('#resume-import').hide();
                    $('#start-import').show().text('Start');
                    JobImportUI.hideImportUI();
                }
            }).catch(function(xhr, status, error) {
                PuntWorkJSLogger.error('Initial status AJAX error', 'EVENTS', error);
                JobImportUI.appendLogs(['Initial status AJAX error: ' + error]);
                // Ensure UI is in clean state even on error
                JobImportUI.clearProgress();
                JobImportUI.hideImportUI();
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
         * Start polling for import status updates (used for scheduled imports)
         */
        startStatusPolling: function() {
            console.log('[PUNTWORK] Starting status polling for scheduled import');
            
            // Clear any existing polling interval
            if (this.statusPollingInterval) {
                clearInterval(this.statusPollingInterval);
            }
            
            // Show the progress UI immediately when starting polling for scheduled imports
            JobImportUI.showImportUI();
            $('#start-import').hide();
            $('#resume-import').hide();
            $('#cancel-import').show();
            $('#status-message').text('Import in progress...');
            
            // Poll every 3 seconds
            this.statusPollingInterval = setInterval(function() {
                JobImportAPI.getImportStatus().then(function(response) {
                    if (response.success) {
                        var statusData = JobImportUI.normalizeResponseData(response);
                        
                        // Update progress
                        JobImportUI.updateProgress(statusData);
                        JobImportUI.appendLogs(statusData.logs || []);
                        
                        // Check if import completed
                        if (statusData.complete) {
                            console.log('[PUNTWORK] Polled import completed');
                            JobImportEvents.stopStatusPolling();
                            JobImportUI.resetButtons();
                            $('#status-message').text('Import Complete');
                        }
                    }
                }).catch(function(error) {
                    console.log('[PUNTWORK] Status polling error:', error);
                    // Continue polling on error
                });
            }, 3000); // Poll every 3 seconds
        },

        /**
         * Stop polling for import status updates
         */
        stopStatusPolling: function() {
            if (this.statusPollingInterval) {
                clearInterval(this.statusPollingInterval);
                this.statusPollingInterval = null;
                console.log('[PUNTWORK] Stopped status polling');
            }
        }
    };

    // Expose to global scope
    window.JobImportEvents = JobImportEvents;

})(jQuery, window, document);