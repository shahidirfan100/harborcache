# HarborCache 1.2.8 validation

Validated runtime commit: `ba09059`. Release archive: `dist/harborcache-1.2.8.zip`, 600 files. The build verifier passed source and ZIP parity.

## Correctness and lifecycle

- GitHub Actions run [40686240193](https://github.com/shahidirfan100/harborcache/actions/runs/40686240193) passed all ten jobs: eight PHP/Redis/WordPress combinations, the relocated-ZIP review and the integrations/benchmark job.
- Matrix: PHP 7.4, 8.2, 8.3, 8.4 and 8.5; Redis 6, 7 and 8; WordPress 6.6 and latest.
- Plugin Check reported no errors and no warnings for the exact release ZIP.
- The new `tests/connection-check.php` passed on every matrix job: refused endpoint, missing client, live PhpRedis connection, wrong password, out-of-range database, probe-key cleanup and the status model.
- The new `tests/console-render.php` passed on every matrix job: no em dash, the registered product name, five tabs, the live status region, the saved-connection test, collapsed advanced settings, the raw report summary, the entry count and the restored widget table, summary, links and counters.
- The existing safety, recovery, ACL, TLS, Unix socket, outage, codec, WordPress, WooCommerce, multisite and PHP-FPM checks passed unchanged.
- `tests/dropin-writer.php` (12), `tests/review-regressions.php` (9), `tests/safety-regressions.php` (21), `tests/metrics-failure.php` and `tests/conflict-notice.php` passed locally on PHP 8.3.36.

## Measured performance

One acceptance run, 30 rotating blocks per engine, full-plugin lane, native PhpRedis, PHP 8.3.36, WordPress 7.1.3. Values are within-run median paired changes.

| Measurement | 1.2.8 versus Redis Object Cache 3.0.0 | 1.2.8 versus 1.2.6 |
|---|---:|---:|
| Operation PHP CPU | +2.4% | -7.3% |
| 1,000 request-memory reads, p50 | -14.8% | -29.9% |
| 20 forced Redis reads, p50 | +0.7% | -1.6% |
| 100-key bulk read, p50 | +5.2% | -6.1% |
| 20 writes, p50 | +4.6% | +1.4% |
| 20 misses, p50 | +5.7% | +0.9% |
| 20 cold reads, p50 | +3.6% | -1.3% |
| 16 KiB string round trip, p50 | +3.5% | 0% |
| Bootstrap PHP CPU | +5.5% | +0.7% |
| Redis commands | +0.1% | 0% |
| Peak PHP allocation | 0% | 0% |

Four 1.2.8 runs produced operation CPU results from +2.4% to +15.0% against the free plugin and from -7.3% to -4.8% against 1.2.6. The spread is too wide to claim the 5% target from one run. The table shows the final run; the consistent signal is the roughly 5% to 7% lower CPU than 1.2.6 and the group-invalidation advantage below.

Group invalidation after seeding, from the same run:

| Keys | HarborCache p50 | Free plugin p50 | HarborCache commands | Free plugin commands |
|---|---:|---:|---:|---:|
| 100 | 0.256 ms | 5.715 ms | 2 | 1,632 |
| 5,000 | 0.177 ms | 9.442 ms | 2 | 7,581 |

This measures invalidation latency after seeding, not physical memory reclamation or concurrent production behaviour. The 1.2.8 group registration also uses one Lua command instead of SADD plus three EXPIRE commands on a single node.

## Limits

- One run per configuration, shared GitHub runners. Absolute times are not portable; paired ratios are the useful result.
- Sequential checked operations, not HTTP percentiles. The PHP-FPM smoke test is 160 requests at concurrency eight, not a soak.
- The benchmark pins PhpRedis for all engines. The new-install client default is outside that matched lane; a Predis install gains the C extension by switching, which the benchmark does not quantify.
- No test ran against a production site. Native Cluster/Sentinel failover certification, sustained load and long-running persistent-connection pools remain separate gates.

## Local checks

- PHP 8.3.36 `php -l` over every PHP file outside `vendor/`.
- `tests/connection-check.php` against a local RESP fixture returned the full success card, version and elapsed time.
- A WordPress-free render harness produced the Status, Settings, Cache and Diagnostics screens with `E_ALL` and display errors on, and reported no notice, warning or em dash.
