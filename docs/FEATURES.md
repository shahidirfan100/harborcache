# HarborCache 1.1.3 feature ledger

HarborCache extends the GPL Redis Object Cache 3.0.0 engine with original code. No proprietary Object Cache Pro implementation, license bypass, vendor account or vendor updater is included. This describes the stable release implementation, not certification of complete Pro parity.

| Area | Behavior and evidence |
| --- | --- |
| Persistent cache | Typed values, false/null found flags, objects, bulk operations, add/replace/delete, runtime reset and cross-request persistence tested against real Redis. |
| Counters | Atomic decimal Lua arithmetic, exact integers beyond 2^53, overflow rejection and four-process concurrency checks. |
| Namespace isolation | Installation, site, global, group and wildcard-group invalidation; unrelated applications' keys preserved. |
| Reclamation | Immediate namespace rotation plus optional queued reclamation. Bounded SCAN batches, explicit KEYS mode, UNLINK on supported servers, per-primary cluster cleanup, generation guards and cron/manual/CLI workers. |
| Flush strategies | Group atomic/scan/keys/incremental/full and wildcard scan/keys/incremental tested. Full invalidates this installation, never FLUSHDB/FLUSHALL. SCAN/KEYS do one bounded deletion batch and queue remaining work. KEYS itself can block Redis; avoid it on large deployments. |
| Expiration | Finite maximum TTL and a separate limit for query groups. Old query generations expire; there is no separate hourly stale-query discovery sweep. |
| Split alloptions | Redis hash with atomic changed-field patches. Concurrent edits to different fields survive and stale snapshots do not restore unrelated deletions. Same-field conflicts are last-writer-wins. Finite TTL applies; Relay uses separate option strings and an atomic manifest. Real Relay concurrent invalidation remains unverified. |
| Prefetch | Bounded per-route/user manifests, refreshed each request and replayed using bulk reads. Excluded/nonpersistent groups respected. |
| Retries | Bounded safe-read retries with none/constant/exponential/jitter backoff. Ambiguous writes are not replayed. Predis replicated writes use an explicit primary connection. |
| Replication | Real primary/replica tests for distribute, replica-only and concentrate reads. Reads of keys written in the same request stay on the primary. Other replica reads can observe replication lag. |
| Sentinel | Real Sentinel discovery, replication and controlled promotion tested; a fresh request discovered the promoted primary and read/wrote successfully. This is not exhaustive network partition certification. |
| Cluster | Three primaries plus three replicas exercised. Per-group hash slots support bulk operations. Primary, error fallback, mixed and replica-only routing tested. Metadata always uses primaries. |
| Analytics | Local sampled measurements, retention, count limits, optional persistence across flushes and aggregate HTML footnote. Measurements are retained in Redis even when flush persistence is disabled. |
| Logging | Callable, class or PSR-style object logger with level filtering and redacted failure context. Logger failures do not break caching. |
| Tracing | Core cache operations and batches pass through instrumentation. Callable wrappers and OpenTelemetry provider/span lifecycle have contract tests. New Relic emits optional custom command events. Real OpenTelemetry SDK/OTLP HTTP export has local receiver evidence; real New Relic agent export remains untested. |
| Extension points | Custom connector callable and cache subclass/factory exercised against Redis. These are HarborCache APIs, not compatibility with Pro's interfaces. |
| Native clients | PhpRedis and Relay connection paths; Relay Cluster constructor, cache/invalidation patterns, adaptive policy, listeners and native routing have API-contract fixtures. Real PhpRedis 6.3.0 standalone, cluster, replicas and Sentinel were validated on Windows with Redis 5. Relay still has contract fixtures only. |
| Values | PHP serialization live-tested. igbinary/LZF/LZ4/Zstandard adapters reject missing codec functions. Live codec-extension testing remains required. |
| Administration | Six responsive tabs, guarded settings/drop-in forms, actions, copyable diagnostics, dashboard widget, admin bar, Site Health, WP-CLI and protected admin-post actions. Original teal branding, icons, banners and favicon. |
| Shared servers | Shared mode hides memory/key totals which would include other applications; dedicated mode sums primary memory and selected-database key counts. Server command statistics are explicitly labeled. |
| Protection | Strict prefix ownership, foreign drop-in preservation, verified drop-in staging and recoverable replacement, saved-password retention, credential-redacted diagnostics, corrupt-entry isolation and safe uninstall. |

## Configuration coverage

Each listed key is validated and connected to behavior. Unknown keys are rejected. API shapes and defaults intentionally differ from Pro.

