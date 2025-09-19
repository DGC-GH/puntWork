/**
 * Job Import Admin - Logic Module
 * Handles core import processing logic and batch management
 */

(function($, window, document) {
    'use strict';

    var JobImportLogic = {
        isImporting: false,

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
                    // Normalize response data
                    var batchData = JobImportUI.normalizeResponseData(response);
                    JobImportUI.updateProgress(batchData);
                    JobImportUI.appendLogs(batchData.logs || []);

                    let total = batchData.total;
                    let current = batchData.processed;
                    PuntWorkJSLogger.debug('Initial current: ' + current + ', total: ' + total, 'LOGIC');

                    while (current < total && this.isImporting) {
                        PuntWorkJSLogger.debug('Continuing to next batch, current: ' + current + ', total: ' + total, 'LOGIC');
                        response = await JobImportAPI.runImportBatch(current);
                        PuntWorkJSLogger.debug('Next batch response', 'LOGIC', response);

                        if (response.success) {
                            // Normalize response data
                            batchData = JobImportUI.normalizeResponseData(response);
                            JobImportUI.updateProgress(batchData);
                            JobImportUI.appendLogs(batchData.logs || []);
                            current = batchData.processed;
                        } else {
                            JobImportUI.appendLogs(['Import batch error: ' + (response.message || 'Unknown')]);
                            $('#status-message').text('Error: ' + (response.message || 'Unknown'));
                            JobImportUI.resetButtons();
                            break;
                        }
                    }

                    if (this.isImporting && current >= total) {
                        await this.handleImportCompletion();
                    }
                } else {
                    JobImportUI.appendLogs(['Initial import batch error: ' + (response.message || 'Unknown')]);
                    $('#status-message').text('Error: ' + (response.message || 'Unknown'));
                    JobImportUI.resetButtons();
                }
            } catch (e) {
                PuntWorkJSLogger.error('Handle import error', 'LOGIC', e);
                JobImportUI.appendLogs(['Handle import error: ' + e.message]);
                $('#status-message').text('Error: ' + e.message);
                JobImportUI.resetButtons();
            }
        },

        /**
         * Handle import completion and cleanup
         */
        handleImportCompletion: async function() {
            JobImportUI.appendLogs(['Import complete, starting purge...']);

            try {
                const purgeResponse = await JobImportAPI.purgeImport();
                PuntWorkJSLogger.debug('Purge response', 'LOGIC', purgeResponse);
                JobImportUI.appendLogs(['Purge completed']);

                const finalResponse = await JobImportAPI.getImportStatus();
                PuntWorkJSLogger.debug('Final status response', 'LOGIC', finalResponse);

                if (finalResponse.success) {
                    // Handle both response formats: direct data or wrapped in .data
                    var statusData = JobImportUI.normalizeResponseData(finalResponse);
                    JobImportUI.updateProgress(statusData);
                    JobImportUI.appendLogs(statusData.logs || []);
                }
            } catch (error) {
                PuntWorkJSLogger.error('Final status AJAX error', 'LOGIC', error);
                JobImportUI.appendLogs(['Final status AJAX error: ' + error]);
            }

            $('#status-message').text('Import Complete');
            JobImportUI.resetButtons();
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

            this.isImporting = true;

            try {
                JobImportUI.clearProgress();
                $('#start-import').hide();
                $('#cancel-import').show();
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

                for (let feed of feeds) {
                    console.log('[PUNTWORK] Processing feed:', feed);
                    $('#status-message').text(`Processing feed: ${feed}`);
                    const response = await JobImportAPI.processFeed(feed);
                    PuntWorkJSLogger.debug(`Process feed ${feed} response`, 'LOGIC', response);

                    if (response.success) {
                        JobImportUI.appendLogs(response.data.logs || []);
                        total_items += response.data.item_count;
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
                const combineResponse = await JobImportAPI.combineJsonl(total_items);
                PuntWorkJSLogger.debug('Combine JSONL response', 'LOGIC', combineResponse);

                if (combineResponse.success) {
                    JobImportUI.appendLogs(combineResponse.data.logs || []);
                } else {
                    throw new Error('Combining JSONL failed: ' + (combineResponse.message || 'Unknown error'));
                }

                // Start import
                $('#status-message').text('Starting import...');
                await JobImportAPI.clearImportCancel();
                await this.handleImport(0);

            } catch (error) {
                PuntWorkJSLogger.error('Start import error', 'LOGIC', error);
                JobImportUI.appendLogs([error.message]);
                $('#status-message').text('Error: ' + error.message);
                JobImportUI.resetButtons();
            }
        },

        /**
         * Handle resume import process
         * @returns {Promise} Resume import process promise
         */
        handleResumeImport: async function() {
            PuntWorkJSLogger.info('Resume Import clicked', 'LOGIC');
            if (this.isImporting) return;

            this.isImporting = true;
            $('#start-import').hide();
            $('#resume-import').hide();
            $('#cancel-import').show();
            JobImportUI.showImportUI();

            await JobImportAPI.clearImportCancel();
            await this.handleImport(jobImportData.resume_progress || 0);
        },

        /**
         * Handle cancel import process
         */
        handleCancelImport: function() {
            PuntWorkJSLogger.info('Cancel Import clicked', 'LOGIC');

            JobImportAPI.cancelImport().then(function(response) {
                PuntWorkJSLogger.debug('Cancel response', 'LOGIC', response);
                if (response.success) {
                    JobImportUI.appendLogs(['Import cancelled']);
                    $('#status-message').text('Import Cancelled');
                    JobImportUI.resetButtons();
                    $('#resume-import').show();
                    $('#start-import').text('Restart').show();
                }
            }).catch(function(xhr, status, error) {
                PuntWorkJSLogger.error('Cancel AJAX error', 'LOGIC', error);
                JobImportUI.appendLogs(['Cancel AJAX error: ' + error]);
            });
        }
    };

    // Expose to global scope
    window.JobImportLogic = JobImportLogic;

})(jQuery, window, document);