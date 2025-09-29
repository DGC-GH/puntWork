/**
 * Queue Management Interface for puntWork
 * Provides UI for monitoring and managing background job queues
 */

class PuntworkQueueInterface {
    constructor() {
        this.container = null;
        this.stats = {};
        this.refreshInterval = null;
        this.consecutiveErrors = 0;
        this.maxConsecutiveErrors = 3;
        this.init();
    }

    init() {
        this.createInterface();
        this.bindEvents();
        this.startAutoRefresh();
        this.loadQueueStats();
    }

    createInterface() {
        // Create queue management section
        const queueSection = document.createElement('div');
        queueSection.id = 'puntwork-queue-management';
        queueSection.className = 'puntwork-queue-section';
        queueSection.innerHTML = `
            <div class="queue-header">
                <h3>Background Queue Management</h3>
                <div class="queue-controls">
                    <button id="queue-refresh" class="button button-secondary">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <button id="queue-process" class="button button-primary">
                        <i class="fas fa-play"></i> Process Queue
                    </button>
                    <button id="queue-clear-completed" class="button button-secondary">
                        <i class="fas fa-trash"></i> Clear Completed
                    </button>
                </div>
            </div>

            <div class="queue-stats">
                <div class="stat-card pending">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number" id="queue-pending">0</div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>

                <div class="stat-card processing">
                    <div class="stat-icon">
                        <i class="fas fa-cog fa-spin"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number" id="queue-processing">0</div>
                        <div class="stat-label">Processing</div>
                    </div>
                </div>

                <div class="stat-card completed">
                    <div class="stat-icon">
                        <i class="fas fa-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number" id="queue-completed">0</div>
                        <div class="stat-label">Completed</div>
                    </div>
                </div>

                <div class="stat-card failed">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number" id="queue-failed">0</div>
                        <div class="stat-label">Failed</div>
                    </div>
                </div>
            </div>

            <div class="queue-jobs-list">
                <h4>Recent Jobs</h4>
                <div id="queue-jobs-container" class="jobs-container">
                    <div class="loading">Loading jobs...</div>
                </div>
            </div>

            <div class="queue-actions">
                <button id="queue-add-test-job" class="button button-secondary">
                    <i class="fas fa-plus"></i> Add Test Job
                </button>
                <button id="queue-toggle-auto-refresh" class="button button-secondary">
                    <i class="fas fa-pause"></i> Pause Auto-Refresh
                </button>
            </div>
        `;

        // Add to admin page
        const adminContent = document.querySelector('.wrap') || document.querySelector('#wpbody-content');
        if (adminContent) {
            adminContent.appendChild(queueSection);
        }

        this.container = queueSection;
    }

    bindEvents() {
        // Queue control buttons
        document.getElementById('queue-refresh')?.addEventListener('click', () => {
            this.loadQueueStats();
            this.loadRecentJobs();
        });

        document.getElementById('queue-process')?.addEventListener('click', () => {
            this.processQueue();
        });

        document.getElementById('queue-clear-completed')?.addEventListener('click', () => {
            this.clearCompletedJobs();
        });

        document.getElementById('queue-add-test-job')?.addEventListener('click', () => {
            this.addTestJob();
        });

        document.getElementById('queue-toggle-auto-refresh')?.addEventListener('click', (e) => {
            this.toggleAutoRefresh(e.target);
        });
    }

    async loadQueueStats() {
        try {
            console.log('[QUEUE] Loading queue stats, ajaxurl:', puntworkQueue.ajaxurl);
            const response = await this._retryAjax({
                url: puntworkQueue.ajaxurl,
                type: 'POST',
                data: {
                    action: 'puntwork_get_queue_stats',
                    nonce: puntworkQueue.nonce
                }
            }, 3, 1000);

            console.log('[QUEUE] Response received');
            const data = response;
            console.log('[QUEUE] Response data:', data);

            if (data.success) {
                this.updateStats(data.data);
                this.consecutiveErrors = 0; // Reset error count on success
            } else {
                console.error('Failed to load queue stats:', data.data);
                this.handleAjaxError();
            }
        } catch (error) {
            console.error('Error loading queue stats after retries:', error);
            this.handleAjaxError();
        }
    }

    updateStats(stats) {
        this.stats = stats;

        // Update stat cards
        Object.keys(stats).forEach(key => {
            const element = document.getElementById(`queue-${key}`);
            if (element) {
                element.textContent = stats[key];
            }
        });

        // Update processing animation
        const processingCard = document.querySelector('.stat-card.processing .stat-icon i');
        if (processingCard) {
            if (stats.processing > 0) {
                processingCard.classList.add('fa-spin');
            } else {
                processingCard.classList.remove('fa-spin');
            }
        }
    }

    /**
     * Retry AJAX helper method
     * @param {Object} ajaxOptions - jQuery AJAX options
     * @param {number} maxRetries - Maximum number of retries
     * @param {number} delay - Delay between retries in ms
     * @returns {Promise} Promise that resolves with AJAX response
     */
    async _retryAjax(ajaxOptions, maxRetries = 3, delay = 1000) {
        let lastError;

        for (let attempt = 0; attempt <= maxRetries; attempt++) {
            try {
                const response = await $.ajax(ajaxOptions);
                return response;
            } catch (error) {
                lastError = error;
                console.warn(`[QUEUE] Attempt ${attempt + 1} failed:`, error);

                // If not the last attempt, wait and retry
                if (attempt < maxRetries) {
                    await new Promise(resolve => setTimeout(resolve, delay));
                }
            }
        }

        // If we get here, all retries failed
        throw lastError || new Error('All retry attempts failed');
    }

