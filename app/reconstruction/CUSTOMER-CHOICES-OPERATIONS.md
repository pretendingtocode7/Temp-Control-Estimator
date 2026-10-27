# Customer package choices

This flow updates an existing draft or sent Books estimate after the homeowner explicitly selects one offered option for each physical system. It does not create an estimate, send email, mark it sent, or accept it. Final Books review remains a separate step, and its link is withheld until both Books and CRM have been verified. The staff send job can distribute the scoped chooser link through the existing explicit send action.

The comparison PDF prepared or sent earlier is a fixed document. Customer selection does not regenerate that PDF or resend email; the verified Books estimate is the authoritative selected equipment and total after the update.

## Public chooser delivery

During deployment verification, the administrator can set `tc_estimate_customer_choices_deployment_hold` to true. Its default is false. While held, alternatives estimates cannot issue or reuse a link, and valid customer links cannot view or apply choices; these requests return a fixed unavailable 503 before provider activity. Invalid tokens still return 401. Legacy estimates without alternatives retain their existing send flow. The hold does not revoke tokens or alter their signature, expiry, revision, selections or pending state. Removing the option releases the hold and restores normal checks. Keep the hold in place until actual browser isolation is verified; this flag does not itself prove delivery isolation.

Only `tc_estimate_choices=1` is handled at `init` priority `-9999`, before later template/enqueue hooks. Other requests retain their normal WordPress lifecycle. The response does not render theme hooks, remove global hooks, or clear output buffers. GET and HEAD receive the same restrictive CSP, no-referrer and no-store headers; HEAD has no body. `no-transform` is an advisory cache directive, not proof that a host or edge service will omit injected content.

Issued and reused chooser links use the plugin REST namespace: `/wp-json/tc-estimate/v1/customer-choices/page?tc_estimate_choices=1&ver=RELEASE#token=...`, generated from WordPress `rest_url()`. Before customer lookup, provider calls or record creation, the service resolves this canonical address once and requires HTTPS on the site's exact origin, no credentials, query, fragment or control characters, and the requested route suffix. Configured subsite paths and custom REST prefixes are preserved; unsupported query-based REST routing fails closed with unavailable 503. The same validated address is used for new and reused links, including links distributed by the existing send job. The existing early query handler serves the active page; no additional REST business route is registered. Without the flag, or when this plugin is inactive, this otherwise missing REST route falls back to WordPress's JSON 404 rather than a tracking homepage. Verify that fallback on the actual host. The public `ver` release parameter avoids older cached responses. The scoped bearer remains exclusively in `#token=...`; the path and version do not rotate that bearer or change its binding, expiry or quoted prices. Delivery verification must use this exact versioned canonical URL issued to customers as well as checking the earlier homepage query cache state.

The only permitted script source is the exact same-origin HTTPS compiled estimator bundle path. Its script tag carries a SHA-384 integrity value computed from the installed local file. Each asset URL combines the public release version with the first 16 hexadecimal characters of its content hash: SHA-384 for JS and SHA-256 for CSS. Changed asset bytes therefore use a new URL even within the same release, avoiding older cached assets that would fail integrity verification. Missing, unreadable or unhashable JS/CSS files, or an invalid asset origin, fail closed with a generic 503 response and no customer boot data. Inline script handlers, frames and workers are blocked explicitly. The generic shell contains no customer information, bearer, staff nonce or service-worker registration.

Origin and edge delivery still require verification: inspect the actual anonymous response headers, browser CSP enforcement, loaded script integrity and request initiators without using a valid customer token. A response transform or prestarted output buffer can outlive an early WordPress hook. If an injected script remains, identify its vendor and use a supported per-response exclusion where needed; do not disable global hosting/security controls or assume that `no-transform` alone isolates this page.

## Provider and quote requirements

The successful owner-bound generate audit must contain the complete offered systems. The original CRM account must independently resolve to exactly one active Books contact; the current quote must have that contact. A unique exact-email Books lookup is used when the CRM customer adapter has no explicit Books contact ID. The related CRM Deal must retain the original account and `Books_Estimate_ID`, its original amount, and identifiable `Quoted_Equipment` rows with stable row IDs. Independent edits to owned financial or equipment fields require staff review. Description-only Books edits are preserved when the same physical choice remains selected.

The CRM subform contract always requires its row ID, `Slot`, `System_Number`, and `Zoho_Item_ID`. A private field contract records only known optional columns consistently exposed on every original owned row; unsupported columns are neither compared nor submitted. For the inspected site, only `Brand` and `Model` are exposed optional equipment columns. Legacy null or empty optional descriptors are recorded as unknown at issuance; nonempty descriptors must match the offered item. Once recorded, a known column appearing or disappearing, or a supported field baseline changing, requires staff review. Selected item identities and Deal amount are verified strictly regardless of optional columns. Books is authoritative for pricing and scope when CRM has no corresponding rate or description columns; this flow does not create fields or invent alternate names.

