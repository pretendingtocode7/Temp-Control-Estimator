/**
 * Estimate Builder — React PWA entry point.
 *
 * Mounts into #tc-estimate-builder-root, which is rendered by the
 * [tc_estimate_builder] WordPress shortcode with a data-boot JSON blob
 * containing { restUrl, ajaxUrl, nonce, brand }.
 *
 * The boot blob is the only way we receive server context — we deliberately
 * do not read window.* globals or localStorage for anything security-relevant.
 * The nonce is valid for this page load only and is required on every REST call.
 */

import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import { registerServiceWorker } from './lib/sw-register.js';
import './styles/main.css';

function boot() {
  const rootEl = document.getElementById('tc-estimate-builder-root');
  if (!rootEl) {
    // Nothing to do on pages without the shortcode.
    return;
  }

  let bootData;
  try {
    bootData = JSON.parse(rootEl.dataset.boot || '{}');
  } catch (err) {
    rootEl.innerHTML =
      '<div class="tc-err">Estimate Builder failed to start: invalid boot data.</div>';
    // eslint-disable-next-line no-console
    console.error('[tc-estimate] boot JSON parse failed', err);
    return;
  }

  const { restUrl, ajaxUrl, nonce, brand } = bootData;
  if (!restUrl || !nonce) {
    rootEl.innerHTML =
      '<div class="tc-err">Estimate Builder not configured. Reload the page.</div>';
    return;
  }

  const root = createRoot(rootEl);
  root.render(
    <React.StrictMode>
      <App restUrl={restUrl} ajaxUrl={ajaxUrl} nonce={nonce} brand={brand || {}} />
    </React.StrictMode>
  );

  // Register the service worker only in secure contexts where it's supported.
  // Scope is the root div's containing page — matches plan §7.
  if ('serviceWorker' in navigator && window.isSecureContext) {
    registerServiceWorker();
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}
