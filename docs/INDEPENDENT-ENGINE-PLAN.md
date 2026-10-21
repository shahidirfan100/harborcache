# Independent engine proposal

Status: planned work for a future major release, tentatively 2.0.0. No independent engine is shipped in 1.2.5. This proposal does not change existing cache behavior or commit to a release date.

## Purpose

HarborCache was developed for the author's websites, with an emphasis on understandable cache management, scoped invalidation, recoverable updates and local diagnostics. The maintained 1.x engine is a GPLv3 fork of Redis Object Cache 3.0.0 by Till Krüss and contributors. Renaming or reorganizing that engine does not make it independently authored.

The proposed engine must be implemented from an explicit behavioral specification and documented WordPress and Redis interfaces. Existing source has already been inspected, so this is not described as a clean-room development process. Retained or adapted code must retain its applicable license and attribution. A provenance review is required before describing a replacement as independently implemented.

## Preserve the current contract

Before engine work starts, inventory the implemented behavior in FEATURES.md and CONFIGURATION.md. Record each option, default, WordPress cache operation, group rule, hook, factory, CLI command, integration, diagnostic field and lifecycle action, with its current evidence and known limitations. Optional and unverified integrations must remain explicitly labeled.

Keep the HarborCache product name, teal palette, icons, banners, typography, button dimensions, responsive tabs, charts, widget and accessible tables. Preserve the current administration controls, saved settings and credential-redaction rules. Any interface changes require a separate reviewed change.

## Work and acceptance gates

| Stage | Work | Gate before progressing |
| --- | --- | --- |
| A: specification | Capture cache API semantics, false/null found flags, object cloning, key normalization, expiry, counters, bulk operations, multisite scope and failure behavior. Specify HarborCache's additional APIs and invalidation invariants separately. | Every current feature has a documented requirement, representative contract checks and an explicit supported or deferred status. No silent feature removal. |
| B: isolated implementation | Develop the replacement behind a test-only selection mechanism. Implement client connection, value encoding, cache storage and namespace behavior in small components. | Identical correctness workloads pass against the maintained fork and replacement, including concurrent writers, numeric boundaries, corrupt values, TTLs, group changes and prefix isolation. No production engine switch. |
| C: integrations | Exercise standalone, ACL, verified TLS, sockets, replicas, Sentinel, cluster, codecs and supported native clients; test optional options splitting, prefetch, tracing and cleanup. | Real supported extensions and servers pass the relevant transport, concurrency, restart and failover cases. Stubs are contract evidence only. Unverified combinations remain unavailable or explicitly experimental. |
| D: lifecycle and migration | Specify cache-format compatibility explicitly. Prefer a new engine namespace and cold-cache rollout when old entries cannot be decoded safely. Keep settings and the previous engine recoverable. | Exact ZIP installation, activation, owned-drop-in upgrades, interrupted writes, foreign drop-in preservation, renamed directories, deactivation and rollback pass on single-site and multisite. Rollback must avoid reading incompatible cache entries. |
| E: performance | Compare maintained HarborCache, the proposed engine and a pinned upstream release under identical workloads and options. Include optional instrumentation overhead, cache churn and high concurrency. | Publish reproducible p50/p95/p99 latency, throughput, PHP/Redis CPU, command counts and peak memory. Set workload-specific budgets before measurement. Investigate material regressions rather than claiming a universal speed advantage. |
| F: staging and release | Run sustained staging traffic, application tests, outage recovery, diagnostics redaction, WordPress checks and responsive/accessibility review. | Independent review, documented residual limits, provenance/license review, a recoverable release package and an explicitly authorized rollout. WordPress.org approval remains its reviewers' decision. |

## Upstream contribution track

Classify generally applicable fixes separately from HarborCache-specific dashboard, configuration and namespace choices. For each potential contribution, check the current upstream implementation, reproduce the issue with a minimal case and prepare a focused patch following upstream guidance. Record actual submission links and outcomes; do not imply that proposals have already been submitted or accepted.

The 1.2.5 cleanup does not submit upstream patches. Its PHP changes affect comments and version headers only, so they are not presented as general upstream bug fixes.

## Immediate 1.x scope

Maintain the existing engine and compatibility hooks. Remove unnecessary competitor comparisons from current descriptions, retain accurate source attribution and historical evidence, and validate that the documentation cleanup changes neither executable PHP nor visual assets. Improvements that require behavioral changes belong in separately scoped, tested updates.
