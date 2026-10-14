# HarborCache 1.2.0 feature and performance comparison

Reviewed 14 October 2026. This comparison separates documented capabilities, local acceptance evidence and measured performance. It does not claim feature parity with Object Cache Pro or universal performance leadership.

## Where HarborCache stands out

HarborCache combines a WordPress administration dashboard, local sampled analytics, cache diagnostics and connection management with generation-scoped site/group invalidation. A group flush rotates its namespace token instead of scanning and deleting every member key. This keeps the invalidation request bounded and avoids clearing unrelated Redis data. HarborCache also supports an optional split `alloptions` layout, bounded asynchronous cleanup, multisite scopes and failure-mode controls.

Redis Object Cache 3.0.0 is the stronger established general-purpose choice today: its official listing documents Predis, PhpRedis, Relay, replication, Sentinel, clustering, WP-CLI, metrics, diagnostics and a dashboard widget. Its 500,000+ active installations and long release history are meaningful maturity advantages. Object Cache Pro documents deeper configuration, performance-oriented serialization and compression, prefetching, several flush strategies, advanced integrations and commercial support. WP Redis is an adjacent integration option rather than a like-for-like admin and performance benchmark.

| Area | HarborCache 1.2.0 | Redis Object Cache 3.0.0 | Object Cache Pro | WP Redis |
|---|---|---|---|---|
| Core cache backend | GPL Redis Object Cache 3.0.0 foundation plus original HarborCache code; typed values, bulk operations and PHP client paths | Mature, widely installed backend with Predis, PhpRedis, Relay, replication, Sentinel and cluster support | Commercial backend with broad topology and tuning options | Lightweight Redis integration with a narrower administration and integration scope |
| Admin and observability | Six-tab dashboard, connection controls, charts/widget, local sampled history, redacted diagnostics, WP-CLI and Site Health | Settings, diagnostics, metrics and dashboard widget; Query Monitor support | Detailed diagnostics, advanced configuration and commercial support | Primarily setup/configuration oriented |
| Invalidation safety | Installation/site/group generations; avoids `FLUSHDB`/`FLUSHALL`; group invalidation remains constant-command in the measured test | Upstream behavior is mature, but shared Redis DB flushing needs correct isolation and configuration | Documents `scan`, `atomic`, `keys`, `incremental` and `full` modes; atomic support has stated server/client version requirements | The reviewed official listing does not establish HarborCache's scoped-generation behavior |
| Additional controls | Split autoloaded options, registered-key prefetch, bounded cleanup, local request analytics, failure modes and optional tracing adapters | Strong baseline configuration and broad upstream/community history | Broad configuration, application integrations and vendor support | Narrower feature surface |
| Evidence boundary | Current CI and local checks are listed in the validation report; production traffic and topology promotion need staging | Its features are documented upstream; this report only benchmarks the pinned 3.0.0 build under the stated workload | Documentation reviewed; no proprietary source or service was used and no same-run benchmark is available | Documentation reviewed; no same-run performance test is available |

