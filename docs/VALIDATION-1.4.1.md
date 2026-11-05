# HarborCache 1.4.1 validation

Runtime commit: `cedbfcd`. Release archive: `dist/harborcache-1.4.1.zip`, 602 files, SHA256 `233e51764cd5882f0b90c705ae62b06ebb83daf3dfde90d7ac49e2ff5ac5c4e5`. The build verifier passed source and ZIP parity.

## What changed

1.4.1 hardens the existing prefetch feature. The cache key format, value format and configuration are unchanged.

- Prefetch manifests are written only for GET and HEAD requests. WP-CLI, REST and XML-RPC requests read and write no manifest.
- The manifest cap rises from 250 to 1,000 keys, lives for 24 hours, skips groups with one key, and omits `alloptions` when `split_alloptions` is on.
- Replay detects `__PHP_Incomplete_Class` at the top level and inside arrays, removes the value from request memory, and excludes it from the next manifest.
- The prefetch id includes the request method and host in addition to the blog, user and URI.
- The request method is unslashed and sanitized before use, which clears the Plugin Check warnings from the first candidate build.

## Linux matrix

Run [42058618816](https://github.com/shahidirfan100/harborcache/actions/runs/42058618816) passed all fourteen jobs: PHP 7.4, 8.2, 8.3, 8.4 and 8.5; Redis 6, 7 and 8; WordPress 6.6, 6.7, 6.8, 7.0, 7.1 and latest; Predis, PhpRedis and Credis; PHP/igbinary with none/LZF/LZ4/Zstandard; ACL, trusted TLS, rejected certificate and Unix socket; concurrent workers; Redis stop/start; WooCommerce; multisite; PHP-FPM HTTP smoke and restart; Relay default/adaptive; relocated ZIP; and package parity. Plugin Check 2.1.0 on the exact ZIP reported `Success: Checks complete. No errors found.` and the report gate reported no errors or warnings.

The live acceptance check `Prefetch replay fills request memory` passed in every WordPress job: two keys were tracked, the manifest was stored, request memory was flushed, replay issued the manifest read and one bulk read, and the following `get` returned the value without another Redis command.

## Local checks (Windows, PHP 8.5.10)

| Suite | Result |
| --- | --- |
| `php tests/status-matrix.php` | 0 failures, including prefetch GET/POST guard, incomplete-class detection, nested detection and single-key skip |
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

- A replay miss still falls back to normal Redis reads.
- A manifest can outlive a shorter `maxttl`; the affected reads simply miss.
- Incomplete-class detection walks arrays, not object graphs, so a deeply nested object graph is reduced to its top-level class check.
- No interface changes; the 1.3.3 screenshots remain representative.
