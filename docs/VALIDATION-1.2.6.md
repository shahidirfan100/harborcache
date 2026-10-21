# HarborCache 1.2.6 validation

Baseline: HarborCache 1.2.5, commit `44cf7cfdb1593172d530d0917fd19f8349863364`. Final runtime and test commit: `c2f720462d58eea223dd8bba7bfb1ed477bbe1b0`. The completed release gates below apply to that runtime and the exact ZIP, not to every possible hosting environment.

Package: `dist/harborcache-1.2.6.zip`, 599 files, SHA256 `44c4483c357cfb0260c23e3e3005385c2c4a50daecbcf395128082a7984fe2e9`.

## Local checks completed

- The initial focused fault fixtures reproduced ten failures on the unmodified baseline.
- Seventeen targeted safety checks now pass, including ownership preservation in startup/probe, corrupt/malformed metadata, denied/false optional INFO replies, critical-option routing, nested Relay bypass state, native socket discard, network identity and log behavior.
- Twelve existing drop-in recovery checks, nine fresh-process review regressions and the conflict-notice check pass.
- PHP 8.5 syntax checks passed for the current includes and test files. No warnings or deprecations were emitted by the targeted checks after adapting test-only reflection calls for PHP 8.5.
- The rebuilt ZIP passed local WordPress 7.1.3 / PHP 8.4.26 installation, activation and Plugin Check without findings. The test uses relocated content/plugins directories and verifies the installed drop-in before and after a plugin-directory rename; both sets of seven runtime assertions pass. Deactivation and uninstall preserve a foreign drop-in. Expected offline logs contain only the exact concise backend message; unexpected warnings or traces fail the check.
- All 40 tracked dashboard/media assets match 1.2.5 byte-for-byte. Exact package verification checks 599 packaged files against source.
- Plugin Check's successful process exit did not reliably indicate a clean report. The new report gate rejects reported errors and warnings, and rejects missing/unrecognized output. Actual findings were corrected before the clean rerun.

## Linux acceptance completed

