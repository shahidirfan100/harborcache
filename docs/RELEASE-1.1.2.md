# HarborCache 1.1.2

This stabilization release implements the critical review's actionable code and dashboard corrections. Connection settings and key namespace format are unchanged. Public REST/helper APIs remain excluded.

## Updates and recovery

The drop-in writer stages and hashes the new file, verifies a previous-copy backup, and serializes updates/removal. Direct same-directory rename provides atomic replacement where the filesystem supports overwrite-rename. Remote transports and fallback moves cannot promise atomic replacement; failures restore the previous file. An interrupted move retains object-cache.php.harborcache-backup.php. An authorized administrator visit retries recovery when the active drop-in is missing. If both installation and rollback are denied by permissions, the backup remains for manual recovery and an admin notice explains it. Never delete a retained backup before recovery.

Direct updates use a persistent lock file; remote updates use a lock directory. Do not remove an active lock. An abandoned remote lock can be retried after five minutes. Foreign drop-ins are checked again inside the lock and refused.

## Cache correctness and maintenance

Per-option patches refresh unchanged field expirations with the manifest. If a referenced unchanged field is missing, the manifest is invalidated rather than reconstructing potentially stale data. Multi-key Lua/delete writes pin every relevant key to the primary for the request. Reconnection clears discovery caches while retaining write history. This is not a promise of cross-request linearizable replica reads.

Cleanup jobs are claimed atomically with a lease. Expired claims are recovered; completion/requeue is acknowledged atomically. Scripts use keys in the same cluster slot without changing the existing pending-queue key. Deletions are bounded and use per-node pipelines where available. Native cluster routing retains individual commands to avoid cross-slot deletion. TTL remains the final expiry fallback. Redis commands and scans have cooperative budgets, not preemptive deadlines; configured read timeouts also matter. Cleanup cron starts when jobs are queued and retires after both pending and in-flight work drain.

## Dashboard and measurements

Native page headings stay outside the connection row so WordPress notices retain their own region. Settings are grouped into Connection, Cache safety, Performance and maintenance, Diagnostics and analytics, and Analytics sampling. Headings use a consistent 24/20/18/16px scale. Body/labels use 14–15px text; primary controls and inputs have 44px minimum heights. Existing teal colors, focus indicators and WordPress system fonts are retained.

Chart geometry adapts to its width while labels remain readable. Metric tabs work by keyboard and each graph has an expandable data table. Sampling rate and last sample time explain the intentional one-minute delay. Combined hit ratios include request-memory hits; diagnostics and the overview report request-memory and Redis-store counters separately. These measure logical cache lookups, not server-wide Redis hit rates, CPU or full page response time. Error responses are not counted as successful store misses.

Malformed/out-of-range telemetry is skipped. Ownership/hash checks are memoized for one request and reset after writes. Unrelated administration pages load only the small admin-bar script, rather than chart/settings logic. Unsupported extensions are explained in settings; configuration constants still take precedence.

## Remaining deployment gates

Linux/MySQL, every advertised PHP runtime, real Relay invalidation/adaptive concurrency, TLS/ACL/socket and codec combinations, native promotion/partition recovery, New Relic agent delivery, production collectors, true multisite/application flows and server CPU profiles still require acceptance. These are evidence gates, not fixed by adding settings or passing Windows fixtures. Prefer the ordinary standalone configuration until the advanced combination you need has deployment evidence. Keep tracing opt-in and check native-agent duplicate instrumentation before enabling it.

Selective stale-query pruning, a native codec storage mode, EVALSHA optimization and further prefetch tuning remain later work requiring profiles and migration/correctness tests. They are not silently introduced by this patch release.

## Install

Back up the prior plugin ZIP and configuration, install 1.1.2, and visit HarborCache. Let the owned drop-in refresh or use Update drop-in. Confirm version 1.1.2 and Connected in Diagnostics, then check ordinary publishing, options, login, frontend pages and scheduled jobs. No artificial outages or synthetic stress are needed on a production site. The previous ZIP/configuration is the rollback path. Plugin Check/readme validation are not directory approval or blanket production certification.