These are feature-surface comparisons, not a claim that each product implements identical semantics. Sources: [Redis Object Cache listing and changelog](https://wordpress.org/plugins/redis-cache/), [Object Cache Pro configuration](https://objectcache.pro/docs/configuration-options), [Object Cache Pro flush behavior](https://objectcache.pro/docs/flushing), [WP Redis listing](https://wordpress.org/plugins/wp-redis/).

## Troubleshooting patterns from users and maintainers

The Redis Object Cache maintainers' troubleshooting guide documents several situations that matter in day-to-day site operation: Redis becoming unavailable, stale or incompatible plugin cache groups, slow requests while caching is enabled, large flushes timing out, and repeated flushes that need attribution. These are documented support cases, not evidence that the current free plugin is broadly unreliable. WP Redis also cautions that thousands of serial Redis calls can add substantial latency, which is a useful reminder to measure application call volume rather than assume a persistent cache always makes every page faster. Sources: [upstream troubleshooting guide](https://github.com/rhubarbgroup/redis-cache/blob/develop/FAQ.md), [WP Redis guidance](https://wordpress.org/plugins/wp-redis/).

HarborCache 1.2.0 addresses parts of that operational burden with explicit connection controls, diagnostics, local sampled metrics, configurable non-persistent groups, and namespace-scoped invalidation that avoids deleting other applications' keys. Its bounded cleanup and safe-read retry settings also make recovery behavior configurable. The next useful differentiator is a low-overhead flush and miss attribution view that helps an administrator isolate a noisy group or integration without collecting cache values or enabling full command logging by default. That work belongs behind the existing sampling and retention controls and should be accepted only after measuring its CPU and memory cost.

## Same-workload Linux measurements

The GitHub Actions benchmark compares the pinned Redis Object Cache 3.0.0 build, the packaged HarborCache 1.1.5 baseline, and the HarborCache 1.2.0 release build on PHP 8.3.35, WordPress 7.1.3, MySQL 8.0 and Redis 7 with PhpRedis. Seven rounds rotate the engine order. Optional analytics, prefetch and split options are off for this core-cache comparison. Each table entry is the median of seven run-level batch p50 values from [successful Linux acceptance run 38550596065](https://github.com/shahidirfan100/harborcache/actions/runs/38550596065); the benchmark job passed and uploaded its raw measurements.

| Operation batch | Redis Object Cache 3.0.0 | HarborCache 1.1.5 | HarborCache 1.2.0 | 1.2.0 vs 1.1.5 |
|---|---:|---:|---:|---:|
| 1,000 runtime reads | 0.4535 ms | 0.6044 ms | 0.5860 ms | -3.0% |
| 20 forced Redis reads | 3.4586 ms | 3.5410 ms | 3.5436 ms | +0.1% |
| 100-key bulk read | 0.4792 ms | 0.5352 ms | 0.5425 ms | +1.4% |
| 20 writes | 3.4365 ms | 3.6691 ms | 3.6897 ms | +0.6% |
| 20 repeated misses | 3.1375 ms | 3.3750 ms | 3.3630 ms | -0.4% |
| 20 distinct cold misses | 3.2306 ms | 3.4561 ms | 3.4680 ms | +0.3% |
| 16 KiB write/read | 0.3767 ms | 0.4192 ms | 0.4177 ms | -0.4% |

Measured-phase PHP CPU was 268.21 ms for Redis Object Cache 3.0.0, 321.69 ms for HarborCache 1.1.5 and 318.08 ms for HarborCache 1.2.0. Redis CPU was 222.42, 223.69 and 226.46 ms. Median Redis command counts were 4,667, 4,673 and 4,673; peak PHP memory was 34 MiB for the free build and 36 MiB for both HarborCache builds. HarborCache 1.2.0 is effectively unchanged from its packaged baseline in this small sequential workload. Redis Object Cache 3.0.0 was faster in all seven batches and used about 16% less measured PHP CPU in this run. The runtime optimization must not be advertised as a general speed win.

### Group invalidation probe

After seeding a group, five isolated flush samples were measured at each size. Tail percentiles are the maximum of five samples, so they are coarse.

| Keys in group | Redis Object Cache 3.0.0 p50 / commands | HarborCache 1.1.5 p50 / commands | HarborCache 1.2.0 p50 / commands |
|---:|---:|---:|---:|
| 100 | 0.9286 ms / 358 | 0.3481 ms / 2 | 0.3472 ms / 2 |
| 5,000 | 8.1072 ms / 5,723 | 0.4071 ms / 2 | 0.4111 ms / 2 |

The scoped generation design sharply reduced Redis commands and flush time for these specific groups. HarborCache 1.2.0 remained at two Redis commands; its 100-key p50 was 0.3% lower than baseline and its 5,000-key p50 was 1.0% higher. Compared with Redis Object Cache 3.0.0, the measured group flushes were about 2.7× and 19.7× faster. The design advantage is constant-work invalidation and preserving foreign keys, not an across-the-board hot-path speed claim. A larger, repeated staging benchmark should precede stronger operational claims.

All timings are short isolated runner measurements. They exclude WordPress bootstrap, do not represent full HTTP latency or sustained production traffic, and share a CI VM and Redis daemon whose CPU/RSS can vary. Raw measurements are retained in GitHub Actions artifacts. See [validation evidence](VALIDATION-1.2.0.md).

## Roadmap

| Version | Focus | Acceptance gate |
|---|---|---|
| 1.2.0 MVP | Correctness, readable dashboard, package checks, repeatable comparisons and bounded group invalidation | Final CI matrix, local regression, extracted-package checks, clean Plugin Check and readme validation |
| 1.3.0 | Relay option invalidation and adaptive/cluster behavior; sampled flush/miss attribution; real New Relic/OpenTelemetry export; topology connectors | Native extensions and agents, concurrent readers/writers, controlled failover and partition recovery, duplicate-span checks, measured attribution overhead with values excluded |
| 1.4.0 | Sustained performance work, measured prefetch tuning and selective stale-query reclamation | Isolated staging profiles for latency, CPU, memory, cache churn and application correctness with optional features on/off |
| 1.5.0 | Broader application and deployment compatibility | Payment, queues, cron, upgrade/rollback and real site workflow matrix |

For the remaining deployment gates, see [acceptance plan](ACCEPTANCE.md). WordPress.org reviewer approval, trademark clearance, sustained site capacity and any behavior not exercised by the test matrix remain separate evidence requirements.
