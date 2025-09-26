/**
 * puntWork JavaScript Logger
 * Enhanced console logging for development and debugging
 */

(function($, window, document) {
    'use strict';

    var PuntWorkJSLogger = {
        // Configuration
        config: {
            enableConsole: true,
            enableGrouping: true,
            maxLogHistory: 100,
            logLevel: 'DEBUG', // DEBUG, INFO, WARN, ERROR
            enablePerformanceMonitoring: true,
            performanceLogInterval: 30000 // 30 seconds
        },

        // Log history for debugging
        logHistory: [],

        // Performance monitoring session
        performanceSession: null,
        performanceInterval: null,

        // Log levels
        LEVELS: {
            DEBUG: 0,
            INFO: 1,
            WARN: 2,
            ERROR: 3
        },

        /**
         * Initialize the logger
         */
        init: function() {
            this.log('info', 'PuntWork JS Logger initialized', 'SYSTEM');
            this.addDevHelpers();
        },

        /**
         * Add development helper functions to window
         */
        addDevHelpers: function() {
            if (typeof window !== 'undefined') {
                window.pwLog = {
                    history: () => this.getLogHistory(),
                    clear: () => this.clearLogHistory(),
                    level: (level) => this.setLogLevel(level),
                    config: () => this.config,
                    perf: {
                        start: (name) => this.startPerformanceSession(name || 'dev-session'),
                        end: () => this.endPerformanceSession(),
                        memory: () => this.logMemoryUsage(),
                        system: () => this.logSystemPerformance(),
                        timer: (label) => this.startTimer(label)
                    }
                };
            }
        },

        /**
         * Set the minimum log level
         * @param {string} level - Log level (DEBUG, INFO, WARN, ERROR)
         */
        setLogLevel: function(level) {
            if (this.LEVELS.hasOwnProperty(level.toUpperCase())) {
                this.config.logLevel = level.toUpperCase();
                this.log('info', 'Log level set to: ' + level, 'SYSTEM');
            } else {
                console.warn('[PUNTWORK] Invalid log level: ' + level + '. Use: DEBUG, INFO, WARN, ERROR');
            }
        },

        /**
         * Log a debug message
         * @param {string} message - Log message
         * @param {string} context - Context identifier
         * @param {*} data - Additional data to log
         */
        debug: function(message, context, data) {
            this.log('debug', message, context, data);
        },

        /**
         * Log an info message
         * @param {string} message - Log message
         * @param {string} context - Context identifier
         * @param {*} data - Additional data to log
         */
        info: function(message, context, data) {
            this.log('info', message, context, data);
        },

        /**
         * Log a warning message
         * @param {string} message - Log message
         * @param {string} context - Context identifier
         * @param {*} data - Additional data to log
         */
        warn: function(message, context, data) {
            this.log('warn', message, context, data);
        },

        /**
         * Log an error message
         * @param {string} message - Log message
         * @param {string} context - Context identifier
         * @param {*} data - Additional data to log
         */
        error: function(message, context, data) {
            this.log('error', message, context, data);
        },

        /**
         * Core logging method
         * @param {string} level - Log level
         * @param {string} message - Log message
         * @param {string} context - Context identifier
         * @param {*} data - Additional data to log
         */
        log: function(level, message, context, data) {
            // Check if logging is enabled and level is appropriate
            if (!this.config.enableConsole) return;
            if (this.LEVELS[level.toUpperCase()] < this.LEVELS[this.config.logLevel]) return;

            var timestamp = new Date().toISOString();
            var formattedMessage = '[' + timestamp + '] [' + level.toUpperCase() + '] [' + (context || 'GENERAL') + '] ' + message;

            // Add to history
            this.addToHistory({
                timestamp: timestamp,
                level: level,
                context: context,
                message: message,
                data: data
            });

            // Console output with appropriate method
            var consoleMethod = level.toLowerCase();
            if (typeof console[consoleMethod] === 'function') {
                if (data !== undefined) {
                    console[consoleMethod](formattedMessage, data);
                } else {
                    console[consoleMethod](formattedMessage);
                }
            } else {
                console.log(formattedMessage, data || '');
            }

            // Track performance session metrics
            if (this.performanceSession) {
                if (level === 'error') this.performanceSession.errors++;
                if (level === 'warn') this.performanceSession.warnings++;
            }

            // Group related logs if enabled
            if (this.config.enableGrouping && context) {
                this.groupLogsByContext(context);
            }
        },

        /**
         * Add log entry to history
         * @param {object} logEntry - Log entry object
         */
        addToHistory: function(logEntry) {
            this.logHistory.push(logEntry);

            // Maintain max history size
            if (this.logHistory.length > this.config.maxLogHistory) {
                this.logHistory.shift();
            }
        },

        /**
         * Get log history
         * @param {string} context - Optional context filter
         * @param {string} level - Optional level filter
         * @returns {array} Filtered log history
         */
        getLogHistory: function(context, level) {
            var filtered = this.logHistory;

            if (context) {
                filtered = filtered.filter(function(log) {
                    return log.context === context;
                });
            }

            if (level) {
                filtered = filtered.filter(function(log) {
                    return log.level === level;
                });
            }

            return filtered;
        },

        /**
         * Clear log history
         */
        clearLogHistory: function() {
            this.logHistory = [];
            this.log('info', 'Log history cleared', 'SYSTEM');
        },

        /**
         * Group logs by context for better organization
         * @param {string} context - Context to group
         */
        groupLogsByContext: function(context) {
            // This is a placeholder for future grouping functionality
            // Could be enhanced to create collapsible console groups
        },

        /**
         * Log AJAX request details
         * @param {string} action - AJAX action
         * @param {object} data - Request data
         */
        logAjaxRequest: function(action, data) {
            this.debug('AJAX Request: ' + action, 'AJAX', this.sanitizeData(data));

            // Track AJAX calls in performance session
            if (this.performanceSession) {
                this.performanceSession.ajaxCalls++;
            }
        },

        /**
         * Log AJAX response details
         * @param {string} action - AJAX action
         * @param {*} response - Response data
         * @param {boolean} success - Whether request was successful
         */
        logAjaxResponse: function(action, response, success) {
            var level = success ? 'debug' : 'error';
            var status = success ? 'SUCCESS' : 'FAILED';
            this.log(level, 'AJAX Response: ' + action + ' - ' + status, 'AJAX', this.sanitizeData(response));
        },

        /**
         * Log batch processing progress
         * @param {number} processed - Items processed
         * @param {number} total - Total items
         * @param {number} batchSize - Current batch size
         */
        logBatchProgress: function(processed, total, batchSize) {
            var percent = total > 0 ? Math.round((processed / total) * 100) : 0;
            this.info('Batch Progress: ' + processed + '/' + total + ' (' + percent + '%) | Batch Size: ' + batchSize, 'BATCH', {
                processed: processed,
                total: total,
                percentage: percent,
                batchSize: batchSize
            });
        },

        /**
         * Log UI state changes
         * @param {string} element - UI element identifier
         * @param {string} action - Action performed
         * @param {*} details - Additional details
         */
        logUIChange: function(element, action, details) {
            this.debug('UI Change: ' + element + ' - ' + action, 'UI', details);
        },

        /**
         * Sanitize data for logging (remove sensitive information)
         * @param {*} data - Data to sanitize
         * @returns {*} Sanitized data
         */
        sanitizeData: function(data) {
            if (typeof data === 'object' && data !== null) {
                var sanitized = Array.isArray(data) ? [] : {};

                for (var key in data) {
                    if (data.hasOwnProperty(key)) {
                        // Remove sensitive fields
                        if (key.toLowerCase().includes('password') ||
                            key.toLowerCase().includes('key') ||
                            key.toLowerCase().includes('secret') ||
                            key.toLowerCase().includes('token') ||
                            key.toLowerCase().includes('nonce')) {
                            sanitized[key] = '[REDACTED]';
                        } else if (typeof data[key] === 'object') {
                            sanitized[key] = this.sanitizeData(data[key]);
                        } else {
                            sanitized[key] = data[key];
                        }
                    }
                }

                return sanitized;
            }

            return data;
        },

        /**
         * Create a performance timer
         * @param {string} label - Timer label
         * @returns {function} Function to end timer
         */
        startTimer: function(label) {
            var startTime = performance.now();
            this.debug('Timer started: ' + label, 'PERF');

            return function() {
                var endTime = performance.now();
                var duration = Math.round((endTime - startTime) * 100) / 100;
                this.debug('Timer ended: ' + label + ' - ' + duration + 'ms', 'PERF');
            }.bind(this);
        },

        /**
         * Log memory usage information with enhanced details
         */
        logMemoryUsage: function() {
            if (performance.memory) {
                var mem = performance.memory;
                var usedMB = Math.round(mem.usedJSHeapSize / 1024 / 1024);
                var totalMB = Math.round(mem.totalJSHeapSize / 1024 / 1024);
                var limitMB = Math.round(mem.jsHeapSizeLimit / 1024 / 1024);
                var usagePercent = Math.round((usedMB / limitMB) * 100);

                var level = 'debug';
                if (usagePercent > 80) level = 'warn';
                if (usagePercent > 90) level = 'error';

                this.log(level, 'Memory Usage: ' + usedMB + 'MB/' + totalMB + 'MB (' + usagePercent + '% of ' + limitMB + 'MB limit)', 'PERF', {
                    usedMB: usedMB,
                    totalMB: totalMB,
                    limitMB: limitMB,
                    usagePercent: usagePercent,
                    timestamp: new Date().toISOString()
                });
            } else {
                this.debug('Memory monitoring not available in this browser', 'PERF');
            }
        },

        /**
         * Monitor AJAX request performance
         * @param {string} action - AJAX action name
         * @param {function} ajaxCall - Function that returns AJAX promise
         * @returns {Promise} Monitored AJAX promise
         */
        monitorAjaxPerformance: function(action, ajaxCall) {
            var startTime = performance.now();
            this.debug('AJAX Performance Monitor started: ' + action, 'PERF');

            return ajaxCall().then(function(response) {
                var endTime = performance.now();
                var duration = Math.round((endTime - startTime) * 100) / 100;
                this.info('AJAX Performance: ' + action + ' completed in ' + duration + 'ms', 'PERF', {
                    action: action,
                    duration: duration,
                    success: true,
                    timestamp: new Date().toISOString()
                });
                return response;
            }.bind(this)).catch(function(error) {
                var endTime = performance.now();
                var duration = Math.round((endTime - startTime) * 100) / 100;
                this.error('AJAX Performance: ' + action + ' failed after ' + duration + 'ms', 'PERF', {
                    action: action,
                    duration: duration,
                    success: false,
                    error: error,
                    timestamp: new Date().toISOString()
                });
                throw error;
            }.bind(this));
        },

        /**
         * Monitor batch processing performance
         * @param {string} operation - Operation name
         * @param {number} batchSize - Size of current batch
         * @param {number} totalItems - Total items to process
         * @param {function} processFunction - Function to monitor
         * @returns {Promise} Monitored process promise
         */
        monitorBatchPerformance: function(operation, batchSize, totalItems, processFunction) {
            var startTime = performance.now();
            var initialMemory = performance.memory ? performance.memory.usedJSHeapSize : 0;

            this.info('Batch Performance Monitor started: ' + operation + ' (batch: ' + batchSize + ', total: ' + totalItems + ')', 'PERF');

            return processFunction().then(function(result) {
                var endTime = performance.now();
                var duration = Math.round((endTime - startTime) * 100) / 100;
                var finalMemory = performance.memory ? performance.memory.usedJSHeapSize : 0;
                var memoryDelta = finalMemory - initialMemory;
                var itemsPerSecond = batchSize > 0 ? Math.round((batchSize / duration) * 1000) : 0;

                this.info('Batch Performance: ' + operation + ' completed in ' + duration + 'ms (' + itemsPerSecond + ' items/sec)', 'PERF', {
                    operation: operation,
                    batchSize: batchSize,
                    totalItems: totalItems,
                    duration: duration,
                    itemsPerSecond: itemsPerSecond,
                    memoryDelta: Math.round(memoryDelta / 1024) + 'KB',
                    success: true,
                    timestamp: new Date().toISOString()
                });

                // Log memory usage if significant change
                if (Math.abs(memoryDelta) > 1024 * 1024) { // 1MB change
                    this.logMemoryUsage();
                }

                return result;
            }.bind(this)).catch(function(error) {
                var endTime = performance.now();
                var duration = Math.round((endTime - startTime) * 100) / 100;
                this.error('Batch Performance: ' + operation + ' failed after ' + duration + 'ms', 'PERF', {
                    operation: operation,
                    batchSize: batchSize,
                    totalItems: totalItems,
                    duration: duration,
                    success: false,
                    error: error,
                    timestamp: new Date().toISOString()
                });
                throw error;
            }.bind(this));
        },

        /**
         * Log system performance metrics
         */
        logSystemPerformance: function() {
            var metrics = {
                timestamp: new Date().toISOString(),
                userAgent: navigator.userAgent,
                language: navigator.language,
                platform: navigator.platform,
                cookieEnabled: navigator.cookieEnabled,
                onLine: navigator.onLine
            };

            // Add timing information if available
            if (performance.timing) {
                var timing = performance.timing;
                metrics.pageLoad = {
                    navigationStart: timing.navigationStart,
                    loadEventEnd: timing.loadEventEnd,
                    totalLoadTime: timing.loadEventEnd - timing.navigationStart,
                    domContentLoaded: timing.domContentLoadedEventEnd - timing.navigationStart,
                    firstPaint: timing.responseStart - timing.navigationStart
                };
            }

            // Add memory information
            if (performance.memory) {
                var mem = performance.memory;
                metrics.memory = {
                    used: Math.round(mem.usedJSHeapSize / 1024 / 1024) + 'MB',
                    total: Math.round(mem.totalJSHeapSize / 1024 / 1024) + 'MB',
                    limit: Math.round(mem.jsHeapSizeLimit / 1024 / 1024) + 'MB'
                };
            }

            this.info('System Performance Metrics', 'PERF', metrics);
        },

        /**
         * Start comprehensive performance monitoring session
         * @param {string} sessionName - Name for the monitoring session
         */
        startPerformanceSession: function(sessionName) {
            this.performanceSession = {
                name: sessionName,
                startTime: performance.now(),
                initialMemory: performance.memory ? performance.memory.usedJSHeapSize : 0,
                ajaxCalls: 0,
                errors: 0,
                warnings: 0
            };

            this.info('Performance monitoring session started: ' + sessionName, 'PERF');
            this.logSystemPerformance();
            this.logMemoryUsage();

            // Set up periodic monitoring
            this.performanceInterval = setInterval(function() {
                this.logMemoryUsage();
            }.bind(this), this.config.performanceLogInterval);
        },

        /**
         * End performance monitoring session
         */
        endPerformanceSession: function() {
            if (!this.performanceSession) {
                this.warn('No active performance session to end', 'PERF');
                return;
            }

            var session = this.performanceSession;
            var endTime = performance.now();
            var duration = Math.round((endTime - session.startTime) * 100) / 100;
            var finalMemory = performance.memory ? performance.memory.usedJSHeapSize : 0;
            var memoryDelta = finalMemory - session.initialMemory;

            this.info('Performance monitoring session ended: ' + session.name + ' (' + duration + 'ms total)', 'PERF', {
                sessionName: session.name,
                duration: duration,
                memoryDelta: Math.round(memoryDelta / 1024 / 1024) + 'MB',
                ajaxCalls: session.ajaxCalls,
                errors: session.errors,
                warnings: session.warnings,
                timestamp: new Date().toISOString()
            });

            // Clear interval
            if (this.performanceInterval) {
                clearInterval(this.performanceInterval);
                this.performanceInterval = null;
            }

            this.performanceSession = null;
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        PuntWorkJSLogger.init();
    });

    // Expose to global scope
    window.PuntWorkJSLogger = PuntWorkJSLogger;

})(jQuery, window, document);