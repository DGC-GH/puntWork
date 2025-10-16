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
                    // Status polling will handle UI updates, just log the response
                    const statusResponse = await JobImportAPI.getImportStatus();
                    if (statusResponse.success) {
                        var batchData = JobImportUI.normalizeResponseData(statusResponse);
                    }

                    let total = response.data.total || 0;
                    let current = response.data.processed || 0;
                    PuntWorkJSLogger.debug('Initial current: ' + current + ', total: ' + total, 'LOGIC');

                    while (current < total && this.isImporting) {
                        PuntWorkJSLogger.debug('Continuing to next batch, current: ' + current + ', total: ' + total, 'LOGIC');
                        
                        try {
                            response = await JobImportAPI.runImportBatch(current);
                            PuntWorkJSLogger.debug('Next batch response', 'LOGIC', response);

                            if (response.success) {
                                // Status polling handles UI updates, just update our local tracking
                                current = response.data.processed || current;
                            } else {
                                throw new Error('Import batch failed: ' + (response.message || response.data?.message || 'Unknown error'));
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
                            processed: current
                        });
                        await this.handleImportCompletion();
                    }
                } else {
                    JobImportUI.appendLogs(['Initial import batch error: ' + (response.message || response.data?.message || 'Unknown')]);
                    $('#status-message').text('Error: ' + (response.message || response.data?.message || 'Unknown'));
                    JobImportUI.resetButtons();
                    
                    // Stop status polling on error
                    if (window.JobImportEvents && window.JobImportEvents.stopStatusPolling) {
                        window.JobImportEvents.stopStatusPolling();
                    }
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
                
                // Stop status polling on error
                if (window.JobImportEvents && window.JobImportEvents.stopStatusPolling) {
                    window.JobImportEvents.stopStatusPolling();
                }
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
                    // Status polling will handle the final UI update
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
            
            // Hide import type indicator on completion
            $('#import-type-indicator').hide();
            
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

            if (this.isImporting) {
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
                JobImportUI.setPhase('import-processing');
                $('#start-import').hide();
                $('#resume-import').hide();
                $('#cancel-import').show();
                $('#reset-import').show();
                JobImportUI.showImportUI();

                JobImportUI.appendLogs(['Starting manual import...']);
                $('#status-message').text('Starting import...');

                // Show manual import indicator
                $('#import-type-indicator').show();
                $('#import-type-text').text('Manual import is currently running');

                // Trigger the unified async import process
                await JobImportAPI.clearImportCancel();

                // Removed automatic status polling to prevent loops
                // if (window.JobImportEvents && window.JobImportEvents.startStatusPolling) {
                //     window.JobImportEvents.startStatusPolling();
                // }

                // Trigger the async import using the same API as scheduled imports
                const startResponse = await JobImportAPI.call('run_scheduled_import', { import_type: 'manual' });
                PuntWorkJSLogger.debug('Manual import start response', 'LOGIC', startResponse);

                if (!startResponse.success) {
                    throw new Error('Failed to start import: ' + (startResponse.message || 'Unknown error'));
                }

                // Start status polling for real-time updates
                if (window.JobImportEvents && window.JobImportEvents.startStatusPolling) {
                    window.JobImportEvents.startStatusPolling();
                }

                // The import is now running asynchronously
                // Status polling will provide real-time progress updates

            } catch (error) {
                PuntWorkJSLogger.error('Start import error', 'LOGIC', error);
                JobImportUI.appendLogs([error.message]);
                $('#status-message').text('Error: ' + error.message);
                JobImportUI.resetButtons();
                this.isImporting = false; // Ensure importing flag is reset on error

                // Stop status polling on error (removed)
                // if (window.JobImportEvents && window.JobImportEvents.stopStatusPolling) {
                //     window.JobImportEvents.stopStatusPolling();
                // }
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

            // Show import indicator (could be either manual or scheduled resume)
            $('#import-type-indicator').show();
            $('#import-type-text').text('Resuming import...');

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
                    
                    // Hide import type indicator on cancel
                    $('#import-type-indicator').hide();
                    
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

                    // Hide import type indicator on reset
                    $('#import-type-indicator').hide();
                } else {
                    // Reset failed - show error but don't change UI state
                    JobImportUI.appendLogs(['Reset failed: ' + (response.message || 'Unknown error')]);
                    $('#status-message').text('Reset failed - please try again');
                    $('#reset-import').prop('disabled', false);
                }
            }).catch(function(xhr, status, error) {
                PuntWorkJSLogger.error('Reset AJAX error', 'LOGIC', error);
                JobImportUI.appendLogs(['Reset AJAX error: ' + error]);
                $('#status-message').text('Reset failed - please try again');
                $('#reset-import').prop('disabled', false);
            });
        },

        /**
         * Handle resume stuck import process - manual intervention for stuck imports
         */
        handleResumeStuckImport: function() {
            PuntWorkJSLogger.info('Resume Stuck Import clicked', 'LOGIC');

            // Stop any ongoing import
            this.isImporting = false;

            // Stop status polling
            if (window.JobImportEvents && window.JobImportEvents.stopStatusPolling) {
                window.JobImportEvents.stopStatusPolling();
            }

            JobImportAPI.call('manually_resume_stuck_import', {}).then(function(response) {
                PuntWorkJSLogger.debug('Resume stuck import response', 'LOGIC', response);

                if (response.success) {
                    JobImportUI.appendLogs(['Stuck import manually resumed']);
                    $('#status-message').text('Stuck import resumed - monitoring progress...');

                    // Show import progress UI
                    JobImportUI.showImportUI();

                    // Update progress with current status
                    if (response.result && response.result.processed !== undefined) {
                        JobImportUI.updateProgress(response.result.processed, response.result.total, response.result.complete);
                    }

                    // Show appropriate buttons
                    $('#start-import').hide();
                    $('#resume-import').hide();
                    $('#resume-stuck-import').hide();
                    $('#cancel-import').show();
                    $('#reset-import').show();

                    // Show import type indicator
                    $('#import-type-indicator').show();
                    $('#import-type-text').text('Manual import is currently running');

                    // Start status polling to monitor progress
                    if (window.JobImportEvents && window.JobImportEvents.startStatusPolling) {
                        window.JobImportEvents.startStatusPolling();
                    }
                } else {
                    // Resume failed - show error
                    var errorMsg = response.message || 'Unknown error';
                    JobImportUI.appendLogs(['Resume stuck import failed: ' + errorMsg]);
                    $('#status-message').text('Resume failed: ' + errorMsg);

                    // Reset button states
                    $('#resume-stuck-text').show();
                    $('#resume-stuck-loading').hide();
                    $('#import-status').text('');
                }
            }).catch(function(xhr, status, error) {
                PuntWorkJSLogger.error('Resume stuck import AJAX error', 'LOGIC', error);
                JobImportUI.appendLogs(['Resume stuck import AJAX error: ' + error]);
                $('#status-message').text('Resume stuck import failed - please try again');

                // Reset button states
                $('#resume-stuck-text').show();
                $('#resume-stuck-loading').hide();
                $('#import-status').text('');
            });
        },

        /**
         * Handle cleanup trashed jobs
         */
        handleCleanupTrashedJobs: function() {
            PuntWorkJSLogger.info('Cleanup Trashed Jobs clicked', 'LOGIC');

            // Show loading state
            $('#cleanup-trashed').prop('disabled', true);
            $('#cleanup-trashed-text').hide();
            $('#cleanup-trashed-loading').show();

            // Show progress UI
            JobImportUI.showCleanupUI();
            JobImportUI.clearCleanupProgress();
            $('#cleanup-status').text('Starting cleanup...');

            // Start batch processing
            this.processCleanupBatch('trashed', 0, 50);
        },

        /**
         * Handle cleanup drafted jobs
         */
        handleCleanupDraftedJobs: function() {
            PuntWorkJSLogger.info('Cleanup Drafted Jobs clicked', 'LOGIC');

            // Show loading state
            $('#cleanup-drafted').prop('disabled', true);
            $('#cleanup-drafted-text').hide();
            $('#cleanup-drafted-loading').show();

            // Show progress UI
            JobImportUI.showCleanupUI();
            JobImportUI.clearCleanupProgress();
            $('#cleanup-status').text('Starting cleanup...');

            // Start batch processing
            this.processCleanupBatch('drafted', 0, 50);
        },

        /**
         * Handle cleanup old published jobs
         */
        handleCleanupOldPublishedJobs: function() {
            PuntWorkJSLogger.info('Cleanup Old Published Jobs clicked', 'LOGIC');

            // Show loading state
            $('#cleanup-old-published').prop('disabled', true);
            $('#cleanup-old-published-text').hide();
            $('#cleanup-old-published-loading').show();

            // Show progress UI
            JobImportUI.showCleanupUI();
            JobImportUI.clearCleanupProgress();
            $('#cleanup-status').text('Starting cleanup...');

            // Start batch processing
            this.processCleanupBatch('old-published', 0, 50);
        },

        /**
         * Process cleanup batches for different operations
         * @param {string} operation - The cleanup operation type ('trashed', 'drafted', 'old-published')
         * @param {number} offset - Current offset for batch processing
         * @param {number} batchSize - Size of batch to process
         */
        processCleanupBatch: function(operation, offset, batchSize) {
            var actionMap = {
                'trashed': 'cleanup_trashed_jobs',
                'drafted': 'cleanup_drafted_jobs',
                'old-published': 'cleanup_old_published_jobs'
            };

            var action = actionMap[operation];
            if (!action) {
                PuntWorkJSLogger.error('Unknown cleanup operation', 'LOGIC', operation);
                return;
            }

            var isContinue = offset > 0;
            var apiCall = JobImportAPI.call(action, {
                offset: offset,
                batch_size: batchSize,
                is_continue: isContinue
            });

            apiCall.then(function(response) {
                PuntWorkJSLogger.debug('Cleanup response', 'LOGIC', response);

                if (response.success) {
                    // Update progress UI
                    JobImportUI.updateCleanupProgress(response.data);
                    JobImportUI.appendCleanupLogs(response.data.logs || []);

                    if (response.data.complete) {
                        // Operation completed
                        $('#cleanup-status').text('Cleanup completed: ' + response.data.total_deleted + ' jobs deleted');

                        // Reset button states
                        var buttonMap = {
                            'trashed': '#cleanup-trashed',
                            'drafted': '#cleanup-drafted',
                            'old-published': '#cleanup-old-published'
                        };
                        var textMap = {
                            'trashed': '#cleanup-trashed-text',
                            'drafted': '#cleanup-drafted-text',
                            'old-published': '#cleanup-old-published-text'
                        };
                        var loadingMap = {
                            'trashed': '#cleanup-trashed-loading',
                            'drafted': '#cleanup-drafted-loading',
                            'old-published': '#cleanup-old-published-loading'
                        };

                        $(buttonMap[operation]).prop('disabled', false);
                        $(textMap[operation]).show();
                        $(loadingMap[operation]).hide();

                        // Clear cleanup progress after a delay
                        setTimeout(function() {
                            JobImportUI.clearCleanupProgress();
                        }, 3000);

                    } else {
                        // Update status and continue with next batch
                        $('#cleanup-status').text('Progress: ' + response.data.progress_percentage + '% (' +
                            response.data.total_processed + '/' + response.data.total_jobs + ' jobs processed)');

                        // Continue with next batch
                        JobImportLogic.processCleanupBatch(operation, response.data.next_offset, batchSize);
                    }
                } else {
                    $('#cleanup-status').text('Cleanup failed: ' + (response.data || 'Unknown error'));

                    // Reset button states on failure
                    var buttonMap = {
                        'trashed': '#cleanup-trashed',
                        'drafted': '#cleanup-drafted',
                        'old-published': '#cleanup-old-published'
                    };
                    var textMap = {
                        'trashed': '#cleanup-trashed-text',
                        'drafted': '#cleanup-drafted-text',
                        'old-published': '#cleanup-old-published-text'
                    };
                    var loadingMap = {
                        'trashed': '#cleanup-trashed-loading',
                        'drafted': '#cleanup-drafted-loading',
                        'old-published': '#cleanup-old-published-loading'
                    };

                    $(buttonMap[operation]).prop('disabled', false);
                    $(textMap[operation]).show();
                    $(loadingMap[operation]).hide();
                    JobImportUI.clearCleanupProgress();
                }
            }).catch(function(xhr, status, error) {
                PuntWorkJSLogger.error('Cleanup AJAX error', 'LOGIC', error);
                $('#cleanup-status').text('Cleanup failed: ' + error);
                JobImportUI.appendCleanupLogs(['Cleanup AJAX error: ' + error]);

                // Reset button states on error
                var buttonMap = {
                    'trashed': '#cleanup-trashed',
                    'drafted': '#cleanup-drafted',
                    'old-published': '#cleanup-old-published'
                };
                var textMap = {
                    'trashed': '#cleanup-trashed-text',
                    'drafted': '#cleanup-drafted-text',
                    'old-published': '#cleanup-old-published-text'
                };
                var loadingMap = {
                    'trashed': '#cleanup-trashed-loading',
                    'drafted': '#cleanup-drafted-loading',
                    'old-published': '#cleanup-old-published-loading'
                };

                $(buttonMap[operation]).prop('disabled', false);
                $(textMap[operation]).show();
                $(loadingMap[operation]).hide();
                JobImportUI.clearCleanupProgress();
            });
        }
    };

    // Expose to global scope
    window.JobImportLogic = JobImportLogic;

})(jQuery, window, document);