/*
 * Reconstructed editable source from the saved v0.4.8 deployment worker.
 * This preserves observed behavior; it is not recovered original source.
 * The saved plugin's app/dist/service-worker.js remains the reference artifact.
 */
(function () {
  "use strict";

  // Keep the reference version/cache names while testing parity in isolation.
  const VERSION = "0.4.8";
  const SHELL_CACHE = `tc-est-shell-${VERSION}`;
  const DATA_CACHE = `tc-est-data-${VERSION}`;
  const CATALOG_ROUTE =
    /\/wp-json\/tc-estimate\/v1\/(equipment|templates)(\/|$|\?)/;
  const ONLINE_ONLY_ROUTE =
    /\/wp-json\/tc-estimate\/v1\/(customers|customer-choices|preview|generate|update-estimate|send-estimate|send-status|webhook)/;
  const SHELL_ASSET = /\/app\/dist\/estimate-builder\.(js|css)(\?|$)/;

  self.addEventListener("install", (event) => {
    event.waitUntil(self.skipWaiting());
  });

  self.addEventListener("activate", (event) => {
    event.waitUntil(
      (async () => {
        const cacheNames = await caches.keys();
        await Promise.all(
          cacheNames
            .filter(
              (name) => name.startsWith("tc-est-") && !name.endsWith(VERSION),
            )
            .map((name) => caches.delete(name)),
        );
        await self.clients.claim();
      })(),
    );
  });

  self.addEventListener("message", (event) => {
    if (event.data && event.data.type === "SKIP_WAITING") self.skipWaiting();
  });

  self.addEventListener("fetch", (event) => {
    const request = event.request;
    if (request.method !== "GET") return;

    const url = new URL(request.url);
    if (ONLINE_ONLY_ROUTE.test(url.pathname)) return;

    // Preserve the reference's path matching and complete Request cache keys.
    // Do not add origin filtering or cache customer/mutation/status responses.
    if (CATALOG_ROUTE.test(url.pathname)) {
      event.respondWith(networkFirstCatalog(request));
    } else if (SHELL_ASSET.test(url.pathname + url.search)) {
      event.respondWith(staleWhileRefreshingShell(request));
    }
  });

  async function networkFirstCatalog(request) {
    const cache = await caches.open(DATA_CACHE);
    try {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 4000);
      const response = await fetch(request, {
        signal: controller.signal,
        credentials: "same-origin",
      });
      clearTimeout(timeout);

      // Writes run in the background. HTTP errors are returned as-is; only a
      // thrown fetch failure or timeout enters the matching cached fallback.
      if (response && response.ok) cache.put(request, response.clone());
      return response;
    } catch {
      const cached = await cache.match(request);
      return (
        cached ||
        new Response(
          JSON.stringify({
            ok: false,
            error: {
              code: "offline",
              message: "Offline and no cached copy available.",
            },
          }),
          { status: 503, headers: { "Content-Type": "application/json" } },
        )
      );
    }
  }

  async function staleWhileRefreshingShell(request) {
    const cache = await caches.open(SHELL_CACHE);
    const cached = await cache.match(request);
    const network = fetch(request, { credentials: "same-origin" })
      .then((response) => {
        if (response && response.ok) cache.put(request, response.clone());
        return response;
      })
      .catch(() => null);

    return cached || (await network) || new Response("", { status: 504 });
  }
})();
