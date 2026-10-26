# v0.4.8 frontend reconstruction

This is a new editable reconstruction derived from the actual saved v0.4.8
JavaScript, CSS, service worker and PHP contracts. It is **not recovered
original source**, and it does not substitute the older v0.2.38/v0.3.0 app.

The candidate lives in `app/reconstruction/`. Its build writes only
`app/reconstruction/dist/`; WordPress still enqueues the unchanged
`app/dist/` reference. The exact saved installer and all 45 runtime files are
recorded in [reference-manifest.json](reference-manifest.json), and every
candidate build verifies their hashes first. The reference and installer stay
unchanged when release packaging overlays the rebuilt assets and the explicit
service-worker delivery override into a separate installable tree. See the
[v0.4.10 worker correction and packaging instructions](service-worker-fix.md).
Running the development build itself does not modify a website or credentials.

The current authorized interface uses the [unified Package/AC/Furnace/Coil
selector](../releases/v0.4.12-unified-selector.md), with BTU/tonnage filters and
explicit package pricing. Historical reference-screen evidence remains archived.

## Reproducible development setup

From `app/reconstruction/`:

```sh
npm ci
npm run verify:reference
npm run build
npm test
npm run test:selector
```

The lockfile pins the dependency tree. Node 24.14.1 was used for validation;
the declared minimum is Node 22.12.0. Browser parity uses an isolated local
HTTP fixture and an ephemeral native Chrome profile, with external hosts
blocked and every business API response mocked. See the Playwright config
for the Chrome path override. No connected WordPress/Zoho/email account is
needed to run these checks.

`test:parity` retains the historical exact-screen comparison for the frontend
before the selector redesign. Current behavior and layout are validated by
`test:selector`; the old reference screenshots and request/PDF evidence are kept.

`src/entry.jsx` produces a self-contained classic IIFE, not a module script,
plus `estimate-builder.css` and a classic `service-worker.js`. Build target is
ES2020. Source maps are generated only in the separate candidate output.
`dist/`, dependencies and test output directories are ignored by Git; the
source, exact dependency lock and validation evidence are tracked.

The supplied bundle identifies React 18.3.1, html2canvas 1.4.1 and jsPDF 3.0.4.
The reconstruction pins React/React DOM 18.3.1 and html2canvas 1.4.1, and uses
jsPDF 4.2.1. That is an intentional dependency difference, not a claim to have
recovered the old build configuration. The PDF API calls, geometry and send
payload contract are retained and tested; PDF bytes need not match across
library versions, creation timestamps and generated document IDs.

## Source organization and method

The custom application regions were identified in the supplied bundle,
separated from bundled third-party libraries, restored to JSX, divided into
components/modules and renamed for readability. Existing CSS rules were
formatted into editable source. Third-party packages are installed through
the pinned manifest rather than copied out of the minified bundle.

- `src/App.jsx`, `model.js`: wizard navigation, in-memory state and request
  shaping, with separate polished proposal and Books edit state.
- `src/components/`: customer search, catalog drawer, systems, manual pricing,
  proposal/Books editors, review and explicit draft/send screens.
- `src/lib/api.js`: nonce recovery, error envelopes, timeouts and bounded
  retries. Mutations keep the reference retry boundaries.
- `src/lib/pdf.js`: browser raster capture, Letter-page slicing, attachment
  size check and UTF-8 proposal encoding.
- `src/service-worker.js`: versioned shell/catalog/template caches, with
  customers and mutations excluded.

The unused bundled template-picker component is not reconstructed: v0.4.8
automatically selects a proposal layout and never mounts that picker.
See [behavior-inventory.md](behavior-inventory.md) and
[php-api-contract.md](php-api-contract.md) for the inspected behavior.
The [validation record](validation.md) documents the build hashes, tests,
browser/PDF comparisons and remaining staging checks.

## Review boundaries

The rebuilt output is a candidate for staging, not a replacement installer.
Mocked API tests establish frontend behavior against explicit fixtures; they
do not establish production authorization, CRM mappings, WordPress mail or
WP-Cron behavior. Staging must verify the real shortcode/theme, authenticated
nonce/capability/owner errors, cache/CDN headers and root service-worker scope,
actual Books draft/update layouts, PDF images/fonts/pagination, queued mail,
approval links and error/replay handling before any promotion.

Known reference edge cases are recorded in the inventory and intentionally
remain visible. Reconstruction did not silently add product features or fix
unrelated behavior. Promotion or a deliberate behavior change is separate
work; no merge or deployment is performed here.
