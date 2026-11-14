# HarborCache 1.8.0 validation

Validated code commit: this PR head. PR: pending. Release archive: `dist/harborcache-1.8.0.zip`, 606 files, SHA256 `05cac83ffd1ff691d29cb0c4dd0e29cee9d4fe629561d8eefc1fb93c8d75a601`. The build verifier passed source and ZIP parity.

## What changed

1.8.0 is the measurement release for the hot path. No cache key format, REST route or settings key changed.

- Every benchmark record now includes a 10,000-key sample: median, p95 and maximum key bytes, Redis key count, estimated key bytes and estimated key share of `used_memory`.
- A cross-process Relay gate starts a second PHP process, seeds `v1`, writes `v2` from the parent, then asserts the child reads `v2` instead of a stale local value.
- Site Health now reports unavailable Redis server details as `recommended` instead of `good`.
- The benchmark and Relay evidence are uploaded with the existing CI artifacts.

## Local checks

| Check | Result |
| --- | --- |
| PHP lint on changed files | 0 failures |
| 13 offline suites | 0 failures |
| `python tools/build-release.py` | 606 files, distribution checks passed |
| Source and ZIP parity | passed |

## CI

Run [43586443485](https://github.com/shahidirfan100/harborcache/actions/runs/43586443485) passed. The Linux performance job published 30-run medians; the integrations job passed the cross-process Relay gate (`{"ok":true,"first":"v1","second":"v2"}`).

## Measured results

| 30-run median | Free baseline | HarborCache |
| --- | --- | --- |
| Key bytes (full lane) | 46 | 199 |
| Estimated key share of used_memory | 2.33% | 10.24% |
| PHP CPU for the workload | 209.25 ms | 268.44 ms |
| Forced-read p95 | 2.332 ms | 2.616 ms |
| Write p95 | 2.572 ms | 2.954 ms |

Key format v2 is not justified by this sample: the key share is about 10%, below the 20% gate. The cold/write CPU gap remains and moves to 1.9, where the profile points at the Codec HMAC path and key build.

## Limits

- The key-byte sample is synthetic (10,000 built keys), not a live production key dump. The memory share is an estimate and the free/Harbor comparison is not a production key census.
- The Relay gate proves cross-process invalidation, not node stop/restart. Failover certification remains 2.0.
- Cold/write CPU is measured by the existing benchmark, but no optimization is claimed until the same-run numbers show a reproducible gap.
- Screenshots: `media/` recaptured from this ZIP in run 43585848472 (version 1.8.0).
