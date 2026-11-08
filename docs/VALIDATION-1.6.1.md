# HarborCache 1.6.1 validation

Code commit: `49f9bda`; documentation and package refreshed in PR #14. Release archive: `dist/harborcache-1.6.1.zip`, 603 files, SHA256 `d871bfd102bba7c90da85611f406e8c65f0b667e700c110eb961eca122fcdff3`. The build verifier passed source and ZIP parity.

## What changed

1.6.1 hardens the REST API introduced in 1.6.0.

- `POST /flush` requires exactly one of `group` or `scope`; an empty or ambiguous request is 400.
- REST forces `atomic` when `group_flush=full`, so a group flush cannot rotate the whole installation.
- A flush that Redis does not confirm returns 500/503, not HTTP 200.
- `/groups`, `/groups/{scope}/{group}` and `/relay` catch backend failures and return 503 instead of a PHP fatal.
- `/analytics` checks the cache first, reports `enabled:false` when analytics are off, and uses `ratio:null` for zero samples.
- `/slowlog` is 403 on a shared Redis server and returns `{ entries: [...] }` with command names only.
- `/relay` no longer accepts a key/group oracle.
- `/status` uses an explicit allowlist; `/health` decodes text and includes operational facts.
- Group diagnostics use the same key encoding as writes, URL-decode the route parameter, return 404 for an unregistered group, and report `truncated`.
- `network_flush` and network-admin scope checks are enforced; `/groups` is filtered for non-network callers.
- The REST class loads only on `rest_api_init`.
- `Console::capability()` applies `redis_cache_manager_capability` then `harborcache_manager_capability`; the bridge is removed and `Plugin` delegates to it.
- A reverse activation order now shows a conflict notice; the drop-in header has a `Plugin URI`.

## CI

Run [42460290829](https://github.com/shahidirfan100/harborcache/actions/runs/42460290829) passed **all 14 jobs** on `49f9bda`. Plugin Check 2.2.0 on the exact ZIP reported `Success: Checks complete. No errors found.` and the report gate reported no errors or warnings. All new REST assertions passed: status, endpoint redaction, health, truthful analytics, groups, group diagnostics, colon-encoded group, unknown-group 404, Relay redaction, shared slow-log 403, scoped flush, invalid scope 400, ambiguous flush 400, site flush, unauthenticated read and unauthenticated flush.

## Local checks (Windows, PHP 8.5.10)

| Suite | Result |
| --- | --- |
| 12 offline suites | 0 failures |
| PHP lint, all changed files | 0 failures |
| `python tools/build-release.py --verify` | 603 files, parity passed |

## Live assertions added

Group diagnostics hit, unknown-group 404, invalid scope 400, ambiguous flush 400, unauthenticated flush 401/403, shared slow-log 403, analytics-disabled, Relay redaction.

## WordPress.org readiness

- `Stable tag: 1.6.1` matches the plugin header and ZIP.
- Text domain `harborcache-object-cache-with-redis` matches the plugin slug.
- The ZIP has one top-level folder, `harborcache-object-cache-with-redis/`.
- The ZIP contains no `tests/`, `tools/`, `docs/`, `media/`, `dist/`, `.github/` or Pro-product strings.
- `readme.txt` has Description, Installation, FAQ, Changelog, Requires at least, Tested up to and Requires PHP sections.
- License is GPL-3.0-only; bundled Predis and Credis licenses are present.
- Plugin Check 2.2.0 on the exact ZIP is clean.
- Banners, icons, screenshots and the social preview remain in `media/` for the WordPress.org SVN assets step; they are correctly excluded from the installable ZIP.

This is release-ready for submission. WordPress.org review and SVN publication are external steps and were not performed.

## Limits

- The reverse activation order with another object-cache plugin still boots both plugins; the patch adds a notice, not full arbitration.
- No HTTP application-password test and no multisite REST scope test yet.
- `/health` is not a set of granular Site Health tests.
- The legacy `keys` flush strategy remains selectable and can block Redis.
