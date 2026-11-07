# HarborCache 1.5.0 validation

Source commit: `8e53f64`. Release archive: `dist/harborcache-1.5.0.zip`, 602 files, SHA256 `a165b2d38bd21c8cf18ad1964c62d5749a2683b7f490a444b99af338b6af1329`. The build verifier passed source and ZIP parity. Configuration is unchanged.

## What changed

1.5.0 implements the Relay hardening and integrity work.

- `onInvalidated` now evicts only the reported key from request memory. A namespace token or layout marker still resets memoized ids and the request runtime. `onFlushed` still flushes the full runtime.
- Relay callbacks accept both a `Relay\Event` object and a key array.
- `relay_diagnostics()` reports `stats()` and `maxMemory()` for Relay and Relay Cluster without requiring the adaptive-cache option. Adaptive detail is added when present.
- The WordPress version is part of the derived-key layout and is compared at runtime, so a core upgrade rotates new keys. Old values remain TTL-bound and expire; there is no database wipe.
- Site Health reports Redis `noeviction` as critical when a memory limit is set, and reports Relay memory pressure when Relay exposes `used`/`max`.
- Relay diagnostics are checked from Site Health without failing the check when the extension does not expose them.

## Linux matrix

Run [42349828397](https://github.com/shahidirfan100/harborcache/actions/runs/42349828397) passed all fourteen jobs: PHP 7.4, 8.2, 8.3, 8.4 and 8.5; Redis 6, 7 and 8; WordPress 6.6, 6.7, 6.8, 7.0, 7.1 and latest; Predis, PhpRedis and Credis; PHP/igbinary with none/LZF/LZ4/Zstandard; ACL, trusted TLS, rejected certificate and Unix socket; concurrent workers; Redis stop/start; WooCommerce; multisite; PHP-FPM HTTP smoke and restart; Relay default/adaptive; relocated ZIP; and package parity. Plugin Check 2.1.0 on the exact ZIP reported `Success: Checks complete. No errors found.` and the report gate reported no errors or warnings.

Relay jobs passed:

- `Relay invalidation keeps unrelated runtime values`: after a Relay invalidation event, the untouched key still returns from request memory with no Redis command.
- `Relay invalidated key stays readable`: the changed key is still correct after eviction.
- `Relay diagnostics report memory and stats`: Relay and Relay Cluster expose statistics and memory.
- `Relay adaptive diagnostics report detail`: adaptive mode still adds status, width, depth, load factor and per-key statistics.
- The existing forced-read and cache-mode restoration checks continued to pass.

## Local checks (Windows, PHP 8.5.10)

| Suite | Result |
| --- | --- |
| `php tests/status-matrix.php` | 0 failures, including core-version key change, single-key Relay eviction, event-object handling, unrelated-key preservation, namespace reset and diagnostics |
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
| `python tools/build-release.py --verify` | 602 files, source/ZIP parity passed |

## Limits

- The live Relay assertion covers the invalidation event the connection actually delivers in CI. Cross-process Relay invalidation depends on the Relay endpoint's tracking mode; the CI connection reported `tracking=0` even with client invalidations enabled, so cross-process delivery is not asserted here.
- Live Relay/Cluster/Sentinel failover remains an external verification gate.
- The derived-key signature changes once because the WordPress version is now included. Old values are not deleted; they expire by TTL.
- No Object Cache Pro code, runtime, or benchmark was used.
