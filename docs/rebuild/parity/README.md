# Offline browser parity evidence

This is evidence for an editable reconstruction of the saved v0.4.8 frontend, not recovered original source and not production verification. The reference is the untouched plugin's `app/dist/estimate-builder.js` and CSS. The candidate is the separate build in `app/reconstruction/dist`. Each JSON report records their SHA-256 digests, browser version and viewport.

The 2026-10-23 local run passed **8 of 8 Playwright tests** in Chrome 156.0.8078.47, using desktop 1280 × 900 and mobile 390 × 844 viewports at device scale 1. There are **30 screen pairs** with identical root DOM/control values and **zero changed pixels**. Each viewport runs the same four scenarios:

| Scenario | Compared screens per viewport | Verified behavior |
| --- | ---: | --- |
| Complete journey | 11 | Customer search/selection; client-side equipment filtering; editable equipment descriptions; permit price override; adding, renaming and removing systems; empty-system validation; subtotal versus manual total; deposit, warranty, fees, rebate and financing options; editable polished and Books versions; explicit draft creation; Books changes/save; explicit PDF send and processing/sent polling; restart edge case. |
| Nonce and replay | 2 | Recognized expired WordPress nonce refresh; explicit replay after an in-flight draft response; the same attempt key and payload reused; no automatic send. |
| Missing template and offline | 1 | Configuration error, disabled progression, offline banner, no customer persistence in local/session storage. |
| Queued send loses status | 1 | Actual PDF creation; one queued send; a truthful pending state after a polling failure; no automatic resend or enabled resend control. |

For every scenario, reference and candidate issue matching mocked API requests, including JSON payloads, nonce/session headers and mutation order. There are **68 request pairs** across the eight scenarios (46 GET and 22 POST, including local nonce refreshes). GET requests are compared as a sorted list because their completion order can vary; duplicate requests remain represented. The reports include these synthetic requests; PDF binary data is replaced by a marker and checked separately.

## Actual PDF comparison

The tests use the actual browser html2canvas and jsPDF code, not a PDF stub. The reference bundles jsPDF 3.0.4; the reconstructed build pins jsPDF 4.2.1. A 48-paragraph synthetic polished proposal containing UTF-8 `café` produces **three Letter pages** in each build at each viewport. The shorter pending-send case produces one page in each build.

Across **8 PDF page pairs**, the decoded JPEG raster pixels and image placement matrices are identical. The generated files have 612 × 792 pt Letter media boxes, 556 pt image width, 28 pt left and top margins, and identical slice heights and final-page positioning. The three-page files are 633,292 bytes each in this run. UTF-8 HTML, filename and page counts also match. PDF binary identity is not required because producer metadata, timestamps and document IDs may differ between jsPDF versions/runs.

The `*-pdf-page-*.jpg` files are the actual JPEG streams embedded in the generated PDFs. The test-only inspector reads the direct-length image and compressed content streams emitted by jsPDF in these fixtures. It is not a general PDF validator or an independent PDF-viewer rendering test. Page 1 and page 3 were visually inspected. The reference's direct canvas slicing, which can cut a line of text between pages, is preserved.

## Isolation and rerunning

From `app/reconstruction` after the locked dependencies are installed:

```sh
npm run build
npm run test:parity
```

`CHROME_PATH` may override the native Chrome executable configured in `playwright.config.js`. The harness starts its own static HTTP server on `127.0.0.1:4178` and launches fresh Playwright browser contexts. The server serves only explicit reference/candidate assets and fixture pages. Every WordPress API request is fulfilled locally using synthetic customers, equipment, IDs and results. All other network origins are blocked and asserted absent. The Books/CRM example links are never opened. No real customer records, emails, provider transactions, credentials or user browser profile are used.

Real service-worker registration is suppressed in these screen/API tests so a worker cannot bypass the request fixtures. Service-worker network/cache/version behavior is covered separately by the worker contract tests; browser registration, scope and interaction with existing caches still require staging.

Each `desktop-*.json` / `mobile-*.json` report links its reference/candidate PNGs and PDF raster evidence. Successful tests require exact pixel equality, exact DOM/control values, matching synthetic API requests, zero page exceptions and zero external requests. Deterministic fixture UUIDs make idempotency keys comparable. Screenshots use a neutral page background, blur the focused control and hide the caret in both variants.

## Preserved reference edge and remaining checks

The saved v0.4.8 bundle resets the selected template when **Start another estimate** is pressed, but does not reload that template. Selecting another customer then leaves **Next** disabled. The reconstruction preserves this observed behavior; `restart-reference-edge` captures it. A correction would be a separately reviewed product change.

The neutral local fixtures do not establish compatibility with a particular WordPress theme, custom CSS, real branding/images/fonts, production catalog size/content, WordPress authentication/permissions, PHP preview sanitization/rendering, actual Books/CRM responses, job scheduling, email delivery, or live caches. Mobile here is Chrome device emulation, not an iOS/Safari or physical-device check. Only the documented synthetic workflows and viewport/browser combination are covered. WordPress staging with isolated test services remains necessary before any release. No installation, deployment or live site modification occurred.
