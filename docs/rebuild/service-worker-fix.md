# Service-worker delivery fix: v0.4.10 candidate

The saved v0.4.8 frontend registers an unversioned worker from the plugin's
static asset directory with root scope. Its enqueue class attempts to set
`Service-Worker-Allowed` during WordPress initialization, but a host serving that
JavaScript file directly does not run WordPress. The browser can therefore
reject the requested scope. Static-file or intermediary caching can also keep
an older worker response. Changing PHP's plugin version alone does not change
the worker's internal cache version.

The deployment override is isolated in
`app/reconstruction/runtime/public/class-enqueue.php`. It serves the fixed
`app/dist/service-worker.js` file through the WordPress home URL with
`?tc_estimate_sw=1&ver=<plugin-version>`. It accepts that exact query flag only
on the home path, permits GET and HEAD, and never uses request input to select
a filesystem path. An older `ver` query still returns the current fixed file.
The response sets JavaScript content type, `nosniff`, no-store/no-cache headers
and `Service-Worker-Allowed` to the WordPress home path (`/` for a root site).
An unavailable worker returns 503; other methods return 405. The endpoint is
public because the worker is a public asset and contains no credentials.

The enqueue override adds the worker URL and current shortcode page's path as
attributes on this plugin's script tag. `src/main.jsx` uses those values and
registers with `updateViaCache: "none"`. The actual registration scope is the
shortcode page, such as `/estimate-builder/`; the allowed header expresses the
maximum scope and also supports WordPress installed under a home subdirectory.
If no suitable permalink is available, the registration scope falls back to
the home path. The legacy URL/root-scope fallback remains for an older enqueue
layer. Existing worker registrations are not explicitly unregistered by this
change; browser behavior with an existing registration requires verification.

The worker's fetch, offline and cache behavior is unchanged. Packaging changes
only its `VERSION` constant to `0.4.10`, which changes the existing cache suffix
and activates its existing old-cache cleanup. Customer and equipment selection,
pricing, preview, Books draft creation, description edits, explicit email/PDF
actions, nonce checks and ownership/capability checks retain their existing
behavior. The original installer and its 45 runtime reference files remain
byte-for-byte unchanged in the repository. The override is projected into a
release package separately; PR #4 remains unmerged.

## Reproducible offline package

After all intended changes are committed, run from the repository root:

```sh
python3 app/reconstruction/tools/prepare-release.py \
  --commit <complete-current-HEAD-SHA> \
  --output-root /Users/jessicalevin/Documents/Codex/2026-10-23/task-2/deployment-preparation/service-worker-v0.4.10
```

Choose a fresh output directory inside the workspace and outside the repository.
The tool requires the complete current HEAD and a clean tracked checkout. It
exports that commit, verifies the original archive SHA-256 and all 45 reference
files, checks the unchanged dependency lock and installed dependency versions,
then runs `npm run verify:reference`, `npm test` (124 passing tests required) and
`npm run build`. It uses the existing locked dependency installation in offline
mode; it does not install or download dependencies. The reconstruction package
version and lockfile are unchanged. Build and test output in the checkout stays
in ignored paths.

The installable ZIP has one `temp-control-estimate-builder/` root and exactly
47 files: the original 45-file runtime allowlist, replacing its three built
assets and enqueue class, plus the matching JavaScript source map and
`build-provenance.json`. The built JavaScript, CSS and map are copied intact.
The staged plugin header, `TC_ESTIMATE_VERSION`, readme stable tag and worker
cache version become `0.4.10`. No source, dependencies, tests, screenshots,
nested installer ZIP or private backup material is included beyond the matching
map deliberately retained with its tested JavaScript.

The tool records the source commit, original archive hash, dependency lock,
Node/npm versions, build asset hashes, override provenance and validation
results. The external `release-candidate-manifest.json` records every staged
file hash and the ZIP hash. ZIP entries are sorted with fixed commit timestamps
and permissions; repeatability requires the recorded build, Python and zlib
versions. The tool verifies CRCs, paths, file types, the exact allowlist and
read-back bytes, scans common credential patterns without printing values, and
runs JavaScript syntax checks. Native PHP syntax checks run when `php` is
available; otherwise that check is explicitly marked pending for separate PHP
validation. A credential-pattern scan does not prove the absence of every
possible secret.

## Validation and deployment boundary

The local frontend suite passes 124 tests, including the two added bootstrap
cases for the supplied worker URL/scope and the legacy fallback. The isolated
PHP delivery harness passes 50 assertions covering the response, method, path,
header, file and subdirectory contracts. The package tool reruns the frontend
checks after pinning the final source commit. Full staged PHP validation is
recorded separately by the release operator.

All eight local browser parity tests pass. A real local headless browser also
registers the worker at `/estimate-builder/` with `updateViaCache: "none"`,
leaves an outside page uncontrolled, and serves cached catalog/templates offline
while excluding customer and mutation requests. Live header/update checks and
existing-worker migration remain pending, as does live deployment verification.
The packager
performs no SSH, installation, live API calls, customer email, provider
transaction, live backup or rollback operation, and labels output
`prepared-not-installed`. Before installation, the operator must verify the
installed version and source divergence, any additional live vendor files,
settings/data backups and rollback availability. Do not overwrite newer or
divergent work. Record live checks separately rather than treating offline
package preparation as deployment evidence.
