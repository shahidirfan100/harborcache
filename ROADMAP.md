# HarborCache roadmap

Current release: 1.8.1 (hot-path patch). Source of truth for behavior: `docs/FEATURES.md`; design rules: `design.md`; audit record: `docs/DESIGN-REVIEW-1.6.1.md`; performance protocol: `docs/PERFORMANCE-PLAN.md`.

## Principles

1. Fix safety and correctness before adding surface area.
2. Every performance claim uses the existing measured protocol, not one runner sample.
3. Key-format work is a migration with a measured premise, a rollback and a mixed-version test.
4. The WordPress.org package excludes development docs, tests, media and third-party product references.
5. One release, one reason.

## 1.6.x — close the current line

1.6.1 shipped the REST hardening; 1.6.2 shipped the safety follow-up. 1.6.3 shipped the audit fixes:

- Cache values are signed with an HMAC; unsigned `V1` values are rejected instead of deserialized.
- REST flushes reserve their slot atomically in Redis, so parallel requests cannot exceed the budget.
- Arbitration guard: another manager's constants or drop-in stop HarborCache before it registers admin UI, REST routes or drop-in writes, in either activation order.
- The REST network check covers admin-post `flush-global` and cross-site `flush-site`.
- HTTP application-password and multisite REST scope tests run in CI.
- A per-user destructive-route limiter lives outside the rotated namespace: 1/minute, 10/hour, `429` with `Retry-After`; a successful flush does not reset it.
- A startup warning appears when a remote Redis endpoint is unencrypted or has no password, because object values are unserialized.

Acceptance: both-order test passes; one admin surface; limiter test passes; app-password and multisite tests pass; no PHP fatal; Plugin Check clean.

## 1.7.0 — operator visibility (shipped)

`watch`, `digest`, `aggregate` and `/health` already existed; 1.7.0 improves their shared data source and coverage.

- One `Status::health_facts()` provider; Site Health, REST `/health` and the dashboard use the same facts.
- Four Site Health tests (connection, Redis server, drop-in, cron) and a REST payload contract check.
- A tested REST error-code table and one response example per route, not a full OpenAPI document.
- Admin-only console load; the measured bootstrap comparison remains open.
- `docs/SECURITY.md`; the shared slow-log 403 test remains in the acceptance suite.
- The existing chart controls stay; if the CSS refresh touches them, prove keyboard and reduced-motion behavior.

Acceptance: four Site Health results; REST schema test; logged-in bootstrap CPU measured and lower; shared slow-log 403 test; no operation regresses more than 1% on the 1.5.1 harness.

## Competitive gap and win condition

The category has an established free engine with roughly 500,000 installs and a commercial Relay-first tier. HarborCache already ships the broad free feature set: Relay, compression, prefetch, split options, atomic and asynchronous group flushing, analytics, REST, WP-CLI, Site Health and signed values.

The gaps are distribution, key memory, cold/write CPU, Relay behaviour at scale, test breadth and support. Feature parity is not the goal. The goal is measurable speed and reliability:

| Measure | Target |
| --- | --- |
| WooCommerce p50/p95 TTFB | Within 2% of the best measured engine on the same run, with raw data |
| Cold/write PHP CPU | Within 2% of the free baseline |
| Runtime hits | Keep or improve the measured 22–23% lead |
| Median object key | Below 120 bytes, or a measured key share below 20% of memory |
| Relay failover | No stale read after node stop/restart; bounded fallback |
| Test breadth | At least 500 assertions on critical paths; race and mixed-version tests |
| Distribution | WordPress.org listing, host guide and support policy |

## 1.8.0 — prove the hot path (shipped)

1.8.1 adds a raw-key fast path for repeated forced reads and writes; the benchmark result is recorded in the 1.8.1 validation.

- Shipped: same-run benchmark job; key-byte and memory-share sampling added to every benchmark record.
- Shipped: 10,000-key sampling with median/p95/max bytes, Redis key count and estimated memory share.
- Profile `set`, `get_multiple`, key build, codec and options split with the 30-block protocol; fix only the measured top three.
- Publish encoding presets and measured trade-offs for PHP/igbinary and none/LZF/LZ4/ZSTD.
- Shipped: cross-process Relay invalidation gate; node stop/restart certification remains in 2.0.
- Shipped: codec, config, conflict, status and benchmark coverage. Key-layout and race suites remain open.

