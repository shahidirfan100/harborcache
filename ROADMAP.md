# HarborCache roadmap

Current release: 1.7.0 (operator visibility). Source of truth for behavior: `docs/FEATURES.md`; design rules: `design.md`; audit record: `docs/DESIGN-REVIEW-1.6.1.md`; performance protocol: `docs/PERFORMANCE-PLAN.md`.

## Principles

1. Fix safety and correctness before adding surface area.
2. Every performance claim uses the existing measured protocol, not one runner sample.
3. Key-format work is a migration with a measured premise, a rollback and a mixed-version test.
4. The WordPress.org package excludes development docs, tests, media and third-party product references.
5. One release, one reason.

## 1.6.x — close the current line

1.6.1 shipped the REST hardening; 1.6.2 shipped the safety follow-up. 1.6.3 ships the audit fixes:

- Cache values are signed with an HMAC; unsigned `V1` values are rejected instead of deserialized.
- REST flushes reserve their slot atomically in Redis, so parallel requests cannot exceed the budget.
- Arbitration guard: another manager's constants or drop-in stop HarborCache before it registers admin UI, REST routes or drop-in writes, in either activation order.
- The REST network check now covers admin-post `flush-global` and cross-site `flush-site`.
- HTTP application-password and multisite REST scope tests run in CI.
- A per-user destructive-route limiter lives outside the rotated namespace: 1/minute, 10/hour, `429` with `Retry-After`; a successful flush does not reset it.
- A startup warning appears when a remote Redis endpoint is unencrypted or has no password, because object values are unserialized.

Acceptance: both-order test passes; one admin surface; limiter test passes; app-password and multisite tests pass; no PHP fatal; Plugin Check clean.

## 1.7.0 — operator visibility (shipped)

`watch`, `digest`, `aggregate` and `/health` already existed; 1.7.0 improves their shared data source and coverage.

- Shipped: one `Status::health_facts()` provider; Site Health, REST `/health` and the dashboard use the same facts.
- Shipped: four Site Health tests (connection, Redis server, drop-in, cron) and a REST payload contract check.
- Add a tested REST error-code table and one response example per route, not a full OpenAPI document.
- Shipped: admin-only console load; measured bootstrap comparison remains open.
- Shipped: `docs/SECURITY.md`; the shared slow-log 403 test remains in the acceptance suite.
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
| Reverse order boots two managers | High | Shipped in 1.6.2 |
| Key length/memory | High, measured | 1.8 sample, 2.0 migration |
| Cold/write CPU | Medium | 1.8 profile |
| Relay cross-process/failover | High for Relay users | 1.8 gate or unsupported |
| Granular Site Health | Medium | Shipped in 1.7.0 |
| REST app-password/multisite tests | Medium | Shipped in 1.6.2 |
| Destructive-route rate limit | Medium | Shipped in 1.6.2 |
| Legacy `keys` strategy | Medium | Document and deprecate |
| `unserialize` trust boundary | Medium | Fixed by signed values in 1.6.3 |
| Logged-in console cost | Medium | Shipped admin-only load in 1.7.0; measurement open |
| Unit-test breadth | Medium | 1.8 before 2.0 |

## Release gates

Every release: 12 offline suites, PHP lint, 14-job matrix, Plugin Check on the exact ZIP, source/ZIP parity, updated validation record, and a clean readme with no third-party product comparison. WordPress.org submission follows those gates. Performance claims follow `docs/PERFORMANCE-PLAN.md`.
