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

Pending. The Linux performance job publishes key-byte records; the integrations job runs the cross-process Relay gate.

## Limits

- The key-byte sample is synthetic (10,000 built keys), not a live production key dump. The memory share is an estimate.
- The Relay gate proves cross-process invalidation, not node stop/restart. Failover certification remains 2.0.
- Cold/write CPU is measured by the existing benchmark, but no optimization is claimed until the same-run numbers show a reproducible gap.
- The dashboard layout is unchanged; screenshots still show the 1.7.0 capture until recaptured for 1.8.0.
