# HarborCache 1.2.9 validation

Validated runtime commit: `478a9e8`. Release archive: `dist/harborcache-1.2.9.zip`, 600 files; the SHA256 is recorded in `dist/SHA256SUMS`. The build verifier passed source and ZIP parity.

## Correctness and lifecycle

- GitHub Actions run [40692090577](https://github.com/shahidirfan100/harborcache/actions/runs/40692090577) passed all ten jobs: eight PHP/Redis/WordPress combinations, the relocated-ZIP review and the integrations/benchmark job.
- Matrix: PHP 7.4, 8.2, 8.3, 8.4 and 8.5; Redis 6, 7 and 8; WordPress 6.6 and latest.
- Plugin Check reported no errors and no warnings for the exact release ZIP.
- `tests/metadata-count.php` warmed 30 groups, created a new cache instance and read all 30. Metadata inside the read loop was **2 commands** (one `HGETALL`, one `SMEMBERS`) and the total was **32 commands** (30 data reads plus the 2 metadata reads). The prefix owner and root add 2 commands before the loop, so the request uses about 4 metadata commands regardless of group count.
- The previous protocol used one owner GET, one root GET, one scope GET, one group GET and one registration EVAL per group, or about 63 commands for the same 30 groups.
- The denied-metadata test now probes the first write. A rejected `SADD` disables persistent writes, the write falls back to request memory, and the cache does not claim a persistent write.
- Existing group flush, scope flush, cleanup, concurrent workers, namespace, ACL, TLS, Unix socket, outage, codec, WordPress, WooCommerce, multisite and PHP-FPM checks passed unchanged.
- The new `tests/console-render.php` assertions for the restored widget, entry count and connected state passed on every matrix job.
- Local checks on PHP 8.3.36: `php -l` over every PHP file outside `vendor/`, `dropin-writer` (12), `review-regressions` (9), `safety-regressions` (21), `metrics-failure`, `conflict-notice` and `connection-check`.

## Metadata command count

| Workload | Previous 1.2.8 | 1.2.9 |
|---|---:|---:|
| 30 groups, warm epoch hash | 63 metadata commands | 2 inside the loop, about 4 with owner and root |
| 30 groups, total commands | about 93 | 32 |

The count is deterministic and comes from the cache's own command counter. It does not depend on network latency.

## Engine benchmark

One acceptance run, 30 rotating blocks per engine, full-plugin lane, native PhpRedis, PHP 8.3.36, WordPress 7.1.3. Values are within-run median paired changes.

| Measurement | 1.2.9 versus Redis Object Cache 3.0.0 | 1.2.9 versus 1.2.6 |
|---|---:|---:|
| Operation PHP CPU | +12.8% | -5.3% |
| 1,000 request-memory reads, p50 | -12.9% | -26.4% |
| 20 forced Redis reads, p50 | +1.8% | -0.2% |
| 100-key bulk read, p50 | +7.5% | -4.4% |
| 20 writes, p50 | +7.0% | -1.3% |
| 20 misses, p50 | +7.0% | -0.6% |
| 20 cold reads, p50 | +4.4% | -2.2% |
| 16 KiB string round trip, p50 | +10.3% | -1.4% |
| Bootstrap PHP CPU | +4.8% | -0.7% |
| Redis commands | +0.2% | +0.1% |
| Peak PHP allocation | 0% | 0% |

The engine benchmark uses one cache group, so it does not exercise the metadata change. The 5% CPU target against the free plugin remains open.

Group invalidation after seeding, from the same run:

| Keys | HarborCache p50 | Free plugin p50 | HarborCache commands | Free plugin commands |
|---|---:|---:|---:|---:|
| 5,000 | 0.521 ms | 11.939 ms | 3 | 6,921 |

The hash field adds one command to the previous two-command group invalidation. The result is still about 23 times faster than the free plugin for the measured invalidation call.

## Limits

- One run per configuration, shared runners. The engine CPU result is noisy across runs; the deterministic metadata count is the reliable evidence for this change.
- The benchmark does not inject network latency. The metadata reduction matters most on remote Redis links; command count is the proxy.
- No production site or sustained soak was used. Native Cluster/Sentinel failover certification and long-running persistent-connection pools remain separate gates.
- The first request after upgrading pays the legacy epoch migration for the groups it uses; later requests use the hash.

## Local checks

- PHP 8.3.36 syntax check over every PHP file outside `vendor/`.
- The fixture driver in `tests/safety-regressions.php` now models `HGETALL`, `HGET`, `HSET`, `SMEMBERS`, `SADD` and the epoch Lua commands.
- A WordPress-free render harness still produces the Status, Settings, Cache and Diagnostics screens without notices or warnings.
