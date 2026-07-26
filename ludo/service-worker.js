/**
 * ======================================================
 * SERVICE-WORKER.JS - Push Notifications & Offline Support
 * Ludo Tournament Platform - PWA Service Worker
 * Version: 2.0.0 - COMPLETE
 * ======================================================
 */

const CACHE_NAME = 'ludo-cache-v2';
const ASSETS_TO_CACHE = [
    '/',
    '/index.php',
    '/dashboard.php',
    '/game.php',
    '/join.php',
    '/assets/css/style.css',
    '/assets/js/audio-synth.js',
    '/assets/js/ludo-engine.js',
    '/assets/js/websocket-client.js',
    '/assets/js/invite-system.js',
    '/assets/js/notifications.js',
    '/manifest.json'
];

// ==============================================
// INSTALL EVENT
// ==============================================
self.addEventListener('install', function(event) {
    console.log('📦 Service Worker installing...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(function(cache) {
                console.log('📦 Caching assets...');
                return cache.addAll(ASSETS_TO_CACHE);
            })
            .then(function() {
                console.log('✅ Service Worker installed');
                return self.skipWaiting();
            })
    );
});

// ==============================================
// ACTIVATE EVENT
// ==============================================
self.addEventListener('activate', function(event) {
    console.log('🔧 Service Worker activating...');
    event.waitUntil(
        caches.keys()
            .then(function(cacheNames) {
                return Promise.all(
                    cacheNames.map(function(cacheName) {
                        if (cacheName !== CACHE_NAME) {
                            console.log('🗑️ Removing old cache:', cacheName);
                            return caches.delete(cacheName);
                        }
                    })
                );
            })
            .then(function() {
                console.log('✅ Service Worker activated');
                return self.clients.claim();
            })
    );
});

// ==============================================
// FETCH EVENT - Cache First Strategy
// ==============================================
self.addEventListener('fetch', function(event) {
    const request = event.request;
    const url = new URL(request.url);
    
    if (request.method !== 'GET') {
        event.respondWith(fetch(request));
        return;
    }
    
    if (url.pathname.includes('/api/')) {
        event.respondWith(fetch(request));
        return;
    }
    
    if (url.protocol === 'ws:' || url.protocol === 'wss:') {
        event.respondWith(fetch(request));
        return;
    }
    
    event.respondWith(
        caches.match(request)
            .then(function(cachedResponse) {
                if (cachedResponse) {
                    fetch(request)
                        .then(function(networkResponse) {
                            if (networkResponse && networkResponse.status === 200) {
                                caches.open(CACHE_NAME)
                                    .then(function(cache) {
                                        cache.put(request, networkResponse.clone());
                                    });
                            }
                        })
                        .catch(function() {});
                    return cachedResponse;
                }
                return fetch(request)
                    .then(function(networkResponse) {
                        if (networkResponse && networkResponse.status === 200) {
                            caches.open(CACHE_NAME)
                                .then(function(cache) {
                                    cache.put(request, networkResponse.clone());
                                });
                        }
                        return networkResponse;
                    })
                    .catch(function() {
                        return caches.match('/offline.html');
                    });
            })
    );
});

// ==============================================
// PUSH NOTIFICATION EVENT
// ==============================================
self.addEventListener('push', function(event) {
    console.log('📨 Push notification received:', event);
    
    let data = {
        title: 'Ludo Tournament Pro',
        body: 'You have a new notification!',
        icon: '/assets/icons/icon-192x192.png',
        badge: '/assets/icons/badge-72x72.png',
        data: { url: '/' }
    };
    
    if (event.data) {
        try {
            const parsedData = event.data.json();
            data = { ...data, ...parsedData };
        } catch (error) {
            data.body = event.data.text();
        }
    }
    
    const options = {
        body: data.body,
        icon: data.icon || '/assets/icons/icon-192x192.png',
        badge: data.badge || '/assets/icons/badge-72x72.png',
        vibrate: [200, 100, 200],
        data: data.data || { url: '/' },
        actions: [
            { action: 'open', title: 'Open Game' },
            { action: 'dismiss', title: 'Dismiss' }
        ],
        tag: data.tag || 'ludo-notification',
        renotify: true,
        requireInteraction: true
    };
    
    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// ==============================================
// NOTIFICATION CLICK EVENT
// ==============================================
self.addEventListener('notificationclick', function(event) {
    console.log('📨 Notification clicked:', event);
    event.notification.close();
    
    if (event.action === 'dismiss') {
        return;
    }
    
    const urlToOpen = event.notification.data?.url || '/';
    
    event.waitUntil(
        clients.matchAll({
            type: 'window',
            includeUncontrolled: true
        })
        .then(function(clientList) {
            for (let i = 0; i < clientList.length; i++) {
                const client = clientList[i];
                if (client.url === urlToOpen && 'focus' in client) {
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(urlToOpen);
            }
        })
    );
});

// ==============================================
// NOTIFICATION CLOSE EVENT
// ==============================================
self.addEventListener('notificationclose', function(event) {
    console.log('📨 Notification closed:', event);
});
