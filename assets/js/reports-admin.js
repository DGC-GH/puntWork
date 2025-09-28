/**
 * Advanced Reporting Admin JavaScript
 *
 * JavaScript functionality for the advanced reporting admin interface.
 *
 * @package    Puntwork
 * @subpackage Reporting
 * @since      2.4.0
 */

(function($) {
    'use strict';

    /**
     * Reporting Admin Manager
     */
    var ReportingAdmin = {

        /**
         * Initialize the reporting admin interface
         */
        init: function() {
            console.log('[PUNTWORK] [REPORTS-INIT] init() called');
            console.log('[PUNTWORK] [REPORTS-INIT] Browser info:', navigator.userAgent);
            console.log('[PUNTWORK] [REPORTS-INIT] Current URL:', window.location.href);
            console.log('[PUNTWORK] [REPORTS-INIT] Timestamp:', new Date().toISOString());

            this.bindEvents();
            this.loadInitialData();

            console.log('[PUNTWORK] [REPORTS-INIT] Initialization completed');
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            console.log('[PUNTWORK] [REPORTS-EVENTS] bindEvents() called');
            var self = this;

            // Report generation form
            $('#report-generator-form').on('submit', function(e) {
                console.log('[PUNTWORK] [REPORTS-EVENTS] Report generation form submitted');
                e.preventDefault();
                self.generateReport();
            });
            console.log('[PUNTWORK] [REPORTS-EVENTS] Report generation form event bound');

            // Export report
            $('#export-report').on('click', function() {
                console.log('[PUNTWORK] [REPORTS-EVENTS] Export report button clicked');
                self.exportReport();
            });
            console.log('[PUNTWORK] [REPORTS-EVENTS] Export report button event bound');

            // Schedule report
            $('#schedule-report').on('click', function() {
                console.log('[PUNTWORK] [REPORTS-EVENTS] Schedule report button clicked');
                self.scheduleReport();
            });
            console.log('[PUNTWORK] [REPORTS-EVENTS] Schedule report button event bound');

            // Modal interactions
            $('.puntwork-modal-close').on('click', function() {
                console.log('[PUNTWORK] [REPORTS-EVENTS] Modal close button clicked');
                self.closeModal();
            });
            console.log('[PUNTWORK] [REPORTS-EVENTS] Modal close button event bound');

            $(window).on('click', function(e) {
                if ($(e.target).is('#report-modal')) {
                    console.log('[PUNTWORK] [REPORTS-EVENTS] Window clicked outside modal');
                    self.closeModal();
                }
            });
            console.log('[PUNTWORK] [REPORTS-EVENTS] Window click event bound for modal close');

            // Keyboard navigation
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27 && $('#report-modal').is(':visible')) {
                    console.log('[PUNTWORK] [REPORTS-EVENTS] Escape key pressed, modal visible');
                    self.closeModal();
                }
            });
            console.log('[PUNTWORK] [REPORTS-EVENTS] Keyboard navigation event bound');

            // Filters
            $('#filter-type, #search-reports').on('input change', this.debounce(function() {
                console.log('[PUNTWORK] [REPORTS-EVENTS] Filter input changed, debounced call to loadReportsList');
                self.loadReportsList();
            }, 300));
            console.log('[PUNTWORK] [REPORTS-EVENTS] Filter events bound with debounce');

            // Settings form
            $('#puntwork-reporting-settings').on('submit', function(e) {
                console.log('[PUNTWORK] [REPORTS-EVENTS] Settings form submitted');
                e.preventDefault();
                self.saveSettings();
            });
            console.log('[PUNTWORK] [REPORTS-EVENTS] Settings form event bound');

            console.log('[PUNTWORK] [REPORTS-EVENTS] All event bindings completed');
        },

        /**
         * Load initial data
         */
        loadInitialData: function() {
            console.log('[PUNTWORK] [REPORTS-DATA] loadInitialData() called');
            this.loadReportsList();
            this.loadDashboardStats();
            console.log('[PUNTWORK] [REPORTS-DATA] Initial data loading initiated');
        },

        /**
         * Generate a custom report
         */
        generateReport: function() {
            console.log('[PUNTWORK] [REPORTS-GENERATE] generateReport() called');
            var self = this;
            var formData = new FormData(document.getElementById('report-generator-form'));

            var data = {
                action: 'generate_custom_report',
                nonce: puntworkReports.generate_nonce,
                report_type: formData.get('report_type'),
                date_range: formData.get('date_range'),
                format: formData.get('format')
            };

            console.log('[PUNTWORK] [REPORTS-GENERATE] Report generation data:', data);

            this.showLoading('#generate-report', puntworkReports.strings.generating);

            $.ajax({
                url: puntworkReports.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    console.log('[PUNTWORK] [REPORTS-GENERATE] AJAX success response:', response);
                    if (response.success) {
                        self.showReportPreview(response.data);
                        self.loadReportsList();
                        self.showMessage(puntworkReports.strings.report_generated, 'success');
                        console.log('[PUNTWORK] [REPORTS-GENERATE] Report generated successfully');
                    } else {
                        console.error('[PUNTWORK] [REPORTS-GENERATE] Report generation failed:', response.data);
                        self.showMessage(response.data || puntworkReports.strings.error, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[PUNTWORK] [REPORTS-GENERATE] AJAX error:', {xhr: xhr, status: status, error: error});
                    self.showMessage(puntworkReports.strings.error + ': ' + error, 'error');
                },
                complete: function() {
                    self.hideLoading('#generate-report', 'Generate Report');
                    console.log('[PUNTWORK] [REPORTS-GENERATE] Report generation AJAX completed');
                }
            });
        },

        /**
         * Show report preview
         */
        showReportPreview: function(reportData) {
            console.log('[PUNTWORK] [REPORTS-PREVIEW] showReportPreview() called with data:', reportData);
            if (reportData.formatted) {
                $('#report-content').html(reportData.formatted);
                $('#report-preview').show();
                $('#report-preview')[0].scrollIntoView({ behavior: 'smooth' });

                // Initialize any charts in the preview
                this.initializeCharts();
                console.log('[PUNTWORK] [REPORTS-PREVIEW] Report preview displayed and charts initialized');
            } else {
                console.warn('[PUNTWORK] [REPORTS-PREVIEW] No formatted data in reportData');
            }
        },

        /**
         * Export current report
         */
        exportReport: function() {
            console.log('[PUNTWORK] [REPORTS-EXPORT] exportReport() called');
            var self = this;
            var reportData = $('#report-content').html();

            if (!reportData) {
                console.error('[PUNTWORK] [REPORTS-EXPORT] No report data to export');
                this.showMessage('No report to export', 'error');
                return;
            }

            console.log('[PUNTWORK] [REPORTS-EXPORT] Report data length:', reportData.length);

            var data = {
                action: 'export_current_report',
                nonce: puntworkReports.export_nonce,
                report_data: reportData,
                format: $('#report-format').val()
            };

            console.log('[PUNTWORK] [REPORTS-EXPORT] Export data:', {action: data.action, format: data.format});

            this.showLoading('#export-report', puntworkReports.strings.exporting);

            $.ajax({
                url: puntworkReports.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    console.log('[PUNTWORK] [REPORTS-EXPORT] AJAX success response:', response);
                    if (response.success) {
                        // Trigger download
                        var link = document.createElement('a');
                        link.href = response.data.download_url;
                        link.download = response.data.filename;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);

                        self.showMessage(puntworkReports.strings.report_exported, 'success');
                        console.log('[PUNTWORK] [REPORTS-EXPORT] Report export download triggered');
                    } else {
                        console.error('[PUNTWORK] [REPORTS-EXPORT] Export failed:', response.data);
                        self.showMessage(response.data || puntworkReports.strings.error, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[PUNTWORK] [REPORTS-EXPORT] AJAX error:', {xhr: xhr, status: status, error: error});
                    self.showMessage(puntworkReports.strings.error + ': ' + error, 'error');
                },
                complete: function() {
                    self.hideLoading('#export-report', 'Export Report');
                    console.log('[PUNTWORK] [REPORTS-EXPORT] Export AJAX completed');
                }
            });
        },

        /**
         * Schedule a report
         */
        scheduleReport: function() {
            // Open scheduling modal
            this.showScheduleModal();
        },

        /**
         * Show schedule report modal
         */
        showScheduleModal: function() {
            var modal = $('#report-modal');
            var modalTitle = $('#modal-title');
            var modalContent = $('#modal-content');

            modalTitle.text('Schedule Report');
            modalContent.html(this.getScheduleForm());
            modal.show();

            // Bind schedule form events
            this.bindScheduleFormEvents();
        },

        getScheduleForm: function() {
            return `
                <form id="schedule-report-form">
                    <div class="form-group">
                        <label for="schedule-frequency">Frequency</label>
                        <select id="schedule-frequency" name="frequency" required>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="schedule-time">Time</label>
                        <input type="time" id="schedule-time" name="time" required>
                    </div>
                    <div class="form-group">
                        <label for="schedule-email">Email Recipients</label>
                        <input type="email" id="schedule-email" name="email" placeholder="email@example.com" multiple>
                        <p class="description">Comma-separated email addresses</p>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="button button-primary">Schedule Report</button>
                        <button type="button" class="button cancel-schedule">Cancel</button>
                    </div>
                </form>
            `;
        },

        /**
         * Bind schedule form events
         */
        bindScheduleFormEvents: function() {
            var self = this;

            $('#schedule-report-form').on('submit', function(e) {
                e.preventDefault();
                self.submitScheduleForm();
            });

            $('.cancel-schedule').on('click', function() {
                self.closeModal();
            });
        },

        /**
         * Submit schedule form
         */
        submitScheduleForm: function() {
            var self = this;
            var formData = new FormData(document.getElementById('schedule-report-form'));

            var data = {
                action: 'schedule_report',
                nonce: puntworkReports.nonce,
                frequency: formData.get('frequency'),
                time: formData.get('time'),
                email: formData.get('email'),
                report_config: {
                    type: $('#report-type').val(),
                    date_range: $('#date-range').val(),
                    format: $('#report-format').val()
                }
            };

            this.showLoading('#schedule-report-form button[type="submit"]', 'Scheduling...');

            $.ajax({
                url: puntworkReports.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        self.closeModal();
                        self.showMessage('Report scheduled successfully', 'success');
                        self.loadReportsList();
                    } else {
                        self.showMessage(response.data || puntworkReports.strings.error, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    self.showMessage(puntworkReports.strings.error + ': ' + error, 'error');
                },
                complete: function() {
                    self.hideLoading('#schedule-report-form button[type="submit"]', 'Schedule Report');
                }
            });
        },

        /**
         * Load reports list
         */
        loadReportsList: function() {
            var self = this;
            var filterType = $('#filter-type').val();
            var searchTerm = $('#search-reports').val();

            $('#reports-list').html('<div class="loading"><div class="spinner"></div>Loading reports...</div>');

            $.ajax({
                url: puntworkReports.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_reports_list',
                    nonce: puntworkReports.nonce,
                    filter_type: filterType,
                    search: searchTerm
                },
                success: function(response) {
                    if (response.success) {
                        self.renderReportsList(response.data);
                    } else {
                        $('#reports-list').html('<p class="error">Error loading reports: ' + response.data + '</p>');
                    }
                },
                error: function(xhr, status, error) {
                    $('#reports-list').html('<p class="error">Error loading reports: ' + error + '</p>');
                }
            });
        },

        renderReportsList: function(reports) {
            if (!reports || reports.length === 0) {
                $('#reports-list').html('<p>No reports found</p>');
                return;
            }

            var html = '';
            reports.forEach(function(report) {
                var reportDate = new Date(report.created_at).toLocaleDateString();
                var reportTime = new Date(report.created_at).toLocaleTimeString();
                var statusClass = report.status === 'completed' ? 'success' : 'warning';

                html += '<div class="report-item" data-report-id="' + report.id + '">';
                html += '<div class="report-info">';
                html += '<div class="report-title">' + self.escapeHtml(report.report_title) + '</div>';
                html += '<div class="report-meta">';
                html += 'Type: ' + self.escapeHtml(report.report_type) + ' | ';
                html += 'Format: ' + self.escapeHtml(report.report_format) + ' | ';
                html += 'Created: ' + reportDate + ' ' + reportTime;
                if (report.scheduled) {
                    html += ' | <span class="scheduled-badge">Scheduled</span>';
                }
                html += '</div>';
                html += '</div>';
                html += '<div class="report-item-actions">';
                html += '<button type="button" class="button button-small view-report" data-report-id="' + report.id + '">View</button>';
                html += '<button type="button" class="button button-small export-report-item" data-report-id="' + report.id + '">Export</button>';
                if (!report.scheduled) {
                    html += '<button type="button" class="button button-small delete-report" data-report-id="' + report.id + '">Delete</button>';
                }
                html += '</div>';
                html += '</div>';
            });

            $('#reports-list').html(html);

            // Bind event handlers
            $('.view-report').on('click', function() {
                var reportId = $(this).data('report-id');
                ReportingAdmin.viewReport(reportId);
            });

            $('.export-report-item').on('click', function() {
                var reportId = $(this).data('report-id');
                ReportingAdmin.exportReportItem(reportId);
            });

            $('.delete-report').on('click', function() {
                var reportId = $(this).data('report-id');
                if (confirm(puntworkReports.strings.confirm_delete)) {
                    ReportingAdmin.deleteReport(reportId);
                }
            });
        },

        /**
         * View a specific report
         */
        viewReport: function(reportId) {
            var self = this;
            var modal = $('#report-modal');
            var modalTitle = $('#modal-title');
            var modalContent = $('#modal-content');

            modalTitle.text('Loading Report...');
            modalContent.html('<div class="loading"><div class="spinner"></div>Loading...</div>');
            modal.show();

            $.ajax({
                url: puntworkReports.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_report_data',
                    nonce: puntworkReports.generate_nonce,
                    report_id: reportId
                },
                success: function(response) {
                    if (response.success) {
                        modalTitle.text(self.escapeHtml(response.data.report.report_title));
                        modalContent.html(response.data.report.report_data);
                        self.initializeCharts();
                    } else {
                        modalContent.html('<p class="error">Error loading report: ' + response.data + '</p>');
                    }
                },
                error: function(xhr, status, error) {
                    modalContent.html('<p class="error">Error loading report: ' + error + '</p>');
                }
            });
        },

        /**
         * Export a specific report
         */
        exportReportItem: function(reportId) {
            var self = this;

            var data = {
                action: 'export_report',
                nonce: puntworkReports.export_nonce,
                report_id: reportId
            };

            $.ajax({
                url: puntworkReports.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        // Trigger download
                        var link = document.createElement('a');
                        link.href = response.data.download_url;
                        link.download = response.data.filename;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);

                        self.showMessage(puntworkReports.strings.report_exported, 'success');
                    } else {
                        self.showMessage(response.data || puntworkReports.strings.error, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    self.showMessage(puntworkReports.strings.error + ': ' + error, 'error');
                }
            });
        },

        /**
         * Delete a report
         */
        deleteReport: function(reportId) {
            var self = this;

            $.ajax({
                url: puntworkReports.ajax_url,
                type: 'POST',
                data: {
                    action: 'delete_report',
                    nonce: puntworkReports.delete_nonce,
                    report_id: reportId
                },
                success: function(response) {
                    if (response.success) {
                        self.loadReportsList();
                        self.showMessage(puntworkReports.strings.report_deleted, 'success');
                    } else {
                        self.showMessage(response.data || puntworkReports.strings.error, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    self.showMessage(puntworkReports.strings.error + ': ' + error, 'error');
                }
            });
        },

        /**
         * Load dashboard statistics
         */
        loadDashboardStats: function() {
            var self = this;

            $.ajax({
                url: puntworkReports.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_dashboard_stats',
                    nonce: puntworkReports.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.updateDashboardStats(response.data);
                    }
                }
            });
        },

        /**
         * Update dashboard statistics display
         */
        updateDashboardStats: function(stats) {
            // Update any dashboard stat displays if they exist
            if (stats.total_reports) {
                $('.stat-total-reports').text(stats.total_reports);
            }
            if (stats.scheduled_reports) {
                $('.stat-scheduled-reports').text(stats.scheduled_reports);
            }
            if (stats.today_reports) {
                $('.stat-today-reports').text(stats.today_reports);
            }
        },

        /**
         * Save settings
         */
        saveSettings: function() {
            var self = this;

            var data = {
                action: 'save_reporting_settings',
                nonce: puntworkReports.nonce,
                automated_reports_enabled: $('#automated-reports-enabled').is(':checked') ? 1 : 0,
                report_retention_days: $('#report-retention').val(),
                dashboard_refresh_interval: $('#dashboard-refresh').val()
            };

            this.showLoading('#puntwork-reporting-settings button[type="submit"]', 'Saving...');

            $.ajax({
                url: puntworkReports.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        self.showMessage('Settings saved successfully', 'success');
                    } else {
                        self.showMessage(response.data || puntworkReports.strings.error, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    self.showMessage(puntworkReports.strings.error + ': ' + error, 'error');
                },
                complete: function() {
                    self.hideLoading('#puntwork-reporting-settings button[type="submit"]', 'Save Settings');
                }
            });
        },

        /**
         * Initialize charts in report content
         */
        initializeCharts: function() {
            // Initialize Chart.js charts if Chart.js is available
            if (typeof Chart !== 'undefined') {
                $('.chart-container canvas').each(function() {
                    var canvas = $(this);
                    var chartData = canvas.data('chart');

                    if (chartData) {
                        new Chart(canvas[0].getContext('2d'), chartData);
                    }
                });
            }
        },

        /**
         * Close modal
         */
        closeModal: function() {
            $('#report-modal').hide();
        },

        /**
         * Show loading state
         */
        showLoading: function(selector, text) {
            $(selector).prop('disabled', true).text(text);
        },

        /**
         * Hide loading state
         */
        hideLoading: function(selector, text) {
            $(selector).prop('disabled', false).text(text);
        },

        /**
         * Show message
         */
        showMessage: function(message, type) {
            // Remove existing messages
            $('.puntwork-message').remove();

            var messageClass = type === 'error' ? 'error' : 'success';
            var $message = $('<div class="puntwork-message ' + messageClass + '">' + this.escapeHtml(message) + '</div>');
            $('.puntwork-reports-container').prepend($message);

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
        },

        /**
         * Debounce function
         */
        debounce: function(func, wait) {
            var timeout;
            return function executedFunction() {
                var context = this;
                var args = arguments;
                var later = function() {
                    timeout = null;
                    func.apply(context, args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        ReportingAdmin.init();
    });

    // Expose to global scope for debugging
    window.ReportingAdmin = ReportingAdmin;

})(jQuery);