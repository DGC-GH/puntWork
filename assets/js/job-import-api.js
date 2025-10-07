/**
 * Job Import Admin - API Module
 * Handles all AJAX operations and API communications
 */

console.log('[PUNTWORK] job-import-api.js loaded');

(function($, window, document) {
    'use strict';

    var JobImportAPI = {
        /**
         * Run a single import batch with retry logic
         * @param {number} start - Starting index for batch
         * @returns {Promise} AJAX promise
         */
        runImportBatch: function(start) {
            PuntWorkJSLogger.debug('Running import batch at start: ' + start, 'API');

            return this._retryAjax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                timeout: 300000, // Increased to 5 minutes for large batches
                data: { action: 'run_job_import_batch', start: start, nonce: jobImportData.nonce }
            }, 3, 2000); // 3 retries, 2 second delay
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
                timeout: 30000, // 30 second timeout
                data: { action: 'reset_job_import', nonce: jobImportData.nonce }
            });
        },

        /**
         * Process a single feed
         * @param {string} feedKey - Feed key identifier
         * @returns {Promise} AJAX promise
         */
        processFeed: function(feedKey) {
            console.log('[PUNTWORK] ===== API.processFeed DEBUG =====');
            console.log('[PUNTWORK] Processing feed key:', feedKey);
            console.log('[PUNTWORK] AJAX URL:', jobImportData.ajaxurl);
            console.log('[PUNTWORK] Nonce:', jobImportData.nonce);
            console.log('[PUNTWORK] Timestamp:', new Date().toISOString());
            console.log('[PUNTWORK] Browser info:', navigator.userAgent);

            const ajaxData = {
                action: 'process_feed',
                feed_key: feedKey,
                nonce: jobImportData.nonce
            };
            console.log('[PUNTWORK] AJAX data being sent:', ajaxData);

            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: ajaxData,
                timeout: 900000, // 15 minutes for large feed processing (increased from 60 seconds)
                success: function(response) {
                    console.log('[PUNTWORK] AJAX success for processFeed:', response);
                    console.log('[PUNTWORK] Response timestamp:', new Date().toISOString());
                    if (!response || !response.success) {
                        console.error('[PUNTWORK] ERROR: processFeed AJAX returned unsuccessful response:', response);
                    } else if (response.data && response.data.item_count === 0) {
                        console.warn('[PUNTWORK] WARNING: processFeed AJAX returned success but item_count is 0:', response);
                    } else {
                        console.log('[PUNTWORK] SUCCESS: processFeed processed', (response.data && response.data.item_count) || 0, 'items');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[PUNTWORK] AJAX error for processFeed:', {
                        xhr: xhr,
                        status: status,
                        error: error,
                        statusCode: xhr.status,
                        responseText: xhr.responseText,
                        timestamp: new Date().toISOString()
                    });
                }
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
         * Reset import system completely
         * @returns {Promise} AJAX promise
         */
        resetImport: function() {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: { action: 'reset_job_import_status', nonce: jobImportData.nonce }
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
                data: { 
                    action: 'get_job_import_status', 
                    nonce: jobImportData.nonce,
                    _cache_bust: Date.now() // Prevent caching
                }
            });
        },

        /**
         * Cleanup duplicate job posts
         * @returns {Promise} AJAX promise
         */
        cleanupDuplicates: function() {
            console.log('[PUNTWORK] API: Making cleanup duplicates request');
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: { action: 'job_import_cleanup_duplicates', nonce: jobImportData.nonce }
            });
        },

        /**
         * Continue cleanup operation (for batched processing)
         * @param {number} offset - Current offset for batch processing
         * @param {number} batchSize - Size of batch to process
         * @returns {Promise} AJAX promise
         */
        continueCleanup: function(offset, batchSize) {
            console.log('[PUNTWORK] API: Making continue cleanup request - offset:', offset, 'batchSize:', batchSize);
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'job_import_cleanup_continue',
                    offset: offset,
                    batch_size: batchSize,
                    nonce: jobImportData.nonce
                }
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
        },

        /**
         * Retry AJAX request with exponential backoff
         * @param {object} ajaxOptions - jQuery AJAX options
         * @param {number} maxRetries - Maximum number of retries
         * @param {number} initialDelay - Initial delay in milliseconds
         * @returns {Promise} Promise that resolves with AJAX response
         */
        _retryAjax: function(ajaxOptions, maxRetries, initialDelay) {
            var self = this;
            var attempt = 0;

            return new Promise(function(resolve, reject) {
                function attemptRequest() {
                    attempt++;
                    PuntWorkJSLogger.debug('AJAX attempt ' + attempt + '/' + (maxRetries + 1), 'API');

                    $.ajax(ajaxOptions)
                        .done(function(response) {
                            resolve(response);
                        })
                        .fail(function(xhr, status, error) {
                            PuntWorkJSLogger.warn('AJAX attempt ' + attempt + ' failed: ' + error, 'API', {
                                status: xhr.status,
                                statusText: xhr.statusText,
                                readyState: xhr.readyState
                            });

                            // Check if we should retry
                            if (attempt <= maxRetries && self._shouldRetry(xhr, status, error)) {
                                var delay = initialDelay * Math.pow(2, attempt - 1); // Exponential backoff
                                PuntWorkJSLogger.info('Retrying AJAX request in ' + delay + 'ms (attempt ' + attempt + '/' + (maxRetries + 1) + ')', 'API');
                                
                                setTimeout(function() {
                                    attemptRequest();
                                }, delay);
                            } else {
                                reject({
                                    xhr: xhr,
                                    status: status,
                                    error: error,
                                    attempts: attempt
                                });
                            }
                        });
                }

                attemptRequest();
            });
        },

        /**
         * Get database optimization status
         * @returns {Promise} AJAX promise
         */
        getDbOptimizationStatus: function() {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_db_optimization_status',
                    nonce: jobImportData.nonce
                },
                success: function(response) {
                    PuntWorkJSLogger.debug('DB optimization status response', 'API', response);
                },
                error: function(xhr, status, error) {
                    PuntWorkJSLogger.error('DB optimization status error: ' + error, 'API');
                }
            });
        },

        /**
         * Create database indexes
         * @returns {Promise} AJAX promise
         */
        createDatabaseIndexes: function() {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                timeout: 60000, // 1 minute timeout for index creation
                data: {
                    action: 'create_database_indexes',
                    nonce: jobImportData.nonce
                },
                success: function(response) {
                    PuntWorkJSLogger.debug('Create database indexes response', 'API', response);
                },
                error: function(xhr, status, error) {
                    PuntWorkJSLogger.error('Create database indexes error: ' + error, 'API');
                }
            });
        },

        /**
         * Save async processing settings
         * @param {boolean} enabled Whether async processing is enabled
         * @returns {Promise} AJAX promise
         */
        saveAsyncSettings: function(enabled) {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'save_async_settings',
                    enabled: enabled,
                    nonce: jobImportData.nonce
                },
                success: function(response) {
                    PuntWorkJSLogger.debug('Save async settings response', 'API', response);
                },
                error: function(xhr, status, error) {
                    PuntWorkJSLogger.error('Save async settings error: ' + error, 'API');
                }
            });
        },

        /**
         * Get async processing status
         * @returns {Promise} AJAX promise
         */
        getAsyncStatus: function() {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_async_status',
                    nonce: jobImportData.nonce
                },
                success: function(response) {
                    PuntWorkJSLogger.debug('Get async status response', 'API', response);
                },
                error: function(xhr, status, error) {
                    PuntWorkJSLogger.error('Get async status error: ' + error, 'API');
                }
            });
        },



        /**
         * Schedule feed processing using Action Scheduler
         * @param {Array} feedKeys - Array of feed key identifiers
         * @returns {Promise} AJAX promise
         */
        scheduleFeedProcessing: function(feedKeys) {
            console.log('[PUNTWORK] ===== API.scheduleFeedProcessing DEBUG =====');
            console.log('[PUNTWORK] Scheduling feed processing for:', feedKeys);
            console.log('[PUNTWORK] AJAX URL:', jobImportData.ajaxurl);
            console.log('[PUNTWORK] Nonce:', jobImportData.nonce);
            console.log('[PUNTWORK] Timestamp:', new Date().toISOString());

            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'schedule_feed_processing',
                    feed_keys: feedKeys,
                    nonce: jobImportData.nonce
                },
                timeout: 30000, // 30 seconds for scheduling
                success: function(response) {
                    console.log('[PUNTWORK] AJAX success for scheduleFeedProcessing:', response);
                    if (!response || !response.success) {
                        console.error('[PUNTWORK] ERROR: scheduleFeedProcessing AJAX returned unsuccessful response:', response);
                    } else {
                        console.log('[PUNTWORK] SUCCESS: Feed processing scheduled for', response.data && response.data.scheduled_jobs ? response.data.scheduled_jobs.length : 0, 'feeds');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[PUNTWORK] AJAX error for scheduleFeedProcessing:', {
                        xhr: xhr,
                        status: status,
                        error: error,
                        statusCode: xhr.status,
                        responseText: xhr.responseText,
                        timestamp: new Date().toISOString()
                    });
                }
            });
        },

        /**
         * Get feed processing status
         * @param {Array} feedKeys - Array of feed key identifiers
         * @returns {Promise} AJAX promise
         */
        getFeedProcessingStatus: function(feedKeys) {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_feed_processing_status',
                    feed_keys: feedKeys,
                    nonce: jobImportData.nonce,
                    _cache_bust: Date.now() // Prevent caching
                },
                success: function(response) {
                    PuntWorkJSLogger.debug('Get feed processing status response', 'API', response);
                },
                error: function(xhr, status, error) {
                    PuntWorkJSLogger.error('Get feed processing status error: ' + error, 'API');
                }
            });
        },

        /**
         * Run scheduled import (full process: feeds -> combine -> import)
         * @returns {Promise} AJAX promise
         */
        runScheduledImport: function() {
            console.log('[PUNTWORK] ===== API.runScheduledImport DEBUG =====');
            console.log('[PUNTWORK] Running scheduled import (full process)');
            console.log('[PUNTWORK] AJAX URL:', jobImportData.ajaxurl);
            console.log('[PUNTWORK] Nonce:', jobImportData.nonce);
            console.log('[PUNTWORK] Timestamp:', new Date().toISOString());

            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'run_scheduled_import',
                    nonce: jobImportData.nonce
                },
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                timeout: 300000, // 5 minutes for synchronous import
                success: function(response) {
                    console.log('[PUNTWORK] AJAX success for runScheduledImport:', response);
                    if (!response || !response.success) {
                        console.error('[PUNTWORK] ERROR: runScheduledImport AJAX returned unsuccessful response:', response);
                    } else {
                        console.log('[PUNTWORK] SUCCESS: Import completed synchronously');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[PUNTWORK] AJAX error for runScheduledImport:', {
                        xhr: xhr,
                        status: status,
                        error: error,
                        statusCode: xhr.status,
                        responseText: xhr.responseText,
                        timestamp: new Date().toISOString()
                    });
                }
            });
        },

        /**
         * Determine if an AJAX error should be retried
         * @param {object} xhr - XMLHttpRequest object
         * @param {string} status - Error status
         * @param {string} error - Error message
         * @returns {boolean} Whether to retry the request
         */
        _shouldRetry: function(xhr, status, error) {
            // Retry on network errors, timeouts, and server errors (5xx)
            // Don't retry on client errors (4xx) except 408 (timeout) and 429 (rate limit)
            if (status === 'timeout' || xhr.status === 0 || xhr.status === 408 || xhr.status === 429) {
                return true;
            }
            if (xhr.status >= 500 && xhr.status < 600) {
                return true;
            }
            // Don't retry on authentication errors or bad requests
            if (xhr.status === 401 || xhr.status === 403 || xhr.status === 400) {
                return false;
            }
            // Retry on other errors that might be transient
            return xhr.readyState === 0 || status === 'error';
        },

        /**
         * Get API key for real-time updates
         * @returns {Promise} AJAX promise
         */
        getApiKey: function() {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: { action: 'get_api_key', nonce: jobImportData.nonce }
            });
        },



        /**
         * Check import data status (combined file and feed files)
         * @returns {Promise} AJAX promise
         */
        checkImportDataStatus: function() {
            return $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: { 
                    action: 'check_import_data_status', 
                    nonce: jobImportData.nonce,
                    _cache_bust: Date.now() // Prevent caching
                },
                success: function(response) {
                    PuntWorkJSLogger.debug('Check import data status response', 'API', response);
                },
                error: function(xhr, status, error) {
                    PuntWorkJSLogger.error('Check import data status error: ' + error, 'API');
                }
            });
        },

    };

    // Expose to global scope
    window.JobImportAPI = JobImportAPI;

})(jQuery, window, document);