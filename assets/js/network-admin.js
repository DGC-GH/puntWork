/**
 * Network Admin JavaScript
 *
 * Handles the network management interface interactions.
 *
 * @package    Puntwork
 * @subpackage MultiSite
 * @since      2.3.0
 */

(function($) {
    'use strict';

    /**
     * Network Admin Manager
     */
    var NetworkAdmin = {

        /**
         * Initialize the network admin interface
         */
        init: function() {
            this.bindEvents();
            this.loadInitialData();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Network sync
            $('#sync-network').on('click', function(e) {
                e.preventDefault();
                self.syncNetworkData();
            });

            // Refresh statistics
            $('#refresh-stats').on('click', function(e) {
                e.preventDefault();
                self.loadNetworkStats();
                self.loadSitesList();
            });

            // Job distribution
            $('#distribute-jobs').on('click', function(e) {
                e.preventDefault();
                self.distributeJobs();
            });

            // Test site connections
            $(document).on('click', '.test-connection', function(e) {
                e.preventDefault();
                var siteId = $(this).data('site-id');
                self.testSiteConnection(siteId);
            });

            // Auto-refresh stats
            setInterval(function() {
                self.loadNetworkStats();
            }, 30000);
        },

        /**
         * Load initial data
         */
        loadInitialData: function() {
            this.loadNetworkStats();
            this.loadSitesList();
            this.loadActivityLog();
        },

        /**
         * Load network statistics
         */
        loadNetworkStats: function() {
            var self = this;

            $.ajax({
                url: puntworkNetwork.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_network_stats',
                    nonce: puntworkNetwork.stats_nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.updateNetworkStats(response.data);
                    } else {
                        self.showError('Failed to load network stats: ' + response.data);
                    }
                },
                error: function(xhr, status, error) {
                    self.showError('Network error while loading stats: ' + error);
                }
            });
        },

        /**
         * Update network statistics display
         */
        updateNetworkStats: function(data) {
            $('#total-sites').text(data.total_sites || 0);
            $('#active-sites').text(data.active_sites || 0);
            $('#total-jobs').text(data.total_jobs || 0);
            $('#avg-success-rate').text((data.avg_success_rate || 0) + '%');
        },

        /**
         * Load sites list
         */
        loadSitesList: function() {
            var self = this;

            $('#sites-list').html('<div class="loading"><div class="spinner"></div>Loading sites...</div>');

            $.ajax({
                url: puntworkNetwork.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_network_stats',
                    nonce: puntworkNetwork.stats_nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.renderSitesList(response.data.sites);
                    } else {
                        $('#sites-list').html('<p class="error">Error loading sites: ' + response.data + '</p>');
                    }
                },
                error: function(xhr, status, error) {
                    $('#sites-list').html('<p class="error">Error loading sites: ' + error + '</p>');
                }
            });
        },

        /**
         * Render sites list
         */
        renderSitesList: function(sites) {
            if (!sites || sites.length === 0) {
                $('#sites-list').html('<p>No sites found in network</p>');
                return;
            }

            var html = '';
            sites.forEach(function(site) {
                var statusClass = site.stats.active_feeds > 0 ? 'active' : 'inactive';
                var statusText = site.stats.active_feeds > 0 ? 'Active' : 'Inactive';

                html += '<div class="site-card" data-site-id="' + site.id + '">';
                html += '<div class="site-card-header">';
                html += '<span class="site-name">' + this.escapeHtml(site.name) + '</span>';
                html += '<span class="site-status ' + statusClass + '">' + statusText + '</span>';
                html += '</div>';
                html += '<div class="site-metrics">';
                html += '<div class="site-metric"><span class="site-metric-label">Jobs:</span> <span class="site-metric-value">' + site.stats.total_jobs + '</span></div>';
                html += '<div class="site-metric"><span class="site-metric-label">Feeds:</span> <span class="site-metric-value">' + site.stats.active_feeds + '</span></div>';
                html += '<div class="site-metric"><span class="site-metric-label">Success:</span> <span class="site-metric-value">' + site.stats.success_rate + '%</span></div>';
                html += '<div class="site-metric"><span class="site-metric-label">Load:</span> <span class="site-metric-value">' + site.stats.current_load + '%</span></div>';
                html += '</div>';
                html += '<div class="site-actions">';
                html += '<button type="button" class="button button-small test-connection" data-site-id="' + site.id + '">Test Connection</button>';
                html += '</div>';
                html += '</div>';
            }, this);

            $('#sites-list').html(html);
        },

        /**
         * Sync network data
         */
        syncNetworkData: function() {
            var self = this;
            var $button = $('#sync-network');
            var originalText = $button.text();

            $button.prop('disabled', true).text(puntworkNetwork.strings.syncing);

            $.ajax({
                url: puntworkNetwork.ajax_url,
                type: 'POST',
                data: {
                    action: 'sync_network_jobs',
                    nonce: puntworkNetwork.sync_nonce
                },
                success: function(response) {
                    if (response.success) {
                        $button.text(puntworkNetwork.strings.sync_complete);
                        self.loadSitesList();
                        self.addActivityLog('success', 'Network sync completed successfully');
                        self.showSuccess('Network sync completed successfully');
                    } else {
                        $button.text(puntworkNetwork.strings.sync_failed);
                        self.addActivityLog('error', 'Network sync failed: ' + response.data);
                        self.showError('Network sync failed: ' + response.data);
                    }
                },
                error: function(xhr, status, error) {
                    $button.text(puntworkNetwork.strings.sync_failed);
                    self.addActivityLog('error', 'Network sync failed: ' + error);
                    self.showError('Network sync failed: ' + error);
                },
                complete: function() {
                    setTimeout(function() {
                        $button.prop('disabled', false).text(originalText);
                    }, 2000);
                }
            });
        },

        /**
         * Distribute jobs across network
         */
        distributeJobs: function() {
            var self = this;
            var jobsJson = $('#distribution-jobs').val().trim();
            var strategy = $('#distribution-strategy-select').val();

            if (!jobsJson) {
                this.showError('Please enter jobs to distribute');
                return;
            }

            var jobs;
            try {
                jobs = JSON.parse(jobsJson);
            } catch (e) {
                this.showError('Invalid JSON format');
                return;
            }

            var $button = $('#distribute-jobs');
            var originalText = $button.text();

            $button.prop('disabled', true).text(puntworkNetwork.strings.distributing);

            $.ajax({
                url: puntworkNetwork.ajax_url,
                type: 'POST',
                data: {
                    action: 'distribute_jobs_network',
                    nonce: puntworkNetwork.distribute_nonce,
                    jobs: JSON.stringify(jobs),
                    strategy: strategy
                },
                success: function(response) {
                    if (response.success) {
                        $button.text(puntworkNetwork.strings.distribution_complete);
                        self.showDistributionResults(response.data.distribution);
                        self.addActivityLog('success', 'Jobs distributed successfully');
                        self.showSuccess('Jobs distributed successfully');
                    } else {
                        $button.text(puntworkNetwork.strings.distribution_failed);
                        self.addActivityLog('error', 'Job distribution failed: ' + response.data);
                        self.showError('Job distribution failed: ' + response.data);
                    }
                },
                error: function(xhr, status, error) {
                    $button.text(puntworkNetwork.strings.distribution_failed);
                    self.addActivityLog('error', 'Job distribution failed: ' + error);
                    self.showError('Job distribution failed: ' + error);
                },
                complete: function() {
                    setTimeout(function() {
                        $button.prop('disabled', false).text(originalText);
                    }, 2000);
                }
            });
        },

        /**
         * Show distribution results
         */
        showDistributionResults: function(distribution) {
            var html = '<h3>Distribution Results</h3>';

            if (distribution.distributed) {
                html += '<div class="distribution-summary">';
                Object.keys(distribution.distributed).forEach(function(siteId) {
                    var jobs = distribution.distributed[siteId];
                    html += '<div class="distribution-site">';
                    html += '<strong>Site ' + siteId + ':</strong> ' + jobs.length + ' jobs';
                    html += '</div>';
                });
                html += '</div>';
            }

            if (distribution.errors && distribution.errors.length > 0) {
                html += '<div class="distribution-errors">';
                html += '<h4>Errors:</h4>';
                html += '<ul>';
                distribution.errors.forEach(function(error) {
                    html += '<li>' + this.escapeHtml(error) + '</li>';
                }, this);
                html += '</ul>';
                html += '</div>';
            }

            $('#distribution-results').html(html).show();
        },

        /**
         * Test site connection
         */
        testSiteConnection: function(siteId) {
            var self = this;

            var $button = $('.test-connection[data-site-id="' + siteId + '"]');
            var originalText = $button.text();

            $button.prop('disabled', true).text(puntworkNetwork.strings.testing_connection);

            $.ajax({
                url: puntworkNetwork.ajax_url,
                type: 'POST',
                data: {
                    action: 'puntwork_network_test_connection',
                    nonce: puntworkNetwork.nonce,
                    site_id: siteId
                },
                success: function(response) {
                    if (response.success) {
                        $button.text(puntworkNetwork.strings.connection_success);
                        self.addActivityLog('success', 'Connection test successful for site ' + siteId);
                        self.showSuccess('Connection successful for site ' + siteId);
                    } else {
                        $button.text(puntworkNetwork.strings.connection_failed);
                        self.addActivityLog('error', 'Connection test failed for site ' + siteId + ': ' + response.data);
                        self.showError('Connection failed for site ' + siteId + ': ' + response.data);
                    }
                },
                error: function(xhr, status, error) {
                    $button.text(puntworkNetwork.strings.connection_failed);
                    self.addActivityLog('error', 'Connection test failed for site ' + siteId + ': ' + error);
                    self.showError('Connection test failed for site ' + siteId + ': ' + error);
                },
                complete: function() {
                    setTimeout(function() {
                        $button.prop('disabled', false).text(originalText);
                    }, 2000);
                }
            });
        },

        /**
         * Load activity log
         */
        loadActivityLog: function() {
            var activities = this.getStoredActivities();
            this.renderActivityLog(activities);
        },

        /**
         * Add activity log entry
         */
        addActivityLog: function(type, message) {
            var activity = {
                timestamp: new Date().toISOString(),
                type: type,
                message: message
            };

            var activities = this.getStoredActivities();
            activities.unshift(activity);
            activities = activities.slice(0, 50); // Keep last 50 entries

            this.storeActivities(activities);
            this.renderActivityLog(activities);
        },

        /**
         * Render activity log
         */
        renderActivityLog: function(activities) {
            if (activities.length === 0) {
                $('#network-activity').html('<p>No recent activity</p>');
                return;
            }

            var html = '';
            activities.forEach(function(activity) {
                var typeClass = activity.type === 'error' ? 'activity-error' : 'activity-success';
                var timestamp = new Date(activity.timestamp).toLocaleString();
                html += '<div class="activity-entry">';
                html += '<div class="activity-timestamp">' + this.escapeHtml(timestamp) + '</div>';
                html += '<div class="activity-message ' + typeClass + '">' + this.escapeHtml(activity.message) + '</div>';
                html += '</div>';
            }, this);

            $('#network-activity').html(html);
        },

        /**
         * Get stored activities
         */
        getStoredActivities: function() {
            var stored = localStorage.getItem('puntwork_network_activity');
            return stored ? JSON.parse(stored) : [];
        },

        /**
         * Store activities
         */
        storeActivities: function(activities) {
            localStorage.setItem('puntwork_network_activity', JSON.stringify(activities));
        },

        /**
         * Show success message
         */
        showSuccess: function(message) {
            this.showMessage(message, 'success');
        },

        /**
         * Show error message
         */
        showError: function(message) {
            this.showMessage(message, 'error');
        },

        /**
         * Show message
         */
        showMessage: function(message, type) {
            // Remove existing messages
            $('.puntwork-network-message').remove();

            var $message = $('<div class="puntwork-network-message ' + type + '">' + this.escapeHtml(message) + '</div>');
            $('.puntwork-network-container').prepend($message);

            // Auto-hide after 5 seconds
            setTimeout(function() {
                $message.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        NetworkAdmin.init();
    });

})(jQuery);