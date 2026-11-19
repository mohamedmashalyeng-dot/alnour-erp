/* Eyewear ERP service worker.
 * - /build/* (hashed, immutable assets): cache-first.
 * - /pos/offline page: network-first, falls back to the cached copy when offline.
 * - other page navigations: network, falling back to /offline.html when offline.
 * - API calls (bootstrap/sync) and every non-GET request are never cached.
 */
const VERSION = 'erp-v1';
const SHELL = `${VERSION}-shell`;
const ASSETS = `${VERSION}-assets`;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(SHELL).then((c) => c.addAll(['/offline.html', '/manifest.webmanifest'])));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('message', (event) => {
    if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    const url = new URL(req.url);
    if (req.method !== 'GET' || url.origin !== self.location.origin) return;
    if (url.pathname.startsWith('/pos/offline/')) return; // live data: never cached by the SW

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/images/')) {
        event.respondWith(caches.open(ASSETS).then(async (cache) => {
            const hit = await cache.match(req);
            if (hit) return hit;
            const res = await fetch(req);
            if (res.ok) cache.put(req, res.clone());
            return res;
        }));
        return;
    }

    if (req.mode === 'navigate') {
        if (url.pathname === '/pos/offline') {
            event.respondWith(fetch(req).then((res) => {
                // Only cache the real page (not a redirect to the login screen).
                if (res.ok && !res.redirected) caches.open(SHELL).then((c) => c.put('/pos/offline', res.clone()));
                return res;
            }).catch(() => caches.match('/pos/offline').then((r) => r || caches.match('/offline.html'))));
            return;
        }
        event.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
    }
});
