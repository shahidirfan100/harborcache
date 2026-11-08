# HarborCache 1.6.0 competitive comparison

This record covers the supplied Object Cache Pro artifact (v1.26.1) and the HarborCache 1.6.0 response. The artifact was used only as behavioral evidence. No Pro source, names, comments, assets or configuration schema were copied.

## What the Pro artifact provides

Observed in the archive:

- Separate client, connection, connector and object-cache layers for PhpRedis and Relay, including cluster, replication and Sentinel variants.
- An authenticated REST API under its own namespace for analytics, commands, groups, latency, options, Relay adaptive state and slow log.
- Granular Site Health tests: configuration, filesystem, PhpRedis/Relay versions, Relay memory and key tracking, file headers, state, drop-in, errors, connection, eviction policy and Redis version.
- WP-CLI watchers for logs, aggregates and digests.
- Debug Bar and Query Monitor collectors.
- Metrics and analytics for Redis, Relay and WordPress.
- Prefetching, split `alloptions`, query pruning, async flushing and network flushing.
- A large unit-test suite and a mature release history.

## What HarborCache 1.6.0 closes

- **Authenticated REST API.** `harborcache/v1` now covers status, health, analytics, groups, group diagnostics, slow log, Relay diagnostics and scoped flushes. Read responses omit credentials and endpoints; the slow log omits arguments.
- **Bounded group registry.** Diagnostics can list registered groups without `KEYS` or `SCAN`.
- **Capability and authentication.** The API reuses the Settings capability and WordPress REST authentication, including multisite network administrators.
- **Verification.** The existing 14-job matrix now runs live REST assertions in every WordPress job.

## What remains open

| Area | HarborCache 1.6.0 | Remaining gap |
| --- | --- | --- |
| Key memory | Scoped namespace keys, roughly 190 bytes | A shorter key format is a 2.0 migration |
| Health checks | One combined Site Health result plus granular data in status/diagnostics | Separate tests per connection, version, Relay memory and drop-in header |
| CLI | `wp harborcache` cache, status and diagnostics commands | Live `watch`/`digest`/aggregate streams |
| Client architecture | Predis, PhpRedis, Relay and Credis behind one engine | Pro has explicit per-client connection and connector classes |
| Tests | Integration-heavy with a 14-job Linux matrix | A large unit-test suite with coverage reporting |
| Relay | Scoped invalidation, adaptive diagnostics and memory checks | Cross-process tracking and live failover are unproven |
| Encoding | PHP/igbinary with none/LZF/LZ4/ZSTD | No published preset table or measured default |

## Performance position

1.6.0 adds no hot-path work. The 1.5.1 same-run benchmark remains the reference: every operation is within 1.3% of 1.4.1, runtime hits are 22–23% faster than the free Redis Object Cache, and the unweighted six-operation mix is within roughly 2%.

The REST API adds work only when an authorized request calls it; it does not affect front-end cache reads.

## Production position

1.6.0 keeps the 1.5.1 production surface: PHP 7.4–8.5, WordPress 6.6–7.1, Redis 6–8, single site and multisite, Predis/PhpRedis/Credis, Relay, ACL, TLS, Unix socket, Redis restart and PHP-FPM restart. Plugin Check is clean and the full matrix passes.
