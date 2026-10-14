# HarborCache 1.2.0

HarborCache 1.2.0 improves Relay namespace consistency, tightens acceptance coverage and makes the administration dashboard easier to read.

## Changes

- Relay namespace and group-generation reads temporarily bypass Relay's in-memory cache. Fresh cache objects now resolve the same Redis namespace when a prior Relay-side miss could otherwise return stale metadata.
- The cache-key hot path compares the existing scalar layout state instead of allocating a new temporary array on each lookup. The key format is unchanged.
- Dashboard metrics use locale-aware number formatting. Chart, settings and widget type sizes are larger, and the Connect and Disconnect controls have equal dimensions.
- Acceptance coverage checks fresh-worker key agreement, `wp_cache_replace()` visibility from a fresh worker, non-persistent wildcard groups, concurrent alloptions patches, bounded group invalidation and an equal-workload comparison.
- Nine dashboard screenshots were refreshed without visible plugin-version text so they can be reused while the interface remains unchanged.

## Upgrade notes

Upload the 1.2.0 ZIP over the existing HarborCache installation. If the owned `object-cache.php` drop-in is reported as outdated, refresh it from HarborCache's Diagnostics or run `wp harborcache update`. Existing connection configuration and Redis key layout remain compatible. Keep the previous package and configuration backup available for rollback.

Install first on an isolated staging copy. Run the site's normal login, publishing, checkout, scheduled work and deployment checks before production rollout. Redis outages, topology failover, payment gateways and sustained production load were not tested on autoscareer.com.

## Validation

See [1.2.0 validation](VALIDATION-1.2.0.md), [same-workload comparison](COMPARISON-1.2.0.md) and [acceptance plan](ACCEPTANCE.md). These checks support a WordPress.org submission but do not imply directory approval or complete parity with commercial caching products.
