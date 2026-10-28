# HarborCache feature ledger (1.2.8)

HarborCache extends the GPL Redis Object Cache 3.0.0 engine by Till Krüss and contributors with original additions. This ledger describes implemented behavior and its verification boundaries. Version 1.2.8 adds a selected-client connection check with a five second budget, a five-state status model, five dashboard screens, a saved-connection test and PhpRedis detection for new installs. The 1.2.7 cache engine, key format, teal palette and product name are unchanged.

| Area | Behavior and evidence |
| --- | --- |
| Persistent cache | Typed values, false/null found flags, objects, bulk operations, add/replace/delete, runtime reset and cross-request persistence tested against real Redis. |
| Counters | Atomic decimal Lua arithmetic, exact integers beyond 2^53, overflow rejection and four-process concurrency checks. |
| Namespace isolation | Installation, site, global, group and wildcard-group invalidation; unrelated applications' keys preserved. |
| Reclamation | Immediate namespace rotation plus optional queued reclamation. Bounded SCAN batches, explicit KEYS mode, UNLINK on supported servers, per-primary cluster cleanup, generation guards and cron/manual/CLI workers. |
| Flush strategies | Group atomic/scan/keys/incremental/full and wildcard scan/keys/incremental tested. Full invalidates this installation, never FLUSHDB/FLUSHALL. SCAN/KEYS do one bounded deletion batch and queue remaining work. KEYS itself can block Redis; avoid it on large deployments. |
| Expiration | Finite maximum TTL and a separate limit for query groups. Old query generations expire; there is no separate hourly stale-query discovery sweep. |
| Split alloptions | Redis hash with atomic changed-field patches. Concurrent edits to different fields survive and stale snapshots do not restore unrelated deletions. Same-field conflicts are last-writer-wins. Finite TTL applies; Relay uses separate option strings and an atomic manifest. Real Relay 0.50.2 standalone CLI tests cover independent and concurrent option patches; adaptive/topology and long-running shared FPM acceptance are separate gates. |
| Prefetch | Bounded per-route/user manifests, refreshed each request and replayed using bulk reads. Excluded/nonpersistent groups respected. |
| Retries | Bounded safe-read retries with none/constant/exponential/jitter backoff. Ambiguous writes are not replayed. Predis replicated writes use an explicit primary connection. |
| Replication | Real primary/replica tests for distribute, replica-only and concentrate reads. Reads of keys written in the same request stay on the primary. Other replica reads can observe replication lag. |
| Sentinel | Real Sentinel discovery, replication and controlled promotion tested; a fresh request discovered the promoted primary and read/wrote successfully. This is not exhaustive network partition certification. |
| Cluster | Three primaries plus three replicas exercised. Per-group hash slots support bulk operations. Primary, error fallback, mixed and replica-only routing tested. Metadata always uses primaries. |
| Analytics | Local sampled measurements, retention, count limits, optional persistence across flushes and aggregate HTML footnote. Measurements are retained in Redis even when flush persistence is disabled. |
| Logging | Callable, class or PSR-style object logger with level filtering and redacted failure context. Logger failures do not break caching. |
| Tracing | Core cache operations and batches pass through instrumentation. Callable wrappers and OpenTelemetry provider/span lifecycle have contract tests. New Relic emits optional custom command events. Real OpenTelemetry SDK/OTLP HTTP export has local receiver evidence; real New Relic agent export remains untested. |
| Extension points | Custom connector callable and cache subclass/factory exercised against Redis. These follow HarborCache's documented API contracts. |
| Native clients | PhpRedis and Relay connection paths; Relay Cluster constructor, cache/invalidation patterns, adaptive policy, listeners and native routing have API-contract fixtures. Real PhpRedis 6.3.0 standalone, cluster, replicas and Sentinel were validated on Windows with Redis 5. Real Relay 0.50.2 standalone values, counters, forced reads, option patches and concurrent processes passed on Linux. Native Relay topology/failover remains a separate gate. |
| Values | PHP serialization live-tested. igbinary/LZF/LZ4/Zstandard adapters reject missing codec functions. Real Linux extension roundtrips passed for PHP/igbinary serialization with none/LZF/LZ4/Zstandard compression. |
| Administration | Five responsive tabs, guarded settings/drop-in forms, actions, copyable diagnostics, dashboard widget, admin bar, Site Health, WP-CLI and protected admin-post actions. Original teal branding, icons, banners and favicon. |
| Shared servers | Shared mode hides memory/key totals which would include other applications; dedicated mode sums primary memory and selected-database key counts. Server command statistics are explicitly labeled. |
| Protection | Strict prefix ownership, foreign drop-in preservation, verified drop-in staging, interrupted-write recovery and removal of incomplete canonical files, saved-password retention, credential-redacted diagnostics, corrupt-entry isolation and safe uninstall. |

