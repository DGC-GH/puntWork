/**
 * Job Import Admin - API Module
 * Handles all AJAX operations and API communications
 */

(function($, window, document) {
    'use strict';

    var JobImportAPI = {
        /**
         * Run a single import batch
         * @param {number} start - Starting index for batch
         * @returns {Promise} AJAX promise
         */
        runImportBatch: function(start) {
            PuntWorkJSLogger.debug('Running import batch at start: ' + start, 'API');

            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                timeout: 0,
                data: { action: 'run_job_import_batch', start: start, nonce: jobImportData.nonce }
            });
        },

        /**
         * Clear import cancellation flag
         * @returns {Promise} AJAX promise
         */
        clearImportCancel: function() {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: { action: 'clear_import_cancel', nonce: jobImportData.nonce },
                success: function(response) {
                    PuntWorkJSLogger.debug('Clear cancel response', 'API', response);
                },
                error: function(xhr, status, error) {
                    PuntWorkJSLogger.error('Clear cancel error: ' + error, 'API');
                }
            });
        },

        /**
         * Reset import process
         * @returns {Promise} AJAX promise
         */
        resetImport: function() {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: { action: 'reset_job_import', nonce: jobImportData.nonce }
            });
        },

        /**
         * Process a single feed
         * @param {string} feedKey - Feed key identifier
         * @returns {Promise} AJAX promise
         */
        processFeed: function(feedKey) {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: { action: 'process_feed', feed_key: feedKey, nonce: jobImportData.nonce }
            });
        },

        /**
         * Combine JSONL files
         * @param {number} totalItems - Total number of items
         * @returns {Promise} AJAX promise
         */
        combineJsonl: function(totalItems) {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: { action: 'combine_jsonl', total_items: totalItems, nonce: jobImportData.nonce }
            });
        },

        /**
         * Cancel import process
         * @returns {Promise} AJAX promise
         */
        cancelImport: function() {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: { action: 'cancel_job_import', nonce: jobImportData.nonce }
            });
        },

        /**
         * Get current import status
         * @returns {Promise} AJAX promise
         */
        getImportStatus: function() {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: { action: 'get_job_import_status', nonce: jobImportData.nonce }
            });
        },

        /**
         * Perform import purge operation
         * @returns {Promise} AJAX promise
         */
        purgeImport: function() {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: { action: 'job_import_purge', nonce: jobImportData.nonce }
            });
        },

        /**
         * Cleanup duplicate job posts
         * @returns {Promise} AJAX promise
         */
        cleanupDuplicates: function() {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: { action: 'job_import_cleanup_duplicates', nonce: jobImportData.nonce }
            });
        },

        /**
         * Generic API call method for scheduling operations
         * @param {string} action - AJAX action name
         * @param {object} data - Additional data to send
         * @param {function} callback - Success callback function
         * @param {function} errorCallback - Error callback function
         */
        call: function(action, data, callback, errorCallback) {
            var ajaxData = {
                action: action,
                nonce: jobImportData.nonce
            };

            // Merge additional data
            if (data && typeof data === 'object') {
                ajaxData = $.extend(ajaxData, data);
            }

            PuntWorkJSLogger.debug('Making API call: ' + action, 'API', ajaxData);

            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: ajaxData,
                success: function(response) {
                    PuntWorkJSLogger.debug('API call successful: ' + action, 'API', response);
                    if (callback && typeof callback === 'function') {
                        callback(response);
                    }
                },
                error: function(xhr, status, error) {
                    PuntWorkJSLogger.error('API call failed: ' + action + ' - ' + error, 'API', {
                        status: xhr.status,
                        responseText: xhr.responseText
                    });
                    if (errorCallback && typeof errorCallback === 'function') {
                        errorCallback(xhr, status, error);
                    }
                }
            });
        }
    };

    // Expose to global scope
    window.JobImportAPI = JobImportAPI;

})(jQuery, window, document);