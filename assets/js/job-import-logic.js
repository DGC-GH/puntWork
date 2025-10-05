/**
 * Job Import Admin - Logic Module
 * Handles core import processing logic and batch management
 */

/* Added detailed debug logs and error handling for the import process */
console.info("=== Job Import Logic Script Loaded ===");

// Debug function disabled for production - uncomment only for debugging
/*
(function debugImportProcess(){
    console.info("Import process started at " + new Date().toISOString());

    // JSONL combination stage
    console.log("Starting JSONL combination...");
    try {
        // ...existing JSONL combination logic...
        // For debugging purposes, using a placeholder for total items
        var totalItems = 7486; // This should ideally come from the actual data
        console.log("Combined JSONL (" + totalItems + " items)");
    } catch(e) {
        console.error("Error during JSONL combination:", e);
    }

    // Batch import processing stage
    console.debug("Starting batch import processing...");
    try {
        // ...existing batch processing logic...
        console.log("Batch import processing completed successfully.");
    } catch(e) {
        console.error("Error during batch import processing:", e);
    }

    // Finalizing import stage
    console.debug("Finalizing import...");
    try {
        // ...existing finalization logic...
        console.log("Import complete");
    } catch(e) {
        console.error("Error finalizing import:", e);
    }

    console.info("Import process ended at " + new Date().toISOString());
})();
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
            console.log('[PUNTWORK] ===== STARTING BATCH IMPORT =====');
            console.log('[PUNTWORK] Initial start index:', initialStart);
            PuntWorkJSLogger.info('Handling import starting at: ' + initialStart, 'LOGIC');
            let response;

            try {
                console.log('[PUNTWORK] ===== PHASE: INITIAL BATCH API CALL =====');
                console.log('[PUNTWORK] Calling JobImportAPI.runImportBatch with start:', initialStart);
                console.log('[PUNTWORK] PuntWork: AJAX request data: {action: `run_job_import_batch`, start: ' + initialStart + '}');
                const batchStartTime = Date.now();
                const initialBatchTimeout = 600000; // 10 minutes for initial batch
                const initialBatchPromise = JobImportAPI.runImportBatch(initialStart);
                const initialTimeoutPromise = new Promise((_, reject) => {
                    setTimeout(() => reject(new Error('Initial batch processing timeout after ' + (initialBatchTimeout/1000) + ' seconds')), initialBatchTimeout);
                });
                
                response = await Promise.race([initialBatchPromise, initialTimeoutPromise]);
                const batchEndTime = Date.now();
                const initialBatchDuration = (batchEndTime - batchStartTime) / 1000;
                console.log('[PUNTWORK] Initial batch API call completed in', initialBatchDuration.toFixed(2), 'seconds');
                console.log('[PUNTWORK] Batch API response received:', response);
                PuntWorkJSLogger.debug('Import batch response', 'LOGIC', response);
                console.log('[PUNTWORK] Import batch response:', response);

                if (response.success) {
                    console.log('[PUNTWORK] Batch successful - processed:', response.data.processed, 'total:', response.data.total);
                    if ((response.data && response.data.processed === 0 && response.data.total > 0) || (response.data && response.data.total > 0 && !response.data.processed)) {
                        console.warn('[PUNTWORK] WARNING: Import batch returned success but processed 0 items out of', response.data.total, response);
                        PuntWorkJSLogger.warn('Import batch returned success but processed 0 items', 'LOGIC', response);
                        JobImportUI.appendLogs(['WARNING: Import batch returned success but processed 0 items out of ' + response.data.total]);
                    }
                    // Status polling will handle UI updates, just log the response
                    const statusResponse = await JobImportAPI.getImportStatus();
                    if (statusResponse.success) {
                        var batchData = JobImportUI.normalizeResponseData(statusResponse);
                        console.log('[PUNTWORK] Status after batch - published:', batchData.published, 'updated:', batchData.updated, 'total:', batchData.total);
                    } else {
                        console.log('[PUNTWORK] Status fetch failed after batch, continuing...', statusResponse);
                        PuntWorkJSLogger.warn('Status fetch failed after batch', 'LOGIC', statusResponse);
                        JobImportUI.appendLogs(['WARNING: Status fetch failed after batch']);
                    }

                    let total = response.data.total || 0;
                    let current = response.data.processed || 0;
                    console.log('[PUNTWORK] Continuing batch processing - current:', current, 'total:', total);
                    PuntWorkJSLogger.debug('Initial current: ' + current + ', total: ' + total, 'LOGIC');

                    let batchCount = 0;
                    let previousProcessed = 0;
                    let noProgressCount = 0;
                    const maxNoProgressBatches = 5; // Break loop if no progress for 5 consecutive batches
                    
                    while (current < total && this.isImporting) {
                        batchCount++;
                        console.log('[PUNTWORK] ===== STARTING BATCH', batchCount, '=====');
                        console.log('[PUNTWORK] Batch timing - previous batch time will be compared for dynamic sizing');
                        console.log('[PUNTWORK] Current progress: processed', current, 'of', total, '(' + ((current/total)*100).toFixed(1) + '%)');
                        PuntWorkJSLogger.debug('Continuing to next batch, current: ' + current + ', total: ' + total + ', batchCount: ' + batchCount, 'LOGIC');
                        try {
                            const batchStartTime = Date.now();
                            console.log('[PUNTWORK] Batch', batchCount, 'starting at', new Date().toISOString());

                            // Set a timeout for the batch processing
                            const batchTimeout = 600000; // 10 minutes timeout (increased from 5 minutes)
                            const batchPromise = JobImportAPI.runImportBatch(current);
                            const timeoutPromise = new Promise((_, reject) => {
                                setTimeout(() => reject(new Error('Batch processing timeout after ' + (batchTimeout/1000) + ' seconds')), batchTimeout);
                            });

                            response = await Promise.race([batchPromise, timeoutPromise]);
                            const batchEndTime = Date.now();
                            const batchDuration = (batchEndTime - batchStartTime) / 1000;
                            console.log('[PUNTWORK] Batch', batchCount, 'API call completed in', batchDuration.toFixed(2), 'seconds');
                            console.log('[PUNTWORK] Batch', batchCount, 'response:', response);
                            PuntWorkJSLogger.debug('Next batch response', 'LOGIC', response);
                            console.log('[PUNTWORK] Next batch response:', response);

                            if (response.success) {
                                // Calculate how many items were processed in this batch
                                const newProcessed = response.data.processed || 0;
                                const batchProcessed = newProcessed - previousProcessed;
                                
                                // Update current position
                                current += batchProcessed;
                                previousProcessed = newProcessed;
                                
                                console.log('[PUNTWORK] Batch', batchCount, 'completed - batch processed:', batchProcessed, 'total processed so far:', current, 'batchCount:', batchCount, 'duration:', batchDuration.toFixed(2) + 's');

                                // Check for progress
                                if (batchProcessed === 0) {
                                    noProgressCount++;
                                    console.warn('[PUNTWORK] WARNING: Batch', batchCount, 'processed 0 items (no progress). Count:', noProgressCount);
                                    
                                    if (noProgressCount >= maxNoProgressBatches) {
                                        console.error('[PUNTWORK] ERROR: No progress made in', maxNoProgressBatches, 'consecutive batches. Breaking loop to prevent infinite loop.');
                                        JobImportUI.appendLogs(['ERROR: Import appears stuck - no progress made in ' + maxNoProgressBatches + ' consecutive batches']);
                                        $('#status-message').text('Import stuck - no progress being made');
                                        this.isImporting = false;
                                        break;
                                    }
                                } else {
                                    noProgressCount = 0; // Reset counter on successful progress
                                }

                                // Status polling handles UI updates, just update our local tracking
                                console.log('[PUNTWORK] Batch', batchCount, 'completed - current processed:', current, 'batchCount:', batchCount, 'duration:', batchDuration.toFixed(2) + 's');

                                // Force a status update to ensure UI is current
                                try {
                                    const statusResponse = await JobImportAPI.getImportStatus();
                                    if (statusResponse.success) {
                                        var batchData = JobImportUI.normalizeResponseData(statusResponse);
                                        console.log('[PUNTWORK] Status after batch', batchCount, '- published:', batchData.published, 'updated:', batchData.updated, 'total:', batchData.total);
                                        JobImportUI.updateProgress(batchData);
                                        JobImportUI.appendLogs(batchData.logs || []);
                                    }
                                } catch (statusError) {
                                    console.warn('[PUNTWORK] Could not get status after batch', batchCount, ':', statusError);
                                }

                                if ((response.data && response.data.processed === 0 && response.data.total > 0) || (response.data && response.data.total > 0 && !response.data.processed)) {
                                    console.warn('[PUNTWORK] WARNING: Batch', batchCount, 'returned success but processed 0 items out of', response.data.total, response);
                                    PuntWorkJSLogger.warn('Batch ' + batchCount + ' returned success but processed 0 items', 'LOGIC', response);
                                    JobImportUI.appendLogs(['WARNING: Batch ' + batchCount + ' returned success but processed 0 items out of ' + response.data.total]);
                                }
                            } else {
                                console.error('[PUNTWORK] ERROR: Import batch failed:', response);
                                throw new Error('Import batch failed: ' + (response.message || 'Unknown error'));
                            }
                        } catch (batchError) {
                            console.log('[PUNTWORK] Batch', batchCount, 'error:', batchError);
                            PuntWorkJSLogger.error('Batch processing error', 'LOGIC', batchError);
                            console.error('[PUNTWORK] Batch processing error:', batchError);
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

                                // Log failed import to history
                                await this.logFailedManualImportRun({ message: 'Batch failed after ' + batchError.attempts + ' attempts: ' + batchError.error });

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
                        console.log('[PUNTWORK] ===== IMPORT COMPLETED SUCCESSFULLY =====');
                        console.log('[PUNTWORK] Final stats - total:', total, 'processed:', current);
                        PuntWorkJSLogger.info('Import completed successfully', 'LOGIC', {
                            total: total,
                            processed: current
                        });
                        JobImportUI.appendLogs(['Import completed successfully. Total: ' + total + ', Processed: ' + current]);
                        await this.handleImportCompletion();
                    }
                } else {
                    console.error('[PUNTWORK] ERROR: Initial import batch error:', response);
                    JobImportUI.appendLogs(['Initial import batch error: ' + (response.message || 'Unknown')]);
                    $('#status-message').text('Error: ' + (response.message || 'Unknown'));
                    JobImportUI.resetButtons();

                    // Stop status polling on error
                    if (window.JobImportEvents && window.JobImportEvents.stopStatusPolling) {
                        window.JobImportEvents.stopStatusPolling();
                    }
                }
            } catch (e) {
                console.error('[PUNTWORK] ===== IMPORT PROCESS ERROR =====');
                console.error('[PUNTWORK] Error details:', e);
                PuntWorkJSLogger.error('Handle import error', 'LOGIC', e);
                console.error('[PUNTWORK] Handle import error:', e);

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

                // Disconnect from real-time updates on error
                if (window.JobImportRealtime && JobImportRealtime.getConnectionStatus()) {
                    JobImportRealtime.disconnect();
                    PuntWorkJSLogger.info('Real-time updates disconnected - import error', 'LOGIC');
                }
            }
        },

        /**
         * Handle import completion and cleanup
         */
        handleImportCompletion: async function() {
            console.log('[PUNTWORK] ===== IMPORT COMPLETION STARTED =====');
            JobImportUI.appendLogs(['Finalizing import...']);

            try {
                // Small delay to ensure database is updated
                await new Promise(resolve => setTimeout(resolve, 500));

                const finalResponse = await JobImportAPI.getImportStatus();
                console.log('[PUNTWORK] Final status response:', finalResponse);
                PuntWorkJSLogger.debug('Final status response', 'LOGIC', finalResponse);

                if (finalResponse.success) {
                    // Handle both response formats: direct data or wrapped in .data
                    var statusData = JobImportUI.normalizeResponseData(finalResponse);
                    console.log('[PUNTWORK] Final import stats - published:', statusData.published, 'updated:', statusData.updated, 'skipped:', statusData.skipped);
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

                    // Log the manual import run to history
                    await this.logManualImportRun(statusData);
                } else {
                    console.error('[PUNTWORK] Failed to get final status:', finalResponse);
                    PuntWorkJSLogger.error('Failed to get final status', 'LOGIC', finalResponse);
                    JobImportUI.appendLogs(['Failed to get final status']);
                }
            } catch (error) {
                console.error('[PUNTWORK] Final status error:', error);
                PuntWorkJSLogger.error('Final status AJAX error', 'LOGIC', error);
                JobImportUI.appendLogs(['Final status AJAX error: ' + error]);
            }

            console.log('[PUNTWORK] ===== IMPORT COMPLETION FINISHED =====');
            JobImportUI.appendLogs(['Import complete']);
            $('#status-message').text('Import Complete');
            JobImportUI.resetButtons();
            this.isImporting = false; // Reset importing flag on completion
            
            // Stop status polling on completion
            if (window.JobImportEvents && window.JobImportEvents.stopStatusPolling) {
                window.JobImportEvents.stopStatusPolling();
            }
            
            // Disconnect from real-time updates on completion
            if (window.JobImportRealtime && JobImportRealtime.getConnectionStatus()) {
                JobImportRealtime.disconnect();
                PuntWorkJSLogger.info('Real-time updates disconnected - import completed', 'LOGIC');
            }
        },

        /**
         * Handle the start import process
         * @returns {Promise} Start import process promise
         */
        handleStartImport: async function() {
            console.log('[PUNTWORK] [UI-CLICK] Import button clicked by user ' + (window.jobImportData?.current_user_id || 'unknown'));
            console.log('[PUNTWORK] [DEBUG-IMPORT] ===== START IMPORT PROCESS =====');
            console.log('[PUNTWORK] [DEBUG-IMPORT] Timestamp:', new Date().toISOString());
            console.log('[PUNTWORK] [DEBUG-IMPORT] Browser:', navigator.userAgent);
            console.log('[PUNTWORK] [DEBUG-IMPORT] Window location:', window.location.href);
            console.log('[PUNTWORK] [DEBUG-IMPORT] jQuery version:', $.fn.jquery);
            console.log('[PUNTWORK] [DEBUG-IMPORT] Current isImporting flag:', this.isImporting);
            console.log('[PUNTWORK] [DEBUG-IMPORT] Start time:', this.startTime);
            console.log('[PUNTWORK] [DEBUG-IMPORT] Elapsed time:', this.getElapsedTime());
            console.log('[PUNTWORK] [DEBUG-IMPORT] jobImportData:', jobImportData);
            console.log('[PUNTWORK] [DEBUG-IMPORT] Feeds count:', Object.keys(jobImportData.feeds || {}).length);
            console.log('[PUNTWORK] [DEBUG-IMPORT] Nonce:', jobImportData.nonce);
            console.log('[PUNTWORK] [DEBUG-IMPORT] AjaxURL:', jobImportData.ajaxurl);

            PuntWorkJSLogger.info('Start Import clicked', 'LOGIC');
            console.log('[PUNTWORK] [DEBUG-IMPORT] Start Import clicked - checking import status');

            if (this.isImporting) {
                console.log('[PUNTWORK] [DEBUG-IMPORT] ERROR: Import already in progress - blocking start');
                console.log('[PUNTWORK] [DEBUG-IMPORT] isImporting:', this.isImporting);
                console.log('[PUNTWORK] [DEBUG-IMPORT] startTime:', this.startTime);
                console.log('[PUNTWORK] [DEBUG-IMPORT] elapsed time:', this.getElapsedTime());
                console.log('[PUNTWORK] [DEBUG-IMPORT] Checking server status to verify if import is actually running...');

                PuntWorkJSLogger.warn('Import already in progress - blocking start', 'LOGIC', {
                    isImporting: this.isImporting,
                    startTime: this.startTime,
                    elapsed: this.getElapsedTime()
                });

                // Check server status to see if import is actually running
                try {
                    const statusResponse = await JobImportAPI.getImportStatus();
                    console.log('[PUNTWORK] [DEBUG-IMPORT] Server status check response:', statusResponse);
                    
                    if (statusResponse.success && statusResponse.data) {
                        const serverData = JobImportUI.normalizeResponseData(statusResponse);
                        console.log('[PUNTWORK] [DEBUG-IMPORT] Server status - total:', serverData.total, 'processed:', serverData.processed, 'complete:', serverData.complete);
                        
                        // If server shows import is complete or not started, force reset the client flag
                        if (serverData.complete || (serverData.total === 0 && serverData.processed === 0)) {
                            console.log('[PUNTWORK] [DEBUG-IMPORT] Server shows import is not running, forcing client reset');
                            this.isImporting = false;
                            this.startTime = null;
                            PuntWorkJSLogger.warn('Forced client reset - server shows import not running', 'LOGIC');
                            console.log('[PUNTWORK] [DEBUG-IMPORT] Client reset complete, continuing with import');
                        } else {
                            // Force reset the flag if it's been too long (stuck import)
                            if (this.startTime && (Date.now() - this.startTime) > 300000) { // 5 minutes
                                console.log('[PUNTWORK] [DEBUG-IMPORT] Import has been running for >5 minutes, forcing reset');
                                this.isImporting = false;
                                this.startTime = null;
                                PuntWorkJSLogger.warn('Forcing import flag reset due to timeout', 'LOGIC');
                                console.log('[PUNTWORK] [DEBUG-IMPORT] Forced reset complete, continuing with import');
                            } else {
                                console.log('[PUNTWORK] [DEBUG-IMPORT] Import appears to be genuinely running, blocking start');
                                return;
                            }
                        }
                    } else {
                        console.log('[PUNTWORK] [DEBUG-IMPORT] Could not get server status, assuming import is stuck and forcing reset');
                        this.isImporting = false;
                        this.startTime = null;
                        PuntWorkJSLogger.warn('Forced reset due to server status check failure', 'LOGIC');
                        console.log('[PUNTWORK] [DEBUG-IMPORT] Forced reset complete, continuing with import');
                    }
                } catch (statusError) {
                    console.log('[PUNTWORK] [DEBUG-IMPORT] Error checking server status:', statusError);
                    console.log('[PUNTWORK] [DEBUG-IMPORT] Assuming import is stuck and forcing reset');
                    this.isImporting = false;
                    this.startTime = null;
                    PuntWorkJSLogger.warn('Forced reset due to status check error', 'LOGIC');
                    console.log('[PUNTWORK] [DEBUG-IMPORT] Forced reset complete, continuing with import');
                }
            }

            console.log('[PUNTWORK] [DEBUG-IMPORT] Import not in progress, proceeding...');
            this.isImporting = true;
            this.startTime = Date.now();
            console.log('[PUNTWORK] [DEBUG-IMPORT] Set isImporting to true, startTime:', this.startTime);

            try {
                console.log('[PUNTWORK] [DEBUG-IMPORT] ===== PHASE 0: UI SETUP =====');
                console.log('[PUNTWORK] [DEBUG-IMPORT] Clearing progress and setting up UI');
                JobImportUI.clearProgress();
                JobImportUI.setPhase('feed-processing');
                $('#start-import').hide();
                $('#resume-import').hide();
                $('#cancel-import').show();
                $('#reset-import').show();
                JobImportUI.showImportUI();
                $('#status-message').text('Initializing import...');
                console.log('[PUNTWORK] [DEBUG-IMPORT] UI setup complete');

                console.log('[PUNTWORK] [DEBUG-IMPORT] ===== PHASE 0.1: IMPORT RESET =====');
                console.log('[PUNTWORK] [DEBUG-IMPORT] About to reset import via API');

                // Reset import
                const resetStartTime = Date.now();
                const resetResponse = await JobImportAPI.resetImport();
                const resetEndTime = Date.now();
                console.log('[PUNTWORK] [DEBUG-IMPORT] Reset API call took:', (resetEndTime - resetStartTime), 'ms');
                console.log('[PUNTWORK] [DEBUG-IMPORT] Reset API response:', resetResponse);
                console.log('[PUNTWORK] [DEBUG-IMPORT] Reset response success:', resetResponse.success);
                console.log('[PUNTWORK] [DEBUG-IMPORT] Reset response message:', resetResponse.message);

                if (resetResponse.success) {
                    console.log('[PUNTWORK] [DEBUG-IMPORT] Import reset successful');
                    JobImportUI.appendLogs(['Import reset for fresh start']);
                } else {
                    console.warn('[PUNTWORK] [DEBUG-IMPORT] Import reset failed:', resetResponse);
                    JobImportUI.appendLogs(['Warning: Import reset failed - ' + (resetResponse.message || 'Unknown error')]);
                }

                console.log('[PUNTWORK] [DEBUG-IMPORT] ===== PHASE 0.2: STATUS RESET =====');
                console.log('[PUNTWORK] [DEBUG-IMPORT] About to reset status for real-time updates');

                // Additional status reset for real-time updates
                const statusResetStartTime = Date.now();
                const statusResetResponse = await $.ajax({
                    url: jobImportData.ajaxurl,
                    type: 'POST',
                    data: { action: 'reset_job_import_status', nonce: jobImportData.nonce },
                    timeout: 30000
                });
                const statusResetEndTime = Date.now();
                console.log('[PUNTWORK] [DEBUG-IMPORT] Status reset API call took:', (statusResetEndTime - statusResetStartTime), 'ms');
                console.log('[PUNTWORK] [DEBUG-IMPORT] Status reset API response:', statusResetResponse);
                console.log('[PUNTWORK] [DEBUG-IMPORT] Status reset response success:', statusResetResponse.success);

                if (statusResetResponse.success) {
                    console.log('[PUNTWORK] [DEBUG-IMPORT] Status reset for real-time updates successful');
                    JobImportUI.appendLogs(['Import status reset for real-time updates']);
                } else {
                    console.warn('[PUNTWORK] [DEBUG-IMPORT] Status reset failed:', statusResetResponse);
                }

                // Always update combined JSONL from feeds - never skip feed processing
                console.log('[PUNTWORK] [DEBUG-IMPORT] ===== PHASE 0.3: CHECK EXISTING DATA =====');
                console.log('[PUNTWORK] [DEBUG-IMPORT] Checking if combined JSONL file exists (but will always process feeds)');

                const checkDataStartTime = Date.now();
                const checkDataResponse = await $.ajax({
                    url: jobImportData.ajaxurl,
                    type: 'POST',
                    data: { action: 'check_import_data_status', nonce: jobImportData.nonce },
                    timeout: 30000
                });
                const checkDataEndTime = Date.now();
                console.log('[PUNTWORK] [DEBUG-IMPORT] Check data status API call took:', (checkDataEndTime - checkDataStartTime), 'ms');
                console.log('[PUNTWORK] [DEBUG-IMPORT] Check data response:', checkDataResponse);

                let skipFeedProcessing = false; // Always process feeds to update combined JSONL
                let total_items = 0;

                if (checkDataResponse.success && checkDataResponse.data) {
                    const dataStatus = checkDataResponse.data;
                    console.log('[PUNTWORK] [DEBUG-IMPORT] Data status check results:', dataStatus);

                    // Always process feeds to ensure combined JSONL is fresh from feeds
                    console.log('[PUNTWORK] [DEBUG-IMPORT] Always processing feeds to update combined JSONL from latest feed data');
                    skipFeedProcessing = false;
                    JobImportUI.appendLogs(['Updating combined JSONL from feeds (always fresh data)']);
                } else {
                    console.log('[PUNTWORK] [DEBUG-IMPORT] Could not check data status - proceeding with feed processing');
                    skipFeedProcessing = false;
                }

                // Always process feeds to update combined JSONL from feeds
                console.log('[PUNTWORK] [DEBUG-IMPORT] ===== PHASE 1: FEED PROCESSING =====');
                JobImportUI.appendLogs(['Starting feed processing...']);
                $('#status-message').text('Processing feeds...');

                // Process feeds using Action Scheduler
                const feedKeys = Object.keys(jobImportData.feeds);
                console.log('[PUNTWORK] [DEBUG-IMPORT] Scheduling feed processing for:', feedKeys);
                console.log('[PUNTWORK] [DEBUG-IMPORT] Number of feeds to process:', feedKeys.length);

                JobImportUI.appendLogs(['Scheduling ' + feedKeys.length + ' feeds for background processing...']);

                // Schedule feed processing
                const scheduleStartTime = Date.now();
                const scheduleResponse = await JobImportAPI.scheduleFeedProcessing(feedKeys);
                const scheduleEndTime = Date.now();
                console.log('[PUNTWORK] [DEBUG-IMPORT] Schedule feed processing took:', (scheduleEndTime - scheduleStartTime), 'ms');
                console.log('[PUNTWORK] [DEBUG-IMPORT] Schedule response:', scheduleResponse);

                if (!scheduleResponse.success) {
                    console.error('[PUNTWORK] [DEBUG-IMPORT] ERROR: Feed scheduling failed:', scheduleResponse);
                    throw new Error('Feed scheduling failed: ' + (scheduleResponse.message || 'Unknown error'));
                }

                JobImportUI.appendLogs(['Feed processing scheduled successfully']);
                JobImportUI.appendLogs(['Monitoring feed processing progress...']);

                // Monitor feed processing progress
                const maxWaitTime = 15 * 60 * 1000; // 15 minutes max wait
                const checkInterval = 5000; // Check every 5 seconds
                const startWaitTime = Date.now();

                while (true) {
                    const elapsed = Date.now() - startWaitTime;
                    if (elapsed > maxWaitTime) {
                        console.error('[PUNTWORK] [DEBUG-IMPORT] ERROR: Feed processing timeout after', elapsed / 1000, 'seconds');
                        throw new Error('Feed processing timed out after ' + Math.round(elapsed / 1000) + ' seconds');
                    }

                    console.log('[PUNTWORK] [DEBUG-IMPORT] Checking feed processing status...');
                    const statusResponse = await JobImportAPI.getFeedProcessingStatus(feedKeys);
                    console.log('[PUNTWORK] [DEBUG-IMPORT] Feed status response:', statusResponse);

                    if (!statusResponse.success) {
                        console.error('[PUNTWORK] [DEBUG-IMPORT] ERROR: Failed to get feed status:', statusResponse);
                        throw new Error('Failed to get feed processing status: ' + (statusResponse.message || 'Unknown error'));
                    }

                    let allComplete = true;
                    let processingCount = 0;
                    let completedCount = 0;
                    total_items = 0;

                    for (const feedKey of feedKeys) {
                        const status = statusResponse.data[feedKey];
                        console.log('[PUNTWORK] [DEBUG-IMPORT] Feed', feedKey, 'status:', status);

                        if (status && status.success) {
                            completedCount++;
                            total_items += status.item_count || 0;
                            JobImportUI.appendLogs(['Feed ' + feedKey + ' completed: ' + (status.item_count || 0) + ' items']);
                        } else if (status && status.status === 'processing') {
                            processingCount++;
                            allComplete = false;
                        } else if (!status || status.status === 'pending') {
                            allComplete = false;
                        } else if (status && !status.success) {
                            console.error('[PUNTWORK] [DEBUG-IMPORT] ERROR: Feed', feedKey, 'failed:', status.error);
                            throw new Error('Feed ' + feedKey + ' processing failed: ' + (status.error || 'Unknown error'));
                        }
                    }

                    console.log('[PUNTWORK] [DEBUG-IMPORT] Feed progress: completed=' + completedCount + ', processing=' + processingCount + ', total_feeds=' + feedKeys.length);

                    // Update progress UI
                    JobImportUI.updateProgress({
                        total: feedKeys.length,
                        processed: completedCount,
                        published: 0,
                        updated: 0,
                        skipped: 0,
                        duplicates_drafted: 0,
                        drafted_old: 0,
                        time_elapsed: this.getElapsedTime() / 1000,
                        complete: false
                    });

                    $('#status-message').text(`Processing feeds: ${completedCount}/${feedKeys.length} completed`);

                    if (allComplete) {
                        console.log('[PUNTWORK] [DEBUG-IMPORT] All feeds completed successfully');
                        break;
                    }

                    // Wait before checking again
                    await new Promise(resolve => setTimeout(resolve, checkInterval));
                }

                console.log('[PUNTWORK] [DEBUG-IMPORT] ===== PHASE 1 COMPLETE =====');
                console.log('[PUNTWORK] [DEBUG-IMPORT] Total items after feed processing:', total_items);

                if (total_items === 0) {
                    console.error('[PUNTWORK] [DEBUG-IMPORT] ERROR: No items found in feeds after processing');
                    throw new Error('No items found in feeds. Please check that feeds are configured and accessible.');
                }

                // Always combine JSONL after feed processing
                console.log('[PUNTWORK] [DEBUG-IMPORT] ===== PHASE 2: JSONL COMBINATION =====');
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
                    time_elapsed: this.getElapsedTime() / 1000,
                    complete: false
                });

                console.log('[PUNTWORK] [DEBUG-IMPORT] About to call JobImportAPI.combineJsonl with total_items=' + total_items);
                const combineStartTime = Date.now();
                const combineResponse = await JobImportAPI.combineJsonl(total_items);
                const combineEndTime = Date.now();
                console.log('[PUNTWORK] [DEBUG-IMPORT] Combine JSONL call took:', (combineEndTime - combineStartTime), 'ms');
                console.log('[PUNTWORK] [DEBUG-IMPORT] Combine JSONL response:', combineResponse);
                console.log('[PUNTWORK] [DEBUG-IMPORT] Combine response success:', combineResponse.success);

                PuntWorkJSLogger.debug('Combine JSONL response', 'LOGIC', combineResponse);

                if (combineResponse.success) {
                    console.log('[PUNTWORK] [DEBUG-IMPORT] JSONL combination successful - import scheduled in background');
                    JobImportUI.appendLogs(combineResponse.data.logs || []);

                    // Update progress to show JSONL combination complete and import scheduled
                    JobImportUI.updateProgress({
                        total: total_items,
                        processed: 0,
                        published: 0,
                        updated: 0,
                        skipped: 0,
                        duplicates_drafted: 0,
                        drafted_old: 0,
                        time_elapsed: this.getElapsedTime() / 1000,
                        complete: false,
                        phase: 'jsonl-combining'
                    });

                    console.log('[PUNTWORK] [DEBUG-IMPORT] ===== PHASE 3: BACKGROUND IMPORT =====');
                    $('#status-message').text('JSONL combined - starting background import...');
                    JobImportUI.appendLogs(['JSONL files combined successfully']);
                    JobImportUI.appendLogs(['Background import scheduled and starting...']);

                    // Start status polling to monitor the background import progress
                    if (window.JobImportEvents && window.JobImportEvents.startStatusPolling) {
                        console.log('[PUNTWORK] [DEBUG-IMPORT] Starting status polling for background import progress');
                        window.JobImportEvents.startStatusPolling();
                        console.log('[PUNTWORK] [DEBUG-IMPORT] Status polling started successfully');
                    } else {
                        console.log('[PUNTWORK] [DEBUG-IMPORT] Status polling not available');
                    }

                    console.log('[PUNTWORK] [DEBUG-IMPORT] ===== START IMPORT PROCESS COMPLETE =====');
                } else {
                    console.error('[PUNTWORK] [DEBUG-IMPORT] JSONL combination failed:', combineResponse);
                    throw new Error('Combining JSONL failed: ' + (combineResponse.message || 'Unknown error'));
                }

                console.log('[PUNTWORK] [DEBUG-IMPORT] About to clear import cancel');
                await JobImportAPI.clearImportCancel();
                console.log('[PUNTWORK] [DEBUG-IMPORT] Import cancel cleared');

                // Connect to real-time updates for import progress
                if (window.JobImportRealtime && JobImportRealtime.isSupported()) {
                    console.log('[PUNTWORK] [DEBUG-IMPORT] Real-time updates supported, attempting to connect');
                    let apiKey = jobImportData.api_key;
                    if (!apiKey) {
                        console.log('[PUNTWORK] [DEBUG-IMPORT] No API key in jobImportData, trying to get from server');
                        try {
                            const keyResponse = await JobImportAPI.getApiKey();
                            if (keyResponse.success && keyResponse.api_key) {
                                apiKey = keyResponse.api_key;
                                console.log('[PUNTWORK] [DEBUG-IMPORT] Got API key from server');
                            }
                        } catch (e) {
                            console.warn('[PUNTWORK] [DEBUG-IMPORT] Could not get API key for real-time updates:', e);
                        }
                    }

                    if (apiKey) {
                        console.log('[PUNTWORK] [DEBUG-IMPORT] Connecting to real-time updates with API key');
                        const connected = JobImportRealtime.connect(apiKey);
                        if (connected) {
                            console.log('[PUNTWORK] [DEBUG-IMPORT] Real-time updates connected successfully');
                            PuntWorkJSLogger.info('Real-time updates connected for import progress', 'LOGIC');
                        } else {
                            console.warn('[PUNTWORK] [DEBUG-IMPORT] Failed to connect to real-time updates');
                            PuntWorkJSLogger.warn('Failed to connect to real-time updates', 'LOGIC');
                        }
                    } else {
                        console.warn('[PUNTWORK] [DEBUG-IMPORT] No API key available for real-time updates');
                        PuntWorkJSLogger.warn('No API key available for real-time updates', 'LOGIC');
                    }
                } else {
                    console.warn('[PUNTWORK] [DEBUG-IMPORT] Real-time updates not supported in this browser');
                    PuntWorkJSLogger.warn('Real-time updates not supported in this browser', 'LOGIC');
                }

                console.log('[PUNTWORK] [DEBUG-IMPORT] Import started automatically by server after JSONL combination');
                PuntWorkJSLogger.info('Import started automatically by server after JSONL combination', 'LOGIC');

                // Start status polling for real-time UI updates
                if (window.JobImportEvents && window.JobImportEvents.startStatusPolling) {
                    console.log('[PUNTWORK] [DEBUG-IMPORT] Starting status polling for real-time UI updates');
                    window.JobImportEvents.startStatusPolling();
                    console.log('[PUNTWORK] [DEBUG-IMPORT] Status polling started successfully');
                } else {
                    console.log('[PUNTWORK] [DEBUG-IMPORT] Status polling not available');
                }

                // Wait for Action Scheduler to complete the import - no manual batch processing needed
                console.log('[PUNTWORK] [DEBUG-IMPORT] ===== START IMPORT PROCESS COMPLETE =====');

            } catch (error) {
                console.error('[PUNTWORK] [DEBUG-IMPORT] ===== START IMPORT ERROR =====');
                console.error('[PUNTWORK] [DEBUG-IMPORT] Error details:', error);
                console.error('[PUNTWORK] [DEBUG-IMPORT] Error message:', error.message);
                console.error('[PUNTWORK] [DEBUG-IMPORT] Error stack:', error.stack);
                console.error('[PUNTWORK] [DEBUG-IMPORT] Current isImporting before reset:', this.isImporting);

                PuntWorkJSLogger.error('Start import error', 'LOGIC', error);
                JobImportUI.appendLogs([error.message]);
                $('#status-message').text('Error: ' + error.message);
                JobImportUI.resetButtons();

                // Stop status polling on error
                if (window.JobImportEvents && window.JobImportEvents.stopStatusPolling) {
                    window.JobImportEvents.stopStatusPolling();
                }

                console.log('[PUNTWORK] [DEBUG-IMPORT] Resetting isImporting flag to false');
                this.isImporting = false;
                console.log('[PUNTWORK] [DEBUG-IMPORT] isImporting flag reset complete');

                // Log failed import to history
                console.log('[PUNTWORK] [DEBUG-IMPORT] Logging failed import run');
                await this.logFailedManualImportRun({ message: error.message });
                console.log('[PUNTWORK] [DEBUG-IMPORT] Failed import logging complete');
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

            try {
                await JobImportAPI.clearImportCancel();
                
                // Start status polling for real-time UI updates before resuming
                if (window.JobImportEvents && window.JobImportEvents.startStatusPolling) {
                    console.log('[PUNTWORK] Starting status polling for resume import');
                    window.JobImportEvents.startStatusPolling();
                }

                // For resume, we need to check if there's an existing Action Scheduler job or restart the process
                // Check current status first
                const statusResponse = await JobImportAPI.getImportStatus();
                if (statusResponse.success && statusResponse.data) {
                    const statusData = JobImportUI.normalizeResponseData(statusResponse);
                    
                    // If import is already complete, show completion
                    if (statusData.complete) {
                        console.log('[PUNTWORK] Import already complete on resume');
                        await this.handleImportCompletion();
                        return;
                    }
                    
                    // If there's progress but not complete, the Action Scheduler should still be running
                    // Just continue polling for updates
                    console.log('[PUNTWORK] Resuming import monitoring - Action Scheduler should handle processing');
                    PuntWorkJSLogger.info('Resuming import monitoring - letting Action Scheduler handle processing', 'LOGIC');
                }
            } catch (error) {
                PuntWorkJSLogger.error('Resume import error', 'LOGIC', error);
                JobImportUI.appendLogs([error.message]);
                $('#status-message').text('Error: ' + error.message);
                JobImportUI.resetButtons();
                this.isImporting = false; // Ensure importing flag is reset on error
                
                // Log failed import to history
                await this.logFailedManualImportRun({ message: error.message });
            }
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
                    
                    // Disconnect from real-time updates on cancel
                    if (window.JobImportRealtime && JobImportRealtime.getConnectionStatus()) {
                        JobImportRealtime.disconnect();
                        PuntWorkJSLogger.info('Real-time updates disconnected - import cancelled', 'LOGIC');
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
            this.startTime = null; // Also clear start time

            // Stop status polling
            if (window.JobImportEvents && window.JobImportEvents.stopStatusPolling) {
                window.JobImportEvents.stopStatusPolling();
            }
            
            // Disconnect from real-time updates
            if (window.JobImportRealtime && JobImportRealtime.getConnectionStatus()) {
                JobImportRealtime.disconnect();
                PuntWorkJSLogger.info('Real-time updates disconnected - import reset', 'LOGIC');
            }

            // Show loading state
            $('#reset-import').prop('disabled', true).text('Resetting...');

            console.log('[PUNTWORK] About to call JobImportAPI.resetImport()');
            PuntWorkJSLogger.info('Calling reset API', 'LOGIC');

            // Set a fallback timeout to reset button state if AJAX hangs
            var resetTimeout = setTimeout(function() {
                console.log('[PUNTWORK] Reset timeout reached - forcing button reset');
                PuntWorkJSLogger.warn('Reset timeout reached - forcing button reset', 'LOGIC');
                $('#reset-import').prop('disabled', false).text('Reset Import');
                $('#status-message').text('Reset timed out - please try again');
                JobImportUI.appendLogs(['Reset timed out - please try again']);
            }, 35000); // 35 seconds (longer than AJAX timeout)

            JobImportAPI.resetImport().then(function(response) {
                clearTimeout(resetTimeout); // Clear the fallback timeout
                console.log('[PUNTWORK] Reset API success response:', response);
                PuntWorkJSLogger.debug('Reset response', 'LOGIC', response);

                if (response.success) {
                    console.log('[PUNTWORK] Reset successful, updating UI');
                    JobImportUI.appendLogs(['Import system completely reset']);
                    $('#status-message').text('Import system reset - ready to start fresh');

                    // Clear all UI state
                    JobImportUI.clearProgress();
                    JobImportUI.hideImportUI();

                    // Reset all button states
                    $('#start-import').show().text('Start Import').prop('disabled', false);
                    $('#resume-import').hide();
                    $('#cancel-import').hide();
                    $('#reset-import').hide().prop('disabled', false).text('Reset Import');

                    console.log('[PUNTWORK] Button states updated - reset-import should be hidden and re-enabled');
                    console.log('[PUNTWORK] reset-import button state:', {
                        visible: $('#reset-import').is(':visible'),
                        disabled: $('#reset-import').prop('disabled'),
                        text: $('#reset-import').text()
                    });

                    // Force a fresh status check to ensure UI reflects the reset state
                    console.log('[PUNTWORK] Reset completed, forcing fresh status check');
                    setTimeout(function() {
                        JobImportEvents.checkInitialStatus();
                    }, 500);

                    console.log('[PUNTWORK] Reset completed successfully');
                    PuntWorkJSLogger.info('Reset completed successfully', 'LOGIC');
                } else {
                    console.log('[PUNTWORK] Reset API returned unsuccessful response:', response);
                    // Reset failed - show error but don't change UI state
                    JobImportUI.appendLogs(['Reset failed: ' + (response.message || 'Unknown error')]);
                    $('#status-message').text('Reset failed - please try again');
                    $('#reset-import').prop('disabled', false).text('Reset Import');
                    PuntWorkJSLogger.error('Reset API returned unsuccessful response', 'LOGIC', response);
                }
            }).catch(function(xhr, status, error) {
                clearTimeout(resetTimeout); // Clear the fallback timeout
                console.log('[PUNTWORK] Reset API error caught:', {xhr: xhr, status: status, error: error});
                PuntWorkJSLogger.error('Reset AJAX error', 'LOGIC', {xhr: xhr, status: status, error: error});
                
                // Always reset button state on error
                $('#reset-import').prop('disabled', false).text('Reset Import');
                
                JobImportUI.appendLogs(['Reset AJAX error: ' + error]);
                $('#status-message').text('Reset failed - please try again');
                console.log('[PUNTWORK] Reset AJAX error handled, button re-enabled');
            });
        },

        /**
         * Log a failed manual import run to history
         * @param {Object} errorData - Error information
         */
        logFailedManualImportRun: async function(errorData) {
            try {
                const logData = {
                    action: 'log_manual_import_run',
                    nonce: jobImportData.nonce,
                    timestamp: Math.floor(Date.now() / 1000), // Current timestamp in seconds
                    duration: this.getElapsedTime() / 1000, // Convert to seconds
                    success: false,
                    processed: 0,
                    total: 0,
                    published: 0,
                    updated: 0,
                    skipped: 0,
                    error_message: errorData.message || 'Import failed',
                    trigger_type: 'manual'
                };

                const response = await $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: logData,
                    timeout: 10000
                });

                if (response.success) {
                    PuntWorkJSLogger.info('Failed manual import run logged to history', 'LOGIC', {
                        error: logData.error_message,
                        duration: logData.duration
                    });
                } else {
                    PuntWorkJSLogger.error('Failed to log failed manual import run', 'LOGIC', response);
                }
            } catch (error) {
                PuntWorkJSLogger.error('Error logging failed manual import run', 'LOGIC', error);
            }
        },

        /**
         * Debug function to get detailed import status - call from browser console
         * Usage: JobImportLogic.debugImportStatus()
         */
        debugImportStatus: async function() {
            console.log('[PUNTWORK] ===== IMPORT DEBUG STATUS =====');
            console.log('Is importing:', this.isImporting);
            console.log('Start time:', this.startTime ? new Date(this.startTime).toISOString() : 'Not set');
            console.log('Elapsed time:', this.getElapsedTime(), 'milliseconds');
            console.log('Elapsed time (seconds):', this.getElapsedTime() / 1000, 'seconds');

            try {
                const statusResponse = await JobImportAPI.getImportStatus();
                console.log('Current status from server:', statusResponse);

                if (statusResponse.success && statusResponse.data) {
                    const data = statusResponse.data;
                    console.log('Status details:');
                    console.log('  - Total:', data.total);
                    console.log('  - Processed:', data.processed);
                    console.log('  - Published:', data.published);
                    console.log('  - Updated:', data.updated);
                    console.log('  - Skipped:', data.skipped);
                    console.log('  - Complete:', data.complete);
                    console.log('  - Success:', data.success);
                    console.log('  - Time elapsed:', data.time_elapsed);
                    console.log('  - Last update:', data.last_update ? new Date(data.last_update * 1000).toISOString() : 'Never');

                    if (data.logs && data.logs.length > 0) {
                        console.log('Recent logs:');
                        data.logs.slice(-10).forEach((log, i) => {
                            console.log('  ' + (i+1) + ':', log);
                        });
                    }
                }
            } catch (error) {
                console.error('Error getting status:', error);
            }

            // Check status polling
            if (window.JobImportEvents && window.JobImportEvents.statusPollingInterval) {
                console.log('Status polling is active (interval ID:', window.JobImportEvents.statusPollingInterval, ')');
            } else {
                console.log('Status polling is NOT active');
            }

            // Check real-time updates
            if (window.JobImportRealtime && window.JobImportRealtime.getConnectionStatus) {
                console.log('Real-time connection status:', window.JobImportRealtime.getConnectionStatus());
            } else {
                console.log('Real-time updates not available');
            }

            console.log('===== END DEBUG STATUS =====');
        },

        /**
         * Force reset import state - call from browser console if import gets stuck
         * Usage: JobImportLogic.forceResetImport()
         */
        forceResetImport: function() {
            console.log('[PUNTWORK] ===== FORCE RESET IMPORT STATE =====');
            console.log('Previous isImporting:', this.isImporting);
            console.log('Previous startTime:', this.startTime);

            this.isImporting = false;
            this.startTime = null;

            // Stop status polling
            if (window.JobImportEvents && window.JobImportEvents.stopStatusPolling) {
                window.JobImportEvents.stopStatusPolling();
                console.log('Stopped status polling');
            }

            // Disconnect real-time updates
            if (window.JobImportRealtime && window.JobImportRealtime.disconnect) {
                window.JobImportRealtime.disconnect();
                console.log('Disconnected real-time updates');
            }

            // Reset UI
            JobImportUI.resetButtons();
            JobImportUI.clearProgress();
            JobImportUI.hideImportUI();
            $('#status-message').text('Import state reset - ready to start');

            console.log('Import state has been force reset');
            console.log('New isImporting:', this.isImporting);
            console.log('New startTime:', this.startTime);
            console.log('===== FORCE RESET COMPLETE =====');

            PuntWorkJSLogger.warn('Import state force reset from debug function', 'LOGIC');
        },
    };

    // Expose to global scope
    window.JobImportLogic = JobImportLogic;

})(jQuery, window, document);

// Global error listener to catch unexpected errors
window.onerror = function(message, source, lineno, colno, error) {
    console.error("Global error caught:", message, "at", source + ':' + lineno + ':' + colno, "error object:", error);
};