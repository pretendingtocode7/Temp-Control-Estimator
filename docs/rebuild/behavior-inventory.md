# Inspected v0.4.8 behavior inventory

The saved ZIP is the reference. Its custom client code appears in two regions
separated by bundled PDF dependencies; the implementation was inspected before
reconstruction. All fixtures and screenshots for reconstruction checks use
synthetic records.

| Area | Reference behavior to preserve |
| --- | --- |
| Boot/layout | `#tc-estimate-builder-root` reads `data-boot` containing REST/AJAX URL, nonce and brand. React StrictMode; classic script; 760px mobile column, sticky header, four-step progress, fixed footer and catalog drawer. |
| Wizard | Customer → Equipment → Pricing → Review → Done. Automatically choose the first `full_replacement` template, otherwise the first template. One Main System, 10-year parts/labor notes, 35% deposit and 60-month financing default. |
| Customer | Two-character minimum, 300ms debounce, up to 20 CRM search results, name/address display and change selection. No customer creation or browser persistence. IDs stay strings. |
| Catalog/systems | Eleven equipment/accessory/permit slots; `coil` queries `evaporator_coil`. Fetch up to 100 items, then locally search brand/model/name/SKU/JS part/MFG part/description. Editable descriptions; only permit rate is editable. Add, rename, remove and reindex systems. |
| Calculations | Sum finite numeric selected-item rates and round to cents when systems change. Project total stays manual; Use subtotal copies it explicitly. Rebates, financing and fee notes do not automatically change project total. |
| Review | Fetch server preview without Books edits. Keep editable polished HTML separate from Books descriptions/notes/terms; initialize them once and preserve edits. Plain-text paste; reset polished document to server preview. |
| Draft payload | Include selected IDs, descriptions, permit rate, options, manual pricing and Books edits. Strip hydrated customer/catalog fields. Polished HTML stays client-side until explicit send. Allocate one idempotency key and retain it across uncertain creation attempts. |
| Books edit | Pre-generation edits use stable keys. Post-generation update is positional and must preserve line order/count. Prices and item IDs are not edited on these screens. |
| Send | No email during creation. Explicit Send saves dirty Books fields, generates a polished PDF, then queues email. Poll every 2 seconds up to 60 times. After a job ID, a status connection failure becomes pending and blocks duplicate send; explicit job error permits retry. |
| PDF | Offscreen 816px white document, 52px padding, Arial 15px/1.45; image wait up to 3s; capture scale 1.35; Letter portrait, 28pt margins, JPEG quality .8, compressed page slices. Client max 4MiB; return filename, raw PDF base64, size and UTF-8 `html_b64`. |
| API | Same-origin cookies and nonce; one nonce refresh/replay. Read/preview timeout 10s, creation 25s, update/send 30s. Reads/preview retry transient failures twice; status once. General mutation retries are disabled. |
| Cache | Startup limit:1 request primes the 12-hour server catalog cache. Worker caches only query-specific equipment/template GETs and bundle assets; four-second network-first data strategy. Customers, preview, creation, edits, sends, status and webhooks are excluded. State/nonces are not written to web storage. |

## Observed reference edge cases retained for explicit review

- Start another estimate resets `template` to null, but template loading depends
  only on the stable API instance. A second estimate can stall at Customer Next.
- Outstanding customer searches are not aborted; an older response can replace
  newer search results.
- Editing an estimate retains its allocated idempotency key. The generation
  endpoint looks up that key before validating the body and does not compare a
  payload fingerprint; a replay can therefore return the prior result even
  after the operator changes draft fields. Explicit new-attempt handling is
  retained rather than changed during reconstruction.
- Client permit limits are HTML input bounds; PHP performs the actual rate
  bounding. The client subtotal can reflect an out-of-range typed value until
  server validation.
- Read retries ignore `Retry-After`. Timeout cancellation covers fetch response
  headers, not subsequent body reading. Nonce refresh does not adopt a changed
  REST URL. The prewarm promise stays memoized even after rejection.
- Standard WordPress non-nonce HTTP error codes are surfaced as `http_STATUS`
  by the reference client, while envelope codes are retained.
- Worker cache keys include queries; there is no data time expiry beyond
  version cleanup. Existing path-only interception rules have no extra origin
  filter. Returned HTTP errors do not trigger stale-cache fallback.
- The polished editor renders operator-authored HTML and does not run custom
  content through the bundled DOMPurify dependency. Plain-text paste is retained;
  the presence of DOMPurify in the old PDF bundle is not evidence of sanitation.

These observations are not silently changed by the reconstruction. They are
separate from staging-only dependencies such as real authenticated WordPress
responses, provider data, theme styles, image CORS, mail workers and approval
links.
