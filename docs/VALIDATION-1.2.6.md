# HarborCache 1.2.6 validation

Baseline: HarborCache 1.2.5, commit `44cf7cfdb1593172d530d0917fd19f8349863364`. This report is updated as the release candidate is verified; pending gates are not passing results.

## Local checks completed

- The initial focused fault fixtures reproduced ten failures on the unmodified baseline.
- Seventeen targeted safety checks now pass, including ownership preservation in startup/probe, corrupt/malformed metadata, denied/false optional INFO replies, critical-option routing, nested Relay bypass state, native socket discard, network identity and log behavior.
- Twelve existing drop-in recovery checks, nine fresh-process review regressions and the conflict-notice check pass.
- PHP 8.5 syntax checks passed for the current includes and test files. No warnings or deprecations were emitted by the targeted checks after adapting test-only reflection calls for PHP 8.5.
- The rebuilt ZIP passed local WordPress 7.1.3 / PHP 8.4.26 installation, activation and Plugin Check without findings. The test uses relocated content/plugins directories and verifies the installed drop-in before and after a plugin-directory rename; both sets of seven runtime assertions pass. Deactivation and uninstall preserve a foreign drop-in. Expected offline logs contain only the exact concise backend message; unexpected warnings or traces fail the check.
- All 40 tracked dashboard/media assets match 1.2.5 byte-for-byte. Exact package verification checks 599 packaged files against source.
- Plugin Check's successful process exit did not reliably indicate a clean report. The new report gate rejects reported errors and warnings, and rejects missing/unrecognized output. Actual findings were corrected before the clean rerun.

## Gates pending verification

The Linux Relay/concurrency gate and final complete CI run remain pending. The initial runs exposed a test registration error for global site-options groups and an unexpected Relay fallback; neither partial run is a full acceptance pass. The fixture now mirrors WordPress's global-group registration, and disposable Relay diagnostics fail at the original backend error. Final comparative measurements will be recorded after the runtime is finalized.

## Scope and evidence limits

The dashboard and media are intentionally preserved. This update does not include the later guided-setup workflow or independent engine. Native read-disconnect tests include controlled faults and must not be described as exhaustive network-partition certification. Optional diagnostics remain subject to server ACLs. Normal fallback logging is bounded per PHP request, not across requests, hosts or worker pools.
