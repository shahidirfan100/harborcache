# HarborCache 1.2.2 validation

## Review-finding coverage

This release includes the 1.2.1 fixes for sanitized request inputs, removal of unused unprefixed AJAX handlers, dashboard asset/CSS scope and limited admin notices. It also separates HarborCache's own file, plugin path and version constants from Redis Cache's identity globals while continuing to read its established `WP_REDIS_*` connection settings.

## Checks completed

- PHP 8.5 syntax checks passed for 32 non-vendor PHP files; both admin JavaScript files passed Node syntax checks.
- The focused conflict-notice regression passed for the Dashboard, unauthorized users and plugin-management screens.
- WordPress Plugin Check 2.1.0 ran against the final extracted 1.2.2 install ZIP with the exact directory slug `harborcache-object-cache-with-redis`: exit code 0, zero warnings, zero errors.
- On the local WordPress 7.1.3/PHP 8.5 fixture with `WP_DEBUG` enabled, HarborCache activated cleanly both without a drop-in and with its 1.2.2 drop-in. Runtime checks confirmed HarborCache-prefixed identity constants, no Redis Cache file/path identity constants, and continued Redis connection configuration support. The original local fixture plugin and drop-in were restored after the test.
- The install ZIP contains 630 files under the expected plugin directory, with no tests, `.git` data or CI files. The current archive checksum is recorded in `dist/SHA256SUMS` in the source repository.
- The 1.2.2 GitHub Actions Linux acceptance run will be added after it completes.

## Validation boundary

The WP-CLI 2.12.0 executable emitted PHP 8.5 deprecations from bundled React and CLI-color helper files during the local fixture run; no HarborCache warning, notice or fatal was reported. No Redis service is available in this local Windows fixture, so it does not certify a live connection. The official WordPress.org Readme Validator and live autoscareer.com installation have not been verified. GitHub Actions supplies the disposable Redis/MySQL environment. Plugin Check and CI results support submission but cannot guarantee the WordPress.org review team's decision.
