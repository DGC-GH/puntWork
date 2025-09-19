/**
 * Job Import Admin - UI Management Module
 * Handles progress display, logging, time formatting, and UI updates
 */

(function($, window, document) {
    'use strict';

    var JobImportUI = {
        segmentsCreated: false,

        /**
         * Clear all progress indicators and reset UI
         */
        clearProgress: function() {
            this.segmentsCreated = false;
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
            if (!seconds || isNaN(seconds) || seconds < 0) {
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
            return response.data || response;
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
            var percent = data.total > 0 ? Math.floor((data.processed / data.total) * 100) : 0;
            $('#progress-percent').text(percent + '%');

            if (!this.segmentsCreated && data.total > 0) {
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

            $('#progress-bar div:lt(' + percent + ')').css('backgroundColor', '#007aff');
            $('#total-items').text(data.total || 0);
            $('#processed-items').text(data.processed || 0);
            $('#created-items').text(data.created || 0);
            $('#updated-items').text(data.updated || 0);
            $('#skipped-items').text(data.skipped || 0);
            $('#duplicates-drafted').text(data.duplicates_drafted || 0);
            $('#drafted-old').text(data.drafted_old || 0);

            var itemsLeft = (data.total || 0) - (data.processed || 0);
            $('#items-left').text(isNaN(itemsLeft) ? 0 : itemsLeft);
            $('#status-message').text('Importing...');

            // Update elapsed time
            var elapsedTime = data.time_elapsed || 0;
            $('#time-elapsed').text(this.formatTime(elapsedTime));

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

            // Don't calculate if we don't have enough data
            if (total === 0 || processed === 0 || timeElapsed === 0 || itemsLeft <= 0) {
                $('#time-left').text('Calculating...');
                return;
            }

            // Calculate time per item based on current progress
            var timePerItem = timeElapsed / processed;

            // Use batch data if available for more accurate calculation
            if (data.batch_processed && data.batch_time && data.batch_processed > 0) {
                var batchTimePerItem = data.batch_time / data.batch_processed;
                // Use a weighted average: 70% batch time, 30% overall time
                timePerItem = (batchTimePerItem * 0.7) + (timePerItem * 0.3);
            }

            // Calculate estimated time remaining
            var estimatedSeconds = timePerItem * itemsLeft;

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
                estimatedSeconds: estimatedSeconds
            });
            console.log('[PUNTWORK] Time calculation details:', {
                total: total,
                processed: processed,
                timeElapsed: timeElapsed,
                itemsLeft: itemsLeft,
                timePerItem: timePerItem,
                estimatedSeconds: estimatedSeconds,
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