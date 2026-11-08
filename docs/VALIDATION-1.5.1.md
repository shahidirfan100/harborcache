# HarborCache 1.5.1 validation

Source commit: `9af7d74` for the measured build; release commit adds version, package and documentation only. Release archive: `dist/harborcache-1.5.1.zip`, 602 files, SHA256 `5d87f4586b82d6e28251dd2d335e2e73698607d6078002911582ffe2bb88bb4e`. The build verifier passed source and ZIP parity. Configuration and key format are unchanged from 1.5.0.

## What changed

1.5.1 is a performance-maintenance release.

- The WordPress-version integrity check stays in `fast_build_key()`, where a key rebuild already resets memoized ids. It is no longer repeated on every request-memory hit.
- The 1.5.0 Relay, diagnostics, integrity and health behavior is unchanged.
- A same-run comparison with 1.4.1 puts CPU, bootstrap, runtime hits, forced reads, bulk reads, writes, misses and cold reads within +/-1.3%.

The audit record, including the multi-build method and the capability scorecard, remains in version control.

## Linux matrix

Run [42353449119](https://github.com/shahidirfan100/harborcache/actions/runs/42353449119) passed all fourteen jobs: PHP 7.4, 8.2, 8.3, 8.4 and 8.5; Redis 6, 7 and 8; WordPress 6.6, 6.7, 6.8, 7.0, 7.1 and latest; Predis, PhpRedis and Credis; PHP/igbinary with none/LZF/LZ4/Zstandard; ACL, trusted TLS, rejected certificate and Unix socket; concurrent workers; Redis stop/start; WooCommerce; multisite; PHP-FPM HTTP smoke and restart; Relay default/adaptive; relocated ZIP; and package parity. Plugin Check 2.1.0 on the exact ZIP reported `Success: Checks complete. No errors found.` and the report gate reported no errors or warnings.

Run [42352920446](https://github.com/shahidirfan100/harborcache/actions/runs/42352920446) is the multi-build audit run that measured 1.2.6, 1.4.1, 1.5.1 and the baseline build in one job.

## Local checks (Windows, PHP 8.5.10)

| Suite | Result |
| --- | --- |
| `php tests/status-matrix.php` | 0 failures |
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

- The performance figures are CLI, single-process p50 values. They are not HTTP p95 or sustained production load.
- The CPU ratio against the baseline build varies between +2% and +9% across runners. The stable result is the 22–23% runtime-hit lead.
- Cross-process Relay invalidation and live Relay/Cluster/Sentinel failover are not asserted.
- No third-party proprietary code, runtime or benchmark was used.
