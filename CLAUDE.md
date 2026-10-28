# Temp Control Estimate Builder — Claude Code Context

The current runtime is the saved v0.4.8 installer. Its original editable frontend
source has not been recovered. The separately authorized reconstruction lives
in `app/reconstruction/`; its provenance, builds, offline tests and staging
limits are documented in `docs/rebuild/README.md`. Candidate output stays in
`app/reconstruction/dist/`; do not overwrite the exact `app/dist/` assets or
installer ZIP, or substitute older v0.2.38/v0.3.0 code. See
`docs/releases/v0.4.8-maintenance.md` for recovery evidence and PHP test results.
Phase descriptions below retain historical context and are not a complete
description of the v0.4.8 runtime.

The subsequently authorized v0.4.12 interface combines Package, AC, Furnace and
Coil in one picker with server-side BTU/tonnage filters. Read
`docs/releases/v0.4.12-unified-selector.md` before changing catalog, selection or
pricing behavior. The Package reporting tag still gates all four categories;
packages and core components are mutually exclusive within a system. Explicit
runtime overrides are projected by `app/reconstruction/tools/prepare-release.py`
into a separate release tree; keep all original reference bytes intact. Run
`npm test` and `npm run test:selector` in `app/reconstruction/` for current checks.
The older `test:parity` suite/evidence describes the historical interface.

The v0.4.13 corrections add AFUE percentage filtering, separate purchase cost
from the project sales total, and put packages in the proposal equipment list
without equipment line prices. Read
`docs/releases/v0.4.13-efficiency-proposal-pricing.md` before changing these paths.
The UI's internal `pricing.equipment_cost` must never become the legacy wire
sales subtotal or leak into the customer document. Composite costs come from
validated actual Books mapped-item purchase costs and quantities, not sales rates.

The v0.4.14 changes add exact Category 2 filters, alternatives inside each
physical system, and server-resolved zero-price maintenance/permit options.
Read `docs/releases/v0.4.14-per-system-options.md` before changing these paths.
All offered alternatives must validate; only one chosen option per physical
system reaches Books/CRM. Comparison views must never expose purchase cost or
charge every alternative. The public customer chooser updates the existing Books
estimate only after an explicit selection submission. Final Books approval is
separate and withheld while provider synchronization is incomplete. Read
`app/reconstruction/CUSTOMER-CHOICES-OPERATIONS.md` before changing this flow:
single-attempt writes, durable pending operations, customer/CRM binding and
verified stopped-worker recovery are required safety boundaries.
Use `npm run test:options` for the new current workflow; historical selector and
parity evidence is preserved separately.

## What this is

WordPress plugin for Temp Control HVAC field techs to generate Zoho Books estimates + CRM Deals
from mobile. Plan document: `Estimate_Builder_Plugin_Plan.docx` (in conversation history).

## Phase status

**Phase 1 — shipped (0.1.0).** Plugin foundation, Zoho OAuth2 bridge with encrypted refresh token,
`estimate_template` CPT, REST endpoints for `/customers`, `/equipment`, `/templates`, `/preview`,
admin settings page, three seed templates, seed catalog, audit log table, rate limiter, circuit
breaker, Mustache token renderer with loop-block tokens (Option 3 from the plan §3), PHPUnit
test suite, standalone smoke test.

**Phase 2 — shipped (0.2.0).** React PWA under `app/` (Vite, single-file build to `app/dist/`),
`/generate` endpoint with Idempotency-Key header and audit-log pending→success/error transitions,
Deluge `tc_generate_estimate` function (Books estimate + CRM Deal in one transaction with void
rollback on Deal-insert failure), service worker for offline catalog browsing (network-first
with 4s timeout + cache fallback; customer/preview/generate stay online-only), success screen
with Zoho links, Service-Worker-Allowed header emission for root scope.

**Phase 3 — next.** `/webhook/accepted` (HMAC-signed) logic, Deluge `onEstimateAccepted`
(Deal → Won, Quoted_Equipment → Equipment_To_Install), extension to existing `Invoice_Paid`
to populate `Installed_Equipment` on Service_Contracts, `/serial-entry` endpoint and
install-completion form in the React app.

## Architecture decisions locked in

1. **Token language — Mustache Option 3 loop blocks** (plan §3.5). Templates iterate
   `{{#systems}}...{{/systems}}` to handle 1..N systems from one template.
2. **Zoho OAuth refresh token encrypted with sodium_crypto_secretbox** using a key in
   `TC_ESTIMATE_ENC_KEY` wp-config constant (plan §7 Pillars 11-14).
3. **Custom DB table for audit log** (not postmeta) — queryable with status/date filters and
   idempotency-key lookups for retry.
4. **Per-service Zoho base URLs** selected by DC constant (com/eu/in/com.au/com.cn/jp).
5. **fetchWithRetry with exponential backoff** (0, 1, 2, 4 seconds) + circuit breaker that
   trips after 5 consecutive failures and half-opens after 60 seconds (plan §7 Pillars 20-25).
6. **Template version auto-incremented on every save**; pinned in audit log so retries
   replay against the exact template revision that produced the original attempt.
7. **`billing_address_full` view field uses `\n` separators** for plain-text contexts;
   HTML templates use the individual `billing_street`/`billing_city`/`billing_state`/
   `billing_zip` fields to render line breaks properly.
