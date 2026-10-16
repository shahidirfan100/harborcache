# HarborCache 1.2.1 validation

## Review-finding coverage

The reported raw nonce path was in an unused legacy AJAX handler. That handler and its unprefixed AJAX registrations are removed. The active administrative action checks capability and nonce before dispatch. The request URI used for the prefetch hash is now unslashed and sanitized before hashing. CSS selectors are scoped to HarborCache UI roots; chart assets are enqueued only for the HarborCache settings page or a visible dashboard widget. Drop-in recovery notices now render only on HarborCache settings screens.

## Checks completed

- PHP syntax checks passed for all 30 source PHP files and all 25 non-vendor PHP files in the installable ZIP with PHP 8.5.
- JavaScript syntax, acceptance-harness PHP syntax and `git diff --check` passed.
- On the local WordPress 7.1.3 fixture with `WP_DEBUG` enabled, WordPress's `activate_plugin()` completed through WP-CLI with zero unexpected output bytes. The test log contained no HarborCache PHP warning, notice or fatal error.
- A local admin-hook check confirmed the chart stylesheet/script load on HarborCache settings and when the dashboard widget is visible; they stay unloaded when the widget is hidden and on the Plugins screen.
- A forced recovery-state check produced no notice on the Dashboard and showed the recovery notice on HarborCache settings.
- WordPress Plugin Check 2.1.0 reported `Success: Checks complete. No errors found.` for the final installable ZIP and exact slug `harborcache-object-cache-with-redis`. The package contains no hidden repository metadata.
- The acceptance workflow contains the Linux Redis, PHP, client, topology and lifecycle matrix. A fresh GitHub Actions run for these source changes is the remaining automated runtime gate.

The local WP-CLI 2.12.0 executable emitted PHP 8.5 deprecation messages from its bundled CLI color helper during bootstrap. These were not activation output from HarborCache. Separately loading the active drop-in without a Redis daemon produced HarborCache's expected backend-unavailable diagnostic; the local fixture has no Redis service, so it cannot certify an actual Redis connection or outage recovery.

## Validation boundary

The official WordPress.org Readme Validator form did not return a verifiable result during this run. Treat its result as pending; do not describe the readme as officially validated. A clean Plugin Check report and local activation test are useful evidence, but neither predicts the reviewer decision. The local fixture had no Redis server, so live Redis service recovery and the Linux Redis matrix must be verified by the CI run. Review and approval remain the WordPress.org Plugins Team's decision.
