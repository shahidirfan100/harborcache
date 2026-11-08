# HarborCache 1.3.3 validation

Runtime commit: `b2e0e27`. Test/CI commit: `a94307a`. Release archive: `dist/harborcache-1.3.3.zip`, 602 files, SHA256 `fcb51692b340f194d3fbca242a55c0106d1d73c307a47b7fa715bf60302dbe2f`. The build verifier passed source and ZIP parity. GitHub Actions run [42044872172](https://github.com/shahidirfan100/harborcache/actions/runs/42044872172) passed all fourteen jobs.

## Audit fixes in 1.3.3

1.3.3 closes the remaining audit findings:

- The connection check now probes Redis Cluster through a slot-0 node for keyless commands and uses the primary for Sentinel and replicated-server reads. `WP_REDIS_CLUSTER`, `WP_REDIS_SERVERS`, `WP_REDIS_SENTINEL`, `WP_REDIS_SCHEME` and `WP_REDIS_PATH` are read into the effective configuration, so the probe and the router see the topology that wp-config declares.
- A saved Unix-socket connection can switch back to TCP by typing a host. `Config::apply_host()` clears the stale unix path; a plain host does not downgrade an existing TLS connection.
- A blank password field keeps the value already in `wp-content/harborcache-config.php`. A password defined only in wp-config is no longer copied into the settings file.
- The copyable diagnostics report and Site Health debug info omit the endpoint and the probe's raw host/detail fields.
- The dashboard gate honors `WP_REDIS_MANAGER_CAPABILITY`, matching the menu capability.
- Group discovery and epoch metadata are capped at 1,000 entries. Writes use one idempotent `SADD` + `SCARD` + `EXPIRE` script; the epoch hash trims itself when it grows past the cap. The query-maintenance state has the same bound.

## Linux CI matrix

Run [42044872172](https://github.com/shahidirfan100/harborcache/actions/runs/42044872172) passed all fourteen jobs: PHP 7.4, 8.2, 8.3, 8.4 and 8.5; Redis 6, 7 and 8; WordPress 6.6, 6.7, 6.8, 7.0, 7.1 and latest; Predis, PhpRedis and Credis; PHP/igbinary with none/LZF/LZ4/Zstandard; ACL, trusted TLS, rejected certificate and Unix socket; concurrent workers; Redis stop/start; WooCommerce; multisite; PHP-FPM HTTP smoke and restart; Relay default/adaptive; relocated ZIP; and package parity. Plugin Check 2.1.0 on the exact ZIP reported `Success: Checks complete. No errors found.` and the report gate reported no errors or warnings.

The offline step now also runs `tests/config-parity.php`, `tests/capability.php` and `tests/probe-topology.php`. `probe-topology` uses a Predis client stand-in to assert that cluster keyless commands hit the selected node, cluster keyed commands stay on the cluster client, and replica/sentinel probes run every check on the primary.

## Interface capture

Run [42044669760](https://github.com/shahidirfan100/harborcache/actions/runs/42044669760) captured the five screens and the widget from the exact 1.3.3 ZIP on WordPress 7.1.4, PHP 8.3.36, PhpRedis 6.3.0 and Redis 7.4.12. The capture reported zero console errors, zero JavaScript errors and no PHP notices. The footer reads 1.3.3, and the expanded raw report no longer contains an `endpoint` key. The PNGs in `media/` and `media-capture/` and the record in `media/screenshots.json` are refreshed.

## Equal-workload comparison

Artifact `linux-performance-applications` from run [42044872172](https://github.com/shahidirfan100/harborcache/actions/runs/42044872172), 30 rotating blocks per engine, full-plugin and engine lanes, native PhpRedis, PHP 8.3.36, WordPress 7.1.4. Median paired changes against baseline build (free) and the 1.2.6 baseline:

| Measurement | Full plugin vs 1.2.5 baseline | Engine only vs 1.2.5 baseline | Full plugin vs 1.2.6 |
| --- | ---: | ---: | ---: |
| Operation PHP CPU | +10.5% | +10.2% | -5.7% |
| Bootstrap PHP CPU | +4.0% | +2.2% | -0.6% |
| Bootstrap wall | +4.7% | +2.8% | -1.3% |
| 1,000 request-memory reads, p50 | **-7.2%** | **-7.1%** | **-24.4%** |
| 20 forced Redis reads, p50 | +2.3% | +0.2% | -1.5% |
| 100-key bulk read, p50 | +9.4% | +9.4% | -3.9% |
| 20 writes, p50 | +6.3% | +9.7% | -3.7% |
| 20 misses, p50 | +6.9% | +5.1% | -1.3% |
| 16 KiB value read, p50 | +13.5% | +16.3% | -1.5% |
| Peak PHP allocation | 0% | +5.9% | 0% |

The same 1.3.3 code produced a second sample in run [42043716153](https://github.com/shahidirfan100/harborcache/actions/runs/42043716153): full-plugin CPU **+3.5%**, memory reads **-14.2%**, forced Redis reads **+1.8%**. The spread between those two shared-runner samples is larger than the difference between 1.3.2 and 1.3.3, so the reliable signals are the paired direction and the memory-read improvement, not a single CPU percentage. The 1.3.3 changes add one `SCARD` inside the existing per-group registration script and do not touch the read hot path.

## Local checks (Windows, PHP 8.5.10)

| Suite | Result |
| --- | --- |
| `php tests/status-matrix.php` | 0 failures, including first group write registration |
| `php tests/config-parity.php` | 0 failures, topology constants and socket/TLS switching |
| `php tests/capability.php` | 0 failures, menu/gate parity |
| `php tests/probe-topology.php` | 0 failures, cluster and primary routing |
| `php tests/query-pruning.php` | 0 failures, changed-only rotation |
| `php tests/probe-parity.php` | 0 failures |
| `php tests/safety-regressions.php` | 21 passed |
| `php tests/review-regressions.php` | 9 passed |
| `php tests/dropin-writer.php` | 12 passed |
| `php tests/connection-check.php` | passed |
| `php tests/metrics-failure.php` | passed |
| `php tests/conflict-notice.php` | passed |
| `php -l` over changed PHP files | no syntax errors |
| `python tools/build-release.py --verify` | 602 files, source/ZIP parity passed |

## Limits

- The CI matrix has no live Cluster or Sentinel failover stage. The probe's node selection is covered by `tests/probe-topology.php`; native failover and sustained production traffic remain staging gates.
- The 1,000-entry group cap evicts an arbitrary group from discovery and the epoch hash above the cap. Cached values remain TTL-bound and correct; the eviction path is not exercised by CI.
- The benchmark is a shared-runner CLI sample. It does not measure sustained HTTP throughput, and the CPU range across same-code runs is wide.
