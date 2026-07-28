/**
 * SERVICE-WORKER.JS - Robust PWA Service Worker for Ludo
 * - Per-asset caching (no cache.addAll)
 * - Caches only static assets
 * - Network-first for navigations with offline fallback
 * - Works under subfolder scope (e.g. /ludo/)
 */

const CACHE_NAME = 'ludo-cache-v2';

// Assets relative to the service worker base path.
// Ensure these files actually exist in your project (or remove items that don't).
const ASSETS_RELATIVE = [
    '',                 // root (index)
    'index.php',
    'offline.html',     // create this file at same base path
    'manifest.json',
    'assets/css/style.css',
    'assets/js/audio-synth.js',
    'assets/js/ludo-engine.js',
    'assets/js/websocket-client.js',
    'assets/js/invite-system.js',
    'assets/js/notifications.js',
    'assets/icons/icon-192x192.png',
    'assets/icons/icon-512x512.png',
    'assets/icons/badge-72x72.png'
];

// Derive base path from this service worker's URL so it works if installed at /ludo/
const SW_PATH = self.location.pathname || '/service-worker.js';
const BASE_PATH = SW_PATH.endsWith('/service-worker.js') ? SW_PATH.replace(/service-worker\.js$/, '') : SW_PATH.replace(/service-worker\.js$/, '');
const normalizeUrl = (p) => {
    if (!p) return (BASE_PATH === '' || BASE_PATH === '/') ? '/' : BASE_PATH;
    if (p.startsWith('/')) return p;
    if (BASE_PATH.endsWith('/')) return BASE_PATH + p;
    return BASE_PATH + '/' + p;
};
const ASSETS_TO_CACHE = ASSETS_RELATIVE.map(normalizeUrl);

function log() {
    try { console.log.apply(console, arguments); } catch (e) {}
}

self.addEventListener('install', function(event) {
    log('📦 Service Worker installing...');
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE_NAME);
        const failures = [];
        await Promise.all(ASSETS_TO_CACHE.map(async (asset) => {
            try {
                const req = new Request(asset, { credentials: 'same-origin', cache: 'no-cache' });
                const res = await fetch(req);
                if (!res || (!res.ok && res.type !== 'opaque')) {
                    throw new Error('Bad response: ' + (res && res.status));
                }
                await cache.put(asset, res.clone());
                log('✅ Cached:', asset);
            } catch (err) {
                failures.push({ asset, error: err && (err.message || err.toString()) });
                console.warn('⚠️ Cache failed for', asset, err && err.message);
            }
        }));
        if (failures.length) {
            console.warn('⚠️ Service Worker install completed with cache failures:', failures);
        } else {
            log('✅ All assets cached successfully');
        }
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', function(event) {
    log('🔧 Service Worker activating...');
    event.waitUntil((async () => {
        const cacheNames = await caches.keys();
        await Promise.all(cacheNames.map(async (cacheName) => {
            if (cacheName !== CACHE_NAME) {
                log('🗑️ Removing old cache:', cacheName);
                await caches.delete(cacheName);
            }
        }));
        await self.clients.claim();
        log('✅ Service Worker activated');
    })());
});

self.addEventListener('fetch', function(event) {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET') {
        return event.respondWith(fetch(request));
    }

    // Never cache API / dynamic endpoints — network-first with JSON fallback
    if (url.pathname.includes('/api/') ||
        url.pathname.endsWith('/auth.php') ||
        url.pathname.endsWith('/wallet.php') ||
        url.pathname.endsWith('/game.php')) {
        return event.respondWith(
            fetch(request)
                .then((networkResponse) => networkResponse)
                .catch(() => new Response(JSON.stringify({ success: false, message: 'No internet connection' }), { status: 503, headers: { 'Content-Type': 'application/json' } }))
        );
    }

    // Navigation requests: network-first, fallback to cache/offline
    if (request.mode === 'navigate' || (request.headers.get('accept') || '').includes('text/html')) {
        return event.respondWith((async () => {
            try {
                const networkResponse = await fetch(request);
                if (networkResponse && (networkResponse.ok || networkResponse.type === 'opaque')) {
                    const cache = await caches.open(CACHE_NAME);
                    try { await cache.put(request, networkResponse.clone()); } catch (e) { /* ignore put errors */ }
                }
                return networkResponse;
            } catch (err) {
                const cached = await caches.match(request);
                if (cached) return cached;
                const offline = await caches.match(normalizeUrl('offline.html')) || await caches.match(normalizeUrl(''));
                return offline || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
            }
        })());
    }

    // Static assets: cache-first then network and populate cache
    event.respondWith((async () => {
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            fetch(request).then(async (networkResponse) => {
                if (networkResponse && (networkResponse.ok || networkResponse.type === 'opaque')) {
                    const cache = await caches.open(CACHE_NAME);
                    try { await cache.put(request, networkResponse.clone()); } catch (e) { /* ignore */ }
                }
            }).catch(() => {});
            return cachedResponse;
        }
        try {
            const networkResponse = await fetch(request);
            if (networkResponse && (networkResponse.ok || networkResponse.type === 'opaque')) {
                const cache = await caches.open(CACHE_NAME);
                try { await cache.put(request, networkResponse.clone()); } catch (e) { /* ignore caching errors */ }
            }
            return networkResponse;
        } catch (err) {
            const offline = await caches.match(normalizeUrl('offline.html')) || await caches.match(normalizeUrl(''));
            if (offline) return offline;
            return new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
        }
    })());
});

self.addEventListener('push', function(event) {
    log('📨 Push received:', event);
    let data = {
        title: 'Ludo Tournament Pro',
        body: 'You have a new notification!',
        icon: normalizeUrl('assets/icons/icon-192x192.png'),
        badge: normalizeUrl('assets/icons/badge-72x72.png'),
        data: { url: normalizeUrl('') }
    };

    if (event.data) {
        try {
            const parsed = event.data.json();
            data = { ...data, ...parsed };
        } catch (e) {
            data.body = event.data.text();
        }
    }

    const options = {
        body: data.body,
        icon: data.icon,
        badge: data.badge,
        vibrate: [200, 100, 200],
        data: data.data,
        actions: [
            { action: 'open', title: 'Open Game' },
            { action: 'dismiss', title: 'Dismiss' }
        ],
        tag: data.tag || 'ludo-notification',
        renotify: true,
        requireInteraction: true
    };

    event.waitUntil(self.registration.showNotification(data.title, options));
});

self.addEventListener('notificationclick', function(event) {
    log('📨 Notification click:', event);
    event.notification.close();

    if (event.action === 'dismiss') return;

    const urlToOpen = event.notification.data?.url || normalizeUrl('');
    event.waitUntil((async () => {
        const clientList = await clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of clientList) {
            if ((client.url || '').replace(/\/$/, '') === (urlToOpen || '').replace(/\/$/, '') && 'focus' in client) {
                return client.focus();
            }
        }
        if (clients.openWindow) {
            return clients.openWindow(urlToOpen);
        }
    })());
});

self.addEventListener('notificationclose', function(event) {
    log('📨 Notification closed:', event);
});
