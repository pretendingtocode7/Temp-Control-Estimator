=== Temp Control Estimate Builder ===
Contributors: sevendegrees
Tags: hvac, estimates, zoho, crm, field-service
Requires at least: 6.4
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.3.0
License: Proprietary

Mobile-first estimate builder for Temp Control HVAC field techs. Generates Books estimates + CRM Deals from Zoho catalog data.

== Description ==

A WordPress plugin that gives Temp Control field techs a mobile-first estimate builder. Techs pick a customer, pick a proposal template, and select eligible Zoho Books Items. The builder creates a draft Books estimate plus a CRM Deal, then lets the technician preview the actual estimate before explicitly emailing it to the customer.

**Phase 1: office-facing foundation (shipped in 0.1.0)**
* Plugin scaffold + Mustache-based template rendering
* Zoho OAuth2 bridge (encrypted refresh token, access-token caching, retry-with-backoff, circuit breaker)
* estimate_template custom post type with meta configuration
* REST endpoints: /customers, /equipment, /templates, /preview
* Admin pages: Settings, Templates (CPT), Audit Log
* Three seed templates (Full Replacement, AC Only Replacement, Preventive Maintenance Report)
* PHPUnit tests on the token renderer and Zoho bridge

**Phase 2: field-tech builder (this release, 0.2.0)**
* React PWA that drops into any page via [tc_estimate_builder]
* Five-step mobile wizard: Customer → Template → Equipment → Pricing → Review → Draft/Send
* /generate endpoint with Idempotency-Key header, audit-log write-before / update-after
* tc_generate_estimate Deluge function — atomic Books Estimate + CRM Deal creation, voids the estimate if the Deal insert fails
* Service worker for offline catalog browsing (network-first with 4s timeout, cache fallback; customer search and mutations stay online-only for privacy and correctness)
* Books Items only: active Items must have cf_for_estimate checked to appear or generate
* Editable customer-facing description for every selected Item
* Draft-first workflow with proposal preview, actual Books estimate preview, and explicit Send action
* Zoho Deal links surfaced in the admin audit log

**Phase 3: lifecycle automation (planned)**
* /webhook/accepted for Books estimate acceptance
* Quoted_Equipment → Equipment_To_Install → Installed_Equipment flow

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/
2. Run `composer install --no-dev` inside the plugin directory
3. In app/, run `npm install && npm run build` to produce the React bundle
4. Add an encryption key to wp-config.php:
   `define( 'TC_ESTIMATE_ENC_KEY', '<64-char hex>' );`
5. Activate through the WordPress Plugins menu
6. Go to Estimate Builder → Settings and enter Zoho Client ID, Client Secret, Refresh Token, Org ID, DC
7. Click "Test Zoho Connection" to verify OAuth
8. Create the Deluge function tc_generate_estimate in Zoho CRM (see deluge/README.md for install steps and required custom fields)
9. Add [tc_estimate_builder] to a page and assign the "Technician" role (or an Administrator) to the techs who should use it

== Frequently Asked Questions ==

= What Zoho scopes does the refresh token need? =

ZohoCRM.modules.ALL, ZohoCRM.functions.execute.CREATE, and ZohoBooks.fullaccess.all. The Deluge function additionally requires a CRM connection named `zbooks` with `ZohoBooks.estimates.CREATE` and `ZohoBooks.estimates.UPDATE`.

= Where is the refresh token stored? =

Encrypted with libsodium (sodium_crypto_secretbox) in the wp_options table, under option name tc_estimate_zoho_refresh_token_enc. The encryption key comes from the TC_ESTIMATE_ENC_KEY constant in wp-config.php.

= How do I import the seed templates? =

The zip ships with three Mustache templates in seed-templates/. Create three estimate_template posts in WP Admin and paste the body contents — or use WP-CLI: `wp post create --post_type=estimate_template --post_status=publish --post_title="..." --post_content="$(cat seed-templates/full-replacement.mustache)"`.

= What happens if the CRM Deal creation fails after the Books estimate is created? =

The Deluge function rolls back by calling Books `/estimates/{id}/status/void` on the orphaned estimate. The API response carries both the original error and the rollback outcome, and the audit log captures the full state. The idempotency key is preserved so the tech can retry with a fresh key.

= What works offline? =

The React shell, previously-viewed equipment lists, and template lists. Customer search, preview, and generate all require a connection — those are the calls that need fresh Zoho state or can't be safely replayed from a cache.

== Changelog ==

= 0.3.0 =
* Changed: Zoho Books Items are now the only estimate catalog source; active Items must have cf_for_estimate checked.
* Changed: Selected Books Items are used as the estimate line items instead of one aggregate placeholder Item.
* Added: Technicians can edit each selected Item description without changing the source Item in Books.
* Added: Estimate creation no longer emails automatically. The review screen previews the proposal, and the completion screen opens the actual Books estimate before an explicit Send action.
* Added: Retry-safe /send-estimate endpoint restricted to estimates generated by the current user.

