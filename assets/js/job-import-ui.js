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
            return formatted;
        },

        /**
         * Update progress display with new data
         * @param {Object} data - Progress data
         */
        updateProgress: function(data) {
            PuntWorkJSLogger.debug('Updating progress with data', 'UI', data);
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
            $('#total-items').text(data.total);
            $('#processed-items').text(data.processed);
            $('#created-items').text(data.created);
            $('#updated-items').text(data.updated);
            $('#skipped-items').text(data.skipped);
            $('#duplicates-drafted').text(data.duplicates_drafted);
            $('#drafted-old').text(data.drafted_old);

            var itemsLeft = data.total - data.processed;
            $('#items-left').text(isNaN(itemsLeft) ? 0 : itemsLeft);
            $('#status-message').text('Importing...');
            $('#time-elapsed').text(this.formatTime(data.time_elapsed));

            var timePerItem = 0;
            if (data.batch_processed && data.batch_time && data.batch_processed > 0) {
                timePerItem = data.batch_time / data.batch_processed;
            } else if (data.processed > 0 && data.time_elapsed > 0) {
                timePerItem = data.time_elapsed / data.processed;
            }

            if (timePerItem > 0) {
                var timeLeftSeconds = timePerItem * itemsLeft;
                var timeLeftFormatted = this.formatTime(timeLeftSeconds);
                $('#time-left').text(timeLeftFormatted);
            } else {
                $('#time-left').text('Calculating...');
            }
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