Acceptance: key-memory sample published; cold/write CPU within 2%; no p95 regression; cross-process Relay gate passes or is explicitly unsupported; migration dependencies covered.

## 1.9.0 — CPU, prefetch and concurrency

The 1.8 benchmark sampled 30 runs: free keys were 46–48 bytes, HarborCache keys 199–201 bytes, and the estimated key share of `used_memory` was about 10%. That is below the 20% migration gate, so key format v2 is deferred until a production sample justifies it. 1.8.1 cut the CPU ratio from 1.28 to 1.15 and brought cold/write within 6%; bulk reads remain 1.64x.

- Close the remaining bulk-read gap: 1.8.1 is 1.149 CPU, 1.038 forced-read and 1.058 write; bulk-100 is 1.641. Profile `get_multiple` key build and decode, then fix only the measured top cost without weakening object signing.
- Batch prefetch and priming so repeated reads use fewer Redis round trips; prove it with command counts, not claims.
- Add race tests for concurrent options, counters, WooCommerce stock/orders and invalidation. Fix the shared helpers, not each call site.
- Revisit key format only if a 10,000-key production sample shows a >20% key share; otherwise keep the 199-byte keys and spend the work on CPU.
- Measure the encoding matrix and publish the best default for latency and memory.

Acceptance: cold/write CPU within 2% of the free baseline or a documented, security-required exception; no p95 regression; no stale read in race tests; prefetch saves measured round trips; key format decision recorded.

## 2.0.0 — scale and launch

- Certify Relay cross-process and failover, or state the unsupported combinations. Add cluster/Sentinel failover tests.
- Add a multi-server and mixed-version deployment runbook.
- Submit to WordPress.org, publish the host integration guide and a support policy, and provide an importer for existing Redis cache installations.
- Add performance regression thresholds to CI and publish the benchmark artifacts.

Acceptance: clean WordPress.org review; install-from-directory smoke test; no regression beyond 1% on the harness; failover produces no stale read.

## 2.1.0 — dashboard and onboarding

Implement `design.md` as a CSS-first refresh. Keep the existing markup and JavaScript except for semantic wrappers. This follows the measured releases so the same PHP file is not rewritten twice.

- Six-state status component covering the dashboard widget.
- Shared token layer for `#harborcache` and `#harborcache_status`.
- Destructive button treatment, pending/disabled/invalid states, table scroll focus, chart no-JS fallback.
- First-run checklist: connection, drop-in, namespace, codec and Relay.
- Screenshots at 1440, 1100, 1024, 782, 600 and 375 for all tabs and the widget.
- Keyboard, 200% zoom, reduced-motion and no-JS checks; update `tests/console-render.php` if class tokens change.

Acceptance: no behavior change; full matrix green; Plugin Check clean; screenshots reviewed at every listed width.

## Backlog and known limits

| Item | Severity | Plan |
| --- | --- | --- |
| Reverse order boots two managers | High | Shipped in 1.6.2 |
| Key length/memory | Medium, measured | 1.8 sample: ~10% key share; not justified at the 20% gate |
| Cold/write CPU | High, measured | 1.8 measured +28% PHP CPU; 1.9 fix |
| Relay cross-process/failover | High for Relay users | Gate in 1.8; failover certification in 2.0 |
| Granular Site Health | Medium | Shipped in 1.7.0 |
| REST app-password/multisite tests | Medium | Shipped in 1.6.2 |
| Destructive-route rate limit | Medium | Shipped in 1.6.2 |
| Legacy `keys` strategy | Medium | Document and deprecate |
| `unserialize` trust boundary | Medium | Fixed by signed values in 1.6.3 |
| Logged-in console cost | Medium | Shipped admin-only load in 1.7.0; measurement open |
| Unit-test breadth | High | 1.8 before the key migration |
| WordPress.org distribution | High | 2.0 launch and host guide |
| Race-condition coverage | High | 1.9 concurrent tests |

## Release gates

Every release: 13 offline suites, PHP lint, 14-job matrix, Plugin Check on the exact ZIP, source/ZIP parity, updated validation record, and a clean readme with no third-party product comparison. WordPress.org submission follows those gates. Performance claims follow `docs/PERFORMANCE-PLAN.md`.