= 0.2.38 =
* Improved: Cleans up the Books estimate layout by keeping the main estimate line concise and making the Annexure a compact proposal detail page.
* Improved: Reduces duplicated proposal, warranty, terms, and equipment text across the estimate, notes, terms, and Annexure.

= 0.2.37 =
* Fixed: Corrects a PHP syntax error in the experimental Books Annexure content block.

= 0.2.36 =
* Experimental: Generates rich-text proposal Annexure content for Zoho Books and tries to attach it to the estimate before the official Books email is sent.
* Improved: Keeps the main Books line item shorter so the first estimate page can stay cleaner while detailed proposal content moves to Annexure when supported.

= 0.2.35 =
* Improved: Books estimate descriptions now follow Temp Control's current proposal samples with scope, equipment, warranty, maintenance, permit, investment, payment incentive, and thank-you sections.
* Improved: Equipment rows now pass available CRM specs such as SEER, AFUE, BTU, tons, refrigerant, and descriptions into the Books proposal text.

= 0.2.34 =
* Improved: Books estimates now receive a cleaner customer-facing package description, notes, and terms for a better emailed PDF/sign-off document.

= 0.2.33 =
* Added: After Books estimate and CRM Deal creation succeed, Books sends the official customer estimate email for review and sign-off.
* Improved: Books estimate line descriptions and email body now include a cleaner customer-facing equipment summary.
* Improved: Success screen now shows whether the official Books email was sent.

= 0.2.32 =
* Improved: Deluge CRM failures now return the full CRM response to the builder so the next error shows the actual bad field, stage, or validation issue instead of only HTTP 502.

= 0.2.31 =
* Fix: Resolves the selected CRM account to a Zoho Books customer by matching email before creating the estimate, because CRM Account IDs and Books Contact IDs are different.
* Improved: If the CRM Account has no email, generation checks related CRM Contacts for the first valid email to use for the Books customer match.

= 0.2.30 =
* Fix: Sends the short WordPress payload URL using the Zoho argument name `payload`, matching the one-argument function signature Zoho accepts.

= 0.2.29 =
* Fix: Avoids Zoho function URL-length limits by storing the full payload in a short-lived WordPress transient and passing Deluge only a short `payload_url` argument.

= 0.2.28 =
* Fix: Sends Zoho function `arguments` in the execute URL query string so CRM standalone functions receive the `payload` argument instead of an empty string.

= 0.2.27 =
* Fix: Restored single raw `payload` function argument to match Zoho's accepted one-argument function signature.

= 0.2.26 =
* Fix: Function execution sends both `payload_b64` and raw `payload`, and the Deluge paste file accepts both arguments. This avoids failures when Zoho drops or cannot decode one transport format.

= 0.2.25 =
* Fix: Function execution now sends the Deluge payload as Base64 (`payload_b64`) so Zoho cannot corrupt raw JSON argument strings before Deluge parses them.

= 0.2.24 =
* Improved: Zoho/Deluge 502 failures now surface raw execute-response details in the REST error so operators can see the real Deluge or Zoho reason instead of only "Request failed with status 502".

= 0.2.23 =
* Fix: Deluge no longer sends Books estimate custom fields using `api_name`, which Zoho Books rejects on estimate creation with `invalid data (api_name=api_name)`. Source IDs remain stored on the CRM Deal and plugin audit log.

= 0.2.22 =
* Fix: Builder pages now send no-cache headers and the React app can refresh an expired WordPress REST nonce through authenticated AJAX before retrying the failed request. This avoids "Cookie check failed" after cached pages or long-lived tabs.

= 0.2.21 =
* Fix: CRM function execution now sends `arguments` as a form field with `auth_type=oauth`, matching Zoho serverless function requirements and avoiding the generic HTTP 400 method/parameter failure.
* Change: Removed the bundled seed catalog data. Equipment picker data now comes only from the configured CRM equipment module or the browser's existing offline cache until cleared.

= 0.2.20 =
* Fix: CRM records with placeholder picklist values such as `Option 1` no longer get filtered out of the picker. Unknown equipment types are treated as untyped and shown in the picker being opened.

= 0.2.19 =
* Improved: Equipment picker now shows CRM source details such as equipment type, JS part number, and MFG part number. Pricing screen now allows explicit subtotal overrides and includes a "Use subtotal" action for total project cost.

= 0.2.18 =
* Fix: CRM equipment search now shows records with a blank equipment type in the selected picker instead of filtering them all out. Added aliases such as `gas_furnace` → `furnace` and `coil` → `evaporator_coil`.

= 0.2.17 =
* Fix: CRM equipment module requests now include Zoho's required `fields` query parameter for the `Estimate_Builder_Parts` custom module.

= 0.2.16 =
* Improved: Zoho 4xx errors now include Zoho's returned message/details. Added a settings diagnostic button to test the configured CRM equipment module directly.

= 0.2.15 =
* Fix: `Purchase_Cost` is treated as internal COGS only and is never used as the customer-facing rate. Pricing uses `Sales_Price` or another explicit sales/rate field.

