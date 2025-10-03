/**
 * Keyboard Shortcuts and Accessibility Manager for puntWork
 * Provides keyboard shortcuts and enhanced accessibility features
 */

class PuntworkAccessibilityManager {
    constructor() {
        this.shortcuts = {
            // Global shortcuts
            'ctrl+enter': this.handleStartImport.bind(this),
            'ctrl+r': this.handleRefresh.bind(this),
            'ctrl+s': this.handleSaveSettings.bind(this),
            'ctrl+h': this.handleShowHelp.bind(this),
            'escape': this.handleEscape.bind(this),

            // Navigation shortcuts
            'alt+1': () => this.navigateToSection('dashboard'),
            'alt+2': () => this.navigateToSection('feeds'),
            'alt+3': () => this.navigateToSection('jobs'),
            'alt+4': () => this.navigateToSection('analytics'),
            'alt+5': () => this.navigateToSection('settings'),

            // Action shortcuts
            'ctrl+shift+c': this.handleClearCache.bind(this),
            'ctrl+shift+l': this.handleShowLogs.bind(this),
            'ctrl+shift+p': this.handlePauseResume.bind(this)
        };

        this.init();
    }

    init() {
        this.addKeyboardListeners();
        this.addAccessibilityFeatures();
        this.addSkipLinks();
        this.enhanceFocusManagement();
        this.addScreenReaderAnnouncements();
    }

    addKeyboardListeners() {
        document.addEventListener('keydown', (e) => {
            const key = this.getKeyCombination(e);
            const handler = this.shortcuts[key];

            if (handler) {
                e.preventDefault();
                e.stopPropagation();
                handler();
                this.announceAction(key);
            }
        });
    }

    getKeyCombination(e) {
        const keys = [];

        if (e.ctrlKey || e.metaKey) keys.push('ctrl');
        if (e.altKey) keys.push('alt');
        if (e.shiftKey) keys.push('shift');

        keys.push(e.key.toLowerCase());

        return keys.join('+');
    }

    handleStartImport() {
        const startBtn = document.getElementById('start-import-btn') ||
                        document.querySelector('.start-import') ||
                        document.querySelector('[data-action="start-import"]');

        if (startBtn && !startBtn.disabled) {
            startBtn.click();
            this.announceToScreenReader('Starting import process');
        }
    }

    handleRefresh() {
        const refreshBtn = document.getElementById('refresh-history-main') ||
                          document.querySelector('.refresh-btn') ||
                          document.querySelector('[data-action="refresh"]');

        if (refreshBtn) {
            refreshBtn.click();
            this.announceToScreenReader('Refreshing data');
        }
    }

    handleSaveSettings() {
        const saveBtn = document.querySelector('input[type="submit"][name="save_settings"]') ||
                       document.querySelector('.save-settings') ||
                       document.querySelector('[data-action="save"]');

        if (saveBtn) {
            saveBtn.click();
            this.announceToScreenReader('Saving settings');
        }
    }

    handleShowHelp() {
        // Show keyboard shortcuts help modal
        this.showKeyboardShortcutsHelp();
    }

    handleEscape() {
        // Close modals, clear focus, etc.
        const modals = document.querySelectorAll('.modal, .onboarding-modal, [role="dialog"]');
        modals.forEach(modal => {
            if (modal.style.display !== 'none') {
                const closeBtn = modal.querySelector('.close, .onboarding-close, [data-action="close"]');
                if (closeBtn) closeBtn.click();
            }
        });
    }

    handleClearCache() {
        if (confirm('Clear all cached data? This will refresh all cached feeds and analytics.')) {
            this.clearCache();
            this.announceToScreenReader('Cache cleared');
        }
    }

    handleShowLogs() {
        // Open logs/debug panel
        const logsPanel = document.getElementById('debug-logs') ||
                         document.querySelector('.logs-panel');

        if (logsPanel) {
            logsPanel.style.display = logsPanel.style.display === 'none' ? 'block' : 'none';
            this.announceToScreenReader('Debug logs ' + (logsPanel.style.display === 'block' ? 'shown' : 'hidden'));
        }
    }

    handlePauseResume() {
        const pauseBtn = document.querySelector('[data-action="pause"]') ||
                        document.querySelector('.pause-import');

        if (pauseBtn) {
            pauseBtn.click();
            const isPaused = pauseBtn.textContent.toLowerCase().includes('resume');
            this.announceToScreenReader(isPaused ? 'Import resumed' : 'Import paused');
        }
    }

    navigateToSection(section) {
        const sectionMap = {
            dashboard: '#puntwork-dashboard',
            feeds: '#job-feed-dashboard',
            jobs: '#jobs-dashboard',
            analytics: '#puntwork-analytics',
            settings: '#puntwork-api-settings'
        };

        const selector = sectionMap[section];
        if (selector) {
            const element = document.querySelector(selector);
            if (element) {
                element.scrollIntoView({ behavior: 'smooth' });
                element.focus();
                this.announceToScreenReader(`Navigated to ${section} section`);
            }
        }
    }

