/**
 * Service Worker for puntWork Admin PWA
 * Provides offline functionality and caching for the admin interface
 */

const CACHE_NAME = 'puntwork-admin-v1.0.0';
const ADMIN_URLS = [
    '/wp-admin/',
    '/wp-admin/admin.php?page=puntwork-dashboard',
    '/wp-admin/admin.php?page=puntwork-api-settings',
    '/wp-admin/admin.php?page=puntwork-scheduling'
];

// Static assets to cache
const STATIC_ASSETS = [
    '/wp-content/plugins/puntwork/assets/css/admin-modern.css',
    '/wp-content/plugins/puntwork/assets/js/puntwork-logger.js',
    '/wp-content/plugins/puntwork/assets/js/job-import-admin.js',
    '/wp-content/plugins/puntwork/assets/js/job-import-ui.js',
    '/wp-content/plugins/puntwork/assets/js/job-import-api.js',
    '/wp-content/plugins/puntwork/assets/js/job-import-logic.js',
    '/wp-content/plugins/puntwork/assets/js/job-import-events.js',
    '/wp-content/plugins/puntwork/assets/js/job-import-scheduling.js',
    '/wp-content/plugins/puntwork/assets/js/job-import-realtime.js',
    '/wp-content/plugins/puntwork/assets/images/icon.svg',
    '/wp-content/plugins/puntwork/assets/images/logo.svg',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css',
    'https://cdn.jsdelivr.net/npm/chart.js'
];

// Install event - cache static assets
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => {
                return self.skipWaiting();
            })
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => {
            return self.clients.claim();
        })
    );
});

// Fetch event - serve from cache when offline
self.addEventListener('fetch', event => {
    // Only handle GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Check if this is an admin URL or static asset
    const url = new URL(event.request.url);
    const isAdminRequest = url.pathname.startsWith('/wp-admin/') ||
                          STATIC_ASSETS.some(asset => event.request.url.includes(asset));

    if (isAdminRequest) {
        event.respondWith(
            caches.match(event.request)
                .then(response => {
                    // Return cached version if available
                    if (response) {
                        return response;
                    }

                    // Otherwise, fetch from network
                    return fetch(event.request)
                        .then(response => {
                            // Don't cache if not a valid response
                            if (!response || response.status !== 200 || response.type !== 'basic') {
                                return response;
                            }

                            // Clone the response for caching
                            const responseToCache = response.clone();

                            // Cache successful responses
                            caches.open(CACHE_NAME)
                                .then(cache => {
                                    cache.put(event.request, responseToCache);
                                });

                            return response;
                        })
                        .catch(() => {
                            // If offline and no cache, return offline page
                            if (event.request.destination === 'document') {
                                return caches.match('/wp-admin/admin.php?page=puntwork-dashboard')
                                    .then(response => {
                                        if (response) {
                                            return response;
                                        }
                                        // Fallback to a simple offline message
                                        return new Response(
                                            '<html><body><h1>Offline</h1><p>You are currently offline. Some features may not be available.</p></body></html>',
                                            {
                                                headers: { 'Content-Type': 'text/html' }
                                            }
                                        );
                                    });
                            }
                        });
                })
        );
    }
});

// Message event - handle messages from the main thread
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});