## 1.2.8 connection and dashboard changes

The connection check builds its client from the saved configuration, so PhpRedis, Predis, Relay and Credis are checked with their own driver for a single node. Cluster, Sentinel and replica configurations use Predis for the check while the runtime keeps the selected client. The probe uses a five second minimum budget, writes and removes one key inside the installation namespace, and reports a specific reason for refused, timed out, authentication, ACL, TLS, DNS, socket and database failures.

The status model separates not installed, installed but not loaded, loaded but unreachable, connected and disabled by constant. The dashboard exposes Status, Settings, Cache, Analytics and Diagnostics. The Settings screen tests the saved connection without saving, keeps submitted values after a failed save, and collapses advanced limits. Clearing request memory no longer asks for a cache invalidation, and every clear action names its scope.

New installs select PhpRedis when the extension is loaded. Existing installations that saved Predis keep it, and the Settings screen offers a one-click switch. No cache key format changed.

## 1.2.6 safety and initialization changes

Strict ownership checks now preserve a conflicting owner's metadata and root, including the lifecycle probe. The conflicting installation falls back under its configured failure policy; changing an installation's identity may require a distinct prefix or restoring its original identity. There is no automatic ownership takeover. Multisite identity uses the network's base table prefix when available.

Namespace creation and rotation require a successful SET reply. Corrupt tokens are rejected rather than accepted or silently replaced. Known native command errors are distinguished from nil replies where the adapter exposes error/connection state; failed native read connections are discarded.

Optional INFO is fetched on first version use, with at most one attempt per connection state. Denied server diagnostics do not by themselves disable valid data caching. Predis-generated database-zero parameters omit unnecessary SELECT, while nonzero database selection is preserved. Primary routing applies to the options and site-options groups, including bulk and Relay option-string reads. Other permitted replica reads can still observe replication lag.

Normal fallback writes at most one concise redacted error per PHP request across cache instances. Explicit debug logging retains redacted exception traces. This is not a cross-request rate limiter or circuit breaker. The scoped PHP 7.4 TLS compatibility policy and explicit TLS settings remain unchanged. See VALIDATION-1.2.6.md for actual tests and limits; no universal speed claim is made.

## 1.2.0 release additions

The 1.2.0 release adds fresh-worker coverage for `wp_cache_replace()`, confirms wildcard non-persistent groups never reach Redis, and checks that runtime cache entries are discarded when the serialization/layout settings change. The hot key-layout path avoids allocating a temporary array on every lookup while preserving the same key format. Dashboard figures are locale-formatted and the console, chart, settings and widget typography is larger; connection actions retain equal 44px control dimensions. See [the validation report](VALIDATION-1.2.0.md) and [paired comparison](COMPARISON-1.2.0.md) for the actual test results and benchmark limits.

## 1.2.2 Runtime identity isolation

HarborCache owns distinct `HARBORCACHE_FILE`, `HARBORCACHE_BASENAME`, `HARBORCACHE_PLUGIN_DIR`, `HARBORCACHE_PLUGIN_PATH` and `HARBORCACHE_VERSION` constants. The drop-in resolves its files from HarborCache’s own installation path. Existing `WP_REDIS_*` connection settings remain accepted as configuration inputs for migration compatibility, while HarborCache no longer defines Redis Cache’s plugin-file or plugin-path identity constants. This avoids claiming another plugin’s global identity and prevents the drop-in from loading files from a competing plugin.

## 1.2.1 WordPress.org review fixes

The 1.2.1 update removes obsolete Redis Cache AJAX handlers that were already disabled by HarborCache’s admin integration, eliminating their unused unsanitized nonce path. The prefetch identifier now hashes a sanitized request path. Dashboard chart CSS and JavaScript are limited to HarborCache’s own page and visible widget; the chart assets are skipped when the widget is hidden. The metrics cleanup event now uses a HarborCache-specific name, with a one-time cleanup of the old event. The WordPress cache API, key layout and saved connection configuration remain compatible.

## Configuration coverage

Each listed key is validated and connected to behavior. Unknown keys are rejected. API shapes and defaults follow HarborCache's documented contracts.