    clearCache() {
        // Trigger cache clearing via REST API
        const apiKey = puntworkAjax.api_key;
        const apiUrl = `${window.location.origin}/wp-json/puntwork/v1/cache/clear?api_key=${encodeURIComponent(apiKey)}`;

        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            }
        })
        .catch(error => {
            console.error('Error clearing cache:', error);
        });
    }

    addAccessibilityFeatures() {
        // Add ARIA labels to buttons without them
        document.querySelectorAll('button:not([aria-label]):not([aria-labelledby])').forEach(btn => {
            const text = btn.textContent.trim();
            if (text) {
                btn.setAttribute('aria-label', text);
            }
        });

        // Add role and labels to status indicators
        document.querySelectorAll('.status-indicator').forEach(indicator => {
            indicator.setAttribute('role', 'status');
            indicator.setAttribute('aria-live', 'polite');
        });

        // Add progress indicators
        document.querySelectorAll('.progress-bar').forEach(bar => {
            bar.setAttribute('role', 'progressbar');
            bar.setAttribute('aria-valuemin', '0');
            bar.setAttribute('aria-valuemax', '100');
            bar.setAttribute('aria-valuenow', bar.style.width.replace('%', '') || '0');
        });

        // Add descriptions to form fields
        document.querySelectorAll('input, select, textarea').forEach(field => {
            if (!field.getAttribute('aria-describedby') && !field.getAttribute('aria-description')) {
                const label = document.querySelector(`label[for="${field.id}"]`);
                if (label && label.textContent.trim()) {
                    field.setAttribute('aria-label', label.textContent.trim());
                }
            }
        });
    }

    addSkipLinks() {
        const skipLinks = document.createElement('div');
        skipLinks.className = 'puntwork-skip-links';
        skipLinks.innerHTML = `
            <a href="#main-content" class="skip-link">Skip to main content</a>
            <a href="#navigation" class="skip-link">Skip to navigation</a>
            <a href="#keyboard-help" class="skip-link" onclick="puntworkAccessibility.showKeyboardShortcutsHelp()">Keyboard shortcuts help</a>
        `;

        document.body.insertBefore(skipLinks, document.body.firstChild);

        // Hide skip links until focused
        const style = document.createElement('style');
        style.textContent = `
            .skip-link {
                position: absolute;
                top: -40px;
                left: 6px;
                background: #007aff;
                color: white;
                padding: 8px;
                text-decoration: none;
                border-radius: 4px;
                z-index: 1000;
                font-weight: 500;
            }
            .skip-link:focus {
                top: 6px;
            }
        `;
        document.head.appendChild(style);
    }

    enhanceFocusManagement() {
        // Add focus indicators
        const style = document.createElement('style');
        style.textContent = `
            *:focus {
                outline: 2px solid #007aff;
                outline-offset: 2px;
            }
            *:focus:not(:focus-visible) {
                outline: none;
            }
            *:focus-visible {
                outline: 2px solid #007aff;
                outline-offset: 2px;
            }

            /* High contrast mode support */
            @media (prefers-contrast: high) {
                *:focus-visible {
                    outline: 3px solid #ffffff;
                }
            }

            /* Reduced motion support */
            @media (prefers-reduced-motion: reduce) {
                *, *::before, *::after {
                    animation-duration: 0.01ms !important;
                    animation-iteration-count: 1 !important;
                    transition-duration: 0.01ms !important;
                }
            }
        `;
        document.head.appendChild(style);

        // Trap focus in modals
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Tab') {
                const modal = document.querySelector('.modal:visible, .onboarding-modal[style*="display: block"]');
                if (modal) {
                    this.trapFocus(modal, e);
                }
            }
        });
    }

    trapFocus(container, e) {
        const focusableElements = container.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );

        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        if (e.shiftKey) {
            if (document.activeElement === firstElement) {
                lastElement.focus();
                e.preventDefault();
            }
        } else {
            if (document.activeElement === lastElement) {
                firstElement.focus();
                e.preventDefault();
            }
        }
    }

    addScreenReaderAnnouncements() {
        // Create announcement region
        const announcer = document.createElement('div');
        announcer.id = 'puntwork-screen-reader-announcements';
        announcer.setAttribute('aria-live', 'polite');
        announcer.setAttribute('aria-atomic', 'true');
        announcer.className = 'screen-reader-only';

        document.body.appendChild(announcer);

        // Add screen reader only styles
        const style = document.createElement('style');
        style.textContent = `
            .screen-reader-only {
                position: absolute;
                left: -10000px;
                width: 1px;
                height: 1px;
                overflow: hidden;
            }
        `;
        document.head.appendChild(style);
    }

    announceToScreenReader(message) {
        const announcer = document.getElementById('puntwork-screen-reader-announcements');
        if (announcer) {
            announcer.textContent = message;
            // Clear after announcement
            setTimeout(() => {
                announcer.textContent = '';
            }, 1000);
        }
    }

    announceAction(shortcut) {
        const actionMap = {
            'ctrl+enter': 'Start import',
            'ctrl+r': 'Refresh data',
            'ctrl+s': 'Save settings',
            'ctrl+h': 'Show help',
            'escape': 'Close modal',
            'ctrl+shift+c': 'Clear cache',
            'ctrl+shift+l': 'Toggle logs',
            'ctrl+shift+p': 'Pause/Resume import'
        };

        const action = actionMap[shortcut];
        if (action) {
            this.announceToScreenReader(`${action} activated via keyboard shortcut`);
        }
    }

    showKeyboardShortcutsHelp() {
        const modal = document.createElement('div');
        modal.className = 'puntwork-modal keyboard-help-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'keyboard-help-title');

        modal.innerHTML = `
            <div class="modal-overlay" onclick="this.parentElement.remove()"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="keyboard-help-title">Keyboard Shortcuts</h2>
                    <button class="modal-close" onclick="this.closest('.puntwork-modal').remove()" aria-label="Close help">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="shortcuts-grid">
                        <div class="shortcut-group">
                            <h3>Global Actions</h3>
                            <dl>
                                <dt><kbd>Ctrl</kbd> + <kbd>Enter</kbd></dt>
                                <dd>Start import process</dd>
                                <dt><kbd>Ctrl</kbd> + <kbd>R</kbd></dt>
                                <dd>Refresh data</dd>
                                <dt><kbd>Ctrl</kbd> + <kbd>S</kbd></dt>
                                <dd>Save settings</dd>
                                <dt><kbd>Ctrl</kbd> + <kbd>H</kbd></dt>
                                <dd>Show this help</dd>
                                <dt><kbd>Esc</kbd></dt>
                                <dd>Close modals</dd>
                            </dl>
                        </div>
                        <div class="shortcut-group">
                            <h3>Navigation</h3>
                            <dl>
                                <dt><kbd>Alt</kbd> + <kbd>1</kbd></dt>
                                <dd>Go to Dashboard</dd>
                                <dt><kbd>Alt</kbd> + <kbd>2</kbd></dt>
                                <dd>Go to Feeds</dd>
                                <dt><kbd>Alt</kbd> + <kbd>3</kbd></dt>
                                <dd>Go to Jobs</dd>
                                <dt><kbd>Alt</kbd> + <kbd>4</kbd></dt>
                                <dd>Go to Analytics</dd>
                                <dt><kbd>Alt</kbd> + <kbd>5</kbd></dt>
                                <dd>Go to Settings</dd>
                            </dl>
                        </div>
                        <div class="shortcut-group">
                            <h3>Advanced Actions</h3>
                            <dl>
                                <dt><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>C</kbd></dt>
                                <dd>Clear cache</dd>
                                <dt><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>L</kbd></dt>
                                <dd>Toggle debug logs</dd>
                                <dt><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>P</kbd></dt>
                                <dd>Pause/Resume import</dd>
                            </dl>
                        </div>
                    </div>
                    <div class="accessibility-info">
                        <h3>Accessibility Features</h3>
                        <ul>
                            <li>Full keyboard navigation support</li>
                            <li>Screen reader announcements</li>
                            <li>High contrast mode support</li>
                            <li>Focus management in modals</li>
                            <li>Skip links for quick navigation</li>
                        </ul>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Focus management
        const closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) closeBtn.focus();

        // Add styles
        const style = document.createElement('style');
        style.textContent = `
            .keyboard-help-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 10000; }
            .keyboard-help-modal .modal-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
            .keyboard-help-modal .modal-content { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; }
            .keyboard-help-modal .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 20px; border-bottom: 1px solid #e5e5e7; }
            .keyboard-help-modal .modal-header h2 { margin: 0; font-size: 24px; }
            .keyboard-help-modal .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; padding: 4px; }
            .keyboard-help-modal .modal-body { padding: 20px; }
            .shortcuts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
            .shortcut-group h3 { font-size: 18px; margin-bottom: 12px; color: #1d1d1f; }
            .shortcut-group dl { margin: 0; }
            .shortcut-group dt { font-weight: 600; margin-bottom: 4px; }
            .shortcut-group dd { margin: 0 0 12px 0; color: #86868b; }
            .shortcut-group kbd { background: #f2f2f7; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; }
            .accessibility-info h3 { font-size: 18px; margin-bottom: 12px; }
            .accessibility-info ul { margin: 0; padding-left: 20px; }
            .accessibility-info li { margin-bottom: 8px; }
        `;
        document.head.appendChild(style);
    }
}

// Initialize accessibility manager
document.addEventListener('DOMContentLoaded', () => {
    if (typeof puntworkAjax !== 'undefined') {
        window.puntworkAccessibility = new PuntworkAccessibilityManager();
    }
});

// Export for global access
window.PuntworkAccessibilityManager = PuntworkAccessibilityManager;