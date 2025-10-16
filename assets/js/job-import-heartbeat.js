/**
 * Job Import Heartbeat Handler
 * Provides real-time import status updates using WordPress Heartbeat API
 * Replaces polling with push-based updates to prevent loops
 */

(function($, window, document) {
    'use strict';

    var JobImportHeartbeat = {
        lastStatusHash: null,
        isImportActive: false,
        heartbeatInterval: null,

        /**
         * Initialize heartbeat functionality
         */
        init: function() {
            // Configure heartbeat for our needs
            this.configureHeartbeat();

            // Listen for heartbeat responses
            this.bindHeartbeatEvents();

            // Initial status check
            this.checkInitialStatus();
        },

        /**
         * Configure WordPress heartbeat settings
         */
        configureHeartbeat: function() {
            // Set heartbeat interval to 5 seconds for responsive updates
            wp.heartbeat.interval(5);

            // Enable our custom data in heartbeat
            wp.heartbeat.enqueue('puntwork_import_status', 'init', false);
            wp.heartbeat.enqueue('puntwork_scheduled_imports', 'init', false);
        },

        /**
         * Bind heartbeat event handlers
         */
        bindHeartbeatEvents: function() {
            var self = this;

            // Listen for heartbeat ticks (outgoing)
            $(document).on('heartbeat-send.puntwork', function(e, data) {
                // Add our data to the heartbeat request
                data.puntwork_import_status = self.isImportActive ? 'active' : 'passive';
                data.puntwork_scheduled_imports = 'check';
            });

            // Listen for heartbeat responses (incoming)
            $(document).on('heartbeat-tick.puntwork', function(e, data) {
                self.handleHeartbeatResponse(data);
            });

            // Handle heartbeat errors
            $(document).on('heartbeat-error', function(e, jqXHR, textStatus, error) {
                PuntWorkJSLogger.warn('Heartbeat communication error', 'HEARTBEAT', {
                    status: textStatus,
                    error: error
                });
            });
        },

        /**
         * Handle heartbeat response data
         */
        handleHeartbeatResponse: function(data) {
            var self = this;

            // Handle import status updates
            if (data['puntwork_import_update']) {
                var update = data['puntwork_import_update'];

                // Check if status actually changed
                var currentHash = this.generateStatusHash(update.status);
                if (currentHash !== this.lastStatusHash) {
                    this.updateImportUI(update.status);
                    this.lastStatusHash = currentHash;

                    // Update active state
                    this.isImportActive = update.is_active;

                    // Log the update
                    PuntWorkJSLogger.debug('Import status updated via heartbeat', 'HEARTBEAT', {
                        processed: update.status.processed,
                        total: update.status.total,
                        complete: update.status.complete,
                        timestamp: update.timestamp
                    });
                }
            }

            // Handle scheduled imports updates
            if (data['puntwork_scheduled_imports']) {
                var scheduledUpdate = data['puntwork_scheduled_imports'];

                if (scheduledUpdate.has_changes) {
                    this.updateScheduledUI(scheduledUpdate.data);
                }
            }

            // Handle explicit status responses (when client requested)
            if (data['puntwork_import_status']) {
                var statusResponse = data['puntwork_import_status'];
                if (statusResponse.has_changes) {
                    this.updateImportUI(statusResponse.status);
                    this.lastStatusHash = this.generateStatusHash(statusResponse.status);
                }
            }
        },

        /**
         * Generate hash for status comparison
         */
        generateStatusHash: function(status) {
            // Create a simple hash of key status fields
            var key = [
                status.processed || 0,
                status.total || 0,
                status.complete || false,
                status.last_update || 0
            ].join('|');
            return btoa(key); // Simple base64 encoding for comparison
        },

        /**
         * Update import progress UI with new status
         */
        updateImportUI: function(status) {
            // Normalize the status data
            var normalizedStatus = JobImportUI.normalizeResponseData({data: status});

            // Update progress display
            JobImportUI.updateProgress(normalizedStatus);

            // Update logs if present
            if (normalizedStatus.logs && normalizedStatus.logs.length > 0) {
                JobImportUI.appendLogs(normalizedStatus.logs);
            }

            // Handle completion
            if (normalizedStatus.complete) {
                this.handleImportCompletion(normalizedStatus);
            }

            // Update status message
            this.updateStatusMessage(normalizedStatus);
        },

        /**
         * Update scheduled imports UI
         */
        updateScheduledUI: function(data) {
            // Update schedule status if JobImportScheduling is available
            if (typeof JobImportScheduling !== 'undefined' && JobImportScheduling.updateUI) {
                JobImportScheduling.updateUI(data);
            }

            // Update next run time display
            if (data.next_run) {
                $('#next-run-time').text(data.next_run.formatted || '—');
                if (data.next_run.relative) {
                    $('#next-run-time').attr('title', data.next_run.relative);
                }
            }

            // Update last run details
            if (data.last_run && data.last_run.details) {
                this.updateLastRunDetails(data.last_run.details);
            }
        },

        /**
         * Handle import completion
         */
        handleImportCompletion: function(status) {
            // Stop considering import active
            this.isImportActive = false;

            // Update UI for completion
            JobImportUI.resetButtons();
            $('#status-message').text('Import Complete');

            // Show completion notification
            var message = status.message || 'Import completed successfully!';
            if (typeof JobImportScheduling !== 'undefined' && JobImportScheduling.showNotification) {
                JobImportScheduling.showNotification(message, 'success');
            }

            // Refresh history if available
            if (typeof JobImportScheduling !== 'undefined' && JobImportScheduling.loadRunHistory) {
                setTimeout(function() {
                    JobImportScheduling.loadRunHistory();
                }, 1000);
            }
        },

        /**
         * Update status message based on current state
         */
        updateStatusMessage: function(status) {
            var message = 'Ready to start import.';

            if (!status.complete && status.total > 0) {
                var percent = Math.round((status.processed / status.total) * 100);
                message = 'Import in progress... ' + status.processed + '/' + status.total + ' items (' + percent + '%)';
            } else if (!status.complete && status.total === 0 && status.processed > 0) {
                message = 'Counting items... ' + status.processed + ' items found so far';
            } else if (status.complete) {
                message = 'Import completed successfully!';
            }

            $('#status-message').text(message);
        },

        /**
         * Update last run details display
         */
        updateLastRunDetails: function(details) {
            if (!details) return;

            $('#last-run-duration').text(this.formatDuration(details.duration));
            $('#last-run-processed').text(details.processed + ' / ' + details.total);
            $('#last-run-success-rate').text(this.calculateSuccessRate(details));
            $('#last-run-status').text(details.success ? 'Success' : 'Failed')
                .css('color', details.success ? '#34c759' : '#ff3b30');
        },

        /**
         * Format duration in seconds to human readable
         */
        formatDuration: function(seconds) {
            if (!seconds || isNaN(seconds)) return '—';

            var minutes = Math.floor(seconds / 60);
            var remainingSeconds = Math.floor(seconds % 60);

            if (minutes > 0) {
                return minutes + 'm ' + remainingSeconds + 's';
            } else {
                return remainingSeconds + 's';
            }
        },

        /**
         * Calculate success rate
         */
        calculateSuccessRate: function(details) {
            if (!details.total || details.total === 0) return '—';
            var successRate = ((details.processed / details.total) * 100).toFixed(1);
            return successRate + '%';
        },

        /**
         * Check initial status on page load
         */
        checkInitialStatus: function() {
            var self = this;

            // Do one initial AJAX call to get current status
            JobImportAPI.getImportStatus().then(function(response) {
                if (response.success) {
                    var status = JobImportUI.normalizeResponseData(response);
                    self.lastStatusHash = self.generateStatusHash(status);

                    // Check if import is currently active
                    self.isImportActive = !status.complete && (status.processed > 0 || status.total > 0);

                    // Update UI with initial status
                    if (self.isImportActive || status.complete) {
                        self.updateImportUI(status);
                    }
                }
            }).catch(function(error) {
                PuntWorkJSLogger.warn('Initial status check failed', 'HEARTBEAT', error);
            });
        },

        /**
         * Force a status refresh (can be called externally)
         */
        forceStatusRefresh: function() {
            wp.heartbeat.enqueue('puntwork_import_status', 'force', false);
        },

        /**
         * Cleanup on page unload
         */
        destroy: function() {
            $(document).off('heartbeat-send.puntwork');
            $(document).off('heartbeat-tick.puntwork');
            $(document).off('heartbeat-error');

            if (this.heartbeatInterval) {
                clearInterval(this.heartbeatInterval);
            }
        }
    };

    // Expose to global scope
    window.JobImportHeartbeat = JobImportHeartbeat;

    // Auto-initialize when DOM is ready
    $(document).ready(function() {
        // Only initialize on the job feed dashboard
        if ($('#job-import-dashboard').length > 0) {
            JobImportHeartbeat.init();
        }
    });

})(jQuery, window, document);