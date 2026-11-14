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

- Cache values are signed with an HMAC; unsigned `V1` object payloads are rejected.
- REST flushes reserve their slot atomically in Redis, so parallel requests cannot exceed the budget.
- Arbitration guard: another manager's constants or drop-in stop HarborCache before it registers admin UI, REST routes or drop-in writes, in either activation order.
- The REST network check covers admin-post `flush-global` and cross-site `flush-site`.
- HTTP application-password and multisite REST scope tests run in CI.
- A per-user destructive-route limiter lives outside the rotated namespace: 1/minute, 10/hour, `429` with `Retry-After`.
- A startup warning appears when a remote Redis endpoint is unencrypted or has no password.

## 1.7.0 — operator visibility (shipped)

- One `Status::health_facts()` provider; Site Health, REST `/health` and the dashboard use the same facts.
- Four Site Health tests (connection, Redis server, drop-in, cron) and a REST payload contract check.
- A tested REST error-code table and one response example per route.
- Admin-only console load; the measured bootstrap comparison remains open.
- `docs/SECURITY.md`; the shared slow-log 403 test remains in the acceptance suite.

## 1.8.0 / 1.8.1 — prove the hot path (shipped)

- 10,000-key sampling with median/p95/max bytes, Redis key count and estimated key share of `used_memory`.
- Cross-process Relay invalidation gate.
- Raw-key fast path, shared layout predicate, request-local verified and decoded payload caches.
- 30-run full-lane medians versus the free baseline: PHP CPU 1.149, forced-read 1.038, write 1.058, bulk-100 1.641. 1.8.0 was 1.283 / 1.122 / 1.148.
- Median key remains 199 bytes versus 46; key share about 10.4% versus 2.4%.

## Commercial tier gate

The category has an established free engine with roughly 500,000 installs and a commercial Relay-first tier. No licensed commercial artifact is available in CI, so HarborCache makes **no same-run commercial claim**. Public third-party numbers are directional only and are not a substitute for the lane below.

Near-commercial targets:

| Measure | Target |
| --- | --- |
| WooCommerce p50/p95 TTFB | Within 10% of the commercial lane on the same run |
| PHP CPU | Within 10% of the commercial lane; cold/write within 2% of free |
| Bulk-100 | Within 1.1x free |
| Memory | Within 1.2x commercial lane; key format decided by that comparison |
| Relay | Cross-process invalidation and node restart proven |
| Test breadth | 500+ assertions; race, mixed-version, WooCommerce and failover tests |
| Distribution | WordPress.org listing, host guide and support policy |

## 1.9.0 — close bulk and add the commercial lane

- Add a licensed commercial benchmark lane behind a CI secret. Skip cleanly when the artifact is absent. Same PHP/Redis/WordPress/WooCommerce and 30-block protocol.
- Close the remaining bulk-read gap: 1.8.1 is 1.149 CPU, 1.038 forced-read, 1.058 write and 1.641 bulk-100. Profile `get_multiple`, key build and decode; target 1.1x free bulk.
- Batch prefetch and priming so repeated reads use fewer Redis round trips; prove it with command counts.
- Decide key format v2 from measured memory versus the commercial lane, not only the 20% key-share gate. If HarborCache is more than 1.2x commercial memory, ship a versioned migration with a mixed-version test and cold-miss rollback; otherwise keep the 199-byte keys and spend the work on CPU.
- Certify Relay cross-process invalidation and node restart, or state the unsupported combinations.
- Add race tests for concurrent options, counters, WooCommerce stock/orders and invalidation.
- Grow the suite to 500+ assertions.

Acceptance: commercial lane published or explicitly unavailable; bulk-100 within 1.1x free; CPU within 10% of the commercial lane; memory decision recorded; Relay gate passes; no stale reads in race tests.

## 2.0.0 — near-commercial and launch

- Publish the same-run commercial comparison and claim only what it proves.
- Reach within 10% TTFB/CPU, at most 1.2x memory, and proven Relay failover. If the lane cannot prove a target, mark it unsupported rather than claim it.
- Add cluster/Sentinel failover tests and a multi-server, mixed-version deployment runbook.
- Add performance regression thresholds to CI and publish the benchmark artifacts.
- Submit to WordPress.org, publish the host integration guide and support policy, and provide an importer for existing Redis cache installations.

Acceptance: same-run commercial results published; targets met or explicitly unsupported; clean WordPress.org review; install-from-directory smoke test; no regression beyond 1% on the harness.

## 2.1.0 — dashboard and onboarding

Implement `design.md` as a CSS-first refresh. Keep the existing markup and JavaScript except for semantic wrappers.

- Six-state status component covering the dashboard widget.
- Shared token layer for `#harborcache` and `#harborcache_status`.
- Destructive button treatment, pending/disabled/invalid states, table scroll focus, chart no-JS fallback.
- First-run checklist: connection, drop-in, namespace, codec and Relay.
- Screenshots at 1440, 1100, 1024, 782, 600 and 375 for all tabs and the widget.
- Keyboard, 200% zoom, reduced-motion and no-JS checks.

## Backlog and known limits

| Item | Severity | Plan |
| --- | --- | --- |
| Bulk-100 CPU | High, measured | 1.9 `get_multiple` profile |
| Cold/write CPU | Medium, measured | 1.8.1 within 6% of free; 1.9 target 2% |
| Key length/memory | Medium, measured | 199 bytes, ~10% share; decide against commercial lane |
| Commercial same-run lane | High | 1.9 licensed CI lane |
| Relay cross-process/failover | High for Relay users | Gate shipped; node-restart certification in 1.9/2.0 |
| WordPress.org distribution | High | 2.0 launch and host guide |
| Unit-test breadth | High | 1.9, 500+ assertions |
| Race-condition coverage | High | 1.9 concurrent tests |
| Legacy `keys` strategy | Medium | Document and deprecate |
| Logged-in console cost | Medium | Admin-only load shipped; measurement open |

## Release gates

Every release: 13 offline suites, PHP lint, 14-job matrix, Plugin Check on the exact ZIP, source/ZIP parity, updated validation record, and a clean readme with no third-party product comparison. WordPress.org submission follows those gates. Performance claims follow `docs/PERFORMANCE-PLAN.md`.
