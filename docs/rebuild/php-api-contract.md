# v0.4.8 PHP frontend contract inventory

Read-only inventory of the actual saved PHP implementation. No API, mail, cron,
or live-site calls were made. Runtime files are unchanged by this inventory.

## Boot, transport, authentication

`public/class-shortcode.php` mounts `#tc-estimate-builder-root` with an HTML-escaped
`data-boot` JSON object:

```ts
{ restUrl: string, ajaxUrl: string, nonce: string,
  brand: { primary: '#214c7a', name: 'Temp Control Heating & Air Conditioning' } }
```

Use same-origin WordPress session cookies and `X-WP-Nonce` on every frontend REST
request; JSON POSTs use `Content-Type: application/json`. Builder use requires
`manage_tc_estimates` OR `manage_options`. All frontend routes use the standard
permission callback. Settings/admin capability is not a frontend replacement.
REST responses are marked private/no-store by PHP.

`POST ajaxUrl` with URL-encoded `action=tc_estimate_rest_nonce` refreshes the REST
nonce without requiring the expired REST nonce. WordPress response is
`{success:true,data:{restUrl,nonce}}`; unauthenticated/insufficient-capability
response is `{success:false,data:{message}}` with HTTP 401. Gate errors may be
WordPress's native `{code,message,data:{status}}`, whereas handler errors use
`{ok:false,error:{code,message,body?,zoho_response?,deluge_output?}}`. Standard
handler success is `{ok:true,data:...}`. `Retry-After` is an HTTP response header,
not a JSON field. Handle non-JSON proxy errors separately. Only a recognized
nonce failure warrants one nonce-refresh retry; lack of permission is terminal.

IDs must remain strings: account/estimate IDs validate as 15–20 digits, equipment
IDs as 10–20 digits. Values can exceed JavaScript's safe integer range.

## Read routes

- `GET /customers?q=...&limit=20`: minimum 2 trimmed query characters; shorter
  queries return `[]`. Limit clamped 1–50. Data is an array of
  `{id,name,phone,email,billing_address:{street,city,state,zip,country}}`, all
  strings. This is CRM account search; there is no frontend `/customers/{id}`.
- `GET /equipment?type=...&q=...&brand=...&limit=50`: limit clamped 1–100,
  no pagination contract. Type enum: `furnace`, `condenser`, `evaporator_coil`,
  `air_handler`, `thermostat`, `humidifier`, `uv_purifier`, `water_heater`, `filter`,
  `part`, `permit`, `other`. Unknown type → 400 `tc_estimate_bad_type`.
  Untyped/unknown catalog types are included in a requested valid category and
  assigned that type in the response. Brand filter is case-insensitive equality;
  q is a substring search of name/SKU/brand/model/description.
- `GET /equipment/{id}` returns one normalized eligible item. Inactive or
  `cf_for_estimate=false` item → 400 `tc_estimate_item_not_eligible`; missing item
  → 404. Both list and get require Books organization configuration. Data includes
  `item_id,name,sku,equipment_type,brand,model,stages,refrigerant,short_description,
  long_description,js_part_number,mfg_part_number,source:'zoho_books'` strings;
  `rate,stock_on_hand` numbers; `afue,seer,tons,width_inches,purchase_cost,markup`
  nullable numbers; `btu_input,btu_output,warranty_parts_years,warranty_labor_years`
  nullable integers; `for_estimate` boolean.
- `GET /templates?type=...`: array, at most 100 published active templates,
  metadata only. Each row:
  `{id:number,name:string,template_type:string,required_slots:[{type,min,max}],
  supports_rebates:boolean,supports_financing:boolean,default_warranty_parts:number,
  default_warranty_labor:number,active:boolean,version:number}`.
  `GET /templates/{integerId}` adds `body:string`; inactive/unpublished/missing
  → 404`tc_estimate_not_found`.

Server customer cache TTL is 5 minutes. Actual catalog TTL in the v0.4.8 version
constant is 12 hours; class comments claiming 15 minutes are stale. No response
pagination metadata is supplied by the frontend search endpoints.

## Preview and generation request

The shared practical payload shape is:

```ts
{
  template_id: number,
  customer: { zoho_account_id: string, override?: object },
  systems: Array<{
    system_number: number, system_label: string,
    equipment: Record<string, {
      item_id: string, description?: string, notes?: string,
      rate?: number // honored only for permit slot
    }>
  }>,
  options?: {
    warranty_parts_years?: 0 | 10, warranty_labor_years?: 0 | 10,
    credit_card_fee_applies?: boolean, financing_fee_applies?: boolean,
    financing_requested?: boolean, financing_term_months?: number,
    special_notes?: string, rebates?: Array<{name:string,amount:number}>
  },
  pricing: { subtotal: number, total: number, deposit_percent: number },
  books_edits?: {
    line_items: Array<{ key:string,item_id?:string,name?:string,description:string }>,
    notes: string, terms: string
  }
}
```

