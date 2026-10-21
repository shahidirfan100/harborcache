# HarborCache 1.2.6 validation

Baseline: HarborCache 1.2.5, commit `44cf7cfdb1593172d530d0917fd19f8349863364`. This report is updated as the release candidate is verified; pending gates are not passing results.

## Local checks completed

- The initial focused fault fixtures reproduced ten failures on the unmodified baseline.
- Fifteen targeted safety checks now pass, including ownership preservation in startup/probe, corrupt/malformed metadata, denied optional INFO, critical-option routing, native socket discard, network identity and log behavior.
- Twelve existing drop-in recovery checks, nine fresh-process review regressions and the conflict-notice check pass.
- PHP 8.5 syntax checks passed for the current includes and test files. No warnings or deprecations were emitted by the targeted checks after adapting test-only reflection calls for PHP 8.5.

## Gates pending verification

Exact ZIP/source parity, local WordPress installation and Plugin Check, final branding parity, Linux native/ACL/transport/routing/reconnect matrix, application/lifecycle checks and the pinned 1.2.5 performance comparison are pending. This candidate is not represented as having passed those gates yet.

## Scope and evidence limits

The dashboard and media are intentionally preserved. This update does not include the later guided-setup workflow or independent engine. Native read-disconnect tests include controlled faults and must not be described as exhaustive network-partition certification. Optional diagnostics remain subject to server ACLs. Normal fallback logging is bounded per PHP request, not across requests, hosts or worker pools.
