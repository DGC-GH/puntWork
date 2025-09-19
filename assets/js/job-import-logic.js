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
            console.log('Handling import starting at:', initialStart);
            let response;

            try {
                response = await JobImportAPI.runImportBatch(initialStart);
                console.log('Import batch response:', response);

                if (response.success) {
                    JobImportUI.updateProgress(response.data);
                    JobImportUI.appendLogs(response.data.logs || []);

                    let total = response.data.total;
                    let current = response.data.processed;
                    console.log('Initial current:', current, 'total:', total);

                    while (current < total && this.isImporting) {
                        console.log('Continuing to next batch, current:' + current + ', total:' + total);
                        response = await JobImportAPI.runImportBatch(current);
                        console.log('Next batch response:', response);

                        if (response.success) {
                            JobImportUI.updateProgress(response.data);
                            JobImportUI.appendLogs(response.data.logs || []);
                            current = response.data.processed;
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
                console.error('Handle import error:', e);
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
                console.log('Purge response:', purgeResponse);
                JobImportUI.appendLogs(['Purge completed']);

                const finalResponse = await JobImportAPI.getImportStatus();
                console.log('Final status response:', finalResponse);

                if (finalResponse.success) {
                    JobImportUI.updateProgress(finalResponse);
                    JobImportUI.appendLogs(finalResponse.logs || []);
                }
            } catch (error) {
                console.error('Final status AJAX error:', error);
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
            console.log('Start Import clicked');
            if (this.isImporting) return;

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
                let total_items = 0;

                for (let feed of feeds) {
                    $('#status-message').text(`Processing feed: ${feed}`);
                    const response = await JobImportAPI.processFeed(feed);
                    console.log(`Process feed ${feed} response:`, response);

                    if (response.success) {
                        JobImportUI.appendLogs(response.data.logs || []);
                        total_items += response.data.item_count;
                    } else {
                        throw new Error(`Processing feed ${feed} failed: ` + (response.message || 'Unknown error'));
                    }
                }

                // Combine JSONL files
                $('#status-message').text('Combining JSONL files...');
                const combineResponse = await JobImportAPI.combineJsonl(total_items);
                console.log('Combine JSONL response:', combineResponse);

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
                console.error('Start import error:', error);
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
            console.log('Resume Import clicked');
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
            console.log('Cancel Import clicked');

            JobImportAPI.cancelImport().then(function(response) {
                console.log('Cancel response:', response);
                if (response.success) {
                    JobImportUI.appendLogs(['Import cancelled']);
                    $('#status-message').text('Import Cancelled');
                    JobImportUI.resetButtons();
                    $('#resume-import').show();
                    $('#start-import').text('Restart').show();
                }
            }).catch(function(xhr, status, error) {
                console.error('Cancel AJAX error:', error);
                JobImportUI.appendLogs(['Cancel AJAX error: ' + error]);
            });
        }
    };

    // Expose to global scope
    window.JobImportLogic = JobImportLogic;

})(jQuery, window, document);