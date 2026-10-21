# HarborCache performance implementation plan

Prepared 21 October 2026 after reviewing HarborCache 1.2.6, Redis Object Cache 3.0.0 source, upstream commits and public support reports. This plan supersedes feature expansion as the immediate priority. It does not claim that the proposed gains have been achieved or that every target fits one release.

## Success means less work with the same supported behavior

First remove the measured regression against 1.2.5. Then aim to put common single-node PhpRedis workloads within 5% of Redis Object Cache on PHP CPU and ordinary-operation latency, while retaining namespace safety, typed values and scoped invalidation. Surpass it only on workloads where repeatable evidence supports the claim. Do not average away a material regression in checkout, writes or failure recovery.

Keep separate results for:

- engine-only, explicitly matched transport/client/codec configuration;
- both complete active plugins on the same WordPress application;
- each plugin's documented defaults, including analytics and selected client;
- HarborCache's additional operational features and their incremental cost.

Choosing PhpRedis for HarborCache can improve a Predis installation, but it cannot explain away the existing gap: the previous three-engine benchmark already used PhpRedis throughout. Disabling all measurements only for HarborCache is not a fair default comparison.

## 1.2.7: measurement and the ordinary request path

Deliver work in small reversible patches, with one hypothesis and before/after evidence per patch.

1. Correct benchmark symmetry. Activate the free main plugin in the full-plugin lane. Add a true engine-only lane for both. Report process startup, WordPress/plugin bootstrap, cache operations and shutdown separately. Pin the WordPress database snapshot, theme/plugins, Redis version, PHP/OPcache settings, extensions, engine commits and configuration hashes.
2. Add short profiling runs using Xdebug/XHProf or an available sampling profiler on disposable Linux CI. Disable profiling for acceptance timings. Attribute inclusive/exclusive CPU, allocations and calls to key building, codec work, error checks, hooks and optional diagnostics. Do not infer these shares from file size or class count.
3. Test normal objects and scalar values, memory hits, repeated misses, forced reads, bulk reads/writes, many groups, and working sets above both derived-cache caps. Pre-generate random keys outside the timed region. Verify every timed result and found flag, not just the final return value.
4. Optimize the dominant measured path first. Candidate patches are a precomputed layout signature, one normalization path, bound driver capabilities and a simpler memory-hit path. Preserve key bytes, site/global-group semantics, runtime objects/nulls and late filter registration. Do not remove native error checks, prefix ownership or primary routing.
5. Reduce proven management bootstrap work: remove internal no-op hook registrations while keeping compatibility methods; defer actual admin modules where safe; avoid repeated plugin-header disk reads with release-generated metadata. Verify CLI/front-end/admin/cron bootstraps separately.

Acceptance target: matched ordinary-workload PHP CPU no worse than 1.2.5, no new peak-memory growth, no additional Redis commands on warm memory hits, and no material p95 regression. The previous 234.553 ms workload total is a historical reference, not a portable absolute threshold; use paired same-run ratios. Do not ship a performance claim if the difference is within measurement noise.

## 1.2.8: Redis round trips and inexpensive measurements

Start this work only after the profiler and benchmark lanes are trustworthy.

1. Batch independent group-registration and expiry commands after validated namespace token reads. Check every pipeline reply. Preserve initialization races, generation lifetimes and ACL failure behavior. Do not batch dependent GET/SET-NX resolution incorrectly.
2. Keep full invalidation constant-work. Test same-worker forced reads after another worker flushes, in-flight writers, metadata eviction, scope/global groups and TTL boundaries before attempting metadata caching across requests. Do not use APCu metadata caching unless its invalidation protocol is proven.
3. Separate cheap analytics counters from expensive cache-byte estimation. Show the byte metric as an estimate or unavailable when exact enumeration is not collected. Do not quietly replace runtime bytes with Redis server memory, since they measure different things.
4. Batch the four existing metrics commands and evaluate less frequent trimming under an explicit retention/count bound. Pipelining reduces network round trips; it does not by itself reduce command count or provide atomicity. Keep a supported path for ACLs that prohibit Lua and optional server introspection. Any Lua optimization must be optional, capability-aware and bounded.
5. Correct metrics failure handling to catch Throwable and use concise redacted logging. An observability failure must not terminate a normal request. Test large request caches, corrupt samples, concurrent metric writers and denied metrics commands.
6. Measure and simplify codec dispatch without changing the stored format. Only pursue a new scalar envelope or compression-size threshold if profiling demonstrates enough benefit to justify a format namespace and mixed-worker upgrade tests. Preserve PHP types, object cloning, corrupt-value handling and integer precision.

Acceptance target: common-operation CPU and latency within 5% of the pinned free plugin in the matched PhpRedis lane, with the default and full-plugin lanes also reported. Targets require confirmation, not a declaration of parity. Retain two-command logical group invalidation in the standard no-reclamation path. Verify that batching reduces measured round trips at latency without hiding extra cleanup CPU or weakening isolation.

## 1.3.0: setup that answers real operating questions

Keep the existing dashboard and colors. Add only controls supported by the new measurements and tests.

- Offer a selected-client connection check that verifies authentication, database, TLS/socket, temporary key round-trip and namespace ownership using that client. Do not infer native-driver success from a Predis-only probe. Respect configuration precedence and never silently replace an explicit client.
- Explain local socket/TCP versus remote TLS costs and show supported PhpRedis/Relay capabilities. Recommend a configuration based on the measured connection path, without changing Redis eviction policy, ACLs or server files automatically.
- Provide an opt-in diagnostic session with a duration and sample cap. Separate memory hits, Redis hits, Redis misses, cache CPU, connection latency and application response time. Redact credentials, object values and identifying keys in exports. No cloud account or public REST interface is required.
- Show the difference between logical invalidation and physical memory reclamation. State when restrictive ACLs mean old generations will expire naturally. Expose cleanup backlog and oldest-job age without scanning the entire database on a dashboard refresh.
- Keep Connect and Disconnect equally sized and clearly distinguish active, degraded, disabled and unknown states. These states must reflect actual checks; a historical successful connection is not current health proof.

