# HarborCache feature ledger (1.8.0)

HarborCache extends a GPL Redis object-cache engine with original additions. This ledger describes implemented behavior and its verification boundaries. Version 1.8.0 adds key-byte sampling, a cross-process Relay gate and a truthful unavailable-server health state. Version 1.7.0 adds one health provider with four Site Health checks, a REST `/health` schema, and front-end console deferral. Version 1.6.3 signs serialized cache values and reserves REST flush slots atomically. Version 1.6.2 adds the co-installation arbitration guard, admin-post network checks, a per-user REST flush limiter, a remote-endpoint trust warning and application-password plus multisite REST tests. Version 1.6.1 hardens the REST API: explicit scoped flush input, `network_flush` enforcement, no group-to-full escalation, 503 on backend failure, honest analytics/slow-log states, and lazy loading only on `rest_api_init`. Version 1.6.0 adds an authenticated REST API under `harborcache/v1` for status, health, analytics, groups, slow log, Relay diagnostics and scoped flushes, plus a bounded group-registry reader. Version 1.5.1 keeps the WordPress-version integrity check on the key-build path only, so request-memory hits stay at the 1.4.x cost. Version 1.5.0 evicts only the Relay-invalidated key from request memory, resets memoized ids when a namespace token changes, reports Relay statistics and memory without the adaptive cache option, adds the WordPress version to the derived-key layout, and reports `noeviction` and Relay memory pressure in Site Health. Version 1.4.1 restricts prefetch manifests to GET/HEAD page views, raises the manifest to 1,000 keys and 24 hours, skips single-key groups, excludes split `alloptions`, and drops incomplete classes from replay. Version 1.4.0 memoizes request-local cache ids, precomputes command-routing flags, skips namespace metadata for non-persistent groups and removes a redundant group-type lookup, without a key-format change. Version 1.3.3 makes the connection check use a concrete cluster node and the primary for replica topologies, reads the source topology constants, allows a socket-to-TCP switch, keeps wp-config passwords out of the saved file, strips endpoints from the copyable report and bounds group metadata. Version 1.3.2 removes the legacy Predis client and its public FLUSHDB path, rotates namespaces with the selected client, trims analytics every minute, reads the live connection on Status, requires a server-side reclaim confirmation, and prunes only changed query groups. Version 1.3.1 rebuilds the readiness panel as a compact health grid, adds a widget summary and readable chart ticks, and versions CSS/JS by file modification time. Version 1.3.0 separates transport health from namespace readiness, makes the connection check match the live cache path, migrates legacy namespace owners, loads administration code only when needed and removes the inherited no-op hook layer. Version 1.2.9 replaced per-group epoch reads with one shared Redis hash and one registry read per request; the existing key format, cleanup guards and scoped invalidation remain. Version 1.2.8 added the selected-client connection check, five dashboard screens and the restored widget.

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

## 1.3.0 readiness and request overhead

The dashboard, Site Health, Query Monitor and WP-CLI read one status model. Transport (`connected`/`unreachable`), namespace (`owned`/`legacy`/`unclaimed`/`conflict`), value codec and drop-in currency are separate facts. A reachable Redis server with a foreign prefix owner is reported as “Namespace conflict”, not “Not connected”; the cache stays in request-memory fallback until an operator reclaims the namespace or chooses a distinct prefix. Owners written by 1.2.x (which included `AUTH_KEY`) migrate on the first request, so rotating salts no longer disables a healthy cache.

The connection check uses the configured timeout, reads namespace ownership without writing, round-trips a value through the active serializer/compression, writes and deletes one probe key, and reports each step separately. A separate self-test exercises `wp_cache_set/get/delete` through the live drop-in and is available from the Status/Settings screens and `wp harborcache self-test`. `wp harborcache doctor` exits non-zero when the cache is not ready.

Administration, WP-CLI, cron and logged-in admin-bar requests load the console and integration layer; anonymous front-end requests load only the drop-in and the lightweight metrics recorder. Sampled analytics use an estimated runtime byte counter instead of serializing every cached value, and retention trimming runs on the hourly maintenance event. Cache values are decoded without installing an error handler per value.

## 1.2.9 namespace metadata

The request reads the prefix owner and root once, then one `HGETALL` for scope and group epochs and one `SMEMBERS` for the group registry. Reads of known groups perform no metadata write. Writes refresh the metadata lifetime once per group per request. A first request after upgrading migrates the legacy `scope:epoch` and `group:epoch` keys into the hash and preserves their tokens, so existing entries remain valid and the key bytes do not change. Legacy keys stay in place for cleanup guards. The hash key includes the root, so a full flush starts a new hash. A 30-group read measured 2 metadata commands inside the read loop (32 total including 30 data reads), compared with about 63 metadata commands in 1.2.8.

## 1.2.8 connection and dashboard changes

The connection check builds its client from the saved configuration, so PhpRedis, Predis, Relay and Credis are checked with their own driver for a single node. Cluster, Sentinel and replica configurations use Predis for the check while the runtime keeps the selected client. The probe uses a five second minimum budget, writes and removes one key inside the installation namespace, and reports a specific reason for refused, timed out, authentication, ACL, TLS, DNS, socket and database failures.

