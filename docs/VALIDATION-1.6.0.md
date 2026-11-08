# HarborCache 1.6.0 validation

Source commit: `95ca050`. Release archive: `dist/harborcache-1.6.0.zip`, 603 files, SHA256 `02e1f0de099cb9ae2f673a69f97eb18031eec005d1569279fd21fb6b0d7689d9`. The build verifier passed source and ZIP parity. No key-format or configuration change.

## What changed

1.6.0 adds an authenticated REST API for monitoring and scoped maintenance.

- `GET harborcache/v1/status` returns the cache status without the connection endpoint.
- `GET harborcache/v1/health` returns the Site Health result as plain text.
- `GET harborcache/v1/analytics?window=7200&interval=300` returns the sampled summary.
- `GET harborcache/v1/groups` returns the bounded group registry.
- `GET harborcache/v1/groups/{scope}/{group}` returns group diagnostics, optionally with memory.
- `GET harborcache/v1/slowlog` returns command names, timestamps and durations, not arguments.
- `GET harborcache/v1/relay` returns Relay status, memory and adaptive detail without raw endpoint statistics.
- `POST harborcache/v1/flush` flushes a group or a `site`, `global`, `all` scope.
- The capability is the same one that opens Settings (`manage_options`, or `manage_network_options` on multisite), filterable through `harborcache_manager_capability`.

A bounded `group_list()` reader was added so the group list never issues `KEYS`; group diagnostics still uses bounded `SCAN` and optional `MEMORY USAGE`.

The Pro artifact was inspected only for behavior and architecture. No Pro source, names, comments or assets were copied.

## Linux matrix

Run [42455720013](https://github.com/shahidirfan100/harborcache/actions/runs/42455720013) passed all fourteen jobs. Plugin Check 2.1.0 on the exact ZIP reported `Success: Checks complete. No errors found.` and the report gate reported no errors or warnings.

New live REST assertions passed in every WordPress job:

- status, health, analytics, groups, slow log and Relay respond
- status hides the connection endpoint
- scoped flush responds and invalidates its group
- unauthenticated requests are rejected

## Local checks (Windows, PHP 8.5.10)

| Suite | Result |
| --- | --- |
| 12 offline suites | 0 failures |
| `group_list()` registry assertion | passed |
| PHP syntax, all plugin files | 0 failures |
| `python tools/build-release.py --verify` | 603 files, source/ZIP parity passed |

## Limits

- REST authentication is WordPress authentication: cookie plus REST nonce, or an application password. No separate token scheme is issued.
- The API exposes no settings update route; settings remain in the protected admin form.
- The API does not expose raw Redis endpoint statistics.
- Cross-process Relay invalidation and live Relay/Cluster/Sentinel failover remain external gates.