| Settings | HarborCache implementation |
| --- | --- |
| client, host, port, database, username, password, prefix | Connection selection, ACL/password parameters and hashed installation namespace. |
| timeout, read_timeout, retry_interval, retries, backoff | Connection/read limits and bounded safe-read recovery. |
| persistent | Client persistent connection support; requires native deployment verification. |
| maxttl, queryttl | Finite object/query lifetime. |
| shared, strict | Capacity presentation and prefix ownership protection. |
| async_flush, group_flush, network_flush, pattern_flush | Scoped invalidation and reclamation policies described above. |
| cluster, cluster_failover | Redis Cluster topology and read routing. |
| cluster_distribution_strategy, cluster_failover_strategy, cluster_az | Relay-only native options; live Relay acceptance gate remains open. |
| sentinels, service, servers, replication_strategy | Predis discovery and replica routing; PhpRedis and Relay have built-in Sentinel/replica connectors. |
| tracer, logger, log_levels | Optional instrumentation and filtered, redacted logging. |
| serializer, compression | Portable encoding adapters and extension checks. |
| global_groups, non_persistent_groups, non_prefetchable_groups | Group scope, runtime-only storage and prefetch exclusions. |
| analytics | enabled, persist, persist_limit, retention, sample_rate, footnote. |
| relay | cache, listeners, invalidations, ignored, allowed, adaptive. Adaptive dimensions and policy map to Relay's native API. |
| prefetch, split_alloptions | Request prefetch and atomic options hash, or separate option strings with a Relay manifest. |
| tls_options | Verified TLS context by default. |
| cache, connector | HarborCache-specific factory contracts. |
| debug, save_commands | Up to 200 redacted command names/timings per request; pipeline timings describe the whole batch. Query Monitor presentation included. |

HarborCache adds scheme/path, gc_batch_size, gc_budget_ms, failure_mode, auto_update_dropin, query_maintenance and allow_server_controls. No license token is accepted or required. WordPress manages plugin update policy; HarborCache can refresh its own drop-in after an authorized admin connection check. No private vendor updater is bundled.

## Remaining production acceptance gates

Live tests used WordPress 7.1.3, PHP 8.5.10, SQLite integration, Predis/Credis and local Redis 5.0.14.1. PHP 7.4 syntax parsing is separate from runtime compatibility. Native Relay, Linux PhpRedis, Redis 6/7+, TLS/ACL/socket connections, codec extensions, MySQL, a real multisite network and application integrations such as WooCommerce/Jetpack/Yoast/WP Rocket/Batcache still need deployment tests.

Query Monitor and Debug Bar are optional integrations; the validation report identifies the actual versions and tests performed. Command instrumentation does not include every connection handshake/authentication command. New Relic native datastore instrumentation is implemented with an exactly-once callback guard, with a custom-event fallback for older agents. The included MU loader and Composer metadata support project deployments; no public Packagist listing is claimed. Local comparative benchmark results are provided with the release. No 100% coverage, premium service or exact Pro API compatibility is claimed.

Plugin Check does not grant WordPress.org approval. Preliminary name searches do not establish trademark clearance or reserve a directory slug. Complete the applicable deployment checks above for your server configuration before production deployment.

Comparison references: [public features](https://objectcache.pro/) and [configuration reference](https://objectcache.pro/docs/configuration-options/). These are references, not license grants.

## Stable dashboard and overhead

The WordPress dashboard widget shows current-request hits, misses, ratio, commands and Redis time. It fetches at most the latest 100 local samples in one bounded query; it performs no key scans or background polling. Analytics uses configurable sampling (10% default), retention and record limits. Samples include runtime hits and may span sites sharing a namespace. Connection discovery requests only the Redis server section. No zero-CPU or universal performance guarantee is made.

## Version 1.1.0 additions and boundaries

Request-cached validated configuration, five-minute analytics rollups, median/p95 request Redis time, allowlisted windows and CSV export, on-demand bounded group count/memory inspection, explicit per-node pings, Relay adaptive diagnostics, failure modes and an operations capability filter are implemented. Owned drop-in content checks repair the obsolete update action and preserve foreign files. Connection controls share dimensions and typography.

Group inspection reports physical Redis keys and may be partial after 500 keys, 20 scan pages or a cooperative 200 ms budget. Node checks run only on demand. Query maintenance is opt-in bounded group recycling; it can expire valid query entries and does not replicate Pro selective stale-query discovery. Native PhpRedis Sentinel/replication is locally exercised; Relay native topology still needs real-extension testing. Native Relay, codec and telemetry agent runtime acceptance remain open. Optional REST and helper APIs are deliberately excluded.

## Version 1.1.1 evidence

Bounded key memoization, runtime-hit acceleration, inactive tracing bypass and standalone routing shortcuts preserve generation-based namespaces. Native bulk pipelines resolve metadata and expiration filters before queuing; TCP cluster connections no longer receive an enabling TLS context. See the accompanying performance report for measured wall time, PHP process CPU time, memory and deployment limitations. No claim of complete Object Cache Pro parity or universally lower CPU is made.


## Version 1.1.2 stabilization

Verified drop-in staging/locking/recovery, coordinated per-option expiry and all-key primary pinning, reconnect discovery resets, leased cleanup with bounded deletion batches, notice-safe dashboard headings, grouped settings, consistent 44px controls, fixed chart-label scaling, separate runtime/store counters, malformed-sample rejection and reduced idle administration work. See RELEASE-1.1.2.md for behavior and remaining native/Linux acceptance gates.
