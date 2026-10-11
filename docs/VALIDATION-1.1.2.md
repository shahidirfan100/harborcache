# HarborCache 1.1.2 release review

1213 assertions passed, zero failed. 580 PHP files passed PHP 7.4 syntax parsing. Plugin Check with experimental checks and five initialized runtime performance checks reported no findings. The exact readme passed the official validator with no errors/warnings and only the optional donation-link note.

Package: 610 files, 727879 bytes. Source, locally installed plugin and extracted ZIP hashes match; the active owned fixture drop-in also matches. ZIP CRC checks passed.

SHA256: d3dcabe15942525de460d92943f8044d72e8977827d743d656c85f1da2a45ed3

## Critical review closure

| Finding | 1.1.2 outcome |
|---|---|
| Failed drop-in replacement removes old file | Verified staging, locking, previous-copy restoration, retained backup and interrupted-update recovery. Direct and simulated remote failures tested. Remote fallback replacement is recoverable, not promised atomic. |
| Per-option expiry mismatch | Unchanged field TTLs refresh; missing fields invalidate manifest and request snapshot. Actual Lua and real Predis primary/replica transport tested. |
| Incomplete write pinning | Every Lua/delete key is tracked; forced per-option reads after writes use primary. |
| Stale discovery after reconnect | Cached slot, replica/native client and concentration state reset; request write history retained. Real failover matrix remains open. |
| Notices split header | Native heading outside identity/control row; a real WordPress notice fixture stayed outside it. |
| Font/control/settings inconsistency | Unified heading scale and readable labels; matching connection controls; 44px inputs/buttons; grouped checkboxes and aligned sampling fields. |
| Chart scaling/metric ambiguity | 14px rendered axis labels, keyboard metric tabs, accessible table, last sample time and sampling disclosure; separate runtime/store counters and corrected false/null bulk hit accounting. |
| Cleanup efficiency/durability | Bounded batches/pipelines, recoverable leases, atomic claim/ack, queue diagnostics and on-demand cron. Cluster slot safety and abandoned-job recovery tested. |
| Administration/telemetry overhead | Request-memoized file checks, separate admin-bar script, idle-worker retirement and malformed sample rejection. Real native New Relic profiling remains an acceptance gate. |

## Validation scope

Fresh tests cover Predis/Credis/native PhpRedis, standalone/cluster/replica/Sentinel paths, cache types/counters/bulk behavior, namespace isolation, lifecycle/uninstall, upgrade first request with a 1.1.1 drop-in, failed file writes, cleanup lease recovery, protected admin actions, settings save, disconnect/reconnect, nonce guards, real local OpenTelemetry delivery and responsive UI. Dashboard tabs were checked at 320/375/768/1440px. Sampling panels, axis scaling, notice coexistence and matching buttons passed.

This is not exhaustive coverage of every PHP/extension/application combination. Local environment: Windows, WordPress 7.1.3 with SQLite, PHP 8.5.10, Redis 5.0.14.1, native PhpRedis 6.3.0.

## Same-workload performance

Seven rotating rounds compared Redis Cache 3.0.0, packaged HarborCache 1.1.1 and HarborCache 1.1.2. Analytics/prefetch/split options were off. Below are median elapsed milliseconds, excluding WordPress bootstrap. No operation median regressed more than 5% versus 1.1.1; the largest measured increase was 1.43%. Small differences should be treated as desktop measurement noise.

| Client | Workload | 1.1.1 ms | 1.1.2 ms | Change |
|---|---|---:|---:|---:|
| predis | runtime_get_10000 | 9.49 | 9.48 | -0.08% |
| predis | redis_get_1000 | 105.36 | 105.46 | 0.09% |
| predis | bulk_100_keys_100 | 89.24 | 87.77 | -1.64% |
| predis | set_1000 | 130.40 | 129.98 | -0.32% |
| predis | miss_1000 | 94.33 | 94.76 | 0.46% |
| phpredis | runtime_get_10000 | 9.54 | 9.35 | -2.01% |
| phpredis | redis_get_1000 | 103.49 | 103.13 | -0.35% |
| phpredis | bulk_100_keys_100 | 80.70 | 79.96 | -0.91% |
| phpredis | set_1000 | 127.64 | 128.90 | 0.99% |
| phpredis | miss_1000 | 92.57 | 93.89 | 1.43% |

Median measured-phase PHP process CPU: Predis 312.5 → 296.875 ms; PhpRedis 281.25 → 265.625 ms. Windows accounting is coarse and excludes Redis CPU. Free Redis Cache still wins several basic workloads. No claim of production CPU reduction or Pro performance parity is made. The final mixed-version counter declarations and admin-only adjustments do not change the benchmark algorithms.

## Remaining gates and version plan

- Linux/MySQL and every advertised PHP runtime need real acceptance. PHP 7.4 syntax is not runtime certification.
- Per-option algorithm and primary pinning passed real Predis primary/replica tests; native Relay invalidation/adaptive concurrency remains unverified.
- Topology reset is fixed; controlled native promotion/partition recovery still requires a suitable staging environment.
- TLS/ACL/socket, real codecs, New Relic agent behavior/duplicate instrumentation and production collectors remain deployment gates.
- True multisite/application flows, long-running load and production PHP/Redis CPU remain unverified.
- Windows benchmark CPU excludes Redis server CPU and does not predict production throughput.
- WordPress.org Plugin Check/readme validation do not grant directory approval or trademark clearance.
- No package was uploaded to autoscareer.com; its latest verified live version remains 1.1.1.

1.1.2 delivers code/UX stabilization. 1.2.0 closes real native extension, codec, monitoring and failover acceptance; 1.3.0 adds independently measured selective query cleanup and further performance work; 1.4.0 expands application/multisite/upgrade operational evidence. Public REST/helper APIs remain excluded.

## Install

Back up the current plugin ZIP and configuration, upload harborcache-1.1.2.zip, then visit HarborCache. Keep existing connection settings. Let the owned drop-in refresh or use Update drop-in. Confirm version 1.1.2 and Connected in Diagnostics, then exercise normal frontend/publishing/login/options/cron flows. No update or outage test was performed on autoscareer.com.

## Antislop delivery gate

PASS — Existing teal/system-font direction retained; no new decorative assets or unsupported statistics.
PASS — All six tabs fit 320/375/768/1440px; sampling fields and heading sizes passed computed-layout checks.
PASS — Connection controls match and inputs/buttons meet the 44px minimum in the updated settings UI.
PASS — Keyboard chart selection, accessible chart table, focus rules, empty/disconnected states and actionable error handlers verified by browser and PHP suites.
PASS — Text color pairs tested with the skill contrast utility: ink/white 14.53:1, muted/white 6.85:1, teal/white 7.28:1, connected status 8.63:1 and warning status 7.88:1.
PASS — Updated settings submit correctly; disconnect/reconnect preserves configuration and foreign Redis data; notices stay outside the header; no browser JavaScript exceptions.
PASS — Visual hierarchy has functional purposes: native heading anchors WordPress notices; grouped settings separate safety/performance/observability; chart area expresses sampled values; no ornamental hero or additional navigation structure.
PASS — Claims are limited to recorded local evidence; no fabricated testimonials, CPU guarantees, external account claims or directory approval. Remaining native/Linux/production gates are explicit.
