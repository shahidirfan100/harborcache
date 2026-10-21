# HarborCache 1.2.7 validation

Validated runtime commit: `3450de255135d936c41067e426ab32f5d74b368d`.

Release archive: `dist/harborcache-1.2.7.zip`, 599 files, SHA256 `f9e2442d64ddb53f1aa79b784c53005d50b72af2b99e5af6038998a6479fd9e4`. The build verifier passed exact source/package parity. The main header, runtime constant, drop-in template, deployment loader and readme stable tag agree on 1.2.7. Later evidence-only edits are excluded from the archive.

Baseline: 1.2.6, commit `6d235793ec8ad5bcbd9885b35c8043d6915fcea9`, ZIP SHA256 `44c4483c357cfb0260c23e3e3005385c2c4a50daecbcf395128082a7984fe2e9`. Free baseline: Redis Object Cache 3.0.0. Old benchmark totals cannot be compared directly to revised checked operations and corrected bootstrap lanes.

## Correctness and lifecycle

- Local syntax: 42 PHP files passed. Focused checks: 21 safety, four metrics failure, 12 drop-in writer, nine review regressions and one conflict-notice assertion passed (47 total).
- Exact final ZIP installed in a fresh WordPress 7.1.3 / PHP 8.4.26 SQLite fixture with relocated content/plugins directories. Plugin Check returned no errors or warnings. Seven rendered-drop-in runtime checks passed before and after a plugin-folder rename. WordPress safely fell back after the rename, the new drop-in recovered, and deactivation/uninstall preserved a foreign drop-in. Fixture: `work/runtime/harborcache-127-clean-1792588858`.
- Real Linux/MySQL acceptance passed three times on the same runtime commit: [push run](https://github.com/shahidirfan100/harborcache/actions/runs/39607204414), [repeat 1](https://github.com/shahidirfan100/harborcache/actions/runs/39607210408), [repeat 2](https://github.com/shahidirfan100/harborcache/actions/runs/39607210524). All ten jobs passed in each run (30 jobs total).
- Each run includes eight PHP/Redis/WordPress compatibility combinations covering PHP 7.4, 8.2, 8.3, 8.4 and 8.5; Redis 6, 7 and 8; WordPress 6.6 and latest. The separate review-layout job tests the archive in relocated directories.
- Native PhpRedis and Predis, installed codecs, concurrent workers, typed values, WordPress flows, outage/restart recovery, namespace ownership and restricted ACLs passed. Metadata-command rejection must disable unsafe persistence. Optional metrics-command rejection must leave ordinary data caching usable; denied writes, reads, counts and cleanup were tested individually. TLS trust/rejection and Unix sockets passed.
- Integration evidence includes critical-option primary routing against a controlled stale read node, WooCommerce order/stock flows, real multisite scoped invalidation, native Relay/adaptive configurations (79 checks each), and forced Relay reconnect (25 checks). These are the exercised configurations, not certification of every topology.
- Each run passed 160 HTTP requests at concurrency eight and preserved their counter after PHP-FPM restart. This is a short smoke check, not a sustained load test.

Full workflow logs contain expected OpenSSL/connection warnings from the deliberately rejected TLS certificate (three warning lines per matrix job). No other PHP warnings, fatal errors or deprecation diagnostics were found in the three run logs. Integration text artifacts contain none of those diagnostics. A successful negative-certificate test must not be described as a warning-free connection attempt.

## Repeated performance results

The benchmark rotates three engines through 30 blocks in each of two lanes per run: engine-only and complete plugin loaded during WordPress bootstrap. Three independent workflow runs produced 540 rows in total. The table reports the range of the three **within-run median paired percentage changes** in the full-plugin lane. Negative means less time/CPU. Timed operations check payloads and return values; random cold keys are prepared before timing. Both references use the same PhpRedis/default PHP-serialization/no-compression workload, with metrics disabled for the primary comparison.

| Measurement | 1.2.7 versus 1.2.6 | 1.2.7 versus Redis Object Cache 3.0.0 |
|---|---:|---:|
| Measured operation PHP CPU | -6.1% to -4.7% | +13.6% to +18.5% |
| 1,000 request-memory reads, p50 | -26.6% to -15.1% | -8.9% to +2.8% |
| 20 forced Redis reads, p50 | -1.8% to +4.8% | +3.6% to +6.9% |
| 100-key bulk read, p50 | -4.7% to -2.8% | +12.9% to +15.2% |
| 20 writes, p50 | -1.4% to -0.8% | +6.0% to +19.3% |
| 20 misses, p50 | -2.1% to -0.8% | +2.0% to +10.4% |
| 20 cold reads, p50 | -3.5% to -0.4% | +4.4% to +20.4% |
| 16 KiB round trip, p50 | -2.1% to -0.8% | +11.4% to +20.5% |
| Bootstrap PHP CPU, reported separately | -0.5% to -0.2% | +4.2% to +4.8% |
| Bootstrap wall time, reported separately | -1.6% to -0.8% | +5.2% to +6.4% |

Full-lane peak PHP allocator usage was 36 MiB for all three engines in every run. That is allocator peak, not total server RAM or proof of lower memory use. Runner speed varied considerably, so absolute timings are not pooled across machines. `tools/compare-benchmarks.py` reproduces medians, paired changes and operation p50/p95/p99 from the workflow artifacts.

The operation-CPU improvement over 1.2.6 repeated in all three runs. The goal of staying within 5% of the free plugin's operation CPU **was not met**. Forced-read p50 regressed by 4.8% in one run and improved modestly in the other two; this release does not establish a forced-read improvement. Bulk reads and large-value work still need profiling. There is no evidence for a universal speed lead over Redis Object Cache, and the release must not be marketed that way.

The existing atomic group-invalidation design remains a separate measured strength: at 5,000 seeded keys, HarborCache p50 was 0.321-0.392 ms versus 10.911-14.895 ms for the free plugin's default group flush, using five samples per engine/run. HarborCache used two Redis commands per invalidation. Compare the `group-flush.jsonl` artifact for each run. This measures invalidation latency after seeding, not reclaiming all old entries or concurrent production behavior; it was already present in 1.2.6, not a new 1.2.7 gain.

Sampling at 10% and 100% is recorded separately in each run. The primary comparison does not include that optional work; sampling rows are diagnostic observations, not 30-block comparisons. Metrics writes deliberately remain sequential: testing rejected a pipeline that could leave an uncertain native socket after a denied command. The safe error-isolation fix was retained.

## Profiling, scope and release boundaries

The CPU-only fake-transport fixture is for locating PHP work; it does not establish real Redis or WordPress speed. Local Windows timings were noisy. Separate Xdebug profiles confirm removal of repeated scope/group work from warm reads and reduced default key-sanitizer dispatch. Profiled timings are not acceptance timings. Acceptance runs explicitly disable Xdebug profiling and publish a whitelist of runtime settings rather than an environment dump.

Antislop was applied during the build: compatible measured changes, direct comments, documented semantics and no unsupported performance claims. Assets, media and `includes/ui` have no diff against 1.2.6; existing dashboard layout, colors and screenshots are preserved. Runtime behavior changes are described in RELEASE-1.2.7.md. GPL attribution and licenses remain. There is no new REST API, cloud account or server-tuning action.

No test ran against autoscareer.com or another production server. Native Cluster/Sentinel failover certification, longer production-like HTTP comparison, a sustained soak and guided setup remain roadmap work. Local WordPress.org Plugin Check passed, but that does not guarantee directory approval or every hosting configuration. Roll out with a backup, update HarborCache's owned drop-in and verify the target site's diagnostics before broad deployment.
