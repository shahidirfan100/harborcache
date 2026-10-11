# Advanced configuration

Declare HARBORCACHE_CONFIG before WordPress loads. See Config::defaults() in includes/ch-config.php for the complete default map. Unknown keys are rejected. Put advanced arrays, callback factories and logger/tracer objects in this constant; the dashboard saves ordinary scalar settings.

## Topology and recovery

Choose one topology: cluster with node URLs, sentinels plus service, or replicated servers. Nodes need explicit TCP/TLS host and port; Cluster requires database zero. Predis single-server, Credis, a six-node Predis cluster, replicated reads and Sentinel promotion have local evidence. PhpRedis and Relay now have built-in primary/replica and Sentinel connectors. Real PhpRedis standalone, cluster, replication and Sentinel paths were exercised locally; real Relay remains an open Linux acceptance gate.

Example servers array: array( 'tcp://redis-primary:6379?role=primary', 'tcp://redis-replica:6379?role=replica' ). For native clients, list replicas explicitly; Sentinel discovers healthy replicas. Predis may discover replicas from a primary-only list. replication_strategy accepts distribute, distribute_replicas or concentrate. cluster_failover accepts none, error, distribute or distribute_replicas. Replicas may lag; keys written by the same cache instance read from the primary for the rest of that request. Metadata always reads from primaries.

Set retries from 0 to 5 and backoff to none, constant, exponential or smart. Only safe reads retry; an ambiguous write may already have succeeded and is never replayed. Smart backoff uses jitter and is bounded by read_timeout and a one-second per-delay cap.

Relay requires a current native extension and compatible Redis server. relay controls cache, invalidations, listeners, ignored/allowed patterns and optional adaptive dimensions/policy. Relay-only cluster_distribution_strategy, cluster_failover_strategy and cluster_az map to native APIs; actual extension testing is still required. Unsupported requested native options fail the connection rather than being silently ignored.

## Reclamation and isolation

Flush rotates namespace tokens. With async_flush=true, queued bounded jobs reclaim old generations using UNLINK on supported servers. Cron runs once per minute when WordPress cron is available; system cron can invoke the harborcache_cleanup hook or wp harborcache cleanup. With cron disabled, run cleanup manually or rely on TTL expiry. Queue size is bounded; lost/dropped jobs still expire normally. gc_batch_size limits deletions per job execution.

group_flush accepts atomic, scan, keys, incremental or full; pattern_flush accepts scan, keys or incremental. Atomic rotates tokens; incremental queues reclamation; scan/keys perform one deletion batch then queue remaining work. KEYS discovery itself can block Redis and should be avoided on large servers. Full invalidates this installation, never unrelated Redis data.

Every installation requires a unique prefix. strict=true rotates the namespace when installation ownership changes. Shared prefixes between installations cause repeated protective invalidations and must be fixed. Server-wide statistics resets require allow_server_controls=true.

shared=true hides server memory/key totals because other applications may be included. Set false only for dedicated storage. Capacity then sums primary-node memory and selected-database key counts. Server command statistics remain explicitly labeled.

## Analytics and instrumentation

analytics.persist controls whether history survives namespace flushes; it does not disable collection. analytics.enabled=false disables collection. persist_limit is a count bound (null means retention-only), retention limits age, sample_rate sets the sampling percentage, and footnote adds an HTML comment with aggregate hits/misses. Defaults: 10% sampling, 1,000 samples, two-hour retention, no footnote.

logger accepts a callable(message, context), a class with log(level, message, context), or an object with that method; log_levels filters levels. Logger exceptions are isolated. Connection failures use generic messages and redacted context.

tracer accepts none, newrelic, opentelemetry/open-telemetry, a provider/tracer object, or a callable(operation, command). A callable must execute and return the operation. Explicitly configured telemetry delegates command instrumentation to the installed agent/provider. Keys and values are omitted. New Relic uses native datastore segments when the installed agent exposes newrelic_record_datastore_segment, otherwise custom events. Real OpenTelemetry SDK 1.15.0 and OTLP exporter 1.4.0 exported Redis spans to a local HTTP receiver. New Relic agent/account export and production telemetry backend validation remain required.

save_commands/debug records up to 200 command names/durations per request. Pipeline durations describe the whole batch. Query Monitor displays the list; diagnostics and slowlog exports omit command arguments.

## Extension factories and updates

connector is a callable(config) returning a connected, compatible Redis client. cache is a HarborCache_Advanced_Cache subclass name or callable(fail_gracefully) returning that type. Define factories before WordPress loads; an anonymous subclass can be declared inside the factory after the base class loads. These are HarborCache contracts, not Pro interface compatibility.

WordPress manages update visibility and automatic-update policy. No private vendor updater, update-suppression setting or license token is included. Receiving package updates requires a real directory listing/distribution channel.

CLI includes status, diagnostics, enable, disable, update, flush, cleanup, groups, commands, slowlog, analytics, digest, aggregate, watch, latency, health, relay and queries. No plugin REST endpoints are registered. See DEPLOYMENT.md for bounded probes, cleanup budgets and optional query recycling.

Review FEATURES.md for runtime evidence and open deployment acceptance gates.

## Request overhead in 1.1.1

Derived keys are memoized within each cache instance (1,024 entries), with at most 256 cached group/scope prefixes. Root, site and group generation changes clear both maps; encoding-layout changes clear them too. Namespace bytes remain unchanged. Forced reads still fetch Redis, null retains its found flag and returned objects are cloned. Disabled instrumentation skips tracing setup; standalone servers avoid replica bookkeeping. Expiration filters, serialization and namespace discovery finish before native bulk pipelines open.

Native Sentinel credentials are explicit per Sentinel URL and separate from Redis credentials. Discovery rejects a node that no longer reports a primary role. Native writes are never automatically replayed; safe-read retries can rediscover Sentinel. Replica reads may lag. Both TCP and verified TLS paths exist, but TLS/ACL/socket and Linux failover testing remain required.
