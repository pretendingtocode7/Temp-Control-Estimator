# Unified selector browser tests

From `app/reconstruction`, after a successful candidate build:

```sh
npx playwright test --config playwright.selector.config.js
```

The config uses native headless Chrome (`CHROME_PATH` can override its executable), a loopback-only static server on port 4190, and fresh browser contexts at desktop 1280 × 900 and mobile 390 × 844. All business API responses are synthetic. External origins are blocked, and real service-worker registration is suppressed so requests cannot escape the mocks.

The two scenarios run at both sizes:

- A 133-Package catalog exercises three pages, reaching and selecting item 126. Search plus 100000 BTU plus 1.5 tons narrows three fixtures to one. A deliberately late Package response must not replace the active AC results.
- Package→AC/Furnace/Coil→Package replacement verifies canonical slots, other-component preservation, editable descriptions, one Package price, two systems, manual project total, one Books Package line, explicit draft creation and actual browser PDF generation delivered only to a mocked send endpoint.

The existing accessory-add UI is not part of the new four-category selector. Preservation of pre-existing legacy accessories is covered by the separate SystemsStep component tests; this suite does not fabricate a way to create such initial state through the app.

Screenshots, strict horizontal/control-bound checks, request payloads, generated PDF geometry/raster evidence, logs and reports are written outside the repository under `unified-selector/browser-validation/`. Both viewport and full-page screenshots are saved; fixed/sticky app chrome is best assessed in the viewport files. The 85 original v0.4.8 parity evidence files are hashed before and after each project and must remain unchanged.

These are intentional new-interface tests, not pixel comparisons with the old per-slot selector. The fixture server supplies normalized eligible catalog rows and a mocked Books preview; actual PHP eligibility, canonicalization, provider mappings and authentication are tested separately. No real customer record, Books transaction, email, live website or user browser profile is used.