The initial supported financial context is USD, unit quantities of one, no nonzero tax, discount or shipping, and the original common project adjustment. Unsupported or changed financial contexts fail before writes. Selected equipment is revalidated against the catalog, but offered sales prices remain fixed. Actual zero-dollar maintenance and permit item IDs must still match. Public responses exclude internal equipment costs and provider record IDs.

Books updates replace the complete selected-only line array on the bound existing estimate with `send=false`. CRM updates use existing owned subform row IDs, append only genuinely new slots, remove only obsolete owned row IDs, and omit unrelated rows. CRM workflows are disabled with an empty `trigger` array. [Zoho documents the row-ID requirement and append behavior](https://www.zoho.com/crm/developer/docs/api/v8/update-subforms.html).

Every operation is persisted before provider mutation. An uncertain Books or CRM write is reconciled by GET; it is never blindly repeated. A Books success followed by CRM failure remains a visible partial state, keeps the original pending operation, and withholds the approval link. Operator attention may be required when a provider definitively rejects or never applies an attempted update. Do not clear pending state to make a new attempt.

## Interrupted-worker recovery

An estimate lock has no automatic age-based takeover. A PHP process paused for a long time could still resume a provider write, so elapsed time alone is insufficient evidence that a lock is safe to remove. A locked quote returns an update-in-progress response, including to staff revocation. Normally the request's `finally` block releases it.

If a worker crashed, a site administrator must first ask hosting support to verify that the original PHP request has ended, or have it terminated and confirmed stopped. Do not recover while the original worker may still run. During this verification, retain the exact private record, request ID, revision and selections. A fresh customer operation is not a recovery mechanism.

After that hosting verification, use the existing authenticated site's WP-CLI, from its correct WordPress directory. Set `TC_CHOICES_LOOKUP` to only the 32-character nonsecret lookup prefix from the staff chooser link (before the dot); do not paste the bearer signature. Set `TC_CHOICES_BACKUP` to a new absolute file outside the public web root in an administrator-only directory. The following example uses supported WordPress option APIs, creates a private backup without printing record contents, and removes only the exact quote lock. Review the target and hosting verification before running it; this document does not execute it.

```sh
TC_CHOICES_LOOKUP='<32 lowercase hex characters>' TC_CHOICES_BACKUP='/secure/non-web/choice-recovery.backup' wp eval '
$lookup = (string) getenv("TC_CHOICES_LOOKUP");
$backup = (string) getenv("TC_CHOICES_BACKUP");
if (!preg_match("/^[a-f0-9]{32}$/D", $lookup) || !$backup || $backup[0] !== "/") { WP_CLI::error("Invalid recovery target."); }
$key = "tc_estimate_choice_" . $lookup;
$lock_key = "tc_estimate_choice_lock_" . $lookup;
$record = get_option($key, null);
$lock = get_option($lock_key, null);
if (!is_array($record) || !is_array($lock)) { WP_CLI::error("Expected exact quote record and lock are missing."); }
umask(0077);
$file = fopen($backup, "x");
if (!$file) { WP_CLI::error("A new private recovery backup could not be created."); }
$data = maybe_serialize(array("record" => $record, "lock" => $lock));
if (fwrite($file, $data) !== strlen($data)) { fclose($file); WP_CLI::error("Incomplete recovery backup; lock retained."); }
fclose($file);
if (get_option($key, null) !== $record || get_option($lock_key, null) !== $lock) { WP_CLI::error("Quote changed; lock retained."); }
if (!delete_option($lock_key)) { WP_CLI::error("Lock could not be removed."); }
WP_CLI::success("Exact quote lock removed; original pending operation retained.");
'
```

The homeowner's explicit **Check update** repeats the original request ID, revision and selections and performs reconciliation. It must not manufacture a new request ID or clear the private pending record. If the original mutation never reached Books, or CRM cannot be reconciled, the quote stays pending for staff review. A staff member may instead disable the link after the verified lock removal. Revocation does not roll back a provider write.

## Verification scope

The focused PHP harness uses real service, proposal, renderer, generator and endpoint code with synthetic in-memory WordPress and Books/CRM adapters. It forbids raw HTTP, creation, email, provider delete and acceptance actions. Browser checks use local mocked providers. These checks do not verify production nonce handling, multiworker database behavior, CDN headers, actual CRM custom-field configuration or live Zoho tax behavior; those require an isolated WordPress environment with external writes safely controlled.