Acceptance: configuration promotion has a recoverable rollback, no foreign drop-in is overwritten, failed tests leave the live configuration intact, and new controls pass keyboard/error/empty-state checks. Idle dashboards must not poll or trigger server discovery aggressively.

## Recovery and topology work: gate by tests, not a feature list

Schedule this as a later reliability release after the ordinary path is competitive. Do not market it as already certified in 1.2.6.

- Test native PhpRedis and Relay persistent connections under FPM worker reuse, Redis restart, memory rejection, timeout, unread replies and nonzero databases. Include credential and endpoint changes. Use the driver's available liveness/dirty-pool safeguards appropriately; do not add a PING to every get/set.
- Test authenticated three-primary Cluster configurations with replicas, failover and TLS, plus real Sentinel promotion. Preserve credentials, database rules and certificate policy on all recreated connections. Verify ordinary reads/writes and every supported invalidation path after recovery.
- Add mid-pipeline disconnect tests for set/add/delete multiple, including cleanup/discard throwing a second error. Never retry an uncertain write. Bounded readonly retries must reconnect correctly and preserve primary routing for critical groups.
- If a cross-request circuit breaker is justified by outage measurements, design coordination and recovery fencing first. Requests that update the database while Redis is bypassed may leave old cached values behind. Do not reopen an old namespace after missed invalidations; test a safe owner-checked recovery boundary across workers. Request-memory fallback is not a free consistency guarantee.
- Test OOM/noeviction responses separately from transport loss and corrupt data. Keep memory collection, cleanup and retry policies explicit. Do not weaken TLS verification to solve a connection problem.

Acceptance: recovery never exposes foreign data, stale invalidations do not reappear, no fatal error replaces the original cache failure, writes are not duplicated and latency remains bounded by the documented policy. Native/topology support claims must identify the combinations actually exercised.

## Measurement protocol and release gate

Use a dedicated disposable Redis instance with no unrelated tenants or jobs for timing. Run supported cleanup in a separate lane and measure it explicitly. Redis counters sampled from a shared daemon are not reliable per-plugin accounting.

For each meaningful candidate, run at least 30 randomized/rotating fresh-process blocks per engine, with warmups and payload preparation outside the measured operation region. Confirm the final result on three independent CI runs. Report paired ratios, p50/p95/p99, PHP user/system CPU, Redis CPU, command count, round trips, transferred bytes, PHP peak allocation and Redis memory. Keep individual samples and explain variance; a 1-2% difference is not a win if the noise is larger.

Exercise local TCP, Unix sockets, trusted TLS and controlled additional latency, for example 1 and 5 ms on disposable infrastructure. Remote-network tests must report the injected conditions. Redis documents why [round trips and pipelining](https://redis.io/docs/latest/develop/using-commands/pipelining/) matter and why [latency requires diagnosis](https://redis.io/docs/latest/operate/oss_and_stack/management/optimization/latency/); neither source establishes a HarborCache speed gain.

Add matched uncached HTTP/FPM workloads for publishing/admin/report requests, WooCommerce cart/checkout and multisite. Include a sustained run of at least 30 minutes at documented concurrency and a separate restart/outage sequence. Collect request errors, response tails, PHP-FPM CPU/queues and database load. Do not load-test Autoscareer.com without a separate agreed window; use disposable CI first.

Every release must also pass:

- all 1.2.6 namespace, malformed-reply, critical-options, Relay/nested bypass and reconnect regressions;
- explicit cache-disabled/fallback behavior, typed false/null values, object isolation, bulk APIs, atomic counters and expiry;
- plugin/drop-in upgrade interruption and recovery, renamed directories, multisite, deactivation/uninstall and foreign drop-in protection;
- Plugin Check report parsing, security checks and exact source/ZIP/version parity;
- an explanation for every accepted latency, CPU, memory or command-count regression.

A noisy result, a failed correctness check or a material slowdown blocks the performance claim. Keep a patch only if it improves a measured workload or fixes a defined defect. Do not compensate for slower reads by highlighting a different fast flush benchmark.

## Code quality and provenance

Antislop does not certify code authorship and comment removal does not improve PHP execution speed. Keep comments that explain protocol, security, compatibility and concurrency; remove or correct misleading narration. Preserve legal notices, attributed source and public hook contracts.

Improve dense one-line methods in separate readability patches after functional coverage exists. Verify token-equivalence when formatting alone changes, or explicit before/after behavior when splitting a method. Do not introduce generic service containers, adapters with only one implementation, new caching layers or a rewrite solely for appearance. Separate profiling tools and fixtures from shipped runtime code.

A replacement independent engine remains a separate architectural decision. Build it only if profiles and compatibility constraints show that the current extension layer prevents the target. Require the same external API tests, key-format migration, rollback and mixed-version deployment tests before replacing it. Writing new features does not remove licensing obligations for retained GPL-derived code.

## First implementation batch

The next approved build should contain the corrected benchmark/profiling lanes, one measured ordinary-path optimization, reduced proven bootstrap overhead, and a published before/after report. Keep Redis metadata batching and codec-format experiments separate until their own evidence is ready. Do not add new enterprise-style options to that build.
