# Audit target: HarborCache 1.6.0 REST API

## Context

HarborCache 1.6.0 adds an authenticated REST surface under `harborcache/v1`. PR #13 is already merged to `main` at `f33e51f`. This audit decides whether the implementation is working, safe, compatible and worth keeping, and whether a follow-up PR is required.

The free Redis Object Cache 3.0.0 repo was checked at `rhubarbgroup/redis-cache` (branch `develop`, release `3.0.0`). It supports Predis, PhpRedis, Relay, replication, sentinels, clustering and WP-CLI, but has no REST API, dashboard, prefetch, split-alloptions, async reclaim or query maintenance.

The supplied commercial Pro artifact (v1.26.1) exposes its own REST API for analytics, commands, groups, latency, options, Relay adaptive state and slow log, granular Site Health tests, CLI watchers and a large unit suite. It was inspected only as behavioral evidence; no Pro code, names or assets were copied.

## Goals

- Let an authorized operator read cache status, health, analytics, groups, slow log and Relay diagnostics over REST.
- Allow a scoped flush by group or by `site`/`global`/`all` scope.
- Reuse WordPress authentication and the existing HarborCache management capability.
- Do not expose credentials, connection endpoints or slow-log arguments.
- Add no front-end hot-path work.

## Non-goals

- Settings updates over REST.
- Raw Redis command execution.
- Unauthenticated monitoring.
- A new token scheme.
- Pagination or streaming beyond the bounded responses below.

## Routes

| Route | Method | Behavior |
| --- | --- | --- |
| `/status` | GET | `Console::status()` with the endpoint removed |
| `/health` | GET | Site Health result with HTML stripped |
| `/analytics?window=&interval=` | GET | Up to 1,000 samples summarized |
| `/groups` | GET | At most 1,000 registry entries |
| `/groups/{scope}/{group}` | GET | Bounded group diagnostics, optional memory |
| `/slowlog` | GET | 25 entries, command names only |
| `/relay?key=&group=` | GET | Relay status/memory/adaptive detail, raw stats removed |
| `/flush` | POST | Group or `site`/`global`/`all` scope |

## Implementation

- `includes/class-rest.php`: route registration, permission callback, callbacks.
- `includes/ch-operations.php`: `group_list()` reads the registry set; never `KEYS` or `SCAN`.
- `harborcache.php`: loads the class and registers `rest_api_init`.
- `tests/status-matrix.php`: registry assertion.
- `tests/acceptance.php`: live status, endpoint redaction, health, analytics, groups, slow log, Relay, scoped flush and unauthenticated rejection.

## Evidence

- CI run [42455720013](https://github.com/shahidirfan100/harborcache/actions/runs/42455720013): all 14 jobs passed.
- Plugin Check 2.1.0: no errors or warnings.
- Offline suites: 0 failures.
- ZIP: `dist/harborcache-1.6.0.zip`, 603 files, SHA256 `02e1f0de099cb9ae2f673a69f97eb18031eec005d1569279fd21fb6b0d7689d9`.

## Open questions for reviewers

1. Is the permission model correct on single site and multisite, including application passwords?
2. Can any route leak credentials, endpoint details, cross-site data or slow-log arguments?
3. Is `POST /flush` sufficiently guarded, and does it report failure correctly?
4. Does route registration or autoloading break on PHP 7.4, WP 6.6, multisite or a front-end REST request?
5. Is the missing `/groups/{scope}/{group}` live test a material gap?
6. Is any route redundant or too broad for the value it adds?
7. Is the API documented enough for an operator to use it without reading source?
