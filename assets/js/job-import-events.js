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
            $('#start-import').on('click', this.handleStartImport.bind(this));
            $('#resume-import').on('click', this.handleResumeImport.bind(this));
            $('#cancel-import').on('click', this.handleCancelImport.bind(this));
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
         * Check initial import status on page load
         */
        checkInitialStatus: function() {
            JobImportAPI.getImportStatus().then(function(response) {
                console.log('Initial status response:', response);
                if (response.success && response.processed > 0 && !response.complete) {
                    JobImportUI.updateProgress(response);
                    JobImportUI.appendLogs(response.logs);
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
                console.error('Initial status AJAX error:', error);
                JobImportUI.appendLogs(['Initial status AJAX error: ' + error]);
            });
        },

        /**
         * Handle restart import (special case for interrupted imports)
         */
        handleRestartImport: async function() {
            console.log('Restart clicked - resetting and starting over');

            try {
                const resetResponse = await JobImportAPI.resetImport();
                if (resetResponse.success) {
                    JobImportUI.appendLogs(['Import reset for restart']);
                }
                // Trigger start import
                $('#start-import').trigger('click');
            } catch (error) {
                console.error('Restart error:', error);
                JobImportUI.appendLogs(['Restart error: ' + error.message]);
            }
        }
    };

    // Expose to global scope
    window.JobImportEvents = JobImportEvents;

})(jQuery, window, document);