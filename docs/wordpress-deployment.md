# GitHub to WordPress deployment

The workflow checks every pull request. Once enabled, a push or merge to `main` automatically deploys this plugin to the selected WP Engine environment after the checks pass. It also supports a manual run of `main`.

## Current state

The repository currently contains plugin version 0.2.38. The latest supplied plugin is 0.4.8. Import and review the newer source before enabling deployment. The workflow explicitly blocks deploying versions below 0.4.8. This is a migration guard, not a comparison against the version currently installed on the server.

The AC / Furnace / Full Package selection changes and the Zoho Books catalog changes are still pending. This deployment change does not implement them.

## One-time connection

1. Import the current plugin source into the repository root, retaining the deployment files added here. Include the built files in `app/dist/`. This workflow packages those checked-in production assets; it does not rebuild the React app. Rebuild them whenever frontend source changes.
2. Confirm the existing WordPress plugin directory is `wp-content/plugins/temp-control-estimate-builder/`. Deployment synchronizes exactly that directory. Files removed from the package are removed from that plugin directory.
3. Create the GitHub Actions environment `wordpress-production`. Add environment variable `WPE_ENV` with the WP Engine **environment name**, not the website domain. Use the environment for the correct Temp Control installation.
4. Add a dedicated deployment SSH public key to WP Engine SSH Gateway. Store its private key in the GitHub environment secret `WPE_SSHG_KEY_PRIVATE`. Never commit keys, Zoho credentials, spreadsheets, or customer data.
5. Keep repository Actions variable `ESTIMATOR_DEPLOY_ENABLED` unset while reviewing the imported source and the first successful checks. Then set it to `true` and run the workflow on `main`.
6. Verify the plugin version and a representative estimate on WordPress. Subsequent merges to `main` deploy automatically. If the GitHub environment requires a reviewer, that environment rule will pause deployment.

Checks include PHP 8.1 syntax, the existing estimate-rendering smoke tests, and packaging tests. Only explicit runtime directories and file types enter the installable ZIP. Dependencies needed at runtime must be present in `vendor/` or provided by the plugin's existing fallback. The ZIP has the stable WordPress plugin folder name.

GitHub records each deployment and retains its tested ZIP for 30 days. Deployment credentials are supplied only to the deployment job, never pull-request checks. Failed checks prevent deployment. Set `ESTIMATOR_DEPLOY_ENABLED` to `false` to pause deployments.

## Recovery

Restore a known-good commit through a reviewed revert on `main`, then rerun the checks and deployment. Alternatively restore the host's known-good plugin backup. The workflow synchronizes files; it does not roll back database changes or automatically recover a failed runtime health check. It does not activate an inactive plugin.

## References

- [WP Engine deployment documentation](https://wpengine.com/support/github-action-deploy/)
- [WP Engine deployment action](https://github.com/wpengine/github-action-wpe-site-deploy)
