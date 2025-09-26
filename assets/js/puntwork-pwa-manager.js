/**
 * PWA Manager for puntWork Admin
 * Handles service worker registration and install prompt
 */

class PuntworkPWAManager {
    constructor() {
        this.deferredPrompt = null;
        this.isInstalled = false;
        this.init();
    }

    init() {
        // Check if PWA is supported
        if (!('serviceWorker' in navigator) || !('BeforeInstallPromptEvent' in window)) {
            console.log('[PWA] PWA not supported in this browser');
            return;
        }

        this.registerServiceWorker();
        this.setupInstallPrompt();
        this.checkInstallStatus();
    }

    registerServiceWorker() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/wp-content/plugins/puntwork/assets/js/puntwork-admin-sw.js', {
                scope: '/wp-admin/'
            })
            .then(registration => {
                console.log('[PWA] Service Worker registered:', registration);

                // Handle updates
                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    if (newWorker) {
                        newWorker.addEventListener('statechange', () => {
                            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                this.showUpdateNotification();
                            }
                        });
                    }
                });
            })
            .catch(error => {
                console.error('[PWA] Service Worker registration failed:', error);
            });
        }
    }

    setupInstallPrompt() {
        window.addEventListener('beforeinstallprompt', event => {
            console.log('[PWA] Before install prompt fired');
            event.preventDefault();
            this.deferredPrompt = event;
            this.showInstallButton();
        });

        window.addEventListener('appinstalled', event => {
            console.log('[PWA] App installed');
            this.isInstalled = true;
            this.hideInstallButton();
            this.showInstallSuccess();
        });
    }

    checkInstallStatus() {
        // Check if already installed
        if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
            this.isInstalled = true;
            console.log('[PWA] App is running in standalone mode');
        }
    }

    showInstallButton() {
        // Remove existing install button
        this.removeInstallButton();

        // Create install button
        const installButton = document.createElement('button');
        installButton.id = 'pwa-install-button';
        installButton.innerHTML = '<i class="fas fa-download"></i> Install puntWork Admin';
        installButton.className = 'pwa-install-button';
        installButton.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: linear-gradient(135deg, #007aff 0%, #0056cc 100%);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 12px 20px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,122,255,0.3);
            z-index: 10001;
            transition: all 0.2s ease;
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Helvetica Neue', Helvetica, Arial, sans-serif;
        `;

        installButton.addEventListener('click', () => {
            this.installPWA();
        });

        installButton.addEventListener('mouseenter', () => {
            installButton.style.transform = 'translateY(-2px)';
            installButton.style.boxShadow = '0 6px 16px rgba(0,122,255,0.4)';
        });

        installButton.addEventListener('mouseleave', () => {
            installButton.style.transform = 'translateY(0)';
            installButton.style.boxShadow = '0 4px 12px rgba(0,122,255,0.3)';
        });

        document.body.appendChild(installButton);

        // Auto-hide after 10 seconds
        setTimeout(() => {
            if (installButton.parentNode) {
                this.hideInstallButton();
            }
        }, 10000);
    }

    hideInstallButton() {
        const button = document.getElementById('pwa-install-button');
        if (button) {
            button.style.animation = 'slideOut 0.3s ease-out';
            setTimeout(() => {
                button.remove();
            }, 300);
        }
    }

    removeInstallButton() {
        const existingButton = document.getElementById('pwa-install-button');
        if (existingButton) {
            existingButton.remove();
        }
    }

    async installPWA() {
        if (!this.deferredPrompt) {
            console.log('[PWA] No install prompt available');
            return;
        }

        this.deferredPrompt.prompt();
        const { outcome } = await this.deferredPrompt.userChoice;

        console.log('[PWA] User choice:', outcome);
        this.deferredPrompt = null;

        if (outcome === 'accepted') {
            this.hideInstallButton();
        }
    }

    showInstallSuccess() {
        this.showNotification('puntWork Admin has been installed successfully!', 'success');
    }

    showUpdateNotification() {
        this.showNotification('A new version is available. Refresh to update.', 'info', () => {
            window.location.reload();
        });
    }

    showNotification(message, type = 'info', action = null) {
        // Remove existing notifications
        const existingNotifications = document.querySelectorAll('.pwa-notification');
        existingNotifications.forEach(notification => notification.remove());

        const notification = document.createElement('div');
        notification.className = `pwa-notification ${type}`;
        notification.innerHTML = `
            <div class="pwa-notification-content">
                <span>${message}</span>
                ${action ? '<button class="pwa-notification-action">Refresh</button>' : ''}
                <button class="pwa-notification-close">&times;</button>
            </div>
        `;

        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            z-index: 10002;
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 14px;
            font-weight: 500;
            border: 1px solid #e5e5e7;
            animation: slideInFromRight 0.3s ease-out;
            max-width: 400px;
        `;

        if (type === 'success') {
            notification.style.borderColor = '#34c759';
            notification.style.background = 'linear-gradient(135deg, #f8fff9 0%, #ffffff 100%)';
        } else if (type === 'error') {
            notification.style.borderColor = '#ff3b30';
            notification.style.background = 'linear-gradient(135deg, #fff8f7 0%, #ffffff 100%)';
        }

        // Close button
        const closeButton = notification.querySelector('.pwa-notification-close');
        closeButton.addEventListener('click', () => {
            notification.style.animation = 'slideOutToRight 0.3s ease-in';
            setTimeout(() => notification.remove(), 300);
        });

        // Action button
        if (action) {
            const actionButton = notification.querySelector('.pwa-notification-action');
            actionButton.addEventListener('click', () => {
                action();
                notification.remove();
            });
        }

        document.body.appendChild(notification);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.animation = 'slideOutToRight 0.3s ease-in';
                setTimeout(() => notification.remove(), 300);
            }
        }, 5000);
    }
}

// Add CSS animations
const pwaStyles = `
    @keyframes slideInFromRight {
        0% { transform: translateX(100%); opacity: 0; }
        100% { transform: translateX(0); opacity: 1; }
    }

    @keyframes slideOutToRight {
        0% { transform: translateX(0); opacity: 1; }
        100% { transform: translateX(100%); opacity: 0; }
    }

    @keyframes slideOut {
        0% { transform: translateY(0); opacity: 1; }
        100% { transform: translateY(10px); opacity: 0; }
    }

    .pwa-notification-action {
        background: #007aff;
        color: white;
        border: none;
        border-radius: 6px;
        padding: 4px 12px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        margin-left: 12px;
        transition: background 0.2s ease;
    }

    .pwa-notification-action:hover {
        background: #0056cc;
    }

    .pwa-notification-close {
        background: none;
        border: none;
        font-size: 18px;
        cursor: pointer;
        color: #86868b;
        margin-left: 12px;
        padding: 0;
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .pwa-notification-close:hover {
        color: #1d1d1f;
    }

    .pwa-notification-content {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
`;

// Add styles to head
const styleSheet = document.createElement('style');
styleSheet.textContent = pwaStyles;
document.head.appendChild(styleSheet);

// Initialize PWA manager when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new PuntworkPWAManager();
});

// Export for global access
window.PuntworkPWAManager = PuntworkPWAManager;