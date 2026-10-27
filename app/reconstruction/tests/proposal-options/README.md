# Proposal options browser tests

This separate suite exercises the authorized v0.4.14 technician interface and the subsequently authorized homeowner selection page. It preserves the original 85 parity evidence files, every v0.4.13 browser evidence file, and the completed first six-case v0.4.14 evidence. Customer tests verify mocked browser behavior; real Books update and financial guards are checked separately by PHP tests.

After the source owner freezes changes and a candidate build succeeds, run from `app/reconstruction`:

```sh
OPTIONS_PREVIEW_FIXTURE=/absolute/path/options-tiers/validation/php-rendered-options-preview.json OPTIONS_REQUIRE_PHP_FIXTURE=1 npm run test:options
```

Follow the PDF skill marker requirement immediately before the first PDF-creating command for this operation. The workflow produces two actual PDFs, one at each viewport. Native macOS PDFKit can render every page for visual review. No dependency installation or live site access is needed by this suite.

Three scenarios run in fresh desktop 1280×900 and mobile 390×844 contexts:

- Exact Category2 facets and AND filtering with search, BTU, tonnage and efficiency; three-page filtered catalog reachability; stale responses and category reset.
- Two physical systems with Silver/Gold/Platinum alternatives each. Selected installed totals move through $17k/$19k/$23k/$25k, never all six offers’ $63k. Unselected edits do not select or charge. Selected purchase costs remain separate; a common $500 adjustment applies once. Actual PHP-rendered customer HTML shows six tier totals in two physical groups with two selection markers, $25,500 total/$8,925 deposit, no catalog unit prices or private costs. Books has two selected equipment rows plus two canonical zero-price option rows. Actual browser PDF bytes are sent only to a local mocked endpoint.
- Optional-item load failure, explicit retry and unavailable checked-option clearing, with unchanged sales and no generate/send request.

Five additional customer scenarios run at both sizes, for 16 total cases:

- Loading and retail selection cause no apply request. An explicit Update my estimate submits only revision/request identity and one choice identity per physical system, with the capability in the POST body. The approval destination is inspected only after a saved confirmation; selecting again hides it.
- Pending updates and read-only status refresh retain the exact operation. Only an explicit Check update repeats that original request identity and selections.
- The actual client’s 30-second deadline is advanced with the local browser clock. A timeout remains uncertain, with no automatic retry or approval claim, until the same operation is explicitly checked.
- Stale revision errors require a fresh view and do not automatically repeat the financial request.
- Missing, malformed and expired links show safe errors, with no write controls or raw provider echo.

The public shell supplies only restUrl/brand, without a staff nonce. A synthetic staff cookie is seeded to prove the public API uses credentials:omit. Service-worker registration is intercepted and must never be called on this shell. Tracing is disabled for customer cases because Playwright traces include raw POST bodies; even the obvious synthetic capability is redacted before request snapshots are stored. Console/errors and request URLs are checked for leaks. No off-site approval link is followed.

All customers, items and responses are synthetic. Loopback HTTP port 4191 serves the built assets and immutable logo; Playwright fulfills every business API call. Outside origins are blocked, real service-worker registration is suppressed, and no real email, Books transaction, CRM write, live website or user profile is used. Provider-side projection and CRM equipment exclusion are verified by separate PHP tests, not inferred from mocked browser generation.

Outputs go outside the repository to `options-tiers/browser-validation-final-public/` (`OPTIONS_EVIDENCE` overrides the destination). The earlier six-case evidence remains in `options-tiers/browser-validation/`. `OPTIONS_DIST` can select a frozen build. Reports include exact build hashes, sanitized synthetic requests, strict layout bounds, full/viewport screenshots, real PHP fixture provenance and PDF geometry/raster evidence. Do not overwrite earlier release evidence when rerunning a changed build.