    async loadRecentJobs() {
        try {
            console.log('[QUEUE] Loading recent jobs, ajaxurl:', puntworkQueue.ajaxurl);
            const response = await this._retryAjax({
                url: puntworkQueue.ajaxurl,
                type: 'POST',
                data: {
                    action: 'puntwork_get_recent_jobs',
                    nonce: puntworkQueue.nonce
                }
            }, 3, 1000);

            console.log('[QUEUE] Recent jobs response received');
            const data = response;
            console.log('[QUEUE] Recent jobs response data:', data);

            if (data.success) {
                this.displayRecentJobs(data.data);
                this.consecutiveErrors = 0; // Reset error count on success
            } else {
                this.handleAjaxError();
            }
        } catch (error) {
            console.error('Error loading recent jobs after retries:', error);
            this.handleAjaxError();
        }
    }

    displayRecentJobs(jobs) {
        const container = document.getElementById('queue-jobs-container');

        if (!jobs || jobs.length === 0) {
            container.innerHTML = '<div class="no-jobs">No recent jobs found</div>';
            return;
        }

        const jobsHtml = jobs.map(job => `
            <div class="job-item job-${job.status}">
                <div class="job-header">
                    <span class="job-type">${this.formatJobType(job.job_type)}</span>
                    <span class="job-status status-${job.status}">${job.status}</span>
                </div>
                <div class="job-details">
                    <span class="job-time">${this.formatTime(job.created_at)}</span>
                    <span class="job-attempts">${job.attempts}/${job.max_attempts} attempts</span>
                </div>
                ${job.status === 'failed' ? `<div class="job-error">Last error: Processing failed</div>` : ''}
            </div>
        `).join('');

        container.innerHTML = jobsHtml;
    }

    formatJobType(type) {
        return type.split('_').map(word =>
            word.charAt(0).toUpperCase() + word.slice(1)
        ).join(' ');
    }

    formatTime(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = now - date;

        if (diff < 60000) return 'Just now';
        if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`;
        if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`;
        return date.toLocaleDateString();
    }

    async processQueue() {
        const button = document.getElementById('queue-process');
        const originalText = button.innerHTML;

        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        button.disabled = true;

        try {
            const response = await this._retryAjax({
                url: puntworkQueue.ajaxurl,
                type: 'POST',
                data: {
                    action: 'puntwork_process_queue',
                    nonce: puntworkQueue.nonce
                }
            }, 3, 1000);

            const data = response;

            if (data.success) {
                this.showNotification('Queue processed successfully', 'success');
                this.loadQueueStats();
                this.loadRecentJobs();
            } else {
                this.showNotification('Failed to process queue', 'error');
            }
        } catch (error) {
            console.error('Error processing queue:', error);
            this.showNotification('Error processing queue', 'error');
        } finally {
            button.innerHTML = originalText;
            button.disabled = false;
        }
    }

    async clearCompletedJobs() {
        if (!confirm('Are you sure you want to clear all completed jobs?')) {
            return;
        }

        try {
            const response = await this._retryAjax({
                url: puntworkQueue.ajaxurl,
                type: 'POST',
                data: {
                    action: 'puntwork_clear_completed_jobs',
                    nonce: puntworkQueue.nonce
                }
            }, 3, 1000);

            const data = response;

            if (data.success) {
                this.showNotification('Completed jobs cleared', 'success');
                this.loadQueueStats();
                this.loadRecentJobs();
            }
        } catch (error) {
            console.error('Error clearing completed jobs:', error);
        }
    }

    async addTestJob() {
        try {
            const response = await this._retryAjax({
                url: puntworkQueue.ajaxurl,
                type: 'POST',
                data: {
                    action: 'puntwork_add_test_job',
                    nonce: puntworkQueue.nonce
                }
            }, 3, 1000);

            const data = response;

            if (data.success) {
                this.showNotification('Test job added to queue', 'success');
                this.loadQueueStats();
            }
        } catch (error) {
            console.error('Error adding test job:', error);
        }
    }

    handleAjaxError() {
        this.consecutiveErrors++;
        console.warn(`[QUEUE] Consecutive AJAX errors: ${this.consecutiveErrors}/${this.maxConsecutiveErrors}`);
        
        if (this.consecutiveErrors >= this.maxConsecutiveErrors) {
            console.error('[QUEUE] Too many consecutive AJAX errors, stopping auto-refresh');
            this.stopAutoRefresh();
            this.showNotification('Queue monitoring stopped due to repeated errors. Please refresh the page.', 'error');
        }
    }

    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
            
            // Update toggle button if it exists
            const toggleButton = document.getElementById('queue-toggle-auto-refresh');
            if (toggleButton) {
                toggleButton.innerHTML = '<i class="fas fa-play"></i> Resume Auto-Refresh';
            }
        }
    }

    toggleAutoRefresh(button) {
        if (this.refreshInterval) {
            this.stopAutoRefresh();
        } else {
            this.startAutoRefresh();
            button.innerHTML = '<i class="fas fa-pause"></i> Pause Auto-Refresh';
        }
    }

    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `puntwork-notification ${type}`;
        notification.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation-triangle' : 'info'}"></i>
            ${message}
        `;

        document.body.appendChild(notification);

        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    destroy() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
        }

        if (this.container) {
            this.container.remove();
        }
    }
}

// Initialize queue interface when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if (typeof puntworkQueue !== 'undefined') {
        // Only show queue management on relevant pages
        const currentPage = new URLSearchParams(window.location.search).get('page') || '';
        const queuePages = ['puntwork-monitoring', 'job-feed-dashboard'];

        if (queuePages.includes(currentPage)) {
            window.puntworkQueueInterface = new PuntworkQueueInterface();
        }
    }
});