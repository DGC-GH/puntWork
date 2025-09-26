/**
 * Job Import Admin - Real-time Updates Module
 * Handles Server-Sent Events (SSE) for real-time import progress updates
 */

(function($, window, document) {
    'use strict';

    var JobImportRealtime = {
        eventSource: null,
        isConnected: false,
        reconnectAttempts: 0,
        maxReconnectAttempts: 5,
        reconnectDelay: 1000, // Start with 1 second
        lastEventId: null,

        /**
         * Initialize the real-time updates module
         */
        init: function() {
            console.log('[PUNTWORK] JobImportRealtime initialized');
        },

        /**
         * Connect to Server-Sent Events for real-time updates
         * @param {string} apiKey - API key for authentication
         * @returns {boolean} Success status
         */
        connect: function(apiKey) {
            if (this.isConnected) {
                console.log('[PUNTWORK] Already connected to SSE');
                return true;
            }

            if (!apiKey) {
                console.error('[PUNTWORK] No API key provided for SSE connection');
                return false;
            }

            try {
                var sseUrl = window.ajaxurl.replace('admin-ajax.php', 'wp-json/puntwork/v1/import-progress?api_key=' + encodeURIComponent(apiKey));

                console.log('[PUNTWORK] Connecting to SSE:', sseUrl.replace(apiKey, '[REDACTED]'));

                this.eventSource = new EventSource(sseUrl);

                // Handle connection opened
                this.eventSource.onopen = function(event) {
                    console.log('[PUNTWORK] SSE connection opened');
                    JobImportRealtime.isConnected = true;
                    JobImportRealtime.reconnectAttempts = 0;
                    JobImportRealtime.reconnectDelay = 1000;

                    // Update UI to show real-time connection
                    JobImportRealtime.updateConnectionStatus('connected');
                };

                // Handle connection errors
                this.eventSource.onerror = function(event) {
                    console.error('[PUNTWORK] SSE connection error:', event);
                    JobImportRealtime.isConnected = false;
                    JobImportRealtime.updateConnectionStatus('error');

                    // Attempt to reconnect
                    JobImportRealtime.handleReconnect(apiKey);
                };

                // Handle connected event
                this.eventSource.addEventListener('connected', function(event) {
                    var data = JSON.parse(event.data);
                    console.log('[PUNTWORK] SSE connected event:', data);
                    PuntWorkJSLogger.info('Real-time updates connected', 'REALTIME');
                });

                // Handle progress updates
                this.eventSource.addEventListener('progress', function(event) {
                    var data = JSON.parse(event.data);
                    console.log('[PUNTWORK] SSE progress update:', data);

                    // Update UI with real-time data
                    if (data.status) {
                        JobImportUI.updateProgress(data.status);
                        JobImportRealtime.lastEventId = event.lastEventId;
                    }
                });

                // Handle completion
                this.eventSource.addEventListener('complete', function(event) {
                    var data = JSON.parse(event.data);
                    console.log('[PUNTWORK] SSE import completed:', data);

                    // Update UI with final status
                    if (data.status) {
                        JobImportUI.updateProgress(data.status);
                    }

                    // Show completion message
                    if (data.message) {
                        $('#status-message').text(data.message);
                    }

                    // Reset buttons and stop real-time updates
                    JobImportUI.resetButtons();
                    JobImportRealtime.disconnect();

                    PuntWorkJSLogger.info('Import completed via real-time updates', 'REALTIME');
                });

                // Handle errors
                this.eventSource.addEventListener('error', function(event) {
                    var data = JSON.parse(event.data);
                    console.error('[PUNTWORK] SSE error event:', data);

                    // Show error in UI
                    if (data.error) {
                        $('#status-message').text('Error: ' + data.error);
                        JobImportUI.resetButtons();
                        JobImportRealtime.disconnect();
                    }
                });

                return true;

            } catch (error) {
                console.error('[PUNTWORK] Failed to connect to SSE:', error);
                this.updateConnectionStatus('error');
                return false;
            }
        },

        /**
         * Disconnect from Server-Sent Events
         */
        disconnect: function() {
            if (this.eventSource) {
                console.log('[PUNTWORK] Disconnecting from SSE');
                this.eventSource.close();
                this.eventSource = null;
                this.isConnected = false;
                this.updateConnectionStatus('disconnected');
                PuntWorkJSLogger.info('Real-time updates disconnected', 'REALTIME');
            }
        },

        /**
         * Handle reconnection logic
         * @param {string} apiKey - API key for reconnection
         */
        handleReconnect: function(apiKey) {
            if (this.reconnectAttempts >= this.maxReconnectAttempts) {
                console.error('[PUNTWORK] Max reconnection attempts reached');
                this.updateConnectionStatus('failed');
                return;
            }

            this.reconnectAttempts++;
            console.log('[PUNTWORK] Attempting SSE reconnection in', this.reconnectDelay, 'ms (attempt', this.reconnectAttempts, 'of', this.maxReconnectAttempts, ')');

            setTimeout(function() {
                JobImportRealtime.connect(apiKey);
            }, this.reconnectDelay);

            // Exponential backoff
            this.reconnectDelay = Math.min(this.reconnectDelay * 2, 30000); // Max 30 seconds
        },

        /**
         * Update connection status in UI
         * @param {string} status - Connection status ('connected', 'error', 'disconnected', 'failed')
         */
        updateConnectionStatus: function(status) {
            var statusElement = $('#realtime-status');
            if (!statusElement.length) {
                // Create status element if it doesn't exist
                $('#status-message').after('<div id="realtime-status" class="realtime-status"></div>');
                statusElement = $('#realtime-status');
            }

            var statusText = '';
            var statusClass = '';

            switch (status) {
                case 'connected':
                    statusText = '🔴 Real-time updates active';
                    statusClass = 'connected';
                    break;
                case 'error':
                    statusText = '🟡 Real-time connection lost - retrying...';
                    statusClass = 'error';
                    break;
                case 'disconnected':
                    statusText = '⚪ Real-time updates disconnected';
                    statusClass = 'disconnected';
                    break;
                case 'failed':
                    statusText = '❌ Real-time updates failed';
                    statusClass = 'failed';
                    break;
                default:
                    statusText = '';
                    statusClass = '';
            }

            statusElement.removeClass('connected error disconnected failed').addClass(statusClass);
            statusElement.text(statusText);
        },

        /**
         * Check if real-time updates are supported
         * @returns {boolean} Support status
         */
        isSupported: function() {
            return typeof(EventSource) !== 'undefined';
        },

        /**
         * Get connection status
         * @returns {boolean} Connection status
         */
        getConnectionStatus: function() {
            return this.isConnected;
        }
    };

    // Expose to global scope
    window.JobImportRealtime = JobImportRealtime;

})(jQuery, window, document);