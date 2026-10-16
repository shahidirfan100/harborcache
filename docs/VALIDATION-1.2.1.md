# HarborCache 1.2.1 validation

## Review-finding coverage

The reported raw nonce path was in an unused legacy AJAX handler. That handler and its unprefixed AJAX registrations are removed. The active administrative action checks capability and nonce before dispatch. The request URI used for the prefetch hash is now unslashed and sanitized before hashing. CSS selectors are scoped to HarborCache UI roots; chart assets are enqueued only for the HarborCache settings page or a visible dashboard widget. Drop-in recovery notices now render only on HarborCache settings screens. The notice for a competing Redis cache manager is restricted to plugin-management screens and users with plugin-management capability; a focused regression test covers both scope and capability.

## Checks completed

- PHP syntax checks passed for all 30 source PHP files and all 25 non-vendor PHP files in the installable ZIP with PHP 8.5.
- JavaScript syntax, acceptance-harness PHP syntax and `git diff --check` passed.
- On the local WordPress 7.1.3 fixture with `WP_DEBUG` enabled, WordPress's `activate_plugin()` completed through WP-CLI with zero unexpected output bytes. The test log contained no HarborCache PHP warning, notice or fatal error.
- A local admin-hook check confirmed the chart stylesheet/script load on HarborCache settings and when the dashboard widget is visible; they stay unloaded when the widget is hidden and on the Plugins screen.
- A forced recovery-state check produced no notice on the Dashboard and showed the recovery notice on HarborCache settings.
- WordPress Plugin Check 2.1.0 previously reported `Success: Checks complete. No errors found.` for the installable ZIP and exact slug `harborcache-object-cache-with-redis`. The rebuilt ZIP must be checked again after packaging the final 1.2.1 source and docs. The package contains no hidden repository metadata.
- The [final GitHub Actions Linux acceptance run](https://github.com/shahidirfan100/harborcache/actions/runs/38858605285) passed every job for commit `bceea94c5138f5bc7dd7a3c9e169d9137e818d02`. It covered PHP 7.4 and 8.2–8.5, WordPress 6.6 and current, Redis 6/7/8, Predis/PhpRedis/Credis/Relay, serialization/compression, ACL, trusted and rejected TLS, Unix sockets, restart recovery, concurrency, multisite, WooCommerce, PHP-FPM recovery, and Relay option updates.
- An earlier PHP 7.4/current-WordPress TLS probe reached its 90-second test timeout. The failure did not reproduce in the next run; the final run added an independent PhpRedis TLS check and explicit WordPress-bootstrap checkpoints, then passed the complete matrix.

The local WP-CLI 2.12.0 executable emitted PHP 8.5 deprecation messages from its bundled CLI color helper during bootstrap. These were not activation output from HarborCache. Separately loading the active drop-in without a Redis daemon produced HarborCache's expected backend-unavailable diagnostic; the local fixture has no Redis service, so it cannot certify an actual Redis connection or outage recovery. The final GitHub Actions matrix supplies the real Redis transport and recovery evidence.

## Validation boundary

The official WordPress.org Readme Validator form did not return a verifiable result during this run. Treat its result as pending; do not describe the readme as officially validated. A clean Plugin Check report and local activation test are useful evidence, but neither predicts the reviewer decision. Review and approval remain the WordPress.org Plugins Team's decision.
