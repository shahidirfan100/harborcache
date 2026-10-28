# HarborCache 1.2.8 validation

Validated runtime commit: `0c4b34b`. Release archive: `dist/harborcache-1.2.8.zip`, 600 files. The build verifier passed source and ZIP parity.

## Correctness and lifecycle

- GitHub Actions run [40675868347](https://github.com/shahidirfan100/harborcache/actions/runs/40675868347) passed all nine jobs: eight PHP/Redis/WordPress combinations, the relocated-ZIP review and the integrations/benchmark job.
- Matrix: PHP 7.4, 8.2, 8.3, 8.4 and 8.5; Redis 6, 7 and 8; WordPress 6.6 and latest.
- Plugin Check reported no errors and no warnings for the exact release ZIP.
- The new `tests/connection-check.php` passed on every matrix job: refused endpoint, missing client, live PhpRedis connection, wrong password, out-of-range database, probe-key cleanup and the status model.
- The new `tests/console-render.php` passed on every matrix job: no em dash, the registered product name, five tabs, the live status region, the saved-connection test, collapsed advanced settings, the raw report summary and no dead notice container.
- The existing safety, recovery, ACL, TLS, Unix socket, outage, codec, WordPress, WooCommerce, multisite and PHP-FPM checks passed unchanged.
- `tests/dropin-writer.php` (12), `tests/review-regressions.php` (9), `tests/safety-regressions.php` (21), `tests/metrics-failure.php` and `tests/conflict-notice.php` passed locally on PHP 8.3.36.

## Measured performance

One acceptance run, 30 rotating blocks per engine, full-plugin lane, native PhpRedis, PHP 8.3.36, WordPress 7.1.3. Values are within-run median paired changes.

| Measurement | 1.2.8 versus Redis Object Cache 3.0.0 | 1.2.8 versus 1.2.6 |
|---|---:|---:|
| Operation PHP CPU | +10.7% | -5.6% |
| 1,000 request-memory reads, p50 | -15.7% | -28.8% |
| 20 forced Redis reads, p50 | +1.2% | -3.2% |
| 100-key bulk read, p50 | +8.0% | -5.3% |
| 20 writes, p50 | +6.6% | -0.4% |
| 20 misses, p50 | +6.0% | -1.5% |
| 20 cold reads, p50 | +9.8% | -1.7% |
| 16 KiB string round trip, p50 | +8.3% | +0.4% |
| Bootstrap PHP CPU | +5.1% | -0.2% |
| Redis commands | +0.1% | 0% |
| Peak PHP allocation | 0% | 0% |

The 5% CPU target against the free plugin was not met. The 1.2.7 improvement over 1.2.6 remains. A string fast path was implemented in run [40677015431](https://github.com/shahidirfan100/harborcache/actions/runs/40677015431), measured at +11.0% CPU and +10.7% on the 16 KiB case, and reverted because it did not improve the measured workload.

Group invalidation after seeding, from the same run:

| Keys | HarborCache p50 | Free plugin p50 | HarborCache commands | Free plugin commands |
|---|---:|---:|---:|---:|
| 100 | 0.267 ms | 3.970 ms | 2 | 1,633 |
| 5,000 | 0.368 ms | 11.459 ms | 2 | 6,997 |

This measures invalidation latency after seeding, not physical memory reclamation or concurrent production behaviour.

## Limits

- One run per configuration, shared GitHub runners. Absolute times are not portable; paired ratios are the useful result.
- Sequential checked operations, not HTTP percentiles. The PHP-FPM smoke test is 160 requests at concurrency eight, not a soak.
- The benchmark pins PhpRedis for all engines. The new-install client default is outside that matched lane; a Predis install gains the C extension by switching, which the benchmark does not quantify.
- No test ran against a production site. Native Cluster/Sentinel failover certification, sustained load and long-running persistent-connection pools remain separate gates.

## Local checks

- PHP 8.3.36 `php -l` over every PHP file outside `vendor/`.
- `tests/connection-check.php` against a local RESP fixture returned the full success card, version and elapsed time.
- A WordPress-free render harness produced the Status, Settings, Cache and Diagnostics screens with `E_ALL` and display errors on, and reported no notice, warning or em dash.
