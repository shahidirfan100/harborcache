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

## Ponytail note

The fixes are local and small: one REST class rewrite, one group-registry signature, one group-key encoding change, one capability function, one lazy-load hook, one activation notice, and test/doc updates. No new dependency, no new engine path, no key-format change. The `/health` and `/groups` routes stay because they expose facts the dashboard already computes.

## Status

Local changes are on branch `codex/1.6.1-rest-hardening`; not merged. Offline suites and PHP lint pass. The live REST assertions are written but require the GitHub Actions matrix.
