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
            console.log('[PUNTWORK] Purge button exists:', $('#purge-old-jobs').length);

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

            if ($('#purge-old-jobs').length > 0) {
                console.log('[PUNTWORK] Found purge button, binding click handler');
                $('#purge-old-jobs').on('click', function(e) {
                    console.log('[PUNTWORK] Purge button clicked!');
                    e.preventDefault(); // Prevent any default form submission
                    JobImportEvents.handlePurgeOldJobs();
                });
            } else {
                console.log('[PUNTWORK] Purge button NOT found!');
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

            console.log('[PUNTWORK] Events bound successfully');
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

            if (confirm('This will permanently delete duplicate job posts. This action cannot be undone. Continue?')) {
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
         * Handle purge old jobs button click
         */
        handlePurgeOldJobs: function() {
            console.log('[PUNTWORK] Purge old jobs handler called');
            console.log('[PUNTWORK] jobImportData available:', typeof jobImportData);
            console.log('[PUNTWORK] JobImportAPI available:', typeof JobImportAPI);
            console.log('[PUNTWORK] JobImportUI available:', typeof JobImportUI);

            if (confirm('This will permanently delete all jobs that are no longer in the current feed. This action cannot be undone. Continue?')) {
                console.log('[PUNTWORK] User confirmed purge');
                $('#purge-old-jobs').prop('disabled', true);
                $('#purge-text').hide();
                $('#purge-loading').show();
                $('#purge-status').text('Starting purge...');

                // Show progress UI immediately
                JobImportUI.showPurgeUI();
                JobImportUI.clearPurgeProgress();

                console.log('[PUNTWORK] Calling purge API');
                JobImportEvents.processPurgeBatch(0, 50); // Start with first batch
            } else {
                console.log('[PUNTWORK] User cancelled purge');
            }
        },        /**
         * Process purge batch and continue if needed
         * @param {number} offset - Current offset for batch processing
         * @param {number} batchSize - Size of batch to process
         */
        processPurgeBatch: function(offset, batchSize) {
            console.log('[PUNTWORK] Processing purge batch - offset:', offset, 'batchSize:', batchSize);
            var isContinue = offset > 0;
            var action = isContinue ? JobImportAPI.continuePurge(offset, batchSize) : JobImportAPI.purgeImport();

            action.then(function(response) {
                console.log('[PUNTWORK] Purge API response:', response);
                PuntWorkJSLogger.debug('Purge response', 'EVENTS', response);

                if (response.success) {
                    console.log('[PUNTWORK] Purge response successful, complete:', response.data.complete);
                    JobImportUI.appendLogs(response.data.logs || []);

                    if (response.data.complete) {
                        // Operation completed
                        $('#purge-status').text(response.data.message);
                        $('#purge-old-jobs').prop('disabled', false);
                        $('#purge-text').show();
                        $('#purge-loading').hide();
                        JobImportUI.clearPurgeProgress();

                        // Refresh the page to show updated job counts
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        // Update progress and continue with next batch
                        JobImportUI.updatePurgeProgress(response.data);
                        $('#purge-status').text('Progress: ' + response.data.progress_percentage + '% (' +
                            response.data.total_processed + '/' + response.data.total_jobs + ' jobs processed)');
                        JobImportEvents.processPurgeBatch(response.data.next_offset, batchSize);
                    }
                } else {
                    console.log('[PUNTWORK] Purge response failed:', response.data);
                    $('#purge-status').text('Purge failed: ' + (response.data.message || 'Unknown error'));
                    $('#purge-old-jobs').prop('disabled', false);
                    $('#purge-text').show();
                    $('#purge-loading').hide();
                    JobImportUI.clearPurgeProgress();
                }
            }).catch(function(xhr, status, error) {
                console.log('[PUNTWORK] Purge API error:', error);
                console.log('[PUNTWORK] XHR status:', xhr.status, 'response:', xhr.responseText);
                PuntWorkJSLogger.error('Purge AJAX error', 'EVENTS', error);
                $('#purge-status').text('Purge failed: ' + error);
                $('#purge-old-jobs').prop('disabled', false);
                $('#purge-text').show();
                $('#purge-loading').hide();
                JobImportUI.clearPurgeProgress();
            });
        },        /**
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
                    $('#resume-import').show();
                    $('#start-import').text('Restart').on('click', function() {
                        JobImportEvents.handleRestartImport();
                    });
                    JobImportUI.showImportUI();
                    $('#status-message').text('Previous import interrupted. Continue or restart?');
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
        }
    };

    // Expose to global scope
    window.JobImportEvents = JobImportEvents;

})(jQuery, window, document);