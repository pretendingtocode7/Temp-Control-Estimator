/** Reconstructed from the saved v0.4.8 deployment bundle; not recovered original source. */
import { createRoot } from "react-dom/client";
import React from "react";
import { App } from "./App.jsx";
import "./styles.css";

export function registerServiceWorker() {
  if (!("serviceWorker" in navigator)) return;
  const script =
    document.querySelector("script[data-tc-estimate-bundle]") ||
    Array.from(document.scripts).find(
      (candidate) =>
        candidate.src && candidate.src.includes("estimate-builder.js"),
    );
  const workerUrl = script?.src
    ? new URL(script.src, window.location.origin).pathname.replace(
        /estimate-builder\.js(\?.*)?$/,
        "service-worker.js",
      )
    : "/wp-content/plugins/temp-control-estimate-builder/app/dist/service-worker.js";
  // Retain the original load-event timing and root scope; staging must supply the header.
  window.addEventListener("load", () => {
    navigator.serviceWorker
      .register(workerUrl, { scope: "/" })
      .then((registration) => {
        registration.addEventListener("updatefound", () => {
          const worker = registration.installing;
          if (worker)
            worker.addEventListener("statechange", () => {
              if (
                worker.state === "installed" &&
                navigator.serviceWorker.controller
              )
                console.info("[tc-estimate] new service worker installed");
            });
        });
      })
      .catch((error) =>
        console.warn(
          "[tc-estimate] service worker registration skipped:",
          error?.message || error,
        ),
      );
  });
}

export function mount() {
  const root = document.getElementById("tc-estimate-builder-root");
  if (!root) return;
  let boot;
  try {
    boot = JSON.parse(root.dataset.boot || "{}");
  } catch (error) {
    root.innerHTML =
      '<div class="tc-err">Estimate Builder failed to start: invalid boot data.</div>';
    console.error("[tc-estimate] boot JSON parse failed", error);
    return;
  }
  const { restUrl, ajaxUrl, nonce, brand } = boot;
  if (!restUrl || !nonce) {
    root.innerHTML =
      '<div class="tc-err">Estimate Builder not configured. Reload the page.</div>';
    return;
  }
  createRoot(root).render(
    <React.StrictMode>
      <App
        restUrl={restUrl}
        ajaxUrl={ajaxUrl}
        nonce={nonce}
        brand={brand || {}}
      />
    </React.StrictMode>,
  );
  if ("serviceWorker" in navigator && window.isSecureContext)
    registerServiceWorker();
}

if (document.readyState === "loading")
  document.addEventListener("DOMContentLoaded", mount, { once: true });
else mount();
