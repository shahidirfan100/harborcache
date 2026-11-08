# HarborCache roadmap

Current release: 1.6.1 (REST hardening and documentation). Source of truth for behavior: `docs/FEATURES.md`; design rules: `design.md`; audit record: `docs/COMPARISON-EGM-REVIEW-1.6.0.md`; performance protocol: `docs/PERFORMANCE-PLAN.md`.

## Principles

1. Fix safety and correctness before adding surface area.
2. Every performance claim uses the existing measured protocol, not one runner sample.
3. Key-format work is a migration with a measured premise, a rollback and a mixed-version test.
4. The WordPress.org package excludes development docs, tests, media and competitor references.
5. One release, one reason.

## 1.6.x — close the current line

1.6.1 is the controlled REST-hardening release. It keeps the reverse-order co-installation notice.

1.6.2 is the security follow-up:
- Arbitration guard: the drop-in or manager marker prevents the second object-cache manager from registering admin UI or writing the drop-in. Add a both-order CI test (HarborCache then the free plugin, and the reverse) asserting one admin surface, one drop-in writer, no fatal. Update `tests/conflict-notice.php` to the new contract.
- Mirror the REST network check in `Console::execute()` for `flush-global` and cross-site `flush-site`.
- Add the missing HTTP application-password and multisite REST scope tests.
- Add a destructive-route limiter stored outside the rotated root (user meta or the persistent owner namespace): 1/minute and 10/hour per user, `429` with `Retry-After`; test that a successful flush does not reset it.
- Add a startup warning when the Redis endpoint is non-loopback, unauthenticated or non-TLS, because `ch-codec.php` still uses `@unserialize` for object values.

Acceptance: both-order CI test passes; one admin surface; limiter test passes; app-password and multisite tests pass; no PHP fatal; Plugin Check clean.

## 1.7.0 — operator visibility and measured hygiene

Existing `watch`, `digest`, `aggregate` and `/health` already exist; this release improves them rather than re-adding them.

- Refactor health into one `Status::health_facts()` provider. Site Health, REST `/health` and the dashboard render the same facts.
- Split that provider into four high-signal Site Health tests: connection/namespace, eviction policy/Redis version, drop-in state, cron schedules. Add a schema contract test for the REST payload.
- Add a tested REST error-code table and one response example per route, not a full OpenAPI document.
- Defer `class-console.php` on logged-in front-end requests to `admin_bar_menu` or admin-only load. Measure bootstrap CPU for anonymous, subscriber and admin requests before and after.
- Document the trusted Redis boundary and the shared slow-log policy; add a test for the shared `403`.
- Keep the existing chart controls; if the CSS refresh touches them, prove keyboard and reduced-motion behavior.

Acceptance: four Site Health results; REST schema test; logged-in bootstrap CPU measured and lower; shared slow-log 403 test; no operation regresses more than 1% on the 1.5.1 harness.

## 1.8.0 — measured performance and Relay gate

- Measure key memory before any format work: sample 10,000 real keys, report median/p95 object-key bytes, Redis per-key overhead, average value bytes, and the key share of `used_memory`. Current object keys are about 200 bytes for `default` and 231 on Cluster; the old "190" figure was the shortest group.
- Profile cold/write paths (`set`, `get_multiple`, key build) with the existing 30-block protocol and report p50/p95 and peak memory. Improve only what the profile proves.
- Publish encoding presets and measured trade-offs for PHP/igbinary and none/LZF/LZ4/ZSTD.
- Relay gate before cosmetic work: two PHP processes, one Relay node, stop the node, assert bounded fallback, no stale read after recovery, and a named pass threshold. If the environment cannot prove it, mark Relay failover unsupported and remove any parity implication.
- Move the unit suite ahead of 2.0: codec, config validation, key layout, REST validation and the health provider. Keep the assert style with a coverage checklist, or add PHPUnit as a dev-only dependency and name the threshold.

Acceptance: key-memory sample published; performance change reproduced on the protocol; Relay gate passes or is explicitly unsupported; unit suite covers the 2.0 dependencies.

## 1.9.0 — dashboard refresh, if measured

Implement `design.md` as a CSS-first refresh. Keep the existing markup and JavaScript except for semantic wrappers. This is not a standalone cosmetic release; it follows 1.7/1.8 so the same PHP file is not rewritten twice.

- Six-state status component covering the dashboard widget.
- Shared token layer for `#harborcache` and `#harborcache_status`.
- Destructive button treatment, pending/disabled/invalid states, table scroll focus, chart no-JS fallback.
- Screenshots at 1440, 1100, 1024, 782, 600 and 375 for all tabs and the widget.
- Keyboard, 200% zoom, reduced-motion and no-JS checks; update `tests/console-render.php` if class tokens change.

Acceptance: no behavior change; full matrix green; Plugin Check clean; screenshots reviewed at every listed width.

## 2.0.0 — key format, one strategy

Pick one strategy, not three. The recommended path is a layout-signature bump with a measured cold start, not dual-read.

- Inventory every key family: object keys, root, owner, epochs hash, group registry, prefetch manifests, analytics, split-options keys, cleanup queues. The owner key has no TTL; the runbook must cover it.
- Bump the layout signature and accept a cold start. Define rollback as "old plugin serves misses, not corruption".
- No `FLUSHDB`; no cross-site or cross-group collision.
- Add a mixed-version test: two app servers on 1.8 and 2.0, no stale read after either writes, and a measured hit-rate drop.
- Gate the change on the measured premise: keys are a material share of memory (for example >30%) and a v2 saves more than 50 bytes/key. If the sample does not support it, do not ship 2.0; spend the effort on the logged-in bootstrap and codec path instead.

Acceptance: key-family inventory; mixed-version test; bounded cold-start hit-rate drop; rollback produces cold misses, not errors; no data loss claim beyond the cache contract.

## Backlog and known limits

| Item | Severity | Plan |
| --- | --- | --- |
| Reverse order boots two managers | High | 1.6.2 arbitration guard |
| Key length/memory | High, measured | 1.8 sample, 2.0 migration |
| Cold/write CPU | Medium | 1.8 profile |
| Relay cross-process/failover | High for Relay users | 1.8 gate or unsupported |
| Granular Site Health | Medium | 1.7 one provider |
| REST app-password/multisite tests | Medium | 1.6.2 |
| Destructive-route rate limit | Medium | 1.6.2, outside rotated root |
| Legacy `keys` strategy | Medium | Document and deprecate |
| `unserialize` trust boundary | Medium | 1.6.2 warning + docs |
| Logged-in console cost | Medium | 1.7 measured deferral |
| Unit-test breadth | Medium | 1.8 before 2.0 |

## Release gates

Every release: 12 offline suites, PHP lint, 14-job matrix, Plugin Check on the exact ZIP, source/ZIP parity, updated validation record, and a clean readme with no competitor comparison. WordPress.org submission follows those gates. Performance claims follow `docs/PERFORMANCE-PLAN.md`.