= 0.2.14 =
* Fix: CRM equipment mapper now supports the actual `Estimate_Builder_Parts` fields: `Purchase_Cost`, `Markup`, `Sales_Price`, `JS_Part_Number`, `Mfg_Part_Number`, and `Width`.

= 0.2.13 =
* Change: Equipment catalog now reads individual equipment/parts from CRM instead of Zoho Inventory/Books Items. Books receives one aggregate line item configured in settings; selected equipment details go to the CRM Deal subform. Generic `part` is now a selectable equipment type.

= 0.2.12 =
* Fix: Templates created from the inline settings form now save the same metadata keys the builder API reads. Published templates with missing active metadata are treated as active, so existing templates created in the settings page appear in the builder.

= 0.2.11 =
* Fix: REST endpoints for customer search, equipment search, templates, preview, and estimate generation now use the same builder access rule as the shortcode: `manage_tc_estimates` or `manage_options`.

= 0.2.10 =
* Fix: The frontend builder now accepts either `manage_tc_estimates` or full WordPress `manage_options`, matching the settings page behavior for custom admin/operator roles.

= 0.2.9 =
* Improved: Settings page now sends no-cache headers, shows explicit saved/missing status for Zoho credentials, and confirms which fields are stored after save. Secret fields remain blank by design after saving.

= 0.2.8 =
* Added: Plugins screen "Settings" link and a locked-down `[tc_estimate_settings]` shortcode fallback for operators with `manage_tc_estimates`, so Zoho keys can be entered even when wp-admin menu capability caching hides the settings screen.

= 0.2.7 =
* Fix: Estimate Builder admin menu, settings actions, audit log, and template editor now accept the plugin capability `manage_tc_estimates` as well as full WordPress `manage_options`. This supports custom operator roles that can manage estimates but cannot access all WordPress settings.

= 0.2.6 =
* Added: Inline Templates manager directly inside Settings → Templates section. Operators can list, create, edit, and trash templates without using the WP CPT editor. Sidesteps WP Engine cache quirks where the estimate_template CPT capability map wasn't getting picked up consistently. The CPT still exists and the wizard still consumes templates the same way; this just gives a guaranteed-working admin path that uses Settings's manage_options gate.

= 0.2.5 =
* Fix: Plugin no longer fatals on activation when Composer hasn't been run on the host. Bundled Mustache_Engine fallback (`includes/class-mustache-shim.php`) loads only when `vendor/autoload.php` is absent or doesn't provide the class. Operators who do run `composer install --no-dev` get the canonical bobthecow/mustache.php library, which takes precedence due to the `class_exists` guard.
* Removed runtime dependency on Guzzle and ramsey/uuid — neither was actually imported, only declared in composer.json. The plugin uses `wp_remote_*` and `wp_generate_uuid4()` natively. composer.json updated to reflect.

= 0.2.4 =
* Fix: Templates CPT now uses `manage_options` for its capability map instead of the custom `manage_tc_estimates`. Any administrator can edit templates without needing the custom cap correctly installed. Templates are admin-only content — technicians consume them through the builder wizard, not the template editor — so the stricter cap was misplaced.
* Templates submenu visibility now also gated on `manage_options` to match.

= 0.2.3 =
* Fix: Reinstall Roles and Capabilities button now grants the custom cap directly to the user who triggers it, not just the Administrator role. Handles the case where a user has manage_options but isn't literally on the `administrator` role object (custom roles, super admins, stale object cache).
* Added: wp_cache_flush() after reinstall so WP Engine's Memcached picks up the new cap on the next request.

= 0.2.2 =
* Fix: Top-level admin menu now requires `manage_options` instead of the custom `manage_tc_estimates` capability. Resolves "Sorry, you are not allowed to access this page" when admins had the plugin installed but the custom cap had been stripped (e.g. a previous delete cycle ran uninstall.php, which removes the cap).
* Added: Self-heal migration runner on `admin_init` — re-installs roles and caps if the stored plugin version is behind the current one. Runs once per version bump, gated on an option read.
* Added: Settings → Maintenance → "Reinstall Roles and Capabilities" button for manual recovery.

= 0.2.1 =
* Fix: register_post_type() moved to the `init` hook (priority 5) instead of running directly on `plugins_loaded`. WP Engine's stack doesn't populate $wp_rewrite in time for plugins_loaded, which caused a fatal "Call to a member function add_rewrite_tag() on null" on the first admin page load after activation.

= 0.2.0 =
* Phase 2: React PWA, /generate endpoint with idempotency, Deluge function with rollback, service worker for offline catalog.
* Added retry-safe generation: identical Idempotency-Key replays the cached result instead of creating duplicates.
* Added Service-Worker-Allowed header emission for root-scope SW registration.
* Audit log now links directly to the created Books estimate and CRM Deal.
* Extended smoke tests to cover Estimate_Generator payload shaping (50 assertions total).

= 0.1.0 =
* Phase 1 release: plugin foundation, Zoho bridge, preview endpoint, admin settings.
