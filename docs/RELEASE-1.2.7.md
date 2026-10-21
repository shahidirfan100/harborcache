# HarborCache 1.2.7

This release implements the immediate performance work from PERFORMANCE-PLAN.md, with small compatible optimizations rather than a replacement engine. Existing key/value formats, configuration defaults, ownership checks, primary routing and the dashboard are preserved.

- Key contexts are reused until the current site or global-group registration changes. Layout-change checks still prevent stale configuration reads.
- Single-node PhpRedis/Predis metadata registration batches four independent commands after namespace resolution. Command count and expiry policy are unchanged; the aim is fewer round trips. Other topology/client combinations keep their existing path.
- The codec reuses its malformed-value error handler. Byte statistics avoid an intermediate array while keeping their serialized-value definition.
- Unsampled requests skip filesystem-backed status checks. Metrics retain their sequential write path so a failed optional pipeline cannot leave unread replies on the data connection. Collection, retrieval and cleanup contain Throwable failures and emit a bounded redacted message; acknowledged native error flags are cleared.
- Version metadata no longer requires reading the main plugin file on every bootstrap. Unused internal callbacks are no longer registered; their compatibility methods remain callable. Admin helper functions load when required. Console continues to register the admin bar and dashboard.
- Comparative tests load both complete plugins during the same WordPress bootstrap stage, and report a separate engine-only lane. Cold keys are prepared before timing. Profiling runs are separate from acceptance timings.

Update the owned drop-in after installing. The source and package versions must agree. Keep a backup and verify the target host before production rollout. VALIDATION-1.2.7.md records the actual passing gates and measurements; a target in the roadmap is not a measured speed result.

Later guided setup, a replacement independent engine, real Cluster/Sentinel failover certification and long-running production soak tests remain separate roadmap work. No public REST routes, cloud accounts or automatic Redis server tuning were added. Original GPL provenance and bundled licenses remain.
