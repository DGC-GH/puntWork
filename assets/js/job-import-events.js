/**
 * Job Import Admin - Events Module
 * Handles event binding and user interactions
 */

console.log('[PUNTWORK] job-import-events.js loaded - DEBUG MODE');

(function($, window, document) {
    'use strict';

    var JobImportEvents = {
        /**
         * Initialize event bindings
         */
        init: function() {
            console.log('[PUNTWORK] JobImportEvents.init() called');
            console.log('[PUNTWORK] jQuery version:', $.fn.jquery);
            console.log('[PUNTWORK] Document ready state:', document.readyState);

            this.bindEvents();
            this.checkInitialStatus();

            // Removed duplicate event binding timeout that was causing double-click issues
        },

        /**
         * Bind all event handlers
         */
        bindEvents: function() {
            console.log('[PUNTWORK] Binding events...');
            console.log('[PUNTWORK] Start button exists:', $('#start-import').length);
            console.log('[PUNTWORK] Cleanup button exists:', $('#cleanup-duplicates').length);

            // Check if buttons exist before binding
            if ($('#cleanup-duplicates').length > 0) {
                console.log('[PUNTWORK] Found cleanup button, binding click handler');
                $('#cleanup-duplicates').on('click', function(e) {
                    console.log('[PUNTWORK] Cleanup button clicked!');
                    e.preventDefault(); // Prevent any default form submission
                    JobImportEvents.handleCleanupDuplicates();
                });
            } else {
                console.log('[PUNTWORK] Cleanup button NOT found!');
            }

            $('#start-import').on('click', function(e) {
                console.log('[PUNTWORK] Start button clicked!');
                console.log('[PUNTWORK] DEBUG: Start import button click detected at ' + new Date().toISOString());
                
                // Prevent multiple rapid clicks
                if ($(this).prop('disabled')) {
                    console.log('[PUNTWORK] Start button is disabled, ignoring click');
                    return;
                }
                
                $(this).prop('disabled', true).text('Starting...');
                
                // Call the logic handler
                JobImportEvents.handleStartImport();
                
                // Note: Button will be re-enabled by UI reset functions when import completes or fails
            });
            $('#resume-import').on('click', function(e) {
                console.log('[PUNTWORK] Resume button clicked!');
                JobImportEvents.handleResumeImport();
            });
            $('#cancel-import').on('click', function(e) {
                console.log('[PUNTWORK] Cancel button clicked!');
                JobImportEvents.handleCancelImport();
            });
            $('#reset-import').on('click', function(e) {
                console.log('[PUNTWORK] Reset button clicked!');
                JobImportEvents.handleResetImport();
            });

            // Test single job button binding
            // (Button removed - functionality no longer available)

            // Database optimization events
            $('#optimize-database').on('click', function(e) {
                console.log('[PUNTWORK] Optimize database button clicked!');
                JobImportEvents.handleOptimizeDatabase();
            });
            $('#check-db-status').on('click', function(e) {
                console.log('[PUNTWORK] Check DB status button clicked!');
                JobImportEvents.handleCheckDbStatus();
            });

            // Async processing events - only bind if elements exist
            if ($('#save-async-settings').length > 0) {
                $('#save-async-settings').on('click', function(e) {
                    console.log('[PUNTWORK] Save async settings button clicked!');
                    JobImportEvents.handleSaveAsyncSettings();
                });

                // Enable/disable save button when checkbox changes
                $('#enable-async-processing').on('change', function(e) {
                    console.log('[PUNTWORK] Async processing checkbox changed:', $(this).is(':checked'));
                    $('#save-async-settings').prop('disabled', false);
                    $('#async-save-status').text('');
                });
            }

            // Performance monitoring events
            $('#refresh-performance').on('click', function(e) {
                console.log('[PUNTWORK] Refresh performance button clicked!');
                JobImportEvents.handleRefreshPerformance();
            });

            $('#clear-performance-logs').on('click', function(e) {
                console.log('[PUNTWORK] Clear performance logs button clicked!');
                JobImportEvents.handleClearPerformanceLogs();
            });

            // Diagnostics events
            $('#run-import-diagnostics').on('click', function(e) {
                console.log('[PUNTWORK] Run import diagnostics button clicked!');
                JobImportEvents.handleRunImportDiagnostics();
            });

            $('#force-run-batch-job').on('click', function(e) {
                console.log('[PUNTWORK] Force run batch job button clicked!');
                JobImportEvents.handleForceRunBatchJob();
            });

            // Log toggle button
            // $('#toggle-log').on('click', function(e) {
            //     console.log('[PUNTWORK] Toggle log button clicked!');
            //     JobImportUI.toggleLog();
            // });

            console.log('[PUNTWORK] Events bound successfully');
        },

        /**
         * Bind only cleanup event handlers (for jobs dashboard)
         */
        bindCleanupEvents: function() {
            console.log('[PUNTWORK] Binding cleanup events only...');
            console.log('[PUNTWORK] Cleanup button exists:', $('#cleanup-duplicates').length);
            console.log('[PUNTWORK] Jobs cleanup button exists:', $('#jobs-cleanup-duplicates').length);

            // Check if cleanup button exists before binding
            if ($('#cleanup-duplicates').length > 0) {
                console.log('[PUNTWORK] Found cleanup button, binding click handler');
                $('#cleanup-duplicates').on('click', function(e) {
                    console.log('[PUNTWORK] Cleanup button clicked!');
                    e.preventDefault(); // Prevent any default form submission
                    JobImportEvents.handleCleanupDuplicates();
                });
            }

            // Also bind the jobs cleanup button if it exists
            if ($('#jobs-cleanup-duplicates').length > 0) {
                console.log('[PUNTWORK] Found jobs cleanup button, binding click handler');
                $('#jobs-cleanup-duplicates').on('click', function(e) {
                    console.log('[PUNTWORK] Jobs cleanup button clicked!');
                    e.preventDefault(); // Prevent any default form submission
                    JobImportEvents.handleCleanupDuplicates();
                });
            }

            console.log('[PUNTWORK] Cleanup events bound successfully');
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
         * Handle reset import button click
         */
        handleResetImport: function() {
            console.log('[PUNTWORK] Reset Import clicked');
            JobImportLogic.handleResetImport();
        },





        /**
         * Handle cleanup duplicates button click
         */
        handleCleanupDuplicates: function() {
            console.log('[PUNTWORK] Cleanup duplicates handler called');
            console.log('[PUNTWORK] jobImportData available:', typeof jobImportData);
            console.log('[PUNTWORK] JobImportAPI available:', typeof JobImportAPI);
            console.log('[PUNTWORK] JobImportUI available:', typeof JobImportUI);

            if (confirm('This will permanently delete all job posts that are in Draft or Trash status. This action cannot be undone. Continue?')) {
                console.log('[PUNTWORK] User confirmed cleanup');
                // Disable both cleanup buttons
                $('#cleanup-duplicates').prop('disabled', true);
                $('#jobs-cleanup-duplicates').prop('disabled', true);
                $('#cleanup-text').hide();
                $('#jobs-cleanup-text').hide();
                $('#cleanup-loading').show();
                $('#jobs-cleanup-loading').show();
                $('#cleanup-status').text('Starting cleanup...');
                $('#jobs-cleanup-status').text('Starting cleanup...');

                // Show progress UI immediately
                JobImportUI.showCleanupUI();
                JobImportUI.clearCleanupProgress();

                console.log('[PUNTWORK] Calling cleanup API');
                JobImportEvents.processCleanupBatch(0, 1); // Start with first batch at size 1 (one post at a time for maximum safety)
            } else {
                console.log('[PUNTWORK] User cancelled cleanup');
            }
        },        /**
         * Process cleanup batch and continue if needed
         * @param {number} offset - Current offset for batch processing
         * @param {number} batchSize - Size of batch to process
         */
        processCleanupBatch: function(offset, batchSize) {
            console.log('[PUNTWORK] Processing cleanup batch - offset:', offset, 'batchSize:', batchSize);
            
            // Record batch start time for performance metrics
            window.lastCleanupBatchStart = Date.now();
            
            var isContinue = offset > 0;
            var action = isContinue ? JobImportAPI.continueCleanup(offset, batchSize) : JobImportAPI.cleanupDuplicates();

            action.then(function(response) {
                console.log('[PUNTWORK] Cleanup API response:', response);
                PuntWorkJSLogger.debug('Cleanup response', 'EVENTS', response);

                if (response.success) {
                    console.log('[PUNTWORK] Cleanup response successful, complete:', response.data.complete);
                    JobImportUI.appendLogs(response.data.logs || []);

                    // Record cleanup performance metrics for dynamic rate limiting
                    if (typeof window.cleanupBatchMetrics === 'undefined') {
                        window.cleanupBatchMetrics = [];
                    }
                    var batchMetrics = {
                        timestamp: Date.now(),
                        batchSize: batchSize,
                        offset: offset,
                        processingTime: Date.now() - (window.lastCleanupBatchStart || Date.now()),
                        itemsProcessed: response.data.batch_processed || 0,
                        totalDeleted: response.data.total_deleted || 0,
                        nextOffset: response.data.next_offset || 0,
                        complete: response.data.complete || false
                    };
                    window.cleanupBatchMetrics.push(batchMetrics);
                    
                    // Keep only last 10 batches for memory efficiency
                    if (window.cleanupBatchMetrics.length > 10) {
                        window.cleanupBatchMetrics.shift();
                    }
                    
                    // Store metrics in localStorage for persistence across page loads
                    try {
                        localStorage.setItem('puntwork_cleanup_metrics', JSON.stringify(window.cleanupBatchMetrics));
                    } catch (e) {
                        console.log('[PUNTWORK] Failed to store cleanup metrics in localStorage:', e);
                    }

                    if (response.data.complete) {
                        // Operation completed
                        $('#cleanup-status').text('Cleanup completed: ' + response.data.total_deleted + ' duplicates removed');
                        $('#jobs-cleanup-status').text('Cleanup completed: ' + response.data.total_deleted + ' duplicates removed');
                        $('#cleanup-duplicates').prop('disabled', false);
                        $('#jobs-cleanup-duplicates').prop('disabled', false);
                        $('#cleanup-text').show();
                        $('#jobs-cleanup-text').show();
                        $('#cleanup-loading').hide();
                        $('#jobs-cleanup-loading').hide();
                        JobImportUI.clearCleanupProgress();
                    } else {
                        // Update progress and continue with next batch
                        JobImportUI.updateCleanupProgress(response.data);
                        $('#cleanup-status').text('Progress: ' + response.data.progress_percentage + '% (' +
                            response.data.total_processed + '/' + response.data.total_jobs + ' jobs processed)');
                        $('#jobs-cleanup-status').text('Progress: ' + response.data.progress_percentage + '% (' +
                            response.data.total_processed + '/' + response.data.total_jobs + ' jobs processed)');
                        
                        // Use the batch size returned by the server (which may be adjusted dynamically)
                        var nextBatchSize = response.data.batch_size || batchSize;
                        JobImportEvents.processCleanupBatch(response.data.next_offset, nextBatchSize);
                    }
                } else {
                    // Handle rate limit errors specifically for cleanup
                    if (response.error && (response.error.code === 'rate_limit' || 
                        (response.error.message && response.error.message.indexOf('Rate limit exceeded') !== -1))) {
                        console.log('[PUNTWORK] Cleanup rate limit exceeded:', response.error.message);
                        PuntWorkJSLogger.warn('Cleanup rate limit exceeded', 'EVENTS', response.error);
                        
                        // Update status to show rate limiting
                        $('#cleanup-status').text('Rate limited - waiting before retry...');
                        $('#jobs-cleanup-status').text('Rate limited - waiting before retry...');
                        
                        // Extract wait time from error message (e.g., "Please wait 6 seconds")
                        var waitTime = 6000; // Default 6 seconds
                        var match = response.error.message.match(/wait (\d+) seconds/);
                        if (match) {
                            waitTime = parseInt(match[1]) * 1000;
                        }
                        
                        console.log('[PUNTWORK] Waiting ' + waitTime + 'ms before retrying cleanup');
                        
                        // Retry after the specified wait time
                        setTimeout(function() {
                            console.log('[PUNTWORK] Retrying cleanup batch after rate limit');
                            JobImportEvents.processCleanupBatch(offset, batchSize);
                        }, waitTime);
                        
                        return; // Don't continue with error handling
                    }
                    
                    console.log('[PUNTWORK] Cleanup response failed:', response.data);
                    $('#cleanup-status').text('Cleanup failed: ' + (response.data?.error || response.data || 'Unknown error'));
                    $('#jobs-cleanup-status').text('Cleanup failed: ' + (response.data?.error || response.data || 'Unknown error'));
                    $('#cleanup-duplicates').prop('disabled', false);
                    $('#jobs-cleanup-duplicates').prop('disabled', false);
                    $('#cleanup-text').show();
                    $('#jobs-cleanup-text').show();
                    $('#cleanup-loading').hide();
                    $('#jobs-cleanup-loading').hide();
                    JobImportUI.clearCleanupProgress();
                }
            }).catch(function(xhr, status, error) {
                console.log('[PUNTWORK] Cleanup API error:', error);
                console.log('[PUNTWORK] XHR status:', xhr.status, 'response:', xhr.responseText);
                PuntWorkJSLogger.error('Cleanup AJAX error', 'EVENTS', error);
                $('#cleanup-status').text('Cleanup failed: ' + error);
                $('#jobs-cleanup-status').text('Cleanup failed: ' + error);
                JobImportUI.appendLogs(['Cleanup AJAX error: ' + error]);
                $('#cleanup-duplicates').prop('disabled', false);
                $('#jobs-cleanup-duplicates').prop('disabled', false);
                $('#cleanup-text').show();
                $('#jobs-cleanup-text').show();
                $('#cleanup-loading').hide();
                $('#jobs-cleanup-loading').hide();
                JobImportUI.clearCleanupProgress();
            });
        },

        /**
         * Check initial import status on page load
         */
        checkInitialStatus: function() {
            // Load stored cleanup metrics from localStorage
            try {
                var storedMetrics = localStorage.getItem('puntwork_cleanup_metrics');
                if (storedMetrics) {
                    window.cleanupBatchMetrics = JSON.parse(storedMetrics);
                    console.log('[PUNTWORK] Loaded', window.cleanupBatchMetrics.length, 'stored cleanup metrics');
                } else {
                    window.cleanupBatchMetrics = [];
                }
            } catch (e) {
                console.log('[PUNTWORK] Failed to load stored cleanup metrics:', e);
                window.cleanupBatchMetrics = [];
            }

            // Clear progress first to ensure clean state
            JobImportUI.clearProgress();

            // Show Start Import button by default - only hide if there's an incomplete import
            $('#start-import').show().text('Start Import');
            $('#resume-import').hide();
            $('#cancel-import').hide();
            $('#reset-import').hide();

            // Load all status information progressively
            this.loadProgressiveStatus();
        },

        /**
         * Load all status information progressively for better UX
         */
        loadProgressiveStatus: function() {
            console.log('[PUNTWORK] Loading status information progressively');

            // Show Start Import button immediately to prevent delay
            $('#start-import').show().text('Start Import');
            $('#resume-import').hide();
            $('#cancel-import').hide();
            $('#reset-import').hide();
            JobImportUI.hideImportUI();
            $('#status-message').text('Ready to start.');
            $('#background-import-indicator').hide();

            // Load database optimization status first (non-blocking)
            JobImportAPI.getDbOptimizationStatus().then(function(dbResponse) {
                console.log('[PUNTWORK] DB status response:', dbResponse);
                if (dbResponse.success && dbResponse.data && dbResponse.data.status) {
                    				JobImportEvents.updateDbStatusDisplay(dbResponse.data.status);
                } else {
                    console.log('[PUNTWORK] DB status response invalid or missing status data');
                    // Show error state
                    $('#db-status-badge').removeClass('success warning error').addClass('error').html('<i class="fas fa-exclamation-triangle" style="margin-right: 4px;"></i>Error');
                    $('#db-indexes-list').html('<div style="color: #ff3b30;">Unable to load database status</div>');
                }
            }).catch(function(error) {
                console.log('[PUNTWORK] DB status load error:', error);
                $('#db-status-badge').removeClass('success warning error').addClass('error').html('<i class="fas fa-exclamation-triangle" style="margin-right: 4px;"></i>Error');
                $('#db-indexes-list').html('<div style="color: #ff3b30;">Failed to load database status</div>');
            });

            // Load async processing status (non-blocking) - only if async settings UI exists
            if ($('#async-status-badge').length > 0) {
                JobImportAPI.getAsyncStatus().then(function(asyncResponse) {
                    console.log('[PUNTWORK] Async status response:', asyncResponse);
                    if (asyncResponse.success) {
                        JobImportEvents.updateAsyncStatusDisplay(asyncResponse.data);
                        // Update checkbox state
                        $('#enable-async-processing').prop('checked', asyncResponse.data.enabled);
                    } else {
                        // Show error state
                        $('#async-status-badge').removeClass('success warning error').addClass('error').html('<i class="fas fa-exclamation-triangle" style="margin-right: 4px;"></i>Error');
                        $('#async-status-details').html('<div style="color: #ff3b30;">Failed to load async status</div>');
                    }
                }).catch(function(error) {
                    console.log('[PUNTWORK] Async status load error:', error);
                    $('#async-status-badge').removeClass('success warning error').addClass('error').html('<i class="fas fa-exclamation-triangle" style="margin-right: 4px;"></i>Error');
                    $('#async-status-details').html('<div style="color: #ff3b30;">Failed to load async status</div>');
                });
            }

            // Load import status last (most important for user interaction)
            JobImportAPI.getImportStatus().then(function(response) {
                PuntWorkJSLogger.debug('Initial status response', 'EVENTS', response);
                console.log('[PUNTWORK] Initial status response:', response);

                // Handle both response formats: direct data or wrapped in .data
                var statusData = JobImportUI.normalizeResponseData(response);

                // Determine if there's actually an incomplete import to resume
                var hasIncompleteImport = response.success && (
                    (statusData.processed > 0 && !statusData.complete) || // Partially completed import
                    (statusData.resume_progress > 0) || // Has resume progress
                    (!statusData.complete && statusData.total > 0) // Incomplete with total set
                );

                // Function to check for background imports
                var checkBackgroundImports = function() {
                    return new Promise(function(resolve) {
                        if ($('#async-status-badge').length > 0) {
                            JobImportAPI.getAsyncStatus().then(function(asyncResponse) {
                                console.log('[PUNTWORK] Background import check - Async status response:', asyncResponse);
                                if (asyncResponse.success && asyncResponse.data.running_jobs && asyncResponse.data.running_jobs.length > 0) {
                                    console.log('[PUNTWORK] Background import detected -', asyncResponse.data.running_jobs.length, 'running jobs');
                                    PuntWorkJSLogger.info('Background import detected on page load', 'EVENTS', {
                                        runningJobs: asyncResponse.data.running_jobs.length,
                                        jobIds: asyncResponse.data.running_jobs.map(job => job.id)
                                    });

                                    // Show import progress UI for background import
                                    $('#start-import').hide();
                                    $('#resume-import').hide();
                                    $('#cancel-import').show();
                                    $('#reset-import').show();
                                    JobImportUI.showImportUI();
                                    $('#status-message').text('Background import in progress...');
                                    $('#background-import-indicator').show();

                                    // Start polling to monitor the background import
                                    JobImportEvents.startStatusPolling();

                                    // Update progress with any available data
                                    if (statusData.total > 0) {
                                        JobImportUI.updateProgress(statusData);
                                        JobImportUI.appendLogs(statusData.logs || []);
                                    }

                                    resolve(true); // Background import detected
                                } else {
                                    resolve(false); // No background import
                                }
                            }).catch(function(error) {
                                console.log('[PUNTWORK] Background import check failed:', error);
                                resolve(false);
                            });
                        } else {
                            resolve(false);
                        }
                    });
                };

                if (hasIncompleteImport) {
                    JobImportUI.updateProgress(statusData);
                    JobImportUI.appendLogs(statusData.logs || []);
                    
                    // Check if import appears to be currently running (updated within last 5 minutes)
                    // This aligns with the PHP stuck import detection threshold
                    var currentTime = Math.floor(Date.now() / 1000);
                    var timeSinceLastUpdate = currentTime - (statusData.last_update || 0);
                    var isRecentlyActive = timeSinceLastUpdate < 300; // 5 minutes
                    
                    if (isRecentlyActive && statusData.processed > 0) {
                        // Import appears to be currently running - show progress UI with cancel and reset
                        $('#start-import').hide();
                        $('#resume-import').hide();
                        $('#cancel-import').show();
                        $('#reset-import').show(); // Always show reset button for incomplete imports
                        JobImportUI.showImportUI();
                        $('#status-message').text('Import in progress...');
                        console.log('[PUNTWORK] Import appears to be currently running - starting status polling');
                        
                        // Start polling for status updates
                        JobImportEvents.startStatusPolling();
                    } else {
                        // Import was interrupted - show resume and reset options only if not complete
                        if (!statusData.complete) {
                            $('#start-import').hide();
                            $('#resume-import').show();
                            $('#reset-import').show(); // Always show reset button for incomplete imports
                            $('#cancel-import').hide();
                            JobImportUI.showImportUI();
                            $('#status-message').text('Previous import interrupted. Resume or reset?');
                            console.log('[PUNTWORK] Import was interrupted, showing resume and reset options');
                        } else {
                            // Import is complete - show start button
                            $('#start-import').show().text('Start Import');
                            $('#resume-import').hide();
                            $('#cancel-import').hide();
                            $('#reset-import').hide();
                            JobImportUI.hideImportUI();
                            $('#status-message').text('Ready to start.');
                            $('#background-import-indicator').hide();
                            console.log('[PUNTWORK] Import is complete, showing start button');
                        }
                    }
                } else {
                    // No incomplete import found - check for background imports
                    console.log('[PUNTWORK] No incomplete import found, checking for background imports...');
                    checkBackgroundImports().then(function(hasBackgroundImport) {
                        if (!hasBackgroundImport) {
                            // Clean state - Start Import button already shown above
                            console.log('[PUNTWORK] Clean state detected - Start Import button already visible');
                        }
                    });
                }
            }).catch(function(xhr, status, error) {
                PuntWorkJSLogger.error('Initial status AJAX error', 'EVENTS', error);
                JobImportUI.appendLogs(['Initial status AJAX error: ' + error]);
                // Start Import button already shown above, so no change needed on error
                console.log('[PUNTWORK] Initial status load failed, but Start Import button remains visible');
            });
        },

        /**
         * Start polling for import status updates (used for scheduled imports and manual imports)
         */
        startStatusPolling: function() {
            console.log('[PUNTWORK] JobImportEvents.startStatusPolling() called');

            // Clear any existing polling interval
            if (this.statusPollingInterval) {
                console.log('[PUNTWORK] Clearing existing polling interval');
                clearInterval(this.statusPollingInterval);
            }

            // Initialize dynamic polling variables
            this.currentPollingInterval = 1000; // Start with 1000ms for better real-time updates
            this.lastProcessedCount = -1;
            this.unchangedCount = 0;
            this.maxUnchangedBeforeSlow = 50; // After 50 unchanged polls (100 seconds), slow down
            this.completeDetectedCount = 0; // Counter for complete detections
            this.maxCompletePolls = 3; // Continue polling for 3 more polls after detecting complete
            var totalZeroCount = 0; // Counter for polls where total remains 0
            this.maxTotalZeroPolls = 100; // Stop polling after 100 polls with total=0 (200 seconds)
            this.totalPollCount = 0; // Counter for total polls
            this.maxTotalPolls = 1800; // Stop polling after 1800 polls (30 minutes at 1 second intervals)
            var isStartingNewImport = true; // Flag to prevent hiding UI when starting a new import
            var hasSeenImportRunning = false; // Flag to track if we've seen the import actually running

            // Show the progress UI immediately when starting polling
            console.log('[PUNTWORK] Showing import UI for import');
            JobImportUI.showImportUI();
            $('#start-import').hide();
            $('#resume-import').hide();
            $('#cancel-import').show();
            $('#reset-import').show();
            $('#status-message').text('Import in progress...');

            console.log('[PUNTWORK] Starting dynamic status polling (initial: 1000ms)');

            // Add a small delay before starting polling to allow Action Scheduler to begin processing
            console.log('[PUNTWORK] Adding 2-second delay before starting polling to allow Action Scheduler to initialize');
            setTimeout(function() {
                console.log('[PUNTWORK] Delay complete, now starting polling');

                // Store the polling function for reuse
                JobImportEvents.pollStatus = function() {
                    // console.log('[PUNTWORK] Polling for status update (interval: ' + JobImportEvents.currentPollingInterval + 'ms)...');
                    JobImportEvents.totalPollCount++;
                    console.log('[PUNTWORK] Total polls so far: ' + JobImportEvents.totalPollCount);

                    // Safety check: Stop polling if we've exceeded maximum polls
                    if (JobImportEvents.totalPollCount >= JobImportEvents.maxTotalPolls) {
                        console.log('[PUNTWORK] Polling exceeded maximum polls (' + JobImportEvents.maxTotalPolls + '), stopping polling');
                        PuntWorkJSLogger.warn('Polling exceeded maximum polls, stopping', 'EVENTS', {
                            totalPollCount: JobImportEvents.totalPollCount,
                            maxTotalPolls: JobImportEvents.maxTotalPolls
                        });
                        JobImportEvents.stopStatusPolling();
                        JobImportUI.resetButtons();
                        $('#status-message').text('Import monitoring timed out - please refresh the page');
                        $('#background-import-indicator').hide();
                        return;
                    }

                    JobImportAPI.getImportStatus().then(function(response) {
                        console.log('[PUNTWORK] Status polling response:', response);
                        PuntWorkJSLogger.debug('Status polling response', 'EVENTS', {
                            success: response.success,
                            total: response.data?.total,
                            processed: response.data?.processed,
                            complete: response.data?.complete,
                            hasLogs: response.data?.logs?.length > 0
                        });

                        if (response.success) {
                            var statusData = JobImportUI.normalizeResponseData(response);
                            var currentProcessed = statusData.processed || 0;

                            // Check if total is still 0 (import hasn't started)
                            if (statusData.total === 0) {
                                totalZeroCount++;
                                console.log('[PUNTWORK] Import total still 0, count:', totalZeroCount);
                            } else {
                                totalZeroCount = 0;
                            }

                            // Stop polling if total has been 0 for too many polls
                            if (totalZeroCount >= JobImportEvents.maxTotalZeroPolls) {
                                console.log('[PUNTWORK] Import failed to start after', JobImportEvents.maxTotalZeroPolls, 'polls, stopping polling');
                                PuntWorkJSLogger.warn('Import failed to start, stopping polling', 'EVENTS', {
                                    totalZeroCount: totalZeroCount,
                                    maxTotalZeroPolls: JobImportEvents.maxTotalZeroPolls
                                });
                                JobImportEvents.stopStatusPolling();
                                JobImportUI.resetButtons();
                                $('#status-message').text('Import failed to start - please try again');
                                $('#background-import-indicator').hide();
                                return;
                            }

                            // Check if progress has changed
                            if (currentProcessed !== JobImportEvents.lastProcessedCount) {
                                JobImportEvents.lastProcessedCount = currentProcessed;
                                JobImportEvents.unchangedCount = 0;
                                
                                // If progress is happening, ensure fast polling
                                if (JobImportEvents.currentPollingInterval > 1000) {
                                    JobImportEvents.adjustPollingInterval(1000);
                                }
                            } else {
                                JobImportEvents.unchangedCount++;
                                
                                // If no progress for several polls, slow down polling
                                if (JobImportEvents.unchangedCount >= JobImportEvents.maxUnchangedBeforeSlow && 
                                    JobImportEvents.currentPollingInterval < 1000) {
                                    JobImportEvents.adjustPollingInterval(1000);
                                }
                            }

                            // Update UI with progress data (including final completion status)
                            if (statusData.total > 0) {
                                console.log('[PUNTWORK] POLLING CHECK - Updating progress with polling data at ' + new Date().toISOString() + ':', {
                                    total: statusData.total,
                                    processed: statusData.processed,
                                    published: statusData.published,
                                    updated: statusData.updated,
                                    phase: JobImportUI.currentPhase,
                                    isIntermediate: statusData.is_intermediate_update || false,
                                    intermediateTime: statusData.intermediate_update_time || null
                                });
                                JobImportUI.updateProgress(statusData);
                                JobImportUI.appendLogs(statusData.logs || []);
                                // Import has started, clear the starting flag
                                isStartingNewImport = false;
                                hasSeenImportRunning = true; // Mark that we've seen the import running
                            } else if (statusData.total === 0 && !statusData.complete) {
                                console.log('[PUNTWORK] POLLING CHECK - Import not yet started (total=0), continuing to poll at ' + new Date().toISOString());
                                // Import hasn't started yet, keep polling
                            }

                            // Check if import completed
                            if (statusData.complete && statusData.total > 0 && (statusData.processed >= statusData.total || statusData.complete)) {
                                JobImportEvents.completeDetectedCount++;
                                console.log('[PUNTWORK] Polled import completed - total:', statusData.total, 'processed:', statusData.processed, 'detection count:', JobImportEvents.completeDetectedCount);
                                PuntWorkJSLogger.info('Import completed via polling', 'EVENTS', {
                                    total: statusData.total,
                                    processed: statusData.processed,
                                    success: statusData.success
                                });
                                
                                // Continue polling for a few more polls to ensure we get final status updates
                                if (JobImportEvents.completeDetectedCount >= JobImportEvents.maxCompletePolls) {
                                    // Do one final progress update to ensure completion is displayed
                                    JobImportUI.updateProgress(statusData);
                                    
                                    // Log the manual import run to history if this was a manual import
                                    if (window.JobImportLogic && window.JobImportLogic.isImporting && window.JobImportLogic.logManualImportRun) {
                                        window.JobImportLogic.logManualImportRun(statusData);
                                    }
                                    
                                    JobImportEvents.stopStatusPolling();
                                    JobImportUI.resetButtons();
                                    $('#status-message').text('Import Complete');
                                    $('#background-import-indicator').hide();
                                }
                            } else if (statusData.complete && statusData.total === 0 && !isStartingNewImport) {
                                console.log('[PUNTWORK] Import status reset to empty state, stopping polling and resetting UI');
                                PuntWorkJSLogger.info('Import status reset to empty state', 'EVENTS', statusData);
                                JobImportEvents.stopStatusPolling();
                                JobImportUI.clearProgress();
                                JobImportUI.hideImportUI();
                                JobImportUI.resetButtons();
                                $('#status-message').text('Ready to start.');
                                $('#background-import-indicator').hide();
                            } else {
                                // Reset complete detection counter if import is not complete
                                JobImportEvents.completeDetectedCount = 0;
                            }
                        } else {
                            // Handle rate limit errors specifically
                            if (response.error && response.error.code === 'rate_limit') {
                                console.log('[PUNTWORK] Rate limit exceeded, slowing down polling:', response.error.message);
                                PuntWorkJSLogger.warn('Rate limit exceeded, slowing down polling', 'EVENTS', response.error);
                                
                                // Slow down polling significantly when rate limited
                                JobImportEvents.adjustPollingInterval(30000); // 30 seconds
                                
                                // Update status message to inform user
                                $('#status-message').text('Rate limited - waiting before retry...');
                                $('#background-import-indicator').show(); // Keep showing for rate limited
                                
                                // Don't stop polling, just slow it down
                                return;
                            }
                            
                            console.log('[PUNTWORK] Status polling failed:', response);
                            PuntWorkJSLogger.warn('Status polling failed', 'EVENTS', response);
                        }
                    }).catch(function(error) {
                        console.log('[PUNTWORK] Status polling error:', error);
                        PuntWorkJSLogger.error('Status polling error', 'EVENTS', error);
                        // Continue polling on error
                    });
                };

                // Start polling with initial interval
                JobImportEvents.statusPollingInterval = setInterval(JobImportEvents.pollStatus, JobImportEvents.currentPollingInterval);

                // Safety timeout: Stop polling after 30 minutes to prevent infinite polling
                JobImportEvents.statusPollingTimeout = setTimeout(function() {
                    console.log('[PUNTWORK] Status polling timed out after 30 minutes');
                    PuntWorkJSLogger.warn('Status polling timed out', 'EVENTS');
                    JobImportEvents.stopStatusPolling();
                    JobImportUI.resetButtons();
                    $('#status-message').text('Import monitoring timed out - please refresh the page');
                    $('#background-import-indicator').hide();
                }, 30 * 60 * 1000); // 30 minutes
            }, 2000); // 2-second delay
        },

        /**
         * Adjust the polling interval dynamically
         */
        adjustPollingInterval: function(newInterval) {
            if (JobImportEvents.currentPollingInterval === newInterval) {
                return; // No change needed
            }

            console.log('[PUNTWORK] Adjusting polling interval from ' + JobImportEvents.currentPollingInterval + 'ms to ' + newInterval + 'ms');
            
            // Clear current interval
            if (JobImportEvents.statusPollingInterval) {
                clearInterval(JobImportEvents.statusPollingInterval);
            }

            // Update interval and restart with the stored polling function
            JobImportEvents.currentPollingInterval = newInterval;
            JobImportEvents.statusPollingInterval = setInterval(JobImportEvents.pollStatus, JobImportEvents.currentPollingInterval);
        },

        /**
         * Stop polling for import status updates
         */
        stopStatusPolling: function() {
            if (JobImportEvents.statusPollingInterval) {
                clearInterval(JobImportEvents.statusPollingInterval);
                JobImportEvents.statusPollingInterval = null;
                console.log('[PUNTWORK] Stopped status polling');
            }
            if (JobImportEvents.statusPollingTimeout) {
                clearTimeout(JobImportEvents.statusPollingTimeout);
                JobImportEvents.statusPollingTimeout = null;
                console.log('[PUNTWORK] Cleared status polling timeout');
            }
        },

        /**
         * Handle optimize database button click
         */
        handleOptimizeDatabase: function() {
            console.log('[PUNTWORK] Optimize database handler called');

            if (confirm('This will create database indexes to improve import performance. This may take a few moments. Continue?')) {
                console.log('[PUNTWORK] User confirmed database optimization');
                $('#optimize-database').prop('disabled', true);
                $('#optimize-text').hide();
                $('#optimize-loading').show();
                $('#db-optimization-status-msg').text('Creating indexes...');

                JobImportAPI.createDatabaseIndexes().then(function(response) {
                    console.log('[PUNTWORK] Database optimization response:', response);

                    if (response.success) {
                        $('#db-optimization-status-msg').text(response.data.message || 'Database indexes created successfully!');
                        JobImportEvents.updateDbStatusDisplay(response.data.status);
                    } else {
                        $('#db-optimization-status-msg').text('Failed: ' + (response.data.message || 'Unknown error'));
                    }

                    $('#optimize-database').prop('disabled', false);
                    $('#optimize-text').show();
                    $('#optimize-loading').hide();
                }).catch(function(xhr, status, error) {
                    console.log('[PUNTWORK] Database optimization error:', error);
                    $('#db-optimization-status-msg').text('Error: ' + error);
                    $('#optimize-database').prop('disabled', false);
                    $('#optimize-text').show();
                    $('#optimize-loading').hide();
                });
            } else {
                console.log('[PUNTWORK] User cancelled database optimization');
            }
        },

        /**
         * Handle check database status button click
         */
        handleCheckDbStatus: function() {
            console.log('[PUNTWORK] Check DB status handler called');

            $('#check-db-status').prop('disabled', true);
            $('#check-text').hide();
            $('#check-loading').show();
            $('#db-optimization-status-msg').text('Checking status...');

            JobImportAPI.getDbOptimizationStatus().then(function(response) {
                console.log('[PUNTWORK] DB status response:', response);

                if (response.success && response.data && response.data.status) {
                    JobImportEvents.updateDbStatusDisplay(response.data.status);
                    $('#db-optimization-status-msg').text('Status updated');
                } else {
                    $('#db-optimization-status-msg').text('Failed to check status');
                }

                $('#check-db-status').prop('disabled', false);
                $('#check-text').show();
                $('#check-loading').hide();
            }).catch(function(xhr, status, error) {
                console.log('[PUNTWORK] DB status check error:', error);
                $('#db-optimization-status-msg').text('Error: ' + error);
                $('#check-db-status').prop('disabled', false);
                $('#check-text').show();
                $('#check-loading').hide();
            });
        },

        /**
         * Update the database status display
         */
        updateDbStatusDisplay: function(status) {
            console.log('[PUNTWORK] Updating DB status display:', status);

            // Check if status object is valid
            if (!status || typeof status !== 'object') {
                console.error('[PUNTWORK] Invalid status object passed to updateDbStatusDisplay:', status);
                $('#db-status-badge').removeClass('success warning error').addClass('error').html('<i class="fas fa-exclamation-triangle" style="margin-right: 4px;"></i>Error');
                $('#db-indexes-list').html('<div style="color: #ff3b30;">Invalid status data</div>');
                return;
            }

            // Update badge
            var badgeElement = $('#db-status-badge');
            var badgeClass = 'error';
            var badgeText = 'Not Optimized';

            if (status.optimization_complete) {
                badgeClass = 'success';
                badgeText = 'Optimized';
            } else if (status.indexes_created) {
                badgeClass = 'warning';
                badgeText = 'Partial';
            }

            badgeElement.removeClass('success warning error').addClass(badgeClass);
            badgeElement.text(badgeText);

            // Update indexes list
            $('#db-indexes-list').html(status.indexes_html || 'Unable to load index status');
        },

        /**
         * Handle save async settings button click
         */
        handleSaveAsyncSettings: function() {
            console.log('[PUNTWORK] Save async settings handler called');

            $('#save-async-settings').prop('disabled', true);
            $('#async-save-status').text('Saving settings...');

            var enableAsync = $('#enable-async-processing').is(':checked');

            JobImportAPI.saveAsyncSettings(enableAsync).then(function(response) {
                console.log('[PUNTWORK] Save async settings response:', response);

                if (response.success) {
                    $('#async-save-status').text('Settings saved successfully!');
                    JobImportEvents.updateAsyncStatusDisplay(response.data);
                } else {
                    $('#async-save-status').text('Failed to save settings');
                }

                $('#save-async-settings').prop('disabled', false);
            }).catch(function(xhr, status, error) {
                console.log('[PUNTWORK] Save async settings error:', error);
                $('#async-save-status').text('Error: ' + error);
                $('#save-async-settings').prop('disabled', false);
            });
        },

        /**
         * Update the async processing status display
         */
        updateAsyncStatusDisplay: function(status) {
            console.log('[PUNTWORK] Updating async status display:', status);

            // Update badge
            var badgeElement = $('#async-status-badge');
            var badgeClass = 'error';
            var badgeText = 'Unavailable';

            if (status.available) {
                badgeClass = 'success';
                badgeText = 'Available';
            }

            badgeElement.removeClass('success warning error').addClass(badgeClass);
            badgeElement.text(badgeText);

            // Update status details
            var detailsHtml = '';
            if (status.available) {
                detailsHtml += '<div>• Async processing is available</div>';
                detailsHtml += '<div>• Using Action Scheduler (recommended)</div>';
                detailsHtml += '<div>• Large imports will be processed in background</div>';
            } else {
                detailsHtml += '<div>• Async processing is not available</div>';
                detailsHtml += '<div>• All imports will be processed synchronously</div>';
            }

            if (status.enabled) {
                detailsHtml += '<div>• Auto-async enabled for imports > 500 items</div>';
            } else {
                detailsHtml += '<div>• Auto-async disabled</div>';
            }

            $('#async-status-details').html(detailsHtml);
        },

        /**
         * Handle refresh performance metrics button click
         */
        handleRefreshPerformance: function() {
            console.log('[PUNTWORK] Refresh performance handler called');

            $('#refresh-performance').prop('disabled', true);
            $('#refresh-performance-text').hide();
            $('#refresh-performance-loading').show();
            $('#performance-status-msg').text('Refreshing metrics...');

            JobImportAPI.getPerformanceStatus().then(function(response) {
                console.log('[PUNTWORK] Performance status response:', response);

                if (response.success) {
                    JobImportEvents.updatePerformanceDisplay(response.stats, response.snapshot);
                    $('#performance-status-msg').text('Metrics refreshed successfully');
                } else {
                    $('#performance-status-msg').text('Failed to refresh metrics');
                }

                $('#refresh-performance').prop('disabled', false);
                $('#refresh-performance-text').show();
                $('#refresh-performance-loading').hide();
            }).catch(function(xhr, status, error) {
                console.log('[PUNTWORK] Performance status error:', error);
                $('#performance-status-msg').text('Error: ' + error);
                $('#refresh-performance').prop('disabled', false);
                $('#refresh-performance-text').show();
                $('#refresh-performance-loading').hide();
            });
        },

        /**
         * Handle clear performance logs button click
         */
        handleClearPerformanceLogs: function() {
            console.log('[PUNTWORK] Clear performance logs handler called');

            if (!confirm('This will delete performance logs older than 30 days. Continue?')) {
                return;
            }

            $('#clear-performance-logs').prop('disabled', true);
            $('#clear-performance-text').hide();
            $('#clear-performance-loading').show();
            $('#performance-status-msg').text('Clearing old logs...');

            JobImportAPI.clearPerformanceLogs().then(function(response) {
                console.log('[PUNTWORK] Clear performance logs response:', response);

                if (response.success) {
                    $('#performance-status-msg').text(response.message || 'Old logs cleared successfully');
                } else {
                    $('#performance-status-msg').text('Failed to clear logs');
                }

                $('#clear-performance-logs').prop('disabled', false);
                $('#clear-performance-text').show();
                $('#clear-performance-loading').hide();
            }).catch(function(xhr, status, error) {
                console.log('[PUNTWORK] Clear performance logs error:', error);
                $('#performance-status-msg').text('Error: ' + error);
                $('#clear-performance-logs').prop('disabled', false);
                $('#clear-performance-text').show();
                $('#clear-performance-loading').hide();
            });
        },

        /**
         * Update the performance monitoring status display
         */
        updatePerformanceDisplay: function(stats, snapshot) {
            console.log('[PUNTWORK] Updating performance display:', stats, snapshot);

            // Update badge
            var badgeElement = $('#performance-status-badge');
            badgeElement.removeClass('success warning error').addClass('success');
            badgeElement.html('<i class="fas fa-check-circle" style="margin-right: 4px;"></i>Active');

            // Update metrics display
            var metricsHtml = '';

            if (stats && stats.total_runs > 0) {
                metricsHtml += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px;">';

                // Performance stats
                metricsHtml += '<div style="background: linear-gradient(135deg, #007aff 0%, #5856d6 100%); border-radius: 8px; padding: 12px; color: white;">';
                metricsHtml += '<div style="font-size: 12px; opacity: 0.8; margin-bottom: 4px;">Avg Time</div>';
                metricsHtml += '<div style="font-size: 18px; font-weight: 600;">' + (stats.avg_time_seconds || 0) + 's</div>';
                metricsHtml += '</div>';

                metricsHtml += '<div style="background: linear-gradient(135deg, #32d74b 0%, #34c759 100%); border-radius: 8px; padding: 12px; color: white;">';
                metricsHtml += '<div style="font-size: 12px; opacity: 0.8; margin-bottom: 4px;">Items/sec</div>';
                metricsHtml += '<div style="font-size: 18px; font-weight: 600;">' + (stats.avg_items_per_second || 0) + '</div>';
                metricsHtml += '</div>';

                metricsHtml += '<div style="background: linear-gradient(135deg, #ff9500 0%, #ff6b35 100%); border-radius: 8px; padding: 12px; color: white;">';
                metricsHtml += '<div style="font-size: 12px; opacity: 0.8; margin-bottom: 4px;">Memory Used</div>';
                metricsHtml += '<div style="font-size: 18px; font-weight: 600;">' + (stats.avg_memory_mb || 0) + 'MB</div>';
                metricsHtml += '</div>';

                metricsHtml += '<div style="background: linear-gradient(135deg, #af52de 0%, #8e5de8 100%); border-radius: 8px; padding: 12px; color: white;">';
                metricsHtml += '<div style="font-size: 12px; opacity: 0.8; margin-bottom: 4px;">Total Runs</div>';
                metricsHtml += '<div style="font-size: 18px; font-weight: 600;">' + (stats.total_runs || 0) + '</div>';
                metricsHtml += '</div>';

                metricsHtml += '</div>';

                metricsHtml += '<div style="font-size: 12px; color: #666;">Last ' + (stats.period_days || 30) + ' days • Peak memory: ' + (stats.max_peak_memory_mb || 0) + 'MB</div>';
            } else {
                metricsHtml += '<div>No performance data available yet. Run an import to collect metrics.</div>';
            }

            // Current system snapshot
            if (snapshot) {
                metricsHtml += '<div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e0e0e0;">';
                metricsHtml += '<div style="font-size: 14px; font-weight: 500; margin-bottom: 8px;">Current System Status</div>';
                metricsHtml += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; font-size: 12px;">';
                metricsHtml += '<div>Memory: ' + Math.round((snapshot.memory_current || 0) / 1024 / 1024) + 'MB</div>';
                metricsHtml += '<div>Peak: ' + Math.round((snapshot.memory_peak || 0) / 1024 / 1024) + 'MB</div>';
                metricsHtml += '<div>PHP: ' + (snapshot.php_version || 'Unknown') + '</div>';
                metricsHtml += '<div>WP: ' + (snapshot.wordpress_version || 'Unknown') + '</div>';
                metricsHtml += '</div></div>';
            }

            $('#performance-metrics').html(metricsHtml);
        },

        /**
         * Handle run import diagnostics button click
         */
        handleRunImportDiagnostics: function() {
            console.log('[PUNTWORK] Run import diagnostics handler called');

            $('#run-import-diagnostics').prop('disabled', true);
            $('#diagnostics-status').text('Running diagnostics...');
            $('#diagnostics-results').html('<div style="color: #666; font-style: italic;">Checking import system...</div>');

            JobImportAPI.runImportDiagnostics().then(function(response) {
                console.log('[PUNTWORK] Import diagnostics response:', response);

                if (response.success) {
                    JobImportEvents.displayDiagnosticsResults(response.data);
                    $('#diagnostics-status').text('Diagnostics completed');
                } else {
                    $('#diagnostics-status').text('Diagnostics failed');
                    $('#diagnostics-results').html('<div style="color: #ff3b30;">Failed to run diagnostics: ' + (response.data?.error || 'Unknown error') + '</div>');
                }

                $('#run-import-diagnostics').prop('disabled', false);
            }).catch(function(xhr, status, error) {
                console.log('[PUNTWORK] Import diagnostics error:', error);
                $('#diagnostics-status').text('Diagnostics error');
                $('#diagnostics-results').html('<div style="color: #ff3b30;">Error running diagnostics: ' + error + '</div>');
                $('#run-import-diagnostics').prop('disabled', false);
            });
        },

        /**
         * Handle force run batch job button click
         */
        handleForceRunBatchJob: function() {
            console.log('[PUNTWORK] Force run batch job handler called');

            var jobId = $('#batch-job-id').val();
            if (!jobId || jobId.trim() === '') {
                alert('Please enter a valid job ID');
                return;
            }

            if (!confirm('This will force execute the specified Action Scheduler job. This should only be used for debugging. Continue?')) {
                return;
            }

            $('#force-run-batch-job').prop('disabled', true);
            $('#diagnostics-status').text('Executing job ' + jobId + '...');

            JobImportAPI.forceRunBatchJob(jobId).then(function(response) {
                console.log('[PUNTWORK] Force run batch job response:', response);

                if (response.success) {
                    $('#diagnostics-status').text('Job execution completed');
                    $('#diagnostics-results').html('<div style="color: #32d74b;">Job ' + jobId + ' executed successfully</div>');
                    if (response.data && response.data.message) {
                        $('#diagnostics-results').append('<div style="margin-top: 8px; color: #666;">' + response.data.message + '</div>');
                    }
                } else {
                    $('#diagnostics-status').text('Job execution failed');
                    $('#diagnostics-results').html('<div style="color: #ff3b30;">Failed to execute job ' + jobId + ': ' + (response.data?.error || 'Unknown error') + '</div>');
                }

                $('#force-run-batch-job').prop('disabled', false);
            }).catch(function(xhr, status, error) {
                console.log('[PUNTWORK] Force run batch job error:', error);
                $('#diagnostics-status').text('Job execution error');
                $('#diagnostics-results').html('<div style="color: #ff3b30;">Error executing job ' + jobId + ': ' + error + '</div>');
                $('#force-run-batch-job').prop('disabled', false);
            });
        },

        /**
         * Display diagnostics results in the UI
         */
        displayDiagnosticsResults: function(data) {
            console.log('[PUNTWORK] Displaying diagnostics results:', data);

            var resultsHtml = '';

            // File status section
            resultsHtml += '<div style="margin-bottom: 16px;">';
            resultsHtml += '<h4 style="margin: 0 0 8px 0; color: #333;">File Status</h4>';
            if (data.files) {
                resultsHtml += '<div style="display: grid; gap: 4px;">';
                for (var file in data.files) {
                    if (data.files.hasOwnProperty(file)) {
                        var fileStatus = data.files[file];
                        var statusColor = fileStatus.exists ? '#32d74b' : '#ff3b30';
                        var statusIcon = fileStatus.exists ? '✓' : '✗';
                        resultsHtml += '<div style="color: ' + statusColor + ';">' + statusIcon + ' ' + file + ': ' + fileStatus.message + '</div>';
                    }
                }
                resultsHtml += '</div>';
            }
            resultsHtml += '</div>';

            // Action Scheduler status section
            resultsHtml += '<div style="margin-bottom: 16px;">';
            resultsHtml += '<h4 style="margin: 0 0 8px 0; color: #333;">Action Scheduler Status</h4>';
            if (data.action_scheduler) {
                resultsHtml += '<div style="display: grid; gap: 4px;">';
                for (var key in data.action_scheduler) {
                    if (data.action_scheduler.hasOwnProperty(key)) {
                        var asStatus = data.action_scheduler[key];
                        var statusColor = asStatus.status === 'ok' ? '#32d74b' : (asStatus.status === 'warning' ? '#ff9500' : '#ff3b30');
                        var statusIcon = asStatus.status === 'ok' ? '✓' : (asStatus.status === 'warning' ? '⚠' : '✗');
                        resultsHtml += '<div style="color: ' + statusColor + ';">' + statusIcon + ' ' + asStatus.label + ': ' + asStatus.message + '</div>';
                    }
                }
                resultsHtml += '</div>';
            }
            resultsHtml += '</div>';

            // Import status section
            resultsHtml += '<div style="margin-bottom: 16px;">';
            resultsHtml += '<h4 style="margin: 0 0 8px 0; color: #333;">Import Status</h4>';
            if (data.import_status) {
                resultsHtml += '<div style="display: grid; gap: 4px;">';
                for (var key in data.import_status) {
                    if (data.import_status.hasOwnProperty(key)) {
                        var importStatus = data.import_status[key];
                        var statusColor = importStatus.status === 'ok' ? '#32d74b' : (importStatus.status === 'warning' ? '#ff9500' : '#ff3b30');
                        var statusIcon = importStatus.status === 'ok' ? '✓' : (importStatus.status === 'warning' ? '⚠' : '✗');
                        resultsHtml += '<div style="color: ' + statusColor + ';">' + statusIcon + ' ' + importStatus.label + ': ' + importStatus.message + '</div>';
                    }
                }
                resultsHtml += '</div>';
            }
            resultsHtml += '</div>';

            // Recommendations section
            if (data.recommendations && data.recommendations.length > 0) {
                resultsHtml += '<div style="margin-bottom: 16px;">';
                resultsHtml += '<h4 style="margin: 0 0 8px 0; color: #333;">Recommendations</h4>';
                resultsHtml += '<ul style="margin: 0; padding-left: 20px;">';
                data.recommendations.forEach(function(rec) {
                    resultsHtml += '<li style="color: #666; margin-bottom: 4px;">' + rec + '</li>';
                });
                resultsHtml += '</ul>';
                resultsHtml += '</div>';
            }

            $('#diagnostics-results').html(resultsHtml);
        }
    };

    // Expose to global scope
    window.JobImportEvents = JobImportEvents;

})(jQuery, window, document);