[GitHub Actions run 39572042761](https://github.com/shahidirfan100/harborcache/actions/runs/39572042761) completed successfully: all ten jobs passed. The integration job log contains zero PHP warnings or fatal errors.

- Eight compatibility jobs cover PHP 7.4, 8.2, 8.3, 8.4 and 8.5; Redis 6, 7 and 8; WordPress 6.6 and the fetched latest release. These are the combinations in the workflow, not every Cartesian combination or every historical PHP release.
- Real MySQL/Redis checks cover native PhpRedis, bundled PHP clients, serializers/compressors, concurrent counters/options, outage/restart recovery, lifecycle, ACL authentication, trusted TLS, rejected untrusted certificates and Unix sockets.
- Restricted ACL tests reject INFO/SELECT permissions while verifying supported normal cache operations. Prefix-collision and corrupt-metadata tests preserve existing Redis values and verify fallback/recovery.
- A controlled divergent read node passes 13 checks each for Predis and PhpRedis: options and site-options single/bulk reads see primary values, while ordinary groups retain configured replica behavior. The fresh fixture mirrors WordPress's global-group registration.
- Real Relay passes 79 assertions for each of its ordinary/listener and adaptive configurations, with warnings converted to test failures. Its 25 reconnect checks cover database 15, reply alignment, forced reads after closing the connection and cache-mode restoration. A focused fixture also proves nested metadata reads retain the outer forced-read flag.
- WooCommerce cart/checkout, order updates, stock changes/restoration, real multisite isolation and scoped invalidation pass. The 160-request PHP-FPM smoke at concurrency eight has no failed responses; the persistent request count survives an FPM restart.
- The relocated-layout job installs the exact ZIP and passes Plugin Check through the report parser, owned drop-in update, renamed-directory recovery and uninstall checks.

Earlier incomplete runs exposed actual Relay socket/option handling problems, a nested bypass-state defect and a test registration error. Those failures were corrected and retained as checks; a run that exited successfully while emitting Relay warnings was not accepted as the final release gate. The CLI application fixture now supplies its HTTP host so WordPress application code receives the expected disposable request context.

## Reproducible comparison

Source: the final run's `linux-performance-applications` artifact. The workflow pins the 1.2.5 commit and verifies its archive checksum, installs free Redis Object Cache 3.0.0, and rotates the three engines through seven rounds on the same runner. Values below are medians of the seven per-round measurements; operation values are medians of per-round p50s, in milliseconds.

| Measurement | Redis Object Cache 3.0.0 | HarborCache 1.2.5 | HarborCache 1.2.6 |
|---|---:|---:|---:|
| 1,000 request-memory reads | 0.415 | 0.532 | 0.551 |
| 20 forced Redis reads | 2.297 | 2.380 | 2.395 |
| Bulk read of 100 keys | 0.413 | 0.479 | 0.490 |
| 20 writes | 2.289 | 2.458 | 2.480 |
| 20 repeated misses | 2.004 | 2.167 | 2.226 |
| 20 distinct cold misses | 2.042 | 2.274 | 2.305 |
| 16 KiB write/read pair | 0.257 | 0.295 | 0.296 |
| PHP CPU, measured operation workload | 199.183 | 234.553 | 242.149 |
| Redis CPU, measured interval | 154.798 | 157.704 | 157.988 |
| Redis commands, measured workload | 4,667 | 4,673 | 4,673 |
| PHP peak allocation, MiB | 34 | 36 | 36 |
| Bootstrap wall time | 165.675 | 175.288 | 174.064 |

1.2.6 uses about 3.2% more PHP CPU than 1.2.5 in this workload, with ordinary-operation p50 changes below 4%, unchanged command counts and unchanged peak PHP allocation. The extra error checks prioritize detecting unusable replies; these measurements do not establish a general speed improvement. Free Redis Object Cache is faster and uses less PHP memory on these ordinary operations. Its bootstrap excludes its admin plugin, so bootstrap values cannot establish full-plugin startup superiority.

Scoped group invalidation uses five samples at each size. For 5,000 seeded entries, free Redis Object Cache takes 8.022 ms p50 and about 5,723 commands per invalidation, versus 0.335 ms and two commands for 1.2.6. HarborCache invalidates a generation immediately and reclaims old entries separately; this does not measure total reclamation work or immediate memory release. 1.2.5 takes 0.315 ms/two commands in the same check, so 1.2.6's principal advantage here is retained bounded invalidation, not a new speed gain over its predecessor.

The HTTP smoke reports p50 307.623 ms, p95 380.285 ms and p99 389.387 ms. It is a short isolated WordPress test, not a sustained-load SLA or a free-plugin HTTP comparison. The one-off metrics-record calls measure 0.846 ms at 10% sampling and 1.838 ms at 100%; these individual observations are not percentile or per-request CPU guarantees.

The benchmark scripts and pinned versions are committed for reruns. CPU/RSS include shared Redis daemon activity; operation tests exclude bootstrap and are sequential. Runner variance and the small invalidation sample count limit significance. No arbitrary throughput or "fastest plugin" claim follows from this report.

## Scope and evidence limits

The dashboard and media are intentionally preserved. This update does not include the later guided-setup workflow or independent engine. Native read-disconnect tests include controlled faults and must not be described as exhaustive network-partition certification. This matrix does not certify real Sentinel promotions, multi-shard cluster failover or long-running production soak behavior. Optional diagnostics remain subject to server ACLs. Normal fallback logging is bounded per PHP request, not across requests, hosts or worker pools.

Local evidence: disposable fixture `work/runtime/harborcache-126-clean-1792572498/qa-evidence` (WordPress 7.1.3 / PHP 8.4.26, SQLite for the local lifecycle procedure); real MySQL/Redis evidence comes from CI. The local PC has no Redis service. The final ZIP passed local Plugin Check after the final runtime edit. Autoscareer.com was not changed or tested during this build. Automated checks support release review, but WordPress.org approval and behavior under a specific site's workload remain external acceptance steps.
