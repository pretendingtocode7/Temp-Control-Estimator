# Reconstruction validation — 2026-10-23

The candidate package is `0.4.8-reconstruction.1`, reconstructed from the saved
v0.4.8 runtime. This records offline validation, not approval to deploy or a
claim that the original editable source was recovered.

## Build and reference integrity

Node 24.14.1 and the committed npm lockfile were used. A clean `npm ci` installed
the pinned dependencies, followed by two builds with identical JavaScript,
CSS, worker and source-map hashes. Candidate output is ignored under
`app/reconstruction/dist/`; WordPress still loads the original `app/dist/`.

| Candidate file | SHA-256 |
| --- | --- |
| estimate-builder.js | `64ba26a6cb957db60376790e10eaa25b7a116ac3629ca6aa96c4969d5a2b4b56` |
| estimate-builder.css | `550651b9f9babd3f1943b0fb563bb9fa3d1f3990d2d21d281401484212373f7d` |
| service-worker.js | `8f6333baee8abfd204b35d1f9132284344279553e3382890f3b7b77a33003f3d` |
| estimate-builder.js.map | `f7317b5cdf6924b2ea343497593676bfab23ae5df4ccddf0a390ad27abac7a61` |

Both generated JavaScript files passed `node --check`. Every build first
verified the exact installer SHA-256
`0c1c04ef023b6aa094b2237e1f62452bed8f08d37ed4308e2d9aa0732937f56b`
and all 45 original runtime files against [the manifest](reference-manifest.json).
The source and lockfile intentionally use jsPDF 4.2.1 in place of the reference's
3.0.4; actual PDF comparisons below exercise that difference.

## Automated checks

| Check | Result | Scope |
| --- | --- | --- |
| Frontend unit/component tests | 122 passed across 7 files | State, navigation, request shaping, money/search helpers, boot, selectors/pricing, nonce recovery, retry/timeouts, PDF options/limits/Unicode, separate polished/Books edits, explicit send and queued-status behavior. Includes 33 worker tests. |
| Worker contract tests | 33 passed, included above | Run the real worker source in an isolated VM with mock network/caches: install/activation, version cleanup, query-specific data cache, network-first timeout/fallback, shell refresh and excluded routes/mutations. |
| Browser reference comparison | 8 passed | Desktop and mobile Chrome; 30 screen pairs with equal DOM/control values and zero changed pixels; API payloads and mutation order match. |
| Actual browser PDF comparison | 8 page pairs matched | Three-page complete proposals and one-page pending-send proposals at both viewports; decoded JPEG pixels, page count, Letter media boxes, image placements, filenames and UTF-8 HTML match. |
| PHP syntax | 47 files, zero errors | PHP 8.1.34 WebAssembly. |
| Standalone PHP smoke | 71 assertions passed | Shipped renderer/Mustache fallback, v0.4.8 Books/CRM transaction payload and draft-only provider double. |
| PHPUnit 10.5.66 | 40 discovered; 33 passed; 160 assertions; 7 skipped | Four skips need WordPress integration; three need libsodium, absent from this runtime. No failures or warnings. |

The browser fixtures use Chrome 156.0.8078.47 at 1280 × 900 and 390 × 844,
with device scale 1. They compare the actual saved bundle against the rebuilt
bundle, rather than exercising two aliases of the same implementation. They
mock all business APIs with synthetic data, block every external origin and
assert no browser exceptions or external requests. The screen run suppresses
real worker registration. The [parity evidence](parity/README.md) includes
screenshots, PDF image streams, request snapshots and asset hashes.

Representative desktop/mobile screens and the first/last pages of the long
PDF fixture were also visually inspected. The PDF stream inspection and pixel
checks do not replace testing in independent PDF viewers.

A dependency audit on this date reported zero known vulnerabilities for the
locked dependency tree. That is a registry snapshot, not a security guarantee.
Review found no added package/composite-item filtering or changes to the PHP
runtime, installer, existing site, credentials or other repository branches.

## Remaining staging checks

No production service was contacted. Before promoting this candidate, use a
disposable WordPress staging environment and test provider accounts to verify:

- The actual shortcode, theme/CSS, branding, authenticated session, nonce
  refresh, capabilities and estimate ownership errors.
- Service-worker registration/root scope, allowed headers, existing caches and
  CDN/cache behavior on an isolated origin. VM tests do not establish browser
  lifecycle behavior alongside a previously installed worker.
- Real catalog/template/Books/CRM shapes, draft and positional text updates,
  provider validation errors, replay behavior and large catalog content.
- Complex proposal images, fonts, CORS, pagination, independent PDF viewers,
  Safari/iOS and physical mobile devices.
- WP-Cron queue execution, WordPress mail, customer approval links and terminal
  send failures using test destinations only.

Known reference quirks, including restart/template loading, stale customer
searches and idempotency-key reuse after edits, remain documented in the
[behavior inventory](behavior-inventory.md). They were preserved for this
parity reconstruction and need separate review before deliberate changes.
No merge, release package, installation, deployment, real email or Books
transaction was performed.