| Settings | HarborCache implementation |
| --- | --- |
| client, host, port, database, username, password, prefix | Connection selection, ACL/password parameters and hashed installation namespace. |
| timeout, read_timeout, retry_interval, retries, backoff | Connection/read limits and bounded safe-read recovery. |
| persistent | Client persistent connection support; verified with native PhpRedis in the Linux CLI matrix; pool capacity and long-running PHP-FPM behavior need deployment measurements. |
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

Linux CI now tests MySQL 8.0, WordPress 6.6/7.1.3 and eight selected PHP/Redis combinations, with real PhpRedis, codec extensions, TLS/ACL/socket, concurrent workers, Redis restart recovery, WooCommerce and multisite. Real Relay 0.50.2 standalone passed with default/adaptive policies. Short PHP-FPM HTTP requests and restart passed. Local Windows tests additionally cover native topology and responsive administration. Sustained traffic, native Relay shared-FPM/topology/failover, production tracing and wider site-specific application workflows remain gates; see ACCEPTANCE.md.

Query Monitor and Debug Bar are optional integrations; the validation report identifies the actual versions and tests performed. Command instrumentation does not include every connection handshake/authentication command. New Relic native datastore instrumentation is implemented with an exactly-once callback guard, with a custom-event fallback for older agents. The included MU loader and Composer metadata support project deployments; no public Packagist listing is claimed. Local comparative benchmark results are provided with the release. No 100% coverage, premium service or compatibility with another plugin's private APIs is claimed.

Plugin Check does not grant WordPress.org approval. Preliminary name searches do not establish trademark clearance or reserve a directory slug. Complete the applicable deployment checks above for your server configuration before production deployment.

Comparison references: [public features](https://objectcache.pro/) and [configuration reference](https://objectcache.pro/docs/configuration-options/). These are references, not license grants.

## Stable dashboard and overhead

The WordPress dashboard widget shows current-request hits, misses, ratio, commands and Redis time. It fetches at most the latest 100 local samples in one bounded query; it performs no key scans or background polling. Analytics uses configurable sampling (10% default), retention and record limits. Samples include runtime hits and may span sites sharing a namespace. Connection discovery requests only the Redis server section. No zero-CPU or universal performance guarantee is made.

## Version 1.1.0 additions and boundaries

Request-cached validated configuration, five-minute analytics rollups, median/p95 request Redis time, allowlisted windows and CSV export, on-demand bounded group count/memory inspection, explicit per-node pings, Relay adaptive diagnostics, failure modes and an operations capability filter are implemented. Owned drop-in content checks repair the obsolete update action and preserve foreign files. Connection controls share dimensions and typography.

Group inspection reports physical Redis keys and may be partial after 500 keys, 20 scan pages or a cooperative 200 ms budget. Node checks run only on demand. Query maintenance is opt-in bounded group recycling; it can expire valid query entries and does not discover individual stale query entries. Native PhpRedis Sentinel/replication is locally exercised; Relay native topology still needs real-extension testing. Standalone native Relay and codec evidence was added in 1.1.3; native Relay topology and production telemetry agent acceptance remain open. Optional REST and helper APIs are deliberately excluded.

## Version 1.1.1 evidence

Bounded key memoization, runtime-hit acceleration, inactive tracing bypass and standalone routing shortcuts preserve generation-based namespaces. Native bulk pipelines resolve metadata and expiration filters before queuing; TCP cluster connections no longer receive an enabling TLS context. See the accompanying performance report for measured wall time, PHP process CPU time, memory and deployment limitations. No universal speed or CPU advantage is claimed.


## Version 1.1.2 stabilization

Verified drop-in staging/locking/recovery, coordinated per-option expiry and all-key primary pinning, reconnect discovery resets, leased cleanup with bounded deletion batches, notice-safe dashboard headings, grouped settings, consistent 44px controls, fixed chart-label scaling, separate runtime/store counters, malformed-sample rejection and reduced idle administration work. These stabilization fixes are retained in 1.1.3. See RELEASE-1.1.3.md and ACCEPTANCE.md for current evidence and remaining gates.

## Version 1.1.3

Bounded wildcard matching, inactive command bypass, explicit counter status replies and forced Relay cache bypass preserve existing namespaces. Linux runtime/transport/codec/application acceptance and reproducible CPU/memory/latency comparisons are published with this release. Free Redis Object Cache still wins several measured basic workloads; no universal performance leadership is claimed.