8. **CPT registration runs on `init` priority 5, never directly during `plugins_loaded`.**
   WP Engine's stack does not have `$wp_rewrite` populated at `plugins_loaded` time, so
   calling `register_post_type()` there fatals with
   *"Call to a member function add_rewrite_tag() on null"*. All CPT/meta registration
   is wrapped in `add_action('init', ..., 5)` inside `Plugin::run()`. `Plugin::activate()`
   has a defensive branch that falls back to an init action if `$wp_rewrite` is null.

## Running tests

**Standalone smoke test (no dependencies):**
```bash
php tests/smoke.php
```
Produces 71 assertions against the shipped Mustache fallback, renderer/seed
templates, v0.4.8 transaction payload and draft creation with an in-memory Zoho
double. It does not load real HTTP or email implementations.

**Full PHPUnit suite (requires `composer install`):**
```bash
composer install
vendor/bin/phpunit -c tests/phpunit.xml
```
Full tests exercise Zoho_API circuit breaker, rate limiter, Security encryption roundtrip.
WP-integration tests (`ZohoApiTest.php`, `RateLimiterTest.php`) require `WP_TESTS_DIR` set
to wp-phpunit.

**Sample proposals:**
```bash
php tests/sample-render.php
```
Writes `samples/balson-full-replacement.html` and `samples/clemente-two-system.html`.

## Coding conventions enforced

- `declare( strict_types=1 );` at top of every PHP file.
- Singleton pattern via `instance()` static method for every service class.
- Class prefix `TempControl\Estimate\` namespace (PSR-4).
- Nonce + capability check on every REST handler via `Security::gate_request()`.
- Constant-time comparisons with `hash_equals` for any secret comparison.
- All Zoho HTTP calls through `Zoho_API::{get,post,put}` — never raw `wp_remote_request`.
- Transient keys prefixed with `tc_` and tracked in `tc_estimate_cache_index` for flush_all.
- No emojis, no AI-writing vocabulary, no inflated framing.

## Smoke test assertion count

71 assertions total. The generator checks use `build_transaction_payload()`:
meta/books/crm sections, separate contact/account IDs, idempotency/template
metadata, line mapping, pricing and plain approval notes separate from proposal
HTML. Synthetic provider responses cover `send=false`, errors and non-draft
cleanup. PHPUnit adds detailed renderer/edit/permit/adjustment coverage.

## Building the React app

These are historical build commands. The saved v0.4.8 archive has built assets
only; its matching source, package files and Vite configuration are absent.
Do not use an older branch's sources to overwrite the v0.4.8 bundle.

```bash
cd app
npm install
npm run build          # → app/dist/estimate-builder.{js,css} + service-worker.js
```

The WP enqueue layer (`public/class-enqueue.php`) looks for `app/dist/estimate-builder.js`
and skips enqueueing if it's missing — the shortcode then renders a "bundle not built"
notice instead of a silent blank page. The same class handles `Service-Worker-Allowed: /`
emission so the SW can control the REST paths.

## Phase 2 shipped — components

- `endpoints/class-generate-estimate.php` — `/generate` with idempotency replay logic
  (success → cached result, pending<90s → 409, stale pending → retry fall-through,
  error → 409 `tc_estimate_prior_error` requiring a new key).
- `includes/class-estimate-generator.php` — builds the Deluge payload from validated
  request + hydrated customer + rendered body, invokes via `/crm/v7/functions/{fn}/actions/execute`,
  unwraps `details.output` JSON, branches on `ok`.
- `deluge/generate_estimate.deluge` — transactional function. Books createRecord →
  CRM createRecord with Quoted_Equipment subform → on CRM failure, invoke Books
  `/estimates/{id}/status/void`. Success path sets `cf_tc_crm_deal_id` as a best-effort
  back-link on the Books estimate.
- `app/` — Vite + React 18 app. Single-file bundle output. See `app/src/App.jsx` for
  the wizard reducer and `app/src/components/` for the six step components.
- `app/src/sw/service-worker.js` — network-first with 4s timeout for catalog routes,
  never-cache pattern for customers/preview/generate/webhook.

## Known TODOs for Phase 3

- Books acceptance webhook already has HMAC verification. Secret is at
  `tc_estimate_webhook_secret` option, auto-generated on first settings-page view.
- Need new Deal subforms `Quoted_Equipment` and `Equipment_To_Install` in Zoho CRM (plan §5.3).
- Need new `Installed_Equipment` subform on Service_Contracts (plan §5.4).
- Extend existing `Invoice_Paid` Deluge — read `Equipment_To_Install` off the related Deal,
  copy models forward, leave serials empty for tech entry.

## Security pillar coverage

| Pillars | Category | Where |
|---------|----------|-------|
| 01–04 | Access Control | `Capabilities`, `Security::gate_request` |
| 05–10 | Timing Defense | `Security::safe_equals`, `Security::verify_hmac`, `Rate_Limiter` |
| 11–14 | Encryption | `Security::encrypt/decrypt`, `TC_ESTIMATE_ENC_KEY` constant |
| 15–19 | Password Security | Settings form uses password-type inputs; never re-renders entered values |
| 20–25 | Third-Party Resilience | `Zoho_API` retry/backoff/circuit breaker, `Zoho_Cache`, audit log with retry |

## Files you should never touch without extreme care

- `includes/class-security.php` — encryption roundtrip, HMAC, nonce verification. Changes here
  can silently lock out existing tokens or introduce timing leaks.
- `includes/class-zoho-api.php` — circuit breaker state machine. Wrong transitions can either
  hammer Zoho during outages or lock out the plugin entirely.
- `uninstall.php` — this deletes customer-authored templates if altered incorrectly.
