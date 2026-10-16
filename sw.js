/*
 * RARL Community — service worker.
 * - Pages: network first (always fresh, works signed in), falling back to the
 *   last copy of that page, then to offline.html.
 * - Static files (icons, images, CSS/JS, fonts, Font Awesome, Tailwind):
 *   served from cache and refreshed in the background.
 * - Never touches /admin/, form posts, sign-in/out, downloads of personal
 *   documents, or Google Translate.
 * Bump VERSION to roll out changes; old caches are deleted on activate.
 */
const VERSION = 'rarl-v3';
const STATIC = VERSION + '-static';
const PAGES = VERSION + '-pages';
const PRECACHE = ['offline.html', 'assets/icons/icon-192.png', 'assets/icons/icon-512.png', 'assets/mark.png', 'manifest.webmanifest'];
const NEVER = /\/admin\/|\/(login|logout|impersonate|deploy-extract|download-cert|roster-pdf|unsubscribe)\.php|translate\.google|translate\.googleapis|[?&]ics=/;
const STATIC_HOSTS = ['cdnjs.cloudflare.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.tailwindcss.com', 'cdn.jsdelivr.net', 'unpkg.com'];

self.addEventListener('install', (e) => {
  // Precache is best-effort: an unavailable cache (private mode) must not block install.
  e.waitUntil(caches.open(STATIC).then((c) => c.addAll(PRECACHE)).catch(() => {}).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET' || NEVER.test(req.url)) return;
  const url = new URL(req.url);

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).then((res) => {
      // Keep a copy of member pages for offline reading (only real pages, not redirects).
      if (res.ok && res.type === 'basic' && !res.redirected) { const copy = res.clone(); caches.open(PAGES).then((c) => c.put(req, copy)).catch(() => {}); }
      return res;
    }).catch(() => caches.match(req).then((hit) => hit || caches.match('offline.html')).catch(() => Response.error())));
    return;
  }

  const sameOriginStatic = url.origin === location.origin && /\.(png|jpe?g|webp|gif|svg|ico|css|js|woff2?|webmanifest)$/i.test(url.pathname);
  if (sameOriginStatic || STATIC_HOSTS.includes(url.hostname)) {
    // Cache is an optimisation only: any cache failure falls straight back to the network.
    e.respondWith(caches.open(STATIC).then((c) => c.match(req).then((hit) => {
      const fresh = fetch(req).then((res) => { if (res.ok || res.type === 'opaque') c.put(req, res.clone()).catch(() => {}); return res; }).catch(() => hit);
      return hit || fresh;
    })).catch(() => fetch(req)));
  }
});

// Signing out: the page posts this so a shared device doesn't keep member pages.
self.addEventListener('message', (e) => {
  if (e.data === 'clear-pages') caches.delete(PAGES).catch(() => {});
});