Known equipment slots are `furnace,condenser,coil,air_handler,thermostat,humidifier,
uv_purifier,water_heater,filter,part,permit`; note slot `coil` versus equipment
query type `evaporator_coil`. Other keys go into the view's `other` array. PHP
fetches catalog records by item ID and ignores client-supplied non-permit prices.
Descriptions are sanitized textarea text capped 2000 characters. Permit rates
are rounded to 2 decimals and clamped 0–100000. Special notes cap at 2000 characters;
flags are normalized; warranties normalize to 10 or 0.

Books edits sanitize at most 50 rows: key 100 characters, name 250, description 2000,
notes/terms 5000 each. If a books_edits object is present, omitted notes/terms
become empty strings. For generation, descriptions map by stable keys of the
form `system_1_furnace_0`; other rows use their index as the suffix. Empty
description edits are intentional and retained. The pure generator preserves
catalog item IDs/rates/quantity and uses a project price adjustment to reconcile
selected line rates with target total.

`POST /preview` requires a positive template ID and a published template. CRM
customer retrieval can soft-fail; `customer.override` is accepted here only.
Item-load failures do not soft-fail. It returns HTTP 200:

```ts
{
  html: string,
  books_preview: {
    line_items: Array<{key:string,item_id:string,name:string,description:string,rate:number}>,
    notes: string, terms: string
  },
  view: object, template_id: number, template_version: number
}
```

View includes hydrated customer/branding, systems with named slot records,
`items`/`other` arrays, proposal_notes, rebates, financing, totals/formatted
totals and deposit. Preview performs reads/rendering only; it must never create,
update or send an estimate. The PHP preview does not apply books_edits to its
HTML or returned books_preview; preserve the user's separate edit state.

`POST /generate` additionally requires a real CRM account ID, at least one
system and selected item, positive pricing.total, and `Idempotency-Key` (fallback
`X-TC-Idempotency-Key`) of 1–64 `[A-Za-z0-9_-]` characters. Do not send a selected
customer object's `id` under `customer.id`; map it to `zoho_account_id`.

New result HTTP 201 data:
`{estimate_id,estimate_number,estimate_status,estimate_url,deal_id,deal_url,
subtotal,total,books_email_sent:false,books_email_message,template_id,
template_version,idempotency_key,duration_ms}`. URLs are internal Books/CRM
links, not customer approval links. Generation creates a draft with `send=false`,
verifies draft state, and does not email. CRM failure triggers Books draft
rollback; UI must show the returned failure, never fabricate success.

Same-user successful key replay is HTTP 200, same IDs and `replayed:true`, but
omits `estimate_number` and `duration_ms`. Pending < 90 seconds → 409
`tc_estimate_in_flight`, `Retry-After:5`; reuse same key. Prior error → 409
`tc_estimate_prior_error`, new key only after explicit retry. The backend looks
up key before validating the new payload and does not fingerprint the body:
do not reuse a successful key for a different intended estimate. Network/proxy
uncertainty is not permission to silently create a fresh-key duplicate.

## Update draft text

`POST /update-estimate` body `{estimate_id:string,books_edits:{line_items,notes,terms}}`.
Requires successful generate audit owned by current user (403 otherwise) and
live Books status draft (409 `tc_estimate_not_draft` otherwise). Count of edited
rows must exactly match live rows (409 `tc_estimate_books_lines_changed`).

IMPORTANT: after generation, descriptions apply POSITIONALLY by index, not by
edit key/item ID. Preserve preview order, never reorder on a label sort, and
stop on linecount conflict. Names/IDs/rates/quantity/taxes/discount are copied
from live Books; only descriptions/notes/terms are edited. PUT uses send=false.
Success 200 data `{estimate_id,saved:true,message}`. This success does not send
mail and does not return a fresh Books payload or HTML.

## Explicit send and polling

`POST /send-estimate` requires `{estimate_id, pretty_attachment:{filename,data,
html_b64?:string,html?:string}}`. IDs are owned via successful generate audit.
Attachment filename must sanitize to a nonempty .pdf filename; data must be raw
strict-base64 PDF contents, not a data-URL. Decoded bytes must begin `%PDF-` and
be ≤ 8*1024*1024 bytes. Larger → 413 `tc_estimate_attachment_too_large`; missing or
invalid PDF → 400 attachment error. Optional HTML decodes from html_b64 if html
is blank, truncates at 250000 characters; missing/stripped/invalid HTML is allowed.
The PDF is authoritative. Use Unicode-safe UTF8 base64 for html_b64.

Successful new queue response 202:
`{job_id,estimate_id,status:'queued',sent:false,message,error_code:''}`.
Queued/processing duplicate sends replay same job with 202. Already-successful
send replays 200 `{estimate_id,status:'sent',sent:true,replayed:true,message}`
WITHOUT job_id. Pending audit with no job < 90 seconds → 409
`tc_estimate_send_in_flight`, Retry-After: 5. Attachment storage/scheduling/audit
errors are 500 and state no email sent. UI must not call send automatically
after preview or generation, and must disable duplicate clicks while in flight.

