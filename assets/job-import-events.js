/**
 * Job Import Admin - Events Module
 * Handles event binding and user interactions
 */

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
                console.log('[PUNTWORK] Events re-bound after delay');
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
            $('#cleanup-duplicates').on('click', function(e) {
                console.log('[PUNTWORK] Cleanup button clicked!');
                JobImportEvents.handleCleanupDuplicates();
            });
            $('#purge-old-jobs').on('click', function(e) {
                console.log('[PUNTWORK] Purge button clicked!');
                JobImportEvents.handlePurgeOldJobs();
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
            if (confirm('This will permanently delete duplicate job posts. This action cannot be undone. Continue?')) {
                console.log('[PUNTWORK] User confirmed cleanup');
                $('#cleanup-duplicates').prop('disabled', true);
                $('#cleanup-text').hide();
                $('#cleanup-loading').show();
                $('#cleanup-status').text('Cleaning up duplicates...');

                console.log('[PUNTWORK] Calling cleanup API');
                JobImportEvents.processCleanupBatch(0, 50); // Start with first batch
            } else {
                console.log('[PUNTWORK] User cancelled cleanup');
            }
        },

        /**
         * Process cleanup batch and continue if needed
         * @param {number} offset - Current offset for batch processing
         * @param {number} batchSize - Size of batch to process
         */
        processCleanupBatch: function(offset, batchSize) {
            var isContinue = offset > 0;
            var action = isContinue ? JobImportAPI.continueCleanup(offset, batchSize) : JobImportAPI.cleanupDuplicates();

            action.then(function(response) {
                console.log('[PUNTWORK] Cleanup API response:', response);
                PuntWorkJSLogger.debug('Cleanup response', 'EVENTS', response);

                if (response.success) {
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
                    $('#cleanup-status').text('Cleanup failed: ' + (response.data || 'Unknown error'));
                    $('#cleanup-duplicates').prop('disabled', false);
                    $('#cleanup-text').show();
                    $('#cleanup-loading').hide();
                    JobImportUI.clearCleanupProgress();
                }
            }).catch(function(xhr, status, error) {
                console.log('[PUNTWORK] Cleanup API error:', error);
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
            if (confirm('This will permanently delete all jobs that are no longer in the current feed. This action cannot be undone. Continue?')) {
                $('#purge-old-jobs').prop('disabled', true);
                $('#purge-text').hide();
                $('#purge-loading').show();
                $('#purge-status').text('Purging old jobs...');

                JobImportEvents.processPurgeBatch(0, 50); // Start with first batch
            }
        },

        /**
         * Process purge batch and continue if needed
         * @param {number} offset - Current offset for batch processing
         * @param {number} batchSize - Size of batch to process
         */
        processPurgeBatch: function(offset, batchSize) {
            var isContinue = offset > 0;
            var action = isContinue ? JobImportAPI.continuePurge(offset, batchSize) : JobImportAPI.purgeImport();

            action.then(function(response) {
                PuntWorkJSLogger.debug('Purge response', 'EVENTS', response);

                if (response.success) {
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
                    $('#purge-status').text('Purge failed: ' + (response.data.message || 'Unknown error'));
                    $('#purge-old-jobs').prop('disabled', false);
                    $('#purge-text').show();
                    $('#purge-loading').hide();
                    JobImportUI.clearPurgeProgress();
                }
            }).catch(function(xhr, status, error) {
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
                    $('#status-message').text('Previous import interrupted. Continue?');
                } else {
                    $('#resume-import').hide();
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
                // Trigger start import
                $('#start-import').trigger('click');
            } catch (error) {
                PuntWorkJSLogger.error('Restart error', 'EVENTS', error);
                JobImportUI.appendLogs(['Restart error: ' + error.message]);
            }
        }
    };

    // Expose to global scope
    window.JobImportEvents = JobImportEvents;

})(jQuery, window, document);