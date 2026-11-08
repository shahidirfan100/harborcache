# EGM review and ponytail audit: HarborCache 1.6.0 REST API

Target: `docs/COMPARISON-REST-API-1.6.0.md`. Review methods: three independent reviewer passes (design/security, structural/compatibility, QA/readiness) plus a ponytail pass. The installed egm-review bundle lacked its persona files, so the direct-dispatch variant was used. No files were edited by the reviewers.

## Verdict before fixes

**Working on the default happy path, not release-clean.** Six read routes and one scoped flush worked, but the destructive route could escalate, three routes could return a PHP 500, analytics could report a false 100% hit ratio, and the tests missed the security-relevant half of the surface.

## Findings and disposition

| Severity | Finding | Disposition |
| --- | --- | --- |
| BLOCKER | `POST /flush?group=` inherited `group_flush=full` and could rotate the whole installation | Fixed: REST forces `atomic` when the configured strategy is `full` |
| BLOCKER | Flush returned HTTP 200 with `flushed:false` when Redis did not confirm | Fixed: 500/503 `WP_Error` |
| BLOCKER | `/groups`, `/groups/{scope}/{group}` and `/relay` could throw on Redis loss or Relay version gaps | Fixed: shared `safely()` wrapper returns 503; Relay adaptive calls are inside it |
| BLOCKER | Empty `POST /flush` flushed the current site by default | Fixed: `scope` or `group` is required; both together is 400 |
| MAJOR | `/analytics` returned `samples:0, ratio:100` when analytics were disabled or Redis was down | Fixed: cache is checked first; disabled returns `enabled:false, summary:null`; empty samples set `ratio:null` |
| MAJOR | `/slowlog` exposed server-wide commands on a shared Redis | Fixed: 403 when `shared` is true; still available on a dedicated server |
| MAJOR | `network_flush` was ignored; `all`/`global`/other-site scopes were not bound to the caller | Fixed: policy enforcement and network-admin checks |
| MAJOR | Group diagnostics encoded `:` differently from the write path and returned `{keys:0}` for a real group | Fixed: sanitize then encode; URL-decode the route param; 404 for an unknown registered group |
| MAJOR | Free-plugin co-installation guard was order-dependent | Mitigated: reverse-order notice and drop-in `Plugin URI`; full boot arbitration remains open |
| MAJOR | Capability filter bridge diverged from the Settings menu and discarded an existing free-plugin policy | Fixed: one `Console::capability()` path; bridge removed; `Plugin` delegates to it |
| MAJOR | REST class loaded on every front-end request | Fixed: loaded only inside `rest_api_init` |
| MAJOR | Docs contradicted the shipped feature set and omitted scope grammar/status codes | Fixed: readme, FEATURES and validation wording updated |
| NIT | `/relay` accepted arbitrary key/group and could confirm key existence | Fixed: key/group parameters removed; raw stats already removed |
| NIT | `/status` blacklisted only `endpoint` | Fixed: explicit allowlist |
| NIT | `/health` left HTML entities and a dead `actions` field | Fixed: decode entities, drop `actions`, add operational health |
| NIT | Group route charset was too narrow and `%` double-encoded | Fixed: `[^/]{1,200}` with `rawurldecode` |
| NIT | `/groups` truncation was invisible | Fixed: `truncated` flag |
| NIT | `/slowlog` returned a bare array | Fixed: `{ entries: [...] }` |
| NIT | No tests for the destructive surface | Fixed: group hit/404, invalid and ambiguous flush, unauthenticated flush, shared slow log, analytics-disabled, Relay redaction |

## What remains open

1. **Co-installation arbitration.** A reverse activation order still boots both plugins. The patch adds a visible notice and makes the free plugin treat the drop-in as foreign, but does not stop both admin surfaces. A full fix needs a deferred boot or activation guard.
2. **Granular Site Health tests.** `/health` now includes operational health, but the plugin still has one combined Site Health result instead of Pro-style per-check tests.
3. **Application-password and multisite REST coverage.** The live suite uses cookie-authenticated `rest_do_request`; it does not exercise an HTTP application-password request or the multisite `manage_network_options` gate.
4. **Key format/memory, CLI watchers, unit-test breadth.** These remain the main competitive gaps versus the Pro artifact.

## Performance gaps found

These were checked, not all fixed:

1. **Logged-in front-end load.** `harborcache.php:87-96` loads the 94 KB `class-console.php` for any logged-in user because `is_user_logged_in()` is in `$needs_management`. A subscriber, membership site or admin-bar-disabled site pays that parse/boot cost on every front-end request. A lazy `admin_bar_menu` loader is the next safe optimization; it was not changed here because it needs its own acceptance coverage.
2. **Key length.** Scoped keys remain roughly 190 bytes versus a much shorter free/Pro layout. This is the largest steady-state memory and bandwidth gap and a 2.0 key-format migration.
3. **Cold/write CPU.** The same-run 1.5.1 benchmark shows bulk reads +6–8%, writes +6–8%, misses +5%, cold reads +4–10% versus the free plugin. Namespace metadata and the codec are the structural cost; no safe micro-optimization was identified in this pass.
4. **Group diagnostics cost.** `/groups/{scope}/{group}` can issue `SCAN` on up to 32 nodes, 20 pages and 500 keys, plus up to 500 `MEMORY USAGE` calls when `memory=true`. It is authorized-only and time-bounded, but not cheap; keep it out of tight polling.
5. **Analytics response size.** Up to 1,000 samples and a 60-second interval over 24 hours can produce roughly 1,440 buckets, around 200 KB. The cap is bounded; clients should poll at a sensible interval.
6. **Opt-in defaults.** `persistent`, `prefetch`, `split_alloptions`, `async_flush` and `query_maintenance` are off by default. That is safe, but users who never enable them leave the documented wins unused.
7. **Legacy `keys` strategy.** `group_flush`/`pattern_flush` still accept `keys`, which issues `KEYS`. Keep `scan`/`incremental` on production Redis.
8. **Async reclaim is off by default.** Flushes rotate the epoch, but old keys remain until TTL; `used_memory` can look unchanged after `flushed:true`.

## Security and trust boundaries

1. **Co-installation.** Reverse activation order still boots both plugins. The patch adds a notice and a foreign `Plugin URI`, but a full activation/boot guard is still needed.
2. **`unserialize` trust boundary.** `includes/ch-codec.php:65` uses `@unserialize` without `allowed_classes` because the object cache must return arbitrary PHP objects. Treat the Redis endpoint as trusted; do not let untrusted writers share the namespace.
3. **REST rate limiting.** No per-user rate limit exists on `/flush`, `/analytics` or `/groups`. They require management capability, but a compromised admin session can hammer them. A transient lock is a reasonable follow-up.
4. **`/slowlog` on a dedicated server.** Shared Redis is now 403. If an operator sets `shared=false` on a server that still hosts other applications, slow-log command names and timings are visible to the WordPress administrator.
5. **Lowered multisite capability.** If `harborcache_manager_capability` is lowered to a site capability, `/flush` cross-site and `global|all` are blocked by the network check, and `/groups` is filtered to the current site plus global. A site admin can still flush a global group through `POST /flush group=...`; default multisite capability is network-only, so this is latent rather than active.
6. **Capability filter precedence.** `Console::capability()` now applies `redis_cache_manager_capability` before `harborcache_manager_capability`; the old bridge no longer discards a site policy. The Settings menu and REST now agree.
7. **CSV and admin output.** Analytics CSV is numeric; admin forms use capability plus nonce. Plugin Check is clean. No SQL, no `eval`, no shell execution, no outbound HTTP in the plugin path.

## Compatibility and issue notes

- New REST code is PHP 7.4-safe and uses APIs available since WordPress 4.7; the matrix covers 6.6–7.1.
- The REST class is no longer loaded on anonymous front-end requests.
- `/groups` now filters other sites for non-network callers and reports `truncated`.
- `/health` remains a Site Health-shaped object plus operational facts, not per-check tests.
- The live suite still lacks an application-password HTTP request and a multisite REST scope test.
- The free plugin 3.0.0 repo was checked at `rhubarbgroup/redis-cache`: it has Predis/PhpRedis/Relay, replication, Sentinel, cluster and WP-CLI, but no REST API, dashboard, prefetch, split-alloptions, async reclaim or query maintenance. HarborCache is broader there; it is not yet Pro-equivalent on key memory, granular health, CLI watchers or unit-test breadth.

## Ponytail note

The fixes are local and small: one REST class rewrite, one group-registry signature, one group-key encoding change, one capability function, one lazy-load hook, one activation notice, and test/doc updates. No new dependency, no new engine path, no key-format change. The `/health` and `/groups` routes stay because they expose facts the dashboard already computes.

## Status

Local changes are on branch `codex/1.6.1-rest-hardening`; draft PR #14; not merged. Offline suites and PHP lint pass. CI run `42459185555` passed all 14 jobs and Plugin Check is clean.
