/**
 * Service worker for the Estimate Builder PWA.
 *
 * Caching strategy:
 *   - The app bundle (JS + CSS) uses stale-while-revalidate so techs always get a fast
 *     shell and a background update.
 *   - Catalog + template GETs use network-first with a 4s timeout, falling back to cache.
 *     This is the "works in a basement with two bars" pattern — prefer fresh when we can,
 *     but don't block the picker because the network is flaky.
 *   - POST routes (/preview, /generate, /send-estimate, /webhook/*) are never cached. Mutations must
 *     either succeed live or surface an error; there is no useful offline behavior for them.
 *   - Customer search is always network-only. Caching CRM account data would be a privacy
 *     risk (third-party data sitting in a browser-managed store) and the result set is
 *     query-dependent anyway.
 *
 * Versioning:
 *   __APP_VERSION__ is replaced at build time by Vite. Changing the version string
 *   invalidates the old cache — the activate handler deletes anything that doesn't match.
 */

/* global self, caches, clients */

const VERSION = __APP_VERSION__;
const SHELL_CACHE = `tc-est-shell-${VERSION}`;
const DATA_CACHE = `tc-est-data-${VERSION}`;

// Network-first data routes. Anything matching /tc-estimate/v1/(equipment|templates)[/...]
const DATA_ROUTE_PATTERN = /\/wp-json\/tc-estimate\/v1\/(equipment|templates)(\/|$|\?)/;

// Never cache — even with network-first fallback, caching these is wrong.
const NEVER_CACHE_PATTERN =
  /\/wp-json\/tc-estimate\/v1\/(customers|preview|generate|send-estimate|webhook)/;

self.addEventListener('install', (event) => {
  // Activate as soon as the new SW installs; we don't pre-cache the shell because
  // the WP enqueuer versions the script URL and that handles cache-busting for us.
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(
        keys
          .filter((k) => k.startsWith('tc-est-') && !k.endsWith(VERSION))
          .map((k) => caches.delete(k))
      );
      await self.clients.claim();
    })()
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  if (NEVER_CACHE_PATTERN.test(url.pathname)) return;

  if (DATA_ROUTE_PATTERN.test(url.pathname)) {
    event.respondWith(networkFirst(req));
    return;
  }

  // Shell assets: CSS/JS of the app bundle (WP serves with a versioned query string so
  // new versions miss the cache naturally).
  if (/\/app\/dist\/estimate-builder\.(js|css)(\?|$)/.test(url.pathname + url.search)) {
    event.respondWith(staleWhileRevalidate(req));
  }
});

async function networkFirst(req) {
  const cache = await caches.open(DATA_CACHE);
  try {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 4000);
    const netRes = await fetch(req, { signal: controller.signal, credentials: 'same-origin' });
    clearTimeout(timer);
    if (netRes && netRes.ok) {
      cache.put(req, netRes.clone());
    }
    return netRes;
  } catch {
    const cached = await cache.match(req);
    if (cached) return cached;
    return new Response(
      JSON.stringify({
        ok: false,
        error: { code: 'offline', message: 'Offline and no cached copy available.' },
      }),
      { status: 503, headers: { 'Content-Type': 'application/json' } }
    );
  }
}

async function staleWhileRevalidate(req) {
  const cache = await caches.open(SHELL_CACHE);
  const cached = await cache.match(req);
  const netPromise = fetch(req, { credentials: 'same-origin' })
    .then((res) => {
      if (res && res.ok) cache.put(req, res.clone());
      return res;
    })
    .catch(() => null);
  return cached || (await netPromise) || new Response('', { status: 504 });
}
