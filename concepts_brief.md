# HarborCache concept brief

- Author: Shahid Irfan
- Date: 2026-11-08
- Status: reviewed concept, docs only. No dashboard build.

## Overview

HarborCache is a GPL Redis object-cache plugin for WordPress. 1.6.1 has a green 14-job matrix, a clean Plugin Check, a scoped namespace, a dashboard, analytics, prefetch, split options, background reclaim, Relay diagnostics, a REST API and a WordPress.org-ready package. The next work is not more surface area. It is closing the co-installation risk, measuring the real memory and CPU costs, improving operator visibility, and only then refreshing the dashboard.

## Analysis

**Contrarian / YAGNI.** Do not add another dashboard screen or option. `watch`, `digest`, `aggregate` and `/health` already exist; improve them instead of re-adding them. The dashboard refresh is a CSS and hierarchy change, not a new product.

**Cost and quota.** The key-memory premise is unmeasured. Object keys are about 200 bytes for `default` and 231 on Cluster, not the often-quoted 190. Before any key-format work, sample 10,000 keys and report median/p95 key bytes, Redis per-key overhead, value bytes and the key share of `used_memory`. If keys are not a material share, 2.0 is not justified.

**UX and developer ergonomics.** The dashboard has the right facts but inconsistent components: three status presentations, unsafe-looking destructive buttons, missing pending/disabled states and no shared token layer for the widget. The design review corrected `design.md`; implementation waits for 1.9.

**Migration and compatibility.** The reverse activation order with the free plugin is the top risk. The current notice is not arbitration. A 1.6.2 guard must stop the second manager from registering admin UI or writing the drop-in, and a both-order CI test must prove it. The 2.0 key format is a layout-signature bump with a measured cold start, not a dual-read project.

**Observability and SRE.** Site Health is one combined result shared by three surfaces. Refactor to one `Status::health_facts()` provider, then split four tests. The destructive-route limiter must live outside the rotated root; otherwise a successful flush erases its own limiter.

**Long-term maintenance.** The repo is integration-heavy. The unit suite must land before 2.0 and cover codec, config validation, key layout, REST validation and the health provider. Keep the assert style or add PHPUnit as a dev-only dependency with a named threshold.

**Inventive catalyst.** The trade-off between namespace safety and key size is real, but it is a 2.0 decision measured against value size. The trade-off between a richer dashboard and page weight is resolved with CSS-only changes, no webfont, no framework and no new dependency.

## Goals

- 1.6.1 WordPress.org submission after the green matrix.
- 1.6.2 arbitration guard, admin-post network check, app-password/multisite REST tests, destructive-route limiter and unserialize warning.
- 1.7 one health provider, four Site Health tests, logged-in console deferral, shared slow-log test.
- 1.8 key-memory sample, cold/write profile, encoding presets, Relay gate, unit suite.
- 1.9 CSS-first dashboard refresh with the existing brand colors.
- 2.0 one key-format strategy with a measured premise and a mixed-version test.

## Non-goals

- A new UI framework, webfont, icon library or build step.
- A new cache client abstraction.
- A key-format change in 1.6.x or 1.7.x.
- Re-adding already-shipped CLI or REST features.
- Universal performance or Relay parity claims without evidence.
- Removing GPL source attribution from the license/source record.

## Alternatives weighed

- **Dashboard first:** rejected; the co-installation risk and measurement gaps are more important.
- **Key-format v2 in 1.7:** rejected; the premise is unmeasured and the migration needs a mixed-version test.
- **Dual-read key migration:** rejected until a measurement shows the cold start is worse than dual-read.
- **New CLI framework:** rejected; extend the existing WP-CLI commands.
- **SPA dashboard:** rejected; it adds weight, risk and a build step for no operator benefit.

## Decisions carried forward

1. 1.6.1 ships after the green matrix and Plugin Check; 1.6.2 is the fast security follow-up.
2. The dashboard refresh is CSS-first, keeps teal/ink, and follows the measurement releases.
3. Health becomes one provider before it becomes four tests.
4. The rate limiter lives outside the rotated namespace.
5. Key-format 2.0 is gated on a measured memory share and uses one strategy.
6. Relay failover is a named gate, not an implied feature.
