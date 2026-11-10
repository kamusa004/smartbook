/* SmartBook Solutions - Service Worker
 * Strategy
 *   app shell      : cache-first  (works with no network at all)
 *   API GET        : network-first, cached response as fallback (read offline)
 *   API writes     : never cached - queued in IndexedDB by sync.js instead
 * Bump CACHE_VERSION to invalidate old shells after a deploy.
 */
const CACHE_VERSION = 'smartbook-v1.4.1';
const SHELL = [
  './',
  './index.html',
  './admin.html',
  './assets/css/app.css',
  './assets/vendor/bootstrap.min.css',
  './assets/vendor/bootstrap.bundle.min.js',
  './assets/js/api.js',
  './assets/js/db.js',
  './assets/js/sync.js',
  './assets/js/i18n.js',
  './assets/js/app.js',
  './assets/js/pages.js',
  './assets/js/admin.js',
  './assets/img/icon-192.svg',
  './manifest.json'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  if (req.method !== 'GET') {
    return; // writes are handled by the sync queue
  }

  // API reads: network first, fall back to the last good cached copy.
  if (url.pathname.includes('/api/')) {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE_VERSION).then((c) => c.put(req, copy));
          return res;
        })
        .catch(() => caches.match(req).then((hit) => hit || new Response(
          JSON.stringify({ ok: false, error: { code: 'OFFLINE', message: 'You are offline. Showing saved data.' } }),
          { headers: { 'Content-Type': 'application/json' } }
        )))
    );
    return;
  }

  // App shell (HTML): offline-first. Serve the cached shell immediately,
  // then refresh it from the network when the server is reachable.
  if (req.mode === 'navigate' || url.pathname.endsWith('.html')) {
    event.respondWith(
      caches.match(req)
        .then((hit) => hit || caches.match('./index.html'))
        .then((hit) => hit || caches.match('./'))
        .then((hit) => {
          if (hit) return hit;
          return fetch(req).then((res) => {
            if (res.ok && url.origin === self.location.origin) {
              const copy = res.clone();
              caches.open(CACHE_VERSION).then((c) => c.put(req, copy));
            }
            return res;
          });
        })
        .catch(() => new Response(
          '<!DOCTYPE html><meta charset="utf-8"><body style="font-family:system-ui;padding:2rem">You are offline. Open the app once while online to cache it.</body>',
          { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        ))
    );
    return;
  }

  // App shell / static assets: cache first, but never hand out empty bodies.
  event.respondWith(
    caches.match(req).then((hit) => {
      if (hit) {
        return hit.clone().text().then((txt) => (txt.length > 0 ? hit : fetch(req)))
          .catch(() => hit);
      }
      return fetch(req)
        .then((res) => {
          if (res.ok && url.origin === self.location.origin) {
            const copy = res.clone();
            caches.open(CACHE_VERSION).then((c) => c.put(req, copy));
          }
          return res;
        })
        .catch(() => caches.match('./index.html'));
    })
  );
});

// Background sync (Chrome/Android): flush the queue even if the tab is closed.
self.addEventListener('sync', (event) => {
  if (event.tag === 'smartbook-sync') {
    event.waitUntil(
      self.clients.matchAll({ includeUncontrolled: true }).then((clients) => {
        clients.forEach((c) => c.postMessage({ type: 'SMARTBOOK_FLUSH' }));
      })
    );
  }
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
