# Deployment acceptance and next releases

## Version 1.1.2 stabilization

This release fixes the critical review's update, TTL, write tracking, topology reset, cleanup durability and dashboard findings. It preserves key namespace bytes. It does not claim complete Pro parity, Linux/runtime certification or lower production server CPU. See RELEASE-1.1.2.md for supported behavior and remaining gates.

## Existing integration evidence

The local suite exercises WordPress 7.1.3, PHP 8.5.10, SQLite and Redis 5.0.14.1 on Windows. Predis, Credis and real PhpRedis 6.3.0 are exercised. PhpRedis tests cover standalone values, counters, invalidation, cleanup, bulk operations, cluster, replicated reads and Sentinel discovery. Native pipeline tests include expiration filters that issue independent Redis commands. PHP 7.4 parsing confirms syntax only, not runtime compatibility.

OpenTelemetry SDK 1.15.0 with OTLP exporter 1.4.0 sends ended spans over HTTP to a local receiver. Cache operations, batch spans, datastore attributes, error status and omission of cached keys/values are checked. This is real SDK/exporter evidence; it is not evidence of a production collector, remote observability account or zero-code agent deployment. SDK/exporter dependencies belong to the host application and are not bundled or loaded by default in HarborCache. Follow the [official exporter setup](https://opentelemetry.io/docs/languages/php/exporters/).

A fresh read-only audit observed autoscareer.com connected on HarborCache 1.1.1, Predis 2.4.1, PHP 8.2.34 and Redis 7.0.15. This does not validate the 1.1.2 ZIP or measure server CPU. No live settings were changed or new package uploaded.

## Required Linux matrix

No Linux staging runtime was available for this release. The following gates remain open; do not represent them as passed:

| Dimension | Cases | Evidence required |
| --- | --- | --- |
| Database/runtime | MySQL; PHP 8.2, 8.3, 8.4, 8.5; minimum PHP 7.4 if that deployment is required | Activation, settings, upgrade, drop-in refresh, options/posts, CLI and uninstall against the packaged ZIP |
| Redis | Redis 6, 7 and later supported versions; TCP, verified TLS, ACL and Unix socket | Typed roundtrips, expiration, rejection of invalid certificate/credentials and shared namespace isolation |
| Native clients/codecs | PhpRedis; igbinary, LZF, LZ4 and Zstandard where their extensions are available | Actual extension roundtrips, absent-extension errors, large values and memory profiles |
| Recovery | Redis unavailable at boot and mid-request; Sentinel promotion; cluster replica failover | Runtime fallback, fresh-request recovery, no ambiguous-write replay and primary-only metadata |
| Concurrency | Readers/writers, option updates and invalidation across PHP workers | No cross-installation leakage, typed values and same-request read-after-write behavior; replica lag explicitly permitted |
| Performance | Supplied Redis Cache 3.0.0, HarborCache 1.1.0 and 1.1.1 under identical traffic | Rotating run order, wall latency distributions, PHP CPU, Redis CPU and memory; cold/warm caches and analytics on/off |
| Applications | Real multisite and the site's checkout, queues, cron and publishing workflows | Functional smoke checks, latency and rollback evidence |

Use isolated prefixes/databases on disposable staging. Do not run failover, outage injection, global flushes or synthetic load against a production Redis service. Keep a package/configuration backup and test restoration of the previous owned drop-in. The plugin never installs Redis or changes its daemon configuration.

## Roadmap status

| Version | Delivered or planned work | Acceptance status |
| --- | --- | --- |
| 1.1.1 | Bounded request key/prefix memoization, warm-hit acceleration, inactive tracing bypass, standalone routing shortcut; native pipeline and TCP cluster fixes; same-workload latency/PHP CPU/memory comparisons | Local functional and native PhpRedis evidence available. Linux/MySQL/TLS/ACL/socket/codecs and server CPU gates above remain open. |
| 1.1.2 | Stabilization: recoverable drop-in updates, coordinated option TTLs, all-key write pinning, topology reset, cleanup leases/batches, consistent dashboard settings and metrics | Isolated failures and local regression/layout checks; Linux and real Relay gates remain open. |
| 1.2.0 | Real Relay per-option invalidation, adaptive cache and cluster acceptance; New Relic agent export; native topology promotion/recovery and concurrent readers/writers | PhpRedis/Relay topology connector implementation and real OpenTelemetry HTTP export have landed in 1.1.1. Real Relay, New Relic, production collector and native failover acceptance remain open. |
| 1.3.0 | Selective generation-aware stale-query collection and measured prefetch tuning | Demonstrated memory reduction without premature invalidation or cross-site leakage |
| 1.4.0 | Multisite/application matrix, Composer deployment/rollback and verified update distribution | Server workflow evidence, package parity and WordPress.org review |

Relay interfaces have contract fixtures, not real-extension certification. The per-option manifest requires real Relay cross-worker invalidation and concurrent same/different-field testing. Relay topology clients apply adaptive policy and invalidation listeners to replicas as well as the primary; native extension behavior must still be checked. [Relay's event documentation](https://relay.so/docs/1.x/events) describes the corresponding APIs. New Relic's exactly-once wrapper has fixtures, not account delivery evidence.

## Installing this update on an existing site

Install the 1.1.2 ZIP and visit HarborCache. Keep the existing connection settings. Its owned outdated drop-in refreshes after a successful administrator connection check when automatic refresh is enabled; otherwise use Update drop-in. Confirm Diagnostics shows version 1.1.2 and Connected, then check front-end pages, login, publishing, options and scheduled jobs. The teal dashboard, graph widget and equal-sized connection controls remain; optional REST/helper APIs remain excluded.

Default analytics is sampled at 10%, history is bounded and no background browser polling occurs. Prefetch, query recycling, debug command recording and external tracing stay off unless explicitly enabled. Group/node probes run on demand. These defaults limit optional work but do not guarantee low server CPU for every workload. Retain the current Redis client until the operator has verified another installed extension; HarborCache cannot install PhpRedis or Relay through WordPress.
