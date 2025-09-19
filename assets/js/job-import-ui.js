/**
 * Job Import Admin - UI Management Module
 * Handles progress display, logging, time formatting, and UI updates
 */

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

        /**
         * Set the current import phase
         * @param {string} phase - The current phase
         */
        setPhase: function(phase) {
            this.currentPhase = phase;
            PuntWorkJSLogger.debug('Import phase changed to: ' + phase, 'UI');
        },

        /**
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
            $('#progress-bar').empty();
            $('#progress-percent').text('0%');
            $('#total-items').text(0);
            $('#processed-items').text(0);
            $('#created-items').text(0);
            $('#updated-items').text(0);
            $('#skipped-items').text(0);
            $('#duplicates-drafted').text(0);
            $('#drafted-old').text(0);
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
            logs.forEach(function(log) {
                logArea.val(logArea.val() + log + '\n');
            });
            logArea.scrollTop(logArea[0].scrollHeight);
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

            // Ensure all counter fields are present and numeric
            data.total = parseInt(data.total) || 0;
            data.processed = parseInt(data.processed) || 0;
            data.created = parseInt(data.created) || 0;
            data.updated = parseInt(data.updated) || 0;
            data.skipped = parseInt(data.skipped) || 0;
            data.duplicates_drafted = parseInt(data.duplicates_drafted) || 0;
            data.drafted_old = parseInt(data.drafted_old) || 0;
            data.time_elapsed = parseFloat(data.time_elapsed) || 0;
            data.success = data.success !== undefined ? data.success : null;
            data.error_message = data.error_message || '';

            PuntWorkJSLogger.debug('Normalized response data', 'UI', {
                total: data.total,
                processed: data.processed,
                created: data.created,
                updated: data.updated,
                skipped: data.skipped,
                duplicates_drafted: data.duplicates_drafted,
                drafted_old: data.drafted_old,
                time_elapsed: data.time_elapsed,
                success: data.success,
                error_message: data.error_message
            });

            return data;
        },

        /**
         * Update progress display with new data
         * @param {Object} data - Progress data
         */
        updateProgress: function(data) {
            // Normalize the data first
            data = this.normalizeResponseData(data);

            PuntWorkJSLogger.debug('Updating progress with data', 'UI', data);
            console.log('[PUNTWORK] Progress data received:', data);

            // Update success/failure state
            if (data.success !== null) {
                this.importSuccess = data.success;
                this.errorMessage = data.error_message || '';
            }

            var total = data.total || 0;
            var processed = data.processed || 0;
            var percent = 0;

            // Calculate percentage based on current phase
            if (this.currentPhase === 'feed-processing') {
                // Feed processing phase: 0-30% of total progress
                percent = Math.floor((processed / total) * 30);
                this.setPhase(processed >= total ? 'jsonl-combining' : 'feed-processing');
            } else if (this.currentPhase === 'jsonl-combining') {
                // JSONL combining phase: 30-40% of total progress
                percent = 30 + Math.floor((processed / 1) * 10);
                this.setPhase(processed >= 1 ? 'job-importing' : 'jsonl-combining');
            } else if (this.currentPhase === 'job-importing' || this.currentPhase === 'complete') {
                // Job importing phase: 40-100% of total progress
                if (total > 0) {
                    if (processed >= total) {
                        percent = 100;
                        this.setPhase('complete');
                    } else {
                        percent = 40 + Math.floor((processed / total) * 60);
                    }
                }
            } else {
                // Default calculation
                if (total > 0) {
                    if (processed >= total) {
                        percent = 100;
                    } else {
                        percent = Math.floor((processed / total) * 100);
                    }
                }
            }

            // For successful completion, ensure we show 100%
            if (this.importSuccess === true && processed >= total && total > 0) {
                percent = 100;
            }

            $('#progress-percent').text(percent + '%');

            // Update percentage text color based on success/failure state
            var percentColor = '#007aff'; // Default blue
            if (this.importSuccess === true) {
                percentColor = '#34c759'; // Green for success
            } else if (this.importSuccess === false) {
                percentColor = '#ff3b30'; // Red for failure
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

                // Set fill color based on success/failure state
                var fillColor = '#007aff'; // Default blue
                if (this.importSuccess === true) {
                    fillColor = '#34c759'; // Green for success
                } else if (this.importSuccess === false) {
                    fillColor = '#ff3b30'; // Red for failure
                }

                $('#progress-bar div:lt(' + percent + ')').css('backgroundColor', fillColor); // Fill completed segments
            }

            // Check if we're in feed processing phase (no job stats yet)
            var is_feed_processing = (data.created === 0 && data.updated === 0 && data.skipped === 0 &&
                                    data.duplicates_drafted === 0 && data.drafted_old === 0);

            // Check if we're in JSONL combination phase (total=1, processed=0 or 1, no job stats)
            var is_jsonl_combining = (total === 1 && is_feed_processing);

            if (is_feed_processing && !is_jsonl_combining && total > 1) {
                // Feed processing phase - show feed progress
                $('#total-items').text(total);
                $('#processed-items').text(processed);
                $('#created-items').text('—');
                $('#updated-items').text('—');
                $('#skipped-items').text('—');
                $('#duplicates-drafted').text('—');
                $('#drafted-old').text('—');
                $('#items-left').text(total - processed);

                // Update status message for feed processing
                if (processed < total) {
                    $('#status-message').text(`Processing feeds (${processed}/${total})`);
                }
            } else if (is_jsonl_combining) {
                // JSONL combination phase
                $('#total-items').text('—');
                $('#processed-items').text(processed ? 'Complete' : 'In Progress');
                $('#created-items').text('—');
                $('#updated-items').text('—');
                $('#skipped-items').text('—');
                $('#duplicates-drafted').text('—');
                $('#drafted-old').text('—');
                $('#items-left').text(processed ? '0' : '1');

                // Update status message for JSONL combination
                if (processed === 0) {
                    $('#status-message').text('Combining JSONL files...');
                } else {
                    $('#status-message').text('JSONL files combined');
                }
            } else {
                // Job import phase - show normal stats
                $('#total-items').text(total);
                $('#processed-items').text(processed);
                $('#created-items').text(data.created || 0);
                $('#updated-items').text(data.updated || 0);
                $('#skipped-items').text(data.skipped || 0);
                $('#duplicates-drafted').text(data.duplicates_drafted || 0);
                $('#drafted-old').text(data.drafted_old || 0);

                var itemsLeft = total - processed;
                $('#items-left').text(isNaN(itemsLeft) ? 0 : itemsLeft);

                // Update status message based on completion
                if (this.importSuccess === false) {
                    $('#status-message').text('Import Failed: ' + (this.errorMessage || 'Unknown error'));
                } else if (processed >= total && total > 0) {
                    $('#status-message').text('Import Complete');
                } else {
                    $('#status-message').text('Importing...');
                }
            }

            // Update elapsed time
            var elapsedTime = data.time_elapsed || 0;
            $('#time-elapsed').text(this.formatTime(elapsedTime));

            // Update processing speed for better time estimation
            if (this.currentPhase === 'job-importing' && total > 0) {
                this.updateProcessingSpeed(processed, elapsedTime);
            }

            // Calculate and update estimated time remaining
            this.updateEstimatedTime(data);
        },

        /**
         * Update estimated time remaining calculation
         * @param {Object} data - Progress data
         */
        updateEstimatedTime: function(data) {
            var total = data.total || 0;
            var processed = data.processed || 0;
            var timeElapsed = data.time_elapsed || 0;
            var itemsLeft = total - processed;

            // Handle completion case
            if (this.importSuccess === false) {
                $('#time-left').text('Failed');
                return;
            } else if (this.currentPhase === 'complete' || (processed >= total && total > 0)) {
                $('#time-left').text('Complete');
                return;
            }

            // Handle different phases
            if (this.currentPhase === 'feed-processing') {
                // For feed processing, estimate based on feeds processed
                if (processed > 0 && timeElapsed > 0) {
                    var timePerFeed = timeElapsed / processed;
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

            // Job importing phase - use processing speed for better accuracy
            if (this.processingSpeed > 0 && itemsLeft > 0) {
                // Use the tracked processing speed (items per second)
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

            // Fallback to original logic if processing speed not available
            // Don't calculate if we don't have enough data or invalid values
            if (total === 0 || processed === 0 || timeElapsed <= 0 || itemsLeft <= 0 || isNaN(timeElapsed)) {
                $('#time-left').text('Calculating...');
                return;
            }

            // Calculate time per item based on current progress
            var timePerItem = timeElapsed / processed;

            // Validate timePerItem to prevent NaN
            if (isNaN(timePerItem) || !isFinite(timePerItem)) {
                $('#time-left').text('Calculating...');
                return;
            }

            // Use batch data if available for more accurate calculation
            if (data.batch_processed && data.batch_time && data.batch_processed > 0) {
                var batchTimePerItem = data.batch_time / data.batch_processed;
                if (!isNaN(batchTimePerItem) && isFinite(batchTimePerItem)) {
                    // Use a weighted average: 70% batch time, 30% overall time
                    timePerItem = (batchTimePerItem * 0.7) + (timePerItem * 0.3);
                }
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

            PuntWorkJSLogger.debug('Time calculation', 'UI', {
                total: total,
                processed: processed,
                timeElapsed: timeElapsed,
                itemsLeft: itemsLeft,
                timePerItem: timePerItem,
                estimatedSeconds: estimatedSeconds,
                processingSpeed: this.processingSpeed
            });
            console.log('[PUNTWORK] Time calculation details:', {
                total: total,
                processed: processed,
                timeElapsed: timeElapsed,
                itemsLeft: itemsLeft,
                timePerItem: timePerItem,
                estimatedSeconds: estimatedSeconds,
                processingSpeed: this.processingSpeed,
                formattedTime: this.formatTime(estimatedSeconds)
            });
        },

        /**
         * Reset button states to initial configuration
         */
        resetButtons: function() {
            $('#cancel-import').hide();
            $('#resume-import').hide();
            $('#start-import').show();
        },

        /**
         * Show import UI elements
         */
        showImportUI: function() {
            $('#import-progress').show();
            $('#import-log').show();
        },

        /**
         * Hide import UI elements
         */
        hideImportUI: function() {
            $('#import-progress').hide();
            $('#import-log').hide();
        }
    };

    // Expose to global scope
    window.JobImportUI = JobImportUI;

})(jQuery, window, document);