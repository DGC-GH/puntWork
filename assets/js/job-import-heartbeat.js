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
            // Initialize tracking variables
            this.pageLoadTime = Date.now() / 1000; // Track page load time

            // Configure heartbeat for our needs
            this.configureHeartbeat();

            // Listen for heartbeat responses
            this.bindHeartbeatEvents();

            // Initial status check
            this.checkInitialStatus();
        },

        /**
         * Configure WordPress heartbeat settings - only when import is active
         */
        configureHeartbeat: function() {
            // Only configure active heartbeat if there's an active import or scheduled activity
            if (this.shouldEnableHeartbeat()) {
                // Set heartbeat interval to 30 seconds (reduced frequency) only when active
                wp.heartbeat.interval(30);

                // Enable our custom data in heartbeat only when needed
                wp.heartbeat.enqueue('puntwork_import_status', 'init', false);

                PuntWorkJSLogger.debug('Heartbeat enabled for active import state', 'HEARTBEAT');
            } else {
                // For clean/idle state, use minimal heartbeat (60 seconds) only for essential checks
                wp.heartbeat.interval(60);

                // Don't enqueue import status data in idle state to prevent unnecessary queries
                // Comment out: wp.heartbeat.enqueue('puntwork_import_status', 'init', false);

                PuntWorkJSLogger.debug('Heartbeat disabled for clean idle state', 'HEARTBEAT');
            }
        },

        /**
         * Determine if heartbeat should be actively enabled
         */
        shouldEnableHeartbeat: function() {
            // Only enable active heartbeat if:
            // 1. Import is currently active, OR
            // 2. We detected recent import activity (within last 30 minutes), OR
            // 3. There are scheduled imports pending

            // Check if we have an active import from UI state
            if (this.isImportActive) {
                return true;
            }

            // Check for recent import activity (within 30 minutes)
            var now = Date.now() / 1000;
            var recentActivityThreshold = 30 * 60; // 30 minutes
            if (this.pageLoadTime && (now - this.pageLoadTime) < recentActivityThreshold) {
                // Check if there was a recent import completion
                // This is a proxy - if page was loaded recently, there might be active imports
                if (typeof JobImportUI !== 'undefined' && JobImportUI.importSuccess !== undefined) {
                    return true;
                }
            }

            // Check for scheduled imports configured
            if (typeof JobImportScheduling !== 'undefined' &&
                JobImportScheduling.isEnabled && JobImportScheduling.isEnabled()) {
                return true;
            }

            // Don't enable active heartbeat monitoring - keep in idle mode
            return false;
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

                // Timing diagnostics: compare server last_update to client time
                try {
                    var serverStatus = update.status || {};
                    var serverLastUpdate = serverStatus.last_update || serverStatus.last_update === 0 ? serverStatus.last_update : null;
                    var clientReceive = Math.floor(Date.now() / 1000);
                    if (serverLastUpdate) {
                        var lag = clientReceive - serverLastUpdate;
                        console.log('[PUNTWORK] Heartbeat update: server last_update=', serverLastUpdate, 'client_receive=', clientReceive, 'lag_seconds=', lag);
                    } else {
                        console.log('[PUNTWORK] Heartbeat update received (no server last_update) client_receive=', clientReceive);
                    }
                } catch (e) {
                    // ignore diagnostics
                }

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
                } else {
                    PuntWorkJSLogger.debug('Heartbeat received but status unchanged', 'HEARTBEAT', {
                        hash: currentHash,
                        processed: update.status.processed,
                        total: update.status.total
                    });
                }
            } else {
                PuntWorkJSLogger.debug('Heartbeat received but no import update data', 'HEARTBEAT', {
                    available_keys: Object.keys(data)
                });
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
            // Prevent duplicate completion handling on page reload
            if (this.completionHandled) {
                return;
            }
            this.completionHandled = true;

            // Only show notifications for imports that completed recently (within last 5 minutes)
            // to avoid showing stale completion notifications from previous page loads or sessions
            var lastUpdate = status.last_update || 0;
            var now = Date.now() / 1000;
            var timeSinceCompletion = now - lastUpdate;
            var SHOW_NOTIFICATION_THRESHOLD = 300; // 5 minutes in seconds

            // Stop considering import active
            this.isImportActive = false;

            // Update UI for completion - check success status
            JobImportUI.resetButtons();

            var isSuccessful = status.success !== false; // Treat undefined/null as success
            var baseMessage = isSuccessful ? 'Import completed successfully!' : 'Import completed with issues';
            var message = status.message || baseMessage;

            $('#status-message').text(isSuccessful ? 'Import Complete' : 'Import Finished');

            // Only show notification if this is a recent completion (within 5 minutes)
            if (timeSinceCompletion <= SHOW_NOTIFICATION_THRESHOLD) {
                // Show completion notification with appropriate type
                var notificationType = isSuccessful ? 'success' : 'warning';
                if (typeof JobImportScheduling !== 'undefined' && JobImportScheduling.showNotification) {
                    JobImportScheduling.showNotification(message, notificationType);
                }

                PuntWorkJSLogger.debug('Showing completion notification for recent import', 'HEARTBEAT', {
                    timeSinceCompletion: timeSinceCompletion,
                    success: isSuccessful,
                    lastUpdate: lastUpdate
                });
            } else {
                PuntWorkJSLogger.debug('Skipping completion notification for stale import status', 'HEARTBEAT', {
                    timeSinceCompletion: timeSinceCompletion,
                    success: isSuccessful,
                    lastUpdate: lastUpdate
                });
            }

            // Refresh history if available - do this regardless of notification timing
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
                var isSuccessful = status.success !== false; // Treat undefined/null as success
                message = isSuccessful ? 'Import completed successfully!' : 'Import completed with issues';
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

                    // Update UI with initial status BUT avoid stale completion notifications
                    if (self.isImportActive) {
                        // If actively running, show with full processing
                        self.updateImportUI(status);
                    } else if (status.complete) {
                        // For completed imports, only process completion and notifications on page load
                        // if it completed very recently (<30 seconds ago) to avoid spam
                        var lastUpdate = status.last_update || 0;
                        var now = Date.now() / 1000;
                        var timeSinceCompletion = now - lastUpdate;
                        var RECENT_COMPLETION_THRESHOLD = 30; // 30 seconds

                        if (timeSinceCompletion <= RECENT_COMPLETION_THRESHOLD) {
                            // Very recent completion - show with notification
                            PuntWorkJSLogger.debug('Showing recent completion status on page load', 'HEARTBEAT', {
                                timeSinceCompletion: timeSinceCompletion,
                                lastUpdate: lastUpdate
                            });
                            self.updateImportUI(status);
                        } else {
                            // Old completion - just update UI without triggering completion handler
                            PuntWorkJSLogger.debug('Not showing old completion notification on page load', 'HEARTBEAT', {
                                timeSinceCompletion: timeSinceCompletion,
                                lastUpdate: lastUpdate
                            });

                            // Update progress and status message, but skip completion handling
                            JobImportUI.updateProgress(status);
                            // Calling updateStatusMessage directly to show final state without completion
                            if (status.complete) {
                                var isSuccessful = status.success !== false;
                                var message = isSuccessful ? 'Import completed successfully!' : 'Import completed with issues';
                                $('#status-message').text(message);
                            }
                        }
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
