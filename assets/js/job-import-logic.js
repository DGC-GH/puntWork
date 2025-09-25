/**
 * Job Import Admin - Logic Module
 * Handles core import processing logic and batch management
 */

(function($, window, document) {
    'use strict';

    var JobImportLogic = {
        isImporting: false,
        startTime: null,

        /**
         * Initialize the logic module
         */
        init: function() {
            // No initialization needed for this module
            console.log('[PUNTWORK] JobImportLogic initialized');
        },

        /**
         * Get elapsed time since import started
         * @returns {number} Elapsed time in seconds
         */
        getElapsedTime: function() {
            if (!this.startTime) return 0;
            return (Date.now() - this.startTime) / 1000;
        },

        /**
         * Handle the complete import process
         * @param {number} initialStart - Initial starting index
         * @returns {Promise} Import process promise
         */
        handleImport: async function(initialStart) {
            PuntWorkJSLogger.info('Handling import starting at: ' + initialStart, 'LOGIC');
            let response;

            try {
                response = await JobImportAPI.runImportBatch(initialStart);
                PuntWorkJSLogger.debug('Import batch response', 'LOGIC', response);

                if (response.success) {
                    // Get cumulative status instead of using batch data directly
                    const statusResponse = await JobImportAPI.getImportStatus();
                    if (statusResponse.success) {
                        var batchData = JobImportUI.normalizeResponseData(statusResponse);
                        console.log('[PUNTWORK] Updating UI with status data:', batchData);
                        try {
                            JobImportUI.updateProgress(batchData);
                            JobImportUI.appendLogs(batchData.logs || []);
                        } catch (uiError) {
                            console.error('[PUNTWORK] Error updating UI with status data:', uiError);
                        }
                    } else {
                        // Fallback to batch data if status fetch fails
                        var batchData = JobImportUI.normalizeResponseData(response);
                        console.log('[PUNTWORK] Updating UI with batch data (status fetch failed):', batchData);
                        try {
                            JobImportUI.updateProgress(batchData);
                            JobImportUI.appendLogs(batchData.logs || []);
                        } catch (uiError) {
                            console.error('[PUNTWORK] Error updating UI with batch data:', uiError);
                        }
                    }

                    let total = batchData.total;
                    let current = batchData.processed;
                    PuntWorkJSLogger.debug('Initial current: ' + current + ', total: ' + total, 'LOGIC');

                    while (current < total && this.isImporting) {
                        PuntWorkJSLogger.debug('Continuing to next batch, current: ' + current + ', total: ' + total, 'LOGIC');
                        
                        try {
                            response = await JobImportAPI.runImportBatch(current);
                            PuntWorkJSLogger.debug('Next batch response', 'LOGIC', response);

                            if (response.success) {
                                // Get cumulative status instead of using batch data directly
                                const statusResponse = await JobImportAPI.getImportStatus();
                                if (statusResponse.success) {
                                    batchData = JobImportUI.normalizeResponseData(statusResponse);
                                    console.log('[PUNTWORK] Updating UI with next batch status data:', batchData);
                                    try {
                                        JobImportUI.updateProgress(batchData);
                                        JobImportUI.appendLogs(batchData.logs || []);
                                    } catch (uiError) {
                                        console.error('[PUNTWORK] Error updating UI with next batch status data:', uiError);
                                    }
                                } else {
                                    // Fallback to batch data if status fetch fails
                                    batchData = JobImportUI.normalizeResponseData(response);
                                    console.log('[PUNTWORK] Updating UI with next batch data (status fetch failed):', batchData);
                                    try {
                                        JobImportUI.updateProgress(batchData);
                                        JobImportUI.appendLogs(batchData.logs || []);
                                    } catch (uiError) {
                                        console.error('[PUNTWORK] Error updating UI with next batch data:', uiError);
                                    }
                                }
                                current = batchData.processed;
                            } else {
                                throw new Error('Import batch failed: ' + (response.message || 'Unknown error'));
                            }
                        } catch (batchError) {
                            PuntWorkJSLogger.error('Batch processing error', 'LOGIC', batchError);
                            
                            // Check if this is a retryable error
                            if (batchError.attempts && batchError.attempts > 1) {
                                // All retries exhausted
                                JobImportUI.appendLogs(['Batch failed after ' + batchError.attempts + ' attempts: ' + batchError.error]);
                                $('#status-message').text('Batch failed - you can resume later');
                                JobImportUI.resetButtons();
                                $('#resume-import').show();
                                $('#reset-import').show();
                                $('#start-import').text('Restart').show();
                                this.isImporting = false; // Reset flag on failure
                                
                                // Stop status polling on failure
                                if (window.JobImportEvents && window.JobImportEvents.stopStatusPolling) {
                                    window.JobImportEvents.stopStatusPolling();
                                }
                                
                                return; // Exit the import process
                            } else {
                                // Single failure - log and continue trying
                                JobImportUI.appendLogs(['Batch error (will retry): ' + batchError.message]);
                                $('#status-message').text('Batch error - retrying...');
                                // Continue the loop to retry
                                continue;
                            }
                        }
                    }

                    if (this.isImporting && current >= total) {
                        PuntWorkJSLogger.info('Import completed successfully', 'LOGIC', {
                            total: total,
                            processed: current,
                            finalBatch: batchData
                        });
                        // Ensure success state is set before completion
                        batchData.success = true;
                        JobImportUI.updateProgress(batchData);
                        await this.handleImportCompletion();
                    }
                } else {
                    JobImportUI.appendLogs(['Initial import batch error: ' + (response.message || 'Unknown')]);
                    $('#status-message').text('Error: ' + (response.message || 'Unknown'));
                    JobImportUI.resetButtons();
                }
            } catch (e) {
                PuntWorkJSLogger.error('Handle import error', 'LOGIC', e);
                
                // Check if this is a retry exhaustion error
                if (e.attempts && e.attempts > 1) {
                    JobImportUI.appendLogs(['Import failed after ' + e.attempts + ' attempts: ' + e.error]);
                    $('#status-message').text('Import failed - check logs for details');
                    JobImportUI.resetButtons();
                    $('#resume-import').show();
                    $('#reset-import').show();
                    $('#start-import').text('Restart').show();
                } else {
                    JobImportUI.appendLogs(['Handle import error: ' + e.message]);
                    $('#status-message').text('Error: ' + e.message);
                    JobImportUI.resetButtons();
                }
                this.isImporting = false; // Ensure importing flag is reset on error
            }
        },

        /**
         * Handle import completion and cleanup
         */
        handleImportCompletion: async function() {
            JobImportUI.appendLogs(['Finalizing import...']);

            try {
                // Small delay to ensure database is updated
                await new Promise(resolve => setTimeout(resolve, 500));

                const finalResponse = await JobImportAPI.getImportStatus();
                PuntWorkJSLogger.debug('Final status response', 'LOGIC', finalResponse);

                if (finalResponse.success) {
                    // Handle both response formats: direct data or wrapped in .data
                    var statusData = JobImportUI.normalizeResponseData(finalResponse);
                    // Ensure success is set for completion
                    statusData.success = true;
                    PuntWorkJSLogger.info('Final import status', 'LOGIC', {
                        total: statusData.total,
                        processed: statusData.processed,
                        published: statusData.published,
                        updated: statusData.updated,
                        skipped: statusData.skipped,
                        duplicates_drafted: statusData.duplicates_drafted,
                        drafted_old: statusData.drafted_old,
                        complete: statusData.complete,
                        time_elapsed: statusData.time_elapsed
                    });
                    JobImportUI.updateProgress(statusData);
                    JobImportUI.appendLogs(statusData.logs || []);
                } else {
                    PuntWorkJSLogger.error('Failed to get final status', 'LOGIC', finalResponse);
                    JobImportUI.appendLogs(['Failed to get final status']);
                }
            } catch (error) {
                PuntWorkJSLogger.error('Final status AJAX error', 'LOGIC', error);
                JobImportUI.appendLogs(['Final status AJAX error: ' + error]);
            }

            JobImportUI.appendLogs(['Import complete']);
            $('#status-message').text('Import Complete');
            JobImportUI.resetButtons();
            this.isImporting = false; // Reset importing flag on completion
            
            // Stop status polling on completion
            if (window.JobImportEvents && window.JobImportEvents.stopStatusPolling) {
                window.JobImportEvents.stopStatusPolling();
            }
        },

        /**
         * Handle the start import process
         * @returns {Promise} Start import process promise
         */
        handleStartImport: async function() {
            PuntWorkJSLogger.info('Start Import clicked', 'LOGIC');
            console.log('[PUNTWORK] Start Import clicked');
            console.log('[PUNTWORK] jobImportData:', jobImportData);
            console.log('[PUNTWORK] feeds:', jobImportData.feeds);

            if (this.isImporting) {
                console.log('[PUNTWORK] Import already in progress');
                return;
            }

            // Stop any existing status polling (from scheduled imports)
            if (window.JobImportEvents && window.JobImportEvents.stopStatusPolling) {
                window.JobImportEvents.stopStatusPolling();
            }

            this.isImporting = true;

            try {
                JobImportUI.clearProgress();
                this.startTime = Date.now(); // Record start time in milliseconds
                JobImportUI.setPhase('feed-processing');
                $('#start-import').hide();
                $('#resume-import').hide();
                $('#cancel-import').show();
                $('#reset-import').show();
                JobImportUI.showImportUI();

                JobImportUI.appendLogs(['Starting feed processing...']);
                $('#status-message').text('Processing feeds...');

                // Reset import
                const resetResponse = await JobImportAPI.resetImport();
                if (resetResponse.success) {
                    JobImportUI.appendLogs(['Import reset for fresh start']);
                }

                // Process feeds
                const feeds = jobImportData.feeds;
                console.log('[PUNTWORK] Processing feeds:', feeds);
                let total_items = 0;
                const total_feeds = Object.keys(feeds).length;

                JobImportUI.appendLogs(['Processing ' + total_feeds + ' feeds...']);

                // Initialize progress for feed processing phase
                JobImportUI.updateProgress({
                    total: total_feeds,
                    processed: 0,
                    published: 0,
                    updated: 0,
                    skipped: 0,
                    duplicates_drafted: 0,
                    drafted_old: 0,
                    time_elapsed: this.getElapsedTime() / 1000, // Convert to seconds for server compatibility
                    complete: false
                });

                for (let i = 0; i < Object.keys(feeds).length; i++) {
                    const feed = Object.keys(feeds)[i];
                    console.log('[PUNTWORK] Processing feed:', feed);

                    // Update progress for current feed
                    const current_feed_num = i + 1;
                    $('#status-message').text(`Processing feed ${current_feed_num}/${total_feeds}: ${feed}`);
                    JobImportUI.updateProgress({
                        total: total_feeds,
                        processed: current_feed_num - 1, // Show progress up to current feed
                        published: 0,
                        updated: 0,
                        skipped: 0,
                        duplicates_drafted: 0,
                        drafted_old: 0,
                        time_elapsed: this.getElapsedTime() / 1000, // Convert to seconds for server compatibility
                        complete: false
                    });

                    const response = await JobImportAPI.processFeed(feed);
                    PuntWorkJSLogger.debug(`Process feed ${feed} response`, 'LOGIC', response);

                    if (response.success) {
                        JobImportUI.appendLogs(response.data.logs || []);
                        total_items += response.data.item_count;

                        // Update progress after successful feed processing
                        JobImportUI.updateProgress({
                            total: total_feeds,
                            processed: current_feed_num,
                            published: 0,
                            updated: 0,
                            skipped: 0,
                            duplicates_drafted: 0,
                            drafted_old: 0,
                            time_elapsed: this.getElapsedTime() / 1000, // Convert to seconds for server compatibility
                            complete: false
                        });
                    } else {
                        throw new Error(`Processing feed ${feed} failed: ` + (response.message || 'Unknown error'));
                    }
                }

                console.log('[PUNTWORK] Total items after feed processing:', total_items);

                if (total_items === 0) {
                    throw new Error('No items found in feeds. Please check that feeds are configured and accessible.');
                }

                // Combine JSONL files
                $('#status-message').text('Combining JSONL files...');
                JobImportUI.appendLogs(['Starting JSONL combination...']);

                // Show progress for JSONL combination phase
                JobImportUI.updateProgress({
                    total: 1,
                    processed: 0,
                    published: 0,
                    updated: 0,
                    skipped: 0,
                    duplicates_drafted: 0,
                    drafted_old: 0,
                    time_elapsed: this.getElapsedTime() / 1000, // Convert to seconds for server compatibility
                    complete: false
                });

                const combineResponse = await JobImportAPI.combineJsonl(total_items);
                PuntWorkJSLogger.debug('Combine JSONL response', 'LOGIC', combineResponse);

                if (combineResponse.success) {
                    JobImportUI.appendLogs(combineResponse.data.logs || []);

                    // Update progress to show JSONL combination complete
                    JobImportUI.updateProgress({
                        total: 1,
                        processed: 1,
                        published: 0,
                        updated: 0,
                        skipped: 0,
                        duplicates_drafted: 0,
                        drafted_old: 0,
                        time_elapsed: this.getElapsedTime() / 1000, // Convert to seconds for server compatibility
                        complete: false
                    });
                } else {
                    throw new Error('Combining JSONL failed: ' + (combineResponse.message || 'Unknown error'));
                }

                // Start import
                $('#status-message').text('Starting import...');
                JobImportUI.appendLogs(['Starting batch import processing...']);
                await JobImportAPI.clearImportCancel();
                await this.handleImport(0);

            } catch (error) {
                PuntWorkJSLogger.error('Start import error', 'LOGIC', error);
                JobImportUI.appendLogs([error.message]);
                $('#status-message').text('Error: ' + error.message);
                JobImportUI.resetButtons();
                this.isImporting = false; // Ensure importing flag is reset on error
            }
        },

        handleResumeImport: async function() {
            PuntWorkJSLogger.info('Resume Import clicked', 'LOGIC');
            if (this.isImporting) return;

            this.isImporting = true;
            // For resume, we'll use the time from the server since we don't know the original start time
            $('#start-import').hide();
            $('#resume-import').hide();
            $('#cancel-import').show();
            $('#reset-import').show();
            JobImportUI.showImportUI();

            await JobImportAPI.clearImportCancel();
            await this.handleImport(jobImportData.resume_progress || 0);
        },

        /**
         * Handle cancel import process
         */
        handleCancelImport: function() {
            PuntWorkJSLogger.info('Cancel Import clicked', 'LOGIC');

            // Immediately stop the import loop
            this.isImporting = false;

            JobImportAPI.cancelImport().then(function(response) {
                PuntWorkJSLogger.debug('Cancel response', 'LOGIC', response);
                if (response.success) {
                    JobImportUI.appendLogs(['Import cancelled']);
                    $('#status-message').text('Import Cancelled');
                    JobImportUI.resetButtons();
                    $('#resume-import').show();
                    $('#reset-import').show();
                    $('#start-import').text('Restart').show();
                    
                    // Stop status polling on cancel
                    if (window.JobImportEvents && window.JobImportEvents.stopStatusPolling) {
                        window.JobImportEvents.stopStatusPolling();
                    }
                }
            }).catch(function(xhr, status, error) {
                PuntWorkJSLogger.error('Cancel AJAX error', 'LOGIC', error);
                JobImportUI.appendLogs(['Cancel AJAX error: ' + error]);
            });
        },

        /**
         * Handle reset import process - complete reset to fresh state
         */
        handleResetImport: function() {
            PuntWorkJSLogger.info('Reset Import clicked', 'LOGIC');

            // Stop any ongoing import
            this.isImporting = false;

            // Stop status polling
            if (window.JobImportEvents && window.JobImportEvents.stopStatusPolling) {
                window.JobImportEvents.stopStatusPolling();
            }

            // Show loading state
            $('#reset-import').prop('disabled', true).text('Resetting...');

            JobImportAPI.resetImport().then(function(response) {
                PuntWorkJSLogger.debug('Reset response', 'LOGIC', response);
                console.log('[PUNTWORK] Reset API response:', response);

                if (response.success) {
                    JobImportUI.appendLogs(['Import system completely reset']);
                    $('#status-message').text('Import system reset - ready to start fresh');

                    // Clear all UI state
                    JobImportUI.clearProgress();
                    JobImportUI.hideImportUI();

                    // Reset all button states
                    $('#start-import').show().text('Start Import').prop('disabled', false);
                    $('#resume-import').hide();
                    $('#cancel-import').hide();
                    $('#reset-import').hide().prop('disabled', false);

                    console.log('[PUNTWORK] Reset completed successfully');
                } else {
                    // Reset failed - show error but don't change UI state
                    JobImportUI.appendLogs(['Reset failed: ' + (response.message || 'Unknown error')]);
                    $('#status-message').text('Reset failed - please try again');
                    $('#reset-import').prop('disabled', false);
                    console.log('[PUNTWORK] Reset failed:', response);
                }
            }).catch(function(xhr, status, error) {
                PuntWorkJSLogger.error('Reset AJAX error', 'LOGIC', error);
                JobImportUI.appendLogs(['Reset AJAX error: ' + error]);
                $('#status-message').text('Reset failed - please try again');
                $('#reset-import').prop('disabled', false);
                console.log('[PUNTWORK] Reset AJAX error:', error);
            });
        }
    };

    // Expose to global scope
    window.JobImportLogic = JobImportLogic;

})(jQuery, window, document);