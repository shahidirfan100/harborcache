# HarborCache 1.4.0 validation

Runtime commit: `f63bf33`. Release archive: `dist/harborcache-1.4.0.zip`, 602 files, SHA256 `9245c580d4f71fbc7a22aa0c971f780fb02bca48db930f87ab8b006cf24e7bd2`. The build verifier passed source and ZIP parity.

## What changed

1.4.0 is a request-path performance release with no cache key, value, configuration or interface change:

- `HarborCache_Advanced_Cache` memoizes one request-local cache id per blog, group and key. Runtime hits skip namespace, context and derived-key work. The map is capped at 4,096 entries and cleared on namespace rotation, layout change, global-group registration and non-persistent-group registration.
- The single-node, instrumentation and read-only routing decisions are precomputed in the constructor instead of being re-evaluated on every Redis call.
- Non-persistent groups now use the parent runtime key and never create registry or epoch metadata.
- The ignored-group fast path no longer repeats the parent group-type lookup.

The layout guard remains on the read path, so a runtime compression, serializer, split-alloptions or client change still bypasses stale request values.

## Linux matrix

Run [42053714551](https://github.com/shahidirfan100/harborcache/actions/runs/42053714551) passed all fourteen jobs: PHP 7.4, 8.2, 8.3, 8.4 and 8.5; Redis 6, 7 and 8; WordPress 6.6, 6.7, 6.8, 7.0, 7.1 and latest; Predis, PhpRedis and Credis; PHP/igbinary with none/LZF/LZ4/Zstandard; ACL, trusted TLS, rejected certificate and Unix socket; concurrent workers; Redis stop/start; WooCommerce; multisite; PHP-FPM HTTP smoke and restart; Relay default/adaptive; relocated ZIP; and package parity. Plugin Check 2.1.0 on the exact ZIP reported `Success: Checks complete. No errors found.` and the report gate reported no errors or warnings.

## Local checks (Windows, PHP 8.5.10)

| Suite | Result |
| --- | --- |
| `php tests/status-matrix.php` | 0 failures, including zero-command runtime hit, memoized key build, layout bypass and group rotation |
| `php tests/config-parity.php` | 0 failures |
| `php tests/capability.php` | 0 failures |
| `php tests/probe-topology.php` | 0 failures |
| `php tests/query-pruning.php` | 0 failures |
| `php tests/probe-parity.php` | 0 failures |
| `php tests/safety-regressions.php` | 21 passed |
| `php tests/review-regressions.php` | 9 passed |
| `php tests/dropin-writer.php` | 12 passed |
| `php tests/connection-check.php` | passed |
| `php tests/metrics-failure.php` | passed |
| `php tests/conflict-notice.php` | passed |
| `php -l includes/class-advanced-cache.php` | no syntax errors |
| `python tools/build-release.py --verify` | 602 files, source/ZIP parity passed |

## Limits

- The id map is a per-request cache with a 4,096-entry cap. A request with more unique keys resets it and continues correctly.
- This release changes no key format, so no migration or flush is required.
- There are no interface changes, so the 1.3.3 screenshots remain representative.