`GET /send-status?job_id=...` uses same nonce/session. Owner-only; unknown/expired
job → 404`tc_estimate_send_job_missing`, other owner → 403
`tc_estimate_send_job_forbidden`. Jobs last 3600 seconds. Successful HTTP 200 data
`{job_id,estimate_id,status:'queued'|'processing'|'sent'|'error',sent:boolean,
message:string,error_code:string}`. A terminal job error is still ok:true,
so inspect status, not just HTTP or envelope. Polling queued jobs can reschedule
cron. Stop polling on sent/error; a missing/expired job requires checking Books
before explicit retry, not blind resend.

The cron worker fetches current customer/Books estimate, sends branded WordPress
mail with PDF, then best-effort marks Books sent; it does not call Books email.
If Books already sent/accepted/declined/invoiced, it completes without duplicate
email. For a non-draft unexpected state it fails. A Books mark-sent failure must
not cause another email after WordPress mail succeeded.

Per-user rate limits: generate 5/minute and 20/hour, preview 30/minute,
send 5/minute, update 10/minute, customer/equipment searches 60/minute. HTTP 429 includes
Retry-After. No status-specific rate limiter is configured.

## Offline behavior to preserve

The shipped service worker only caches GET equipment/templates and built JS/CSS.
Data uses network-first with a 4-second abort then cache fallback; shell uses cached
copy while refreshing. Customers, preview, generation, update, send, send-status,
webhook, non-GET requests never enter that cache path. No mutation replay queue.
The phase 3 acceptance webhook and legacy Deluge payload route are NOT frontend
workflow endpoints and must not be called by the reconstructed app.

## Proposed isolated mock fixtures and contract matrix

Use only IDs like `200000000000000001` (CRM), `300000000000000001` (Books contact),
`400000000000000001` (item), `500000000000000001` (estimate),
`600000000000000001` (deal), and email `fixture@example.test`. Mock fetch at the
browser boundary and fail every unexpected URL; require zero real network calls.

| Case | Fixture / outcome | Assertions |
| --- | --- | --- |
| Boot/auth | encoded boot JSON; valid session | correct root/base URL, brand, cookie credentials, nonce header |
| Nonce expiry | native WP 403 rest_cookie_invalid_nonce then AJAX nonce | one refresh, same body and idem key, no infinite retry |
| Forbidden/login expired | native/custom 401/403 | no refresh loop, no mutation retry, visible actionable error |
| Proxy HTML / empty body | 502 HTML | useful status/message; no JSON parser crash or false success |
| Customer search | 1 character/empty, 2 characters, stale responses | no premature search; preserve string IDs and document the reference's out-of-order response race |
| Catalog | typed, untyped, missing numeric specs, empty and 401/429 | correct coil-query mapping; preserve reference errors and retry behavior (including ignoring Retry-After) |
| Automatic template | multiple types and none | match saved bundle's selection rule; error when none; no invented backend default |
| Preview | 200 HTML + books_preview + view | POST preview only; no generate/update/send; keep customer override preview-only |
| Sanitized text | 2000-character/HTML descriptions, warranty/fee flags | payload shape and explicit empty descriptions; text fields escaped |
| Pricing | catalog total; surcharge/discount; permit override | non-permit rates not payload-authoritative; totals/deposit consistent |
| New draft | 201 | show draft/no-email; retain IDs/key; no automatic send |
| Replay | 200 without number/duration | no crash; same draft; no second creation/new key |
| In-flight | 409 Retry-After: 5 | stable attempt key; explicit retry/replay; no fresh-key duplicate |
| Prior error | 409 prior_error | explicit new attempt only; no blind automatic retry |
| Edit draft | 200 saved; 409 changed lines/not draft; 403 owner | exact row order and count; text-only edits; cannot send unsaved edits silently |
| PDF preparation | valid small PDF, invalid prefix, >8MiB, Unicode HTML | raw base64 encoding; PDF fail blocks send; missing HTML allowed |
| Queue | 202 queued → 200 processing → 200 sent | correct status; poll only known job; disable send; stop terminal |
| Terminal error | 200 ok:true data.status:error | visible failure; stop polling; no auto resend |
| Replay sent | 200 sent without job_id | no polling undefined job; sent UI; no further send |
| Missing job | 404 | no automatic resend; advise checking estimate |
| Offline | cached catalog but failed sensitive/mutation fetch | catalog browsing works; no cached customers or queued writes |
| Owner mismatch | 403 update/send/status | no state loss or permission bypass |

Implementation safety decisions above are client recommendations where stated;
they do not imply PHP has additional unshown validation or stronger idempotency.