The status model separates not installed, installed but not loaded, loaded but unreachable, connected and disabled by constant. The dashboard exposes Status, Settings, Cache, Analytics and Diagnostics. The Settings screen tests the saved connection without saving, keeps submitted values after a failed save, and collapses advanced limits. Clearing request memory no longer asks for a cache invalidation, and every clear action names its scope.

New installs select PhpRedis when the extension is loaded. Existing installations that saved Predis keep it, and the Settings screen offers a one-click switch. No cache key format changed.

In the 1.2.8 acceptance run, the operation PHP CPU baseline was recorded. The 5% target remains open; the archived validation record holds the table and limits.

The dashboard widget keeps the request table, sampled summary, chart and links, with separate request-memory and Redis counters. The Status page builds one snapshot per request and shows the runtime entry count; it no longer serializes the whole request cache for a byte figure, which stays in Diagnostics. A single-node first-use group registration is one Lua command instead of SADD plus three EXPIRE commands. Configuration defaults are cached per request, and the connection probe class loads only when an admin action needs it.

## 1.2.6 safety and initialization changes

Strict ownership checks now preserve a conflicting owner's metadata and root, including the lifecycle probe. The conflicting installation falls back under its configured failure policy; changing an installation's identity may require a distinct prefix or restoring its original identity. There is no automatic ownership takeover. Multisite identity uses the network's base table prefix when available.

Namespace creation and rotation require a successful SET reply. Corrupt tokens are rejected rather than accepted or silently replaced. Known native command errors are distinguished from nil replies where the adapter exposes error/connection state; failed native read connections are discarded.

Optional INFO is fetched on first version use, with at most one attempt per connection state. Denied server diagnostics do not by themselves disable valid data caching. Predis-generated database-zero parameters omit unnecessary SELECT, while nonzero database selection is preserved. Primary routing applies to the options and site-options groups, including bulk and Relay option-string reads. Other permitted replica reads can still observe replication lag.

Normal fallback writes at most one concise redacted error per PHP request across cache instances. Explicit debug logging retains redacted exception traces. This is not a cross-request rate limiter or circuit breaker. The scoped PHP 7.4 TLS compatibility policy and explicit TLS settings remain unchanged. See VALIDATION-1.2.6.md for actual tests and limits; no universal speed claim is made.

## 1.2.0 release additions

The 1.2.0 release adds fresh-worker coverage for `wp_cache_replace()`, confirms wildcard non-persistent groups never reach Redis, and checks that runtime cache entries are discarded when the serialization/layout settings change. The hot key-layout path avoids allocating a temporary array on every lookup while preserving the same key format. Dashboard figures are locale-formatted and the console, chart, settings and widget typography is larger; connection actions retain equal 44px control dimensions. See the current validation record for the latest test results and benchmark limits.

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

Feature comparisons in this project describe HarborCache's own implemented behavior. They do not bundle, copy or license another product's private code or services.

## Stable dashboard and overhead

The WordPress dashboard widget shows current-request hits, misses, ratio, commands and Redis time. It fetches at most the latest 100 local samples in one bounded query; it performs no key scans or background polling. Analytics uses configurable sampling (10% default), retention and record limits. Samples include runtime hits and may span sites sharing a namespace. Connection discovery requests only the Redis server section. No zero-CPU or universal performance guarantee is made.

## Version 1.1.0 additions and boundaries

Request-cached validated configuration, five-minute analytics rollups, median/p95 request Redis time, allowlisted windows and CSV export, on-demand bounded group count/memory inspection, explicit per-node pings, Relay adaptive diagnostics, failure modes and an operations capability filter are implemented. Owned drop-in content checks repair the obsolete update action and preserve foreign files. Connection controls share dimensions and typography.

Group inspection reports physical Redis keys and may be partial after 500 keys, 20 scan pages or a cooperative 200 ms budget. Node checks run only on demand. Query maintenance is opt-in bounded group recycling; it can expire valid query entries and does not discover individual stale query entries. Native PhpRedis Sentinel/replication is locally exercised; Relay native topology still needs real-extension testing. Standalone native Relay and codec evidence was added in 1.1.3; native Relay topology and production telemetry agent acceptance remain open. The authenticated REST API uses the same bounded group reader and diagnostics; optional unbounded helper APIs remain excluded.

## Version 1.1.1 evidence

Bounded key memoization, runtime-hit acceleration, inactive tracing bypass and standalone routing shortcuts preserve generation-based namespaces. Native bulk pipelines resolve metadata and expiration filters before queuing; TCP cluster connections no longer receive an enabling TLS context. See the accompanying performance report for measured wall time, PHP process CPU time, memory and deployment limitations. No universal speed or CPU advantage is claimed.


## Version 1.1.2 stabilization

Verified drop-in staging/locking/recovery, coordinated per-option expiry and all-key primary pinning, reconnect discovery resets, leased cleanup with bounded deletion batches, notice-safe dashboard headings, grouped settings, consistent 44px controls, fixed chart-label scaling, separate runtime/store counters, malformed-sample rejection and reduced idle administration work. These stabilization fixes are retained in 1.1.3. See RELEASE-1.1.3.md and ACCEPTANCE.md for current evidence and remaining gates.

## Version 1.1.3

Bounded wildcard matching, inactive command bypass, explicit counter status replies and forced Relay cache bypass preserve existing namespaces. Linux runtime/transport/codec/application acceptance and reproducible CPU/memory/latency measurements are published with this release. No universal performance leadership is claimed.
