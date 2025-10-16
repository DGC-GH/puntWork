/**
 * Job Import Admin - UI Management Module
 * Handles progress display, logging, time formatting, and UI updates
 */

console.log('[PUNTWORK] job-import-ui.js loaded');

(function($, window, document) {
    'use strict';

    var JobImportUI = {
        segmentsCreated: false,
        currentPhase: 'idle', // 'idle', 'feed-processing', 'jsonl-combining', 'job-importing', 'complete'
        processingSpeed: 0, // items per second
        lastUpdateTime: 0,
        lastProcessedCount: 0,
        importSuccess: null, // null = in progress, true = success, false = failure
        errorMessage: '', // Error message for failed imports
        startTime: null, // Track actual start time for elapsed time calculation
        batchTimes: [], // Store batch processing times for better estimation
        lastBatchTime: 0, // Time taken for last batch
        lastBatchSize: 0, // Size of last batch

        /**
         * Set the current import phase
         * @param {string} phase - The current phase
         */
        setPhase: function(phase) {
            var oldPhase = this.currentPhase;
            this.currentPhase = phase;
            PuntWorkJSLogger.debug('Import phase changed from ' + oldPhase + ' to: ' + phase, 'UI');

            // Reset progress bar when transitioning to a new phase (except complete)
            if (phase !== 'complete' && phase !== 'idle') {
                // Reset progress bar segments to show 0% for new phase
                if (this.segmentsCreated) {
                    $('#progress-bar div').css('backgroundColor', '#f2f2f7'); // Reset all to default
                }
                $('#progress-percent').text('0%');
                $('#progress-percent').css('color', '#007aff'); // Reset to blue for in-progress
            }

            // Don't reset timing or progress data during phase transitions
            if (phase === 'idle') {
                this.startTime = null;
                this.processingSpeed = 0;
            }
            // Keep startTime and other data intact during phase transitions
        },        /**
         * Update processing speed calculation
         * @param {number} processed - Current processed count
         * @param {number} timeElapsed - Time elapsed so far
         */
        updateProcessingSpeed: function(processed, timeElapsed) {
            if (timeElapsed > 0 && processed > this.lastProcessedCount) {
                var timeDiff = timeElapsed - this.lastUpdateTime;
                var processedDiff = processed - this.lastProcessedCount;

                if (timeDiff > 0) {
                    // Calculate items per second, but smooth it with previous value
                    var currentSpeed = processedDiff / timeDiff;
                    this.processingSpeed = this.processingSpeed === 0 ? currentSpeed : (this.processingSpeed * 0.7 + currentSpeed * 0.3);

                    this.lastUpdateTime = timeElapsed;
                    this.lastProcessedCount = processed;
                }
            }
        },

        /**
         * Clear all progress indicators and reset UI
         */
        clearProgress: function() {
            this.segmentsCreated = false;
            this.setPhase('idle');
            this.processingSpeed = 0; // Reset processing speed
            this.lastUpdateTime = 0;
            this.lastProcessedCount = 0;
            this.importSuccess = null; // Reset success state
            this.errorMessage = ''; // Reset error message
            this.startTime = null; // Reset start time only on complete clear
            this.batchTimes = []; // Reset batch times
            this.lastBatchTime = 0;
            this.lastBatchSize = 0;
            $('#progress-bar').empty();
            $('#progress-percent').text('0%');
            $('#progress-percent').css('color', '#007aff'); // Reset to blue
            $('#total-items').text(0);
            $('#processed-items').text(0);
            $('#published-items').text(0);
            $('#updated-items').text(0);
            $('#skipped-items').text(0);
            $('#duplicates-drafted').text(0);
            $('#items-left').text(0);
            $('#log-textarea').val('');
            $('#status-message').text('Ready to start.');
            $('#time-elapsed').text('0s');
            $('#time-left').text('Calculating...');
            console.log('[PUNTWORK] Progress UI cleared and reset');
        },

        /**
         * Append logs to the log textarea
         * @param {Array} logs - Array of log messages
         */
        appendLogs: function(logs) {
            var logArea = $('#log-textarea');
            if (logArea.length > 0) {
                logs.forEach(function(log) {
                    logArea.val(logArea.val() + log + '\n');
                });
                logArea.scrollTop(logArea[0].scrollHeight);
            }
        },

        /**
         * Append logs to the cleanup log textarea
         * @param {Array} logs - Array of log messages
         */
        appendCleanupLogs: function(logs) {
            var logArea = $('#cleanup-log-textarea');
            if (logArea.length > 0) {
                logs.forEach(function(log) {
                    logArea.val(logArea.val() + log + '\n');
                });
                logArea.scrollTop(logArea[0].scrollHeight);
            }
        },

        /**
         * Format time in seconds to human-readable format
         * @param {number} seconds - Time in seconds
         * @returns {string} Formatted time string
         */
        formatTime: function(seconds) {
            // Handle invalid input
            if (!seconds || isNaN(seconds) || seconds < 0 || !isFinite(seconds)) {
                return '0s';
            }

            seconds = Math.floor(seconds);
            var days = Math.floor(seconds / (3600 * 24));
            seconds -= days * 3600 * 24;
            var hours = Math.floor(seconds / 3600);
            seconds -= hours * 3600;
            var minutes = Math.floor(seconds / 60);
            seconds = Math.floor(seconds % 60);
            var formatted = '';
            if (days > 0) formatted += days + 'd ';
            if (hours > 0 || days > 0) formatted += hours + 'h ';
            if (minutes > 0 || hours > 0 || days > 0) formatted += minutes + 'm ';
            formatted += seconds + 's';
            return formatted.trim();
        },

        /**
         * Normalize AJAX response data (handle both direct and wrapped formats)
         * @param {Object} response - AJAX response object
         * @returns {Object} Normalized data object
         */
        normalizeResponseData: function(response) {
            // Some responses have data directly, others have it in .data property
            var data = response.data || response;

            // Create a copy of all properties first to avoid readonly property issues
            var normalizedData = Object.assign({}, data);

            // Ensure all counter fields are present and numeric, overriding the copied values
            normalizedData.total = parseInt(normalizedData.total) || 0;
            normalizedData.processed = parseInt(normalizedData.processed) || 0;
            normalizedData.published = parseInt(normalizedData.published) || 0;
            normalizedData.updated = parseInt(normalizedData.updated) || 0;
            normalizedData.skipped = parseInt(normalizedData.skipped) || 0;
            normalizedData.duplicates_drafted = parseInt(normalizedData.duplicates_drafted) || 0;
            normalizedData.time_elapsed = parseFloat(normalizedData.time_elapsed) || 0;
            normalizedData.success = normalizedData.success !== undefined ? normalizedData.success : null;
            normalizedData.error_message = normalizedData.error_message || '';

            PuntWorkJSLogger.debug('Normalized response data', 'UI', {
                total: normalizedData.total,
                processed: normalizedData.processed,
                published: normalizedData.published,
                updated: normalizedData.updated,
                skipped: normalizedData.skipped,
                duplicates_drafted: normalizedData.duplicates_drafted,
                time_elapsed: normalizedData.time_elapsed,
                success: normalizedData.success,
                error_message: normalizedData.error_message
            });

            return normalizedData;
        },

        /**
         * Update progress display with new data
         * @param {Object} data - Progress data
         */
        updateProgress: function(data) {
            // Normalize the data first
            data = this.normalizeResponseData(data);

            // Check if server sent a phase update and set it
            if (data.phase && data.phase !== this.currentPhase) {
                this.setPhase(data.phase);
            }

            // Set start time if not set and we're starting (only once per import session)
            if (this.startTime === null && (data.processed > 0 || this.currentPhase !== 'idle')) {
                // Try to sync with JobImportLogic's startTime if available
                if (window.JobImportLogic && window.JobImportLogic.startTime) {
                    this.startTime = window.JobImportLogic.startTime / 1000; // Convert from milliseconds to seconds
                } else {
                    this.startTime = Date.now() / 1000; // Current time in seconds
                }
            }

            var total = data.total || 0;
            var processed = data.processed || 0;
            var percent = 0;

            // Update success/failure state - only set to true when actually complete
            if (data.success !== null) {
                // Only set importSuccess to true when the import is actually complete
                if ((data.success === true && processed >= total && total > 0) || data.complete === true) {
                    this.importSuccess = true;
                } else if (data.success === false) {
                    this.importSuccess = false;
                }
                // Don't set to true for in-progress success responses
                this.errorMessage = data.error_message || '';
            }

            // Calculate percentage based on current phase - each phase has its own 0-100% progress
            var phaseProgress = 0;
            var phaseTotal = total;

            if (this.currentPhase === 'feed-processing') {
                // Feed processing phase: 0-100% for this phase only
                // Use total from server data (which represents feed count during this phase)
                // or fall back to pre-loaded feed count if available
                var feedCount = data.total || Object.keys(jobImportData.feeds || {}).length;
                if (feedCount > 0) {
                    phaseTotal = feedCount;
                    phaseProgress = Math.min(processed / phaseTotal, 1.0);
                    percent = Math.floor(phaseProgress * 100);
                } else {
                    percent = 0;
                }

                // Only transition to next phase when actually complete
                if (processed >= phaseTotal && phaseTotal > 0) {
                    this.setPhase('jsonl-combining');
                    // Force a progress update to reflect the phase change
                    this.updateProgress(data);
                }
            } else if (this.currentPhase === 'jsonl-combining') {
                // JSONL combining phase: 0-100% for this phase only
                phaseProgress = Math.min(processed / 1, 1.0); // This phase processes 1 item (the combination)
                percent = Math.floor(phaseProgress * 100);

                // Only transition when actually complete
                if (processed >= 1) {
                    this.setPhase('job-importing');
                    // Force a progress update to reflect the phase change
                    this.updateProgress(data);
                }
            } else if (this.currentPhase === 'job-importing') {
                // Job importing phase: 0-100% for this phase only
                if (total > 0) {
                    if ((processed >= total && data.success === true) || data.complete === true) {
                        // Check if cleanup phase is needed
                        if (data.cleanup_phase === true) {
                            this.setPhase('cleanup');
                            // Force a progress update to reflect the phase change
                            this.updateProgress(data);
                        } else {
                            percent = 100;
                            this.setPhase('complete');
                            this.importSuccess = true; // Set success when import completes
                            // Force a final progress update to show completion
                            this.updateProgress(data);
                        }
                    } else {
                        phaseProgress = processed / total;
                        percent = Math.round(phaseProgress * 100);
                    }
                } else {
                    percent = 0; // Start from 0 for importing phase
                }
            } else if (this.currentPhase === 'cleanup') {
                // Cleanup phase: show cleanup progress
                var cleanupTotal = data.cleanup_total || 0;
                var cleanupProcessed = data.cleanup_processed || 0;

                if (cleanupTotal > 0) {
                    phaseProgress = cleanupProcessed / cleanupTotal;
                    percent = Math.round(phaseProgress * 100);

                    // Transition to complete when cleanup is done
                    if (cleanupProcessed >= cleanupTotal) {
                        percent = 100;
                        this.setPhase('complete');
                        this.importSuccess = true;
                        // Force a final progress update to show completion
                        this.updateProgress(data);
                    }
                } else {
                    // No cleanup needed, go directly to complete
                    percent = 100;
                    this.setPhase('complete');
                    this.importSuccess = true;
                    this.updateProgress(data);
                }
            } else if (this.currentPhase === 'complete') {
                // Ensure we show 100% when complete
                percent = 100;
            } else {
                // Default calculation for unknown phases or idle state
                if (total > 0) {
                    phaseProgress = processed / total;
                    percent = Math.floor(phaseProgress * 100);
                } else {
                    percent = 0;
                }
            }

            // Ensure percentage stays within bounds
            percent = Math.max(0, Math.min(100, percent));

            // For successful completion, ensure we show 100%
            if ((data.success === true && processed >= total && total > 0) || data.complete === true) {
                percent = 100;
            }

            $('#progress-percent').text(percent + '%');

            // Update percentage text color - ONLY green when complete AND successful
            var percentColor = '#007aff'; // Default blue for in-progress
            var barColor = '#007aff'; // Default blue for in-progress

            // Only turn green when import is truly complete AND successful
            if (this.importSuccess === true && this.currentPhase === 'complete') {
                percentColor = '#34c759'; // Green only for successful completion
                barColor = '#34c759';
            } else if (this.importSuccess === false) {
                percentColor = '#ff3b30'; // Red for failure
                barColor = '#ff3b30';
            }

            $('#progress-percent').css('color', percentColor);

            if (!this.segmentsCreated && total > 0) {
                var container = $('#progress-bar');
                container.empty();
                for (var i = 0; i < 100; i++) {
                    $('<div>').css({
                        width: '1%',
                        backgroundColor: '#f2f2f7',
                        borderRight: i < 99 ? '1px solid #d1d1d6' : 'none'
                    }).appendTo(container);
                }
                this.segmentsCreated = true;
            }

            // Update progress bar segments
            if (this.segmentsCreated) {
                $('#progress-bar div').css('backgroundColor', '#f2f2f7'); // Reset all to default
                $('#progress-bar div:lt(' + percent + ')').css('backgroundColor', barColor); // Fill completed segments
            }

            // Check if we're in feed processing phase (no job stats yet)
            var is_feed_processing = (data.published === 0 && data.updated === 0 && data.skipped === 0 &&
                                    data.duplicates_drafted === 0);

            // Check if we're in JSONL combination phase (total=1, processed=0 or 1, no job stats)
            var is_jsonl_combining = (total === 1 && is_feed_processing);

            if (is_feed_processing && !is_jsonl_combining && total > 1) {
                // Feed processing phase - show feed progress
                $('#total-items').text(total);
                $('#processed-items').text(processed);
                $('#published-items').text('—');
                $('#updated-items').text('—');
                $('#skipped-items').text('—');
                $('#duplicates-drafted').text('—');
                $('#items-left').text(total - processed);

                // Update status message for feed processing
                if (processed < total) {
                    $('#status-message').text(`Processing feeds (${processed}/${total}) - ${percent}%`);
                } else {
                    $('#status-message').text(`Feed processing complete - 100%`);
                }
            } else if (is_jsonl_combining) {
                // JSONL combination phase
                $('#total-items').text('—');
                $('#processed-items').text(processed ? 'Complete' : 'In Progress');
                $('#published-items').text('—');
                $('#updated-items').text('—');
                $('#skipped-items').text('—');
                $('#duplicates-drafted').text('—');
                $('#items-left').text(processed ? '0' : '1');

                // Update status message for JSONL combination
                if (processed === 0) {
                    $('#status-message').text('Combining JSONL files... - 0%');
                } else {
                    $('#status-message').text('JSONL files combined - 100%');
                }
            } else {
                // Job import phase - show normal stats
                $('#total-items').text(total);
                $('#processed-items').text(processed);
                $('#published-items').text(data.published || 0);
                $('#updated-items').text(data.updated || 0);
                $('#skipped-items').text(data.skipped || 0);
                $('#duplicates-drafted').text(data.duplicates_drafted || 0);

                var itemsLeft = total - processed;
                $('#items-left').text(isNaN(itemsLeft) ? 0 : itemsLeft);

                // Update status message based on completion
                if (this.importSuccess === false) {
                    $('#status-message').text('Import Failed: ' + (this.errorMessage || 'Unknown error'));
                } else if (this.currentPhase === 'cleanup') {
                    var cleanupTotal = data.cleanup_total || 0;
                    var cleanupProcessed = data.cleanup_processed || 0;
                    $('#status-message').text(`Cleaning up old jobs... (${cleanupProcessed}/${cleanupTotal})`);
                } else if ((processed >= total && total > 0 && data.success === true) || data.complete === true) {
                    $('#status-message').text('Import Complete - 100%');
                } else {
                    $('#status-message').text(`Importing... - ${percent}%`);
                }
            }

            // Update elapsed time - prioritize server-side calculation for consistency
            var elapsedTime = data.time_elapsed || 0;

            // Only use client-side calculation as fallback if server data is missing
            if (elapsedTime < 1) {
                if (this.startTime !== null) {
                    var currentTime = Date.now() / 1000;
                    elapsedTime = currentTime - this.startTime;
                } else if (window.JobImportLogic && window.JobImportLogic.startTime) {
                    // Fallback to logic module's start time if available
                    var currentTime = Date.now() / 1000;
                    elapsedTime = currentTime - (window.JobImportLogic.startTime / 1000);
                } else if (data.start_time) {
                    // Final fallback to server start_time
                    var currentTime = Date.now() / 1000;
                    elapsedTime = currentTime - data.start_time;
                }
            }

            // Update time elapsed display immediately
            $('#time-elapsed').text(this.formatTime(elapsedTime));

            // Track batch timing for better estimation
            if (data.batch_processed && data.batch_time) {
                this.lastBatchSize = data.batch_processed;
                this.lastBatchTime = data.batch_time;
                this.batchTimes.push({
                    size: data.batch_processed,
                    time: data.batch_time,
                    timePerItem: data.batch_time / data.batch_processed
                });
                // Keep only last 5 batches for averaging
                if (this.batchTimes.length > 5) {
                    this.batchTimes.shift();
                }
            }

            // Update processing speed for better time estimation
            if (this.currentPhase === 'job-importing' && total > 0) {
                this.updateProcessingSpeed(processed, elapsedTime);
            }

            // Calculate and update estimated time remaining
            this.updateEstimatedTime(data, elapsedTime);
        },

        /**
         * Update estimated time remaining calculation
         * @param {Object} data - Progress data
         * @param {number} elapsedTime - Current elapsed time
         */
        updateEstimatedTime: function(data, elapsedTime) {
            var total = data.total || 0;
            var processed = data.processed || 0;
            var itemsLeft = total - processed;

            // Handle completion case
            if (this.importSuccess === false) {
                $('#time-left').text('Failed');
                return;
            } else if (this.currentPhase === 'complete' || (processed >= total && total > 0 && data.success === true)) {
                $('#time-left').text('Complete');
                return;
            }

            // For early phases with limited data, use simple estimates
            if (this.currentPhase === 'feed-processing') {
                // For feed processing, estimate based on feeds processed so far
                if (processed > 0 && elapsedTime > 0) {
                    var timePerFeed = elapsedTime / processed;
                    var feedsLeft = total - processed;
                    var estimatedSeconds = timePerFeed * feedsLeft;
                    if (!isNaN(estimatedSeconds) && isFinite(estimatedSeconds) && estimatedSeconds < 3600) {
                        $('#time-left').text(this.formatTime(estimatedSeconds));
                        return;
                    }
                }
                $('#time-left').text('Processing feeds...');
                return;
            }

            if (this.currentPhase === 'jsonl-combining') {
                $('#time-left').text('Combining files...');
                return;
            }

            if (this.currentPhase === 'cleanup') {
                $('#time-left').text('Cleaning up...');
                return;
            }

            // Use PHP-calculated estimated time remaining if available (most accurate)
            if (data.estimated_time_remaining !== undefined && data.estimated_time_remaining > 0) {
                var estimatedSeconds = data.estimated_time_remaining;

                // PuntWorkJSLogger.debug('Using PHP estimated_time_remaining', 'UI', {
                //     estimated_time_remaining: data.estimated_time_remaining,
                //     formatted: this.formatTime(estimatedSeconds)
                // });

                // Sanity check - don't show ridiculous estimates
                if (estimatedSeconds > 86400) { // More than 24 hours
                    $('#time-left').text('>24h');
                } else if (estimatedSeconds < 0) {
                    $('#time-left').text('Calculating...');
                } else {
                    $('#time-left').text(this.formatTime(estimatedSeconds));
                }
                return;
            }

            // PuntWorkJSLogger.debug('PHP estimated_time_remaining not available, using fallback', 'UI', {
            //     estimated_time_remaining: data.estimated_time_remaining,
            //     phase: this.currentPhase,
            //     processed: processed,
            //     total: total,
            //     elapsedTime: elapsedTime
            // });

            // Fallback: Job importing phase - use overall progress rate for better accuracy
            if (processed > 0 && elapsedTime > 0 && itemsLeft > 0) {
                // Calculate time per item based on overall progress so far
                var overallTimePerItem = elapsedTime / processed;
                var estimatedSeconds = overallTimePerItem * itemsLeft;

                // Use batch timing as a weighted factor if available
                if (this.batchTimes.length > 0) {
                    var totalBatchTime = 0;
                    var totalBatchItems = 0;
                    for (var i = 0; i < this.batchTimes.length; i++) {
                        totalBatchTime += this.batchTimes[i].time;
                        totalBatchItems += this.batchTimes[i].size;
                    }
                    var batchTimePerItem = totalBatchTime / totalBatchItems;

                    // Use weighted average: 70% overall, 30% recent batch
                    if (!isNaN(batchTimePerItem) && isFinite(batchTimePerItem) && batchTimePerItem > 0) {
                        overallTimePerItem = (overallTimePerItem * 0.7) + (batchTimePerItem * 0.3);
                        estimatedSeconds = overallTimePerItem * itemsLeft;
                    }
                }

                // PuntWorkJSLogger.debug('JavaScript time calculation (batch timing enabled)', 'UI', {
                //     processed: processed,
                //     elapsedTime: elapsedTime,
                //     itemsLeft: itemsLeft,
                //     overallTimePerItem: overallTimePerItem,
                //     estimatedSeconds: estimatedSeconds,
                //     batchTimesCount: this.batchTimes.length
                // });

                // Sanity check - don't show ridiculous estimates
                if (estimatedSeconds > 86400) { // More than 24 hours
                    $('#time-left').text('>24h');
                } else if (estimatedSeconds < 0) {
                    $('#time-left').text('Calculating...');
                } else {
                    $('#time-left').text(this.formatTime(estimatedSeconds));
                }
                return;
            }

            // Fallback to processing speed if no overall progress data
            if (this.processingSpeed > 0 && itemsLeft > 0) {
                var estimatedSeconds = itemsLeft / this.processingSpeed;

                // Validate estimatedSeconds
                if (!isNaN(estimatedSeconds) && isFinite(estimatedSeconds)) {
                    // Sanity check - don't show ridiculous estimates
                    if (estimatedSeconds > 86400) { // More than 24 hours
                        $('#time-left').text('>24h');
                    } else if (estimatedSeconds < 0) {
                        $('#time-left').text('Calculating...');
                    } else {
                        $('#time-left').text(this.formatTime(estimatedSeconds));
                    }
                    return;
                }
            }

            // Final fallback - use overall progress rate
            if (total === 0 || processed === 0 || elapsedTime <= 0 || itemsLeft <= 0 || isNaN(elapsedTime)) {
                $('#time-left').text('Calculating...');
                return;
            }

            // Calculate time per item based on overall progress
            var timePerItem = elapsedTime / processed;

            // Validate timePerItem to prevent NaN
            if (isNaN(timePerItem) || !isFinite(timePerItem) || timePerItem <= 0) {
                $('#time-left').text('Calculating...');
                return;
            }

            // Calculate estimated time remaining
            var estimatedSeconds = timePerItem * itemsLeft;

            // Validate estimatedSeconds
            if (isNaN(estimatedSeconds) || !isFinite(estimatedSeconds)) {
                $('#time-left').text('Calculating...');
                return;
            }

            // Sanity check - don't show ridiculous estimates
            if (estimatedSeconds > 86400) { // More than 24 hours
                $('#time-left').text('>24h');
            } else if (estimatedSeconds < 0) {
                $('#time-left').text('Calculating...');
            } else {
                $('#time-left').text(this.formatTime(estimatedSeconds));
            }

            // PuntWorkJSLogger.debug('Time calculation fallback', 'UI', {
            //     phase: this.currentPhase,
            //     total: total,
            //     processed: processed,
            //     elapsedTime: elapsedTime,
            //     itemsLeft: itemsLeft,
            //     timePerItem: timePerItem,
            //     estimatedSeconds: estimatedSeconds,
            //     processingSpeed: this.processingSpeed,
            //     batchTimesCount: this.batchTimes.length
            // });
        },

        /**
         * Reset button states to initial configuration
         */
        resetButtons: function() {
            $('#cancel-import').hide();
            $('#resume-import').hide();
            $('#reset-import').hide();
            $('#resume-stuck-import').hide();
            $('#start-import').show().text('Start Import').prop('disabled', false);
        },

        /**
         * Show cancel button
         */
        showCancelButton: function() {
            $('#cancel-import').show();
        },

        /**
         * Hide cancel button
         */
        hideCancelButton: function() {
            $('#cancel-import').hide();
        },

        /**
         * Show resume button
         */
        showResumeButton: function() {
            $('#resume-import').show();
        },

        /**
         * Hide resume button
         */
        hideResumeButton: function() {
            $('#resume-import').hide();
        },

        /**
         * Show reset button
         */
        showResetButton: function() {
            $('#reset-import').show();
        },

        /**
         * Hide reset button
         */
        hideResetButton: function() {
            $('#reset-import').hide();
        },

        /**
         * Show resume stuck button
         */
        showResumeStuckButton: function() {
            $('#resume-stuck-import').show();
        },

        /**
         * Hide resume stuck button
         */
        hideResumeStuckButton: function() {
            $('#resume-stuck-import').hide();
        },

        /**
         * Show start button
         */
        showStartButton: function() {
            $('#start-import').show();
        },

        /**
         * Hide start button
         */
        hideStartButton: function() {
            $('#start-import').hide();
        },

        /**
         * Show import UI elements
         */
        showImportUI: function() {
            $('#import-progress').show();
        },

        /**
         * Hide import UI elements
         */
        hideImportUI: function() {
            $('#import-progress').hide();
            // Keep log visible for testing purposes
            // this.hideLog();
        },

        /**
         * Show cleanup progress UI
         */
        showCleanupUI: function() {
            $('#cleanup-progress').show();
        },

        /**
         * Hide cleanup progress UI
         */
        hideCleanupUI: function() {
            $('#cleanup-progress').hide();
        },

        /**
         * Update cleanup progress display
         * @param {Object} data - Cleanup progress data
         */
        updateCleanupProgress: function(data) {
            var percent = data.progress_percentage || 0;
            var totalProcessed = data.total_processed || 0;
            var totalJobs = data.total_jobs || 0;
            var timeElapsed = data.time_elapsed || 0;

            $('#cleanup-progress-percent').text(percent + '%');
            $('#cleanup-time-elapsed').text(this.formatTime(timeElapsed));
            $('#cleanup-status-message').text(`Processed ${totalProcessed}/${totalJobs} jobs`);
            $('#cleanup-items-left').text((totalJobs - totalProcessed) + ' left');

            // Update progress bar
            $('#cleanup-progress-bar').empty();
            var progressBar = $('<div>').css({
                width: percent + '%',
                height: '100%',
                backgroundColor: '#ff9500',
                borderRadius: '3px',
                transition: 'width 0.3s ease'
            });
            $('#cleanup-progress-bar').append(progressBar);

            this.showCleanupUI();
        },

        /**
         * Clear cleanup progress
         */
        clearCleanupProgress: function() {
            $('#cleanup-progress-percent').text('0%');
            $('#cleanup-time-elapsed').text('0s');
            $('#cleanup-status-message').text('Ready to start.');
            $('#cleanup-items-left').text('0 left');
            $('#cleanup-progress-bar').empty();
            this.hideCleanupUI();
        }
    };

    // Expose to global scope
    window.JobImportUI = JobImportUI;

})(jQuery, window, document);