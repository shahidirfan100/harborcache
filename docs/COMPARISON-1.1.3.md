# HarborCache 1.1.3: measured comparison and closure report

Reviewed 12 October 2026. This report separates implemented features, reproducible acceptance and production evidence that remains unavailable.

## What changed

- Bounded exact/wildcard group matching avoids compiling a regular expression for every operation; registering new exclusions invalidates the memo immediately.
- Disabled command instrumentation avoids unnecessary wrappers in the standalone path. Optional tracing/debug/retries continue to work when enabled.
- Counter Lua replies use an explicit success tag. Real Relay testing caught an overflow failure being returned as zero; missing/overflow failures now remain false and successful zero remains an integer.
- Forced Relay reads bypass its local cache and restore the previous mode. Forced topology reads use the primary.
- Existing namespace formats, teal dashboard, readable headings/chart labels and equal connection controls remain. REST/optional helper APIs remain excluded.
- SOURCE.md now describes protected administration forms. Deployment documentation distinguishes recoverable remote replacement from an atomic guarantee and marks RedisArray/sharding coverage explicitly.

## Comparison after the fixes

| Area | HarborCache 1.1.3 | Free Redis Object Cache 3.0.0 | WP Redis / LiteSpeed object-cache integration |
| --- | --- | --- | --- |
| Persistent cache and administration | Typed cache operations, six tabs, editable configuration, charts, accessible tables and guarded lifecycle | Persistent cache, settings/diagnostics, chart widget and advanced configuration | WP Redis emphasizes code/CLI configuration; LiteSpeed supplies object-cache settings inside its optimization suite |
| Additional operations | Generation-scoped site/group/installation invalidation, bounded cleanup, split options, registered prefetch, sampled analytics/CSV and optional tracing | Baseline functionality remains useful; graph widgets and advanced connections are not unique HarborCache features | Equivalent full generation/split-options/prefetch/history combination was not established from the reviewed official docs |
| Native evidence | Linux PhpRedis/codecs/transports; real standalone Relay with default/adaptive policy; local native topology tests | Benchmark baseline was the unmodified 3.0.0 release | These competitors were reviewed from documentation, not newly benchmarked |
| Performance verdict | Lower measured PHP CPU than 1.1.2; mixed latency changes and more overhead than free 3.0.0 | Lower measured PHP CPU and several faster basic operations | No measured speed ranking is justified |

Official sources: [Redis Object Cache](https://wordpress.org/plugins/redis-cache/), [WP Redis](https://wordpress.org/plugins/wp-redis/), [LiteSpeed object-cache settings](https://docs.litespeedtech.com/lscache/lscwp/cache/#object-tab). “Not established” is a review boundary, not proof that a feature cannot exist.

Object Cache Pro advertises performance-focused caching, serialization/compression, prefetching, analytics and application integrations. HarborCache implements original equivalents in several areas, but has no demonstrated complete Pro API, implementation, performance or operational parity. No proprietary Pro code, vendor services or support are bundled. [Official Pro feature page](https://objectcache.pro/).

## Linux same-workload measurements

Evidence: [successful nine-job CI run](https://github.com/shahidirfan100/harborcache/actions/runs/38213789404) at `7f43458ba64c39b5a7f5786c9862648f606f3424`. Seven rotating rounds, PHP 8.3.35, MySQL 8.0, Redis 7 and native PhpRedis. Analytics/prefetch/split options off for the comparison. Sequential cache work excludes WordPress bootstrap. Each number is the median of seven per-run p50 batch timings; the raw artifacts also include p95/p99.

| Batch | Free 3.0.0 ms | Harbor 1.1.2 ms | Harbor 1.1.3 ms | Change vs 1.1.2 |
| --- | ---: | ---: | ---: | ---: |
| 100 runtime reads | 0.0470 | 0.0596 | 0.0607 | +1.76% |
| 20 forced Redis reads | 2.8557 | 2.8635 | 3.0116 | +5.17% |
| 100-key bulk read | 0.4396 | 0.4992 | 0.4949 | -0.86% |
| 20 writes | 2.6092 | 3.2618 | 2.9533 | -9.46% |
| 20 repeated misses | 2.5125 | 2.6718 | 2.5702 | -3.81% |
| 20 distinct cold misses | 2.6889 | 2.7862 | 2.7653 | -0.75% |
| 16 KiB write/read | 0.3486 | 0.3700 | 0.3355 | -9.33% |

Measured-phase PHP CPU: free **185.09 ms**, 1.1.2 **231.40 ms**, 1.1.3 **214.72 ms**. This is **7.21% lower** than 1.1.2 and **16.01% higher** than free 3.0.0. Median Redis CPU: 164.81/173.00/169.07 ms. PHP peak: 34/36/36 MiB. Median Redis command counts: 4252/4258/4258.

The latest run shows a 5.17% increase in forced-read p50 and a 1.76% increase in runtime-read p50 versus 1.1.2; writes, misses, bulk and large-value batches improve. Separate runs varied substantially, so these figures do not establish a universal latency win. Shared-runner scheduling/network activity and Redis background work affect wall time and daemon CPU/RSS. Raw evidence is retained rather than selecting only favorable runs.

Default sampling remains 10%. Separate 10%/100% probes record one analytics call and its cost; they are not distributions of production request overhead. The independent 160-request PHP-FPM smoke includes bootstrap and eight concurrent clients; it is a functional/restart test, not a comparative HTTP throughput test.

## Gaps closed in 1.1.3

| Previous gap | Current evidence |
| --- | --- |
| No Linux/MySQL runtime matrix | Eight combinations: PHP 7.4/8.2–8.5 with Redis 7, PHP 8.3 with Redis 6/8, plus minimum WordPress 6.6/PHP 7.4; current core 7.1.3 in remaining cases |
| Native codecs and connection transports | Real PHP/igbinary × none/LZF/LZ4/Zstandard; Predis/PhpRedis ACL, trusted TLS, untrusted-certificate rejection and Unix socket |
| Concurrent options, lifecycle/outage | Four-process atomic counters, barrier-synchronized independent option patches, Redis stop/restart and fresh-worker recovery, activation/deactivation/reactivation and cron |
| Relay only had fixtures | Relay 0.50.2 with 32 MiB available cache: 65 checks each under default and configured adaptive policies, including counter boundaries and forced/shared-cache bypass |
| Applications and multisite absent | WooCommerce product/cart/API checkout/order updates/stock restore; real multisite options, global groups and selected-site invalidation |
| No PHP-FPM exercise | 160 successful requests at concurrency eight; exact shared counter preserved through FPM restart |
| Package/UX/readme drift | Source/install/extraction/repository hashes, official readme validator, Plugin Check, fresh screenshots and responsive/control regressions |

## Remaining acceptance and version plan

1. **1.2.0:** Real Relay shared-FPM invalidation under prolonged traffic, native cluster/Sentinel/replica promotion and partition recovery; New Relic agent/account and production OpenTelemetry collector, with duplicate-span checks. Standalone extension configuration success is not full adaptive/failover certification.
2. **1.3.0:** Sustained same-site traffic profiling, CPU/RSS growth and tail latency, further bulk/key/codec tuning, prefetch usefulness and selective stale-query reclamation. Keep optional query recycling off unless measured; it also expires valid entries.
3. **1.4.0:** Wider payment-gateway/queue/plugin workflows, actual deployment rollback, verified update distribution and directory review. Legacy RedisArray/sharding remains unverified with the enhanced namespace.

**Readiness:** The tested configurations have substantially stronger functional and package evidence. This is a stable 1.1.3 release with explicit support boundaries, not universal production certification or a performance-leader claim. WordPress.org Plugin Check/readme validation support submission but do not constitute acceptance. No update, synthetic load or outage test was performed on autoscareer.com.
