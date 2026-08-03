/**
 * Service worker registration.
 *
 * The SW is served from /wp-content/plugins/temp-control-estimate-builder/app/dist/service-worker.js
 * with a scope of the same directory by default. We explicitly set scope to the plugin root
 * so the SW controls requests for /wp-json/tc-estimate/v1/* — which requires the
 * `Service-Worker-Allowed: /` response header, set by public/class-enqueue.php when serving
 * the SW file.
 *
 * Update handling:
 *   - When a new SW is found (`updatefound`), we let it install silently.
 *   - On installation, if there's already a controlling SW, we log — the new version will
 *     activate on the next navigation. We deliberately don't force reload: a field tech
 *     mid-estimate should not lose their draft because the plugin was updated server-side.
 */

export function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return;

  // Derive SW URL relative to this script's mount. The boot div lives at the
  // shortcode-rendered location; the SW lives alongside the bundle.
  const scriptEl =
    document.querySelector('script[data-tc-estimate-bundle]') ||
    Array.from(document.scripts).find(
      (s) => s.src && s.src.includes('estimate-builder.js')
    );

  let swUrl;
  let scope;
  if (scriptEl && scriptEl.src) {
    const src = new URL(scriptEl.src, window.location.origin);
    swUrl = src.pathname.replace(/estimate-builder\.js(\?.*)?$/, 'service-worker.js');
    // Scope the SW to the plugin root so it can intercept /wp-json/tc-estimate/v1/*
    // only if the server allows it via Service-Worker-Allowed: /. Without that header
    // the effective scope will be the directory, which still covers the bundle itself.
    scope = '/';
  } else {
    // Best-effort fallback if the script tag wasn't marked.
    swUrl =
      '/wp-content/plugins/temp-control-estimate-builder/app/dist/service-worker.js';
    scope = '/';
  }

  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register(swUrl, { scope })
      .then((reg) => {
        reg.addEventListener('updatefound', () => {
          const installing = reg.installing;
          if (!installing) return;
          installing.addEventListener('statechange', () => {
            if (
              installing.state === 'installed' &&
              navigator.serviceWorker.controller
            ) {
              // New version available; activate on next navigation.
              // eslint-disable-next-line no-console
              console.info('[tc-estimate] new service worker installed');
            }
          });
        });
      })
      .catch((err) => {
        // Registration can fail behind strict CSP or on scope mismatch — non-fatal.
        // eslint-disable-next-line no-console
        console.warn('[tc-estimate] service worker registration skipped:', err?.message || err);
      });
  });
}
