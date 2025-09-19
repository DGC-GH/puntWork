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
            if (confirm('This will permanently delete duplicate job posts. This action cannot be undone. Continue?')) {
                $('#cleanup-duplicates').prop('disabled', true);
                $('#cleanup-text').hide();
                $('#cleanup-loading').show();
                $('#cleanup-status').text('Cleaning up duplicates...');

                JobImportAPI.cleanupDuplicates().then(function(response) {
                    PuntWorkJSLogger.debug('Cleanup response', 'EVENTS', response);
                    
                    if (response.success) {
                        $('#cleanup-status').text('Cleanup completed: ' + response.data.deleted_count + ' duplicates removed');
                        JobImportUI.appendLogs(response.data.logs || []);
                    } else {
                        $('#cleanup-status').text('Cleanup failed: ' + (response.data || 'Unknown error'));
                    }
                }).catch(function(xhr, status, error) {
                    PuntWorkJSLogger.error('Cleanup AJAX error', 'EVENTS', error);
                    $('#cleanup-status').text('Cleanup failed: ' + error);
                    JobImportUI.appendLogs(['Cleanup AJAX error: ' + error]);
                }).finally(function() {
                    $('#cleanup-duplicates').prop('disabled', false);
                    $('#cleanup-text').show();
                    $('#cleanup-loading').hide();
                });
            }
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

                JobImportAPI.purgeImport().then(function(response) {
                    PuntWorkJSLogger.debug('Purge response', 'EVENTS', response);
                    
                    if (response.success) {
                        $('#purge-status').text(response.data.message);
                        // Refresh the page to show updated job counts
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#purge-status').text('Purge failed: ' + (response.data.message || 'Unknown error'));
                    }
                }).catch(function(xhr, status, error) {
                    PuntWorkJSLogger.error('Purge AJAX error', 'EVENTS', error);
                    $('#purge-status').text('Purge failed: ' + error);
                }).finally(function() {
                    $('#purge-old-jobs').prop('disabled', false);
                    $('#purge-text').show();
                    $('#purge-loading').hide();
                });
            }
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