# HarborCache 1.5.1 audit and performance record

Scope: code conflict and standards audit, production-readiness checks, and a same-run performance comparison with Redis Object Cache 3.0.0, the 1.2.6 HarborCache baseline, and HarborCache 1.4.1.

## Audit result

| Check | Result | Evidence |
| --- | --- | --- |
| PHP syntax, all plugin files | Pass | 0 failures from `php -l` across `includes/`, `deployment/`, `tests/` and `tools/` |
| Offline suites | Pass | 12 suites, 0 failures |
| Plugin Check 2.1.0 on the exact ZIP | Pass | `Success: Checks complete. No errors found.` |
| PHP 7.4 to 8.5 matrix | Pass | 14-job Linux matrix |
| Redis 6, 7 and 8 | Pass | same matrix |
| WordPress 6.6 to 7.1 and latest | Pass | same matrix |
| Multisite, WooCommerce, PHP-FPM restart, Redis restart | Pass | same matrix |
| Admin actions | Pass | capability check plus `check_admin_referer` on every form action |
| AJAX probe | Pass | `check_ajax_referer` plus capability check |
| Superglobal handling | Pass | sanitized and unslashed; no raw SQL |
| Uninstall | Pass | removes the owned drop-in, config file, schedules and user meta; Redis data expires by TTL |
| Namespace conflicts | Pass | classes use `HarborCache_*`; hooks and `WP_REDIS_*` constants are the documented compatibility surface |
| Fatal duplicate declarations | None found | one `WP_Object_Cache`, one `wp_cache_*` function set, one drop-in |

### Findings that need an operational note

1. `group_flush`, `pattern_flush` and the cleanup job still accept the legacy `keys` strategy, which issues `KEYS`. It is not the default (`atomic` / `incremental`), but it can block Redis on a large keyspace. Use `scan` or `incremental` in production. Removing the option would be a configuration migration; it is a 2.0 change, not a patch.
2. `WP_Object_Cache::flush()` still contains the upstream `FLUSHDB` fallback when `WP_REDIS_SELECTIVE_FLUSH` is off. It is unreachable in HarborCache because `HarborCache_Advanced_Cache::flush()` overrides it and rotates a scoped namespace. It is dead code, not an active wipe path.
3. The codec uses `@unserialize` for object values, as the upstream WordPress object cache does. Treat the Redis endpoint as trusted; do not share it with untrusted writers.
4. Relay cross-process invalidation depends on the connection's Relay tracking endpoint. CI observed the plugin connection reporting `tracking=0` while a fresh connection reported `tracking=1`. The listener, event parsing and single-key eviction are verified; cross-process delivery is not asserted. This is pre-existing and documented in the 1.5.0 validation.

## Performance method

The audit run [42352920446](https://github.com/shahidirfan100/harborcache/actions/runs/42352920446) measured four engines in the same job, on the same runner, with the same rotating 30-round schedule:

- Redis Object Cache 3.0.0 (`free`)
- HarborCache 1.2.6 (`baseline`)
- HarborCache 1.4.1 (`old141`)
- HarborCache 1.5.1 (`harbor`)

Two lanes: `engine` (cache layer only) and `full` (plugin plus admin surface). Metrics are the median of paired per-round ratios; 60 samples per engine per lane.

## Result: 1.5.1 versus 1.4.1

The 1.5.0 features are performance-neutral. Every measured operation is within +/-1.3%.

| Metric (engine lane) | 1.4.1 | 1.5.1 | Change |
| --- | ---: | ---: | ---: |
| Operation PHP CPU | 227.85 ms | 226.98 ms | −0.2% |
| Bootstrap CPU | 187.51 ms | 187.18 ms | −0.3% |
| 1,000 runtime hits | 0.4154 ms | 0.4150 ms | −0.3% |
| 20 forced Redis reads | 2.363 ms | 2.453 ms | +1.3% |
| 100-key bulk read | 0.515 ms | 0.520 ms | +0.6% |
| 20 writes | 2.432 ms | 2.446 ms | +0.8% |
| 20 misses | 2.235 ms | 2.233 ms | −0.8% |
| 20 cold reads | 2.298 ms | 2.310 ms | +0.4% |

The full lane agrees. This is the expected result: 1.5.0 added one integrity field and Relay diagnostics, and 1.5.1 moved the WordPress-version comparison off the per-hit path.

## Result: 1.5.1 versus free Redis Object Cache

Same-run engine lane, and a second standard run ([42353449119](https://github.com/shahidirfan100/harborcache/actions/runs/42353449119)) for confirmation.

| Metric | 4-engine run | Standard run | Direction |
| --- | ---: | ---: | --- |
| 1,000 runtime hits | −23.1% | −22.7% | HarborCache faster |
| 20 forced Redis reads | +3.1% | +0.1% | parity |
| 100-key bulk read | +7.8% | +7.1% | HarborCache slower |
| 20 writes | +8.0% | +6.5% | HarborCache slower |
| 20 misses | +5.4% | +4.7% | HarborCache slower |
| 20 cold reads | +9.7% | +3.7% | HarborCache slower |
| Operation PHP CPU | +8.6% | +2.0% | noisy, +2% to +9% |
| Bootstrap CPU | +3.0% | +3.3% | HarborCache slower |
| Peak PHP memory | +5.9% | +5.9% | 36 MiB vs 34 MiB |

Unweighted six-operation average: about **1.4% slower** on the standard run and **1.8% slower** on the four-engine run. That is **near parity on the aggregate mix**, with a **22–23% runtime-hit lead**.

Do not claim a CPU win. The CPU ratio moved from +1.9% (1.4.1) to +9.3% (1.5.0) to +2.0% (1.5.1) across runners. The runtime-hit result is stable across every run; the CPU figure is not.

## Result: 1.5.1 versus HarborCache 1.2.6

Standard run, full lane:

| Metric | 1.2.6 | 1.5.1 | Change |
| --- | ---: | ---: | ---: |
| Operation PHP CPU | 117.58 ms | 105.20 ms | **−10.5%** |
| Bootstrap CPU | 135.13 ms | 129.74 ms | −4.0% |
| 1,000 runtime hits | 0.3356 ms | 0.2139 ms | **−36.3%** |
| 20 forced Redis reads | 1.296 ms | 1.251 ms | −3.5% |
| 100-key bulk read | 0.2863 ms | 0.2629 ms | −8.2% |
| 20 writes | 1.292 ms | 1.302 ms | +0.7% |
| 20 misses | 1.2497 ms | 1.2208 ms | −2.3% |
| 20 cold reads | 1.2618 ms | 1.2333 ms | −2.3% |

The durable gains since 1.2.6 are the runtime-hit path and CPU, with bulk reads improved and writes flat.

## Gap to Redis Object Cache Pro

There is still no head-to-head measurement. Pro was not installed, executed, or read.

Closed or matched in 1.5.x:

- Memoized request-local cache id and a 22–23% runtime-hit lead over the free plugin.
- Prefetch guards: GET/HEAD only, 1,000 keys, 24 hours, single-key skip, split-`alloptions` exclusion, incomplete-class drop.
- Relay single-key invalidation, event-object handling, scoped namespace reset, and diagnostics with or without the adaptive cache.
- WordPress-version integrity epoch, `noeviction` severity and Relay memory-pressure health.
- Scoped namespace rotation without `FLUSHDB`, plus the 14-job matrix.

Still open:

- Key format and memory: roughly 190-byte keys versus a much shorter Pro layout; a key-format v2 is a 2.0 item.
- Relay cross-process invalidation and live Relay/Cluster/Sentinel failover are not proven.
- Encoding presets and a measured default table are not published; the default stays PHP serialization with no compression.
- Analytics snapshots and JSON samples are simpler than Pro's.
- Pro advertises a much larger test suite; this project is integration-heavy.

## Production verdict

1.5.1 is production-ready for the tested surface: PHP 7.4–8.5, WordPress 6.6–7.1, Redis 6–8, Predis/PhpRedis/Credis, single site and multisite, WooCommerce, ACL, TLS, Unix socket, Redis restart and PHP-FPM restart. Plugin Check is clean and all 14 jobs pass.

Operational limits: keep the default `scan`/`incremental` flush strategies, treat the Redis endpoint as trusted, and verify live Relay failover in your own topology before relying on it.
