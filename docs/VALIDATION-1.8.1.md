# HarborCache 1.8.1 validation

Validated code commit: `227184b`. PR: #19. Release archive: `dist/harborcache-1.8.1.zip`, 606 files, SHA256 `fa4d07fc79c3743bf87108a74c2779f871d236e6cebc0850451478a2c9a02793`. The build verifier passed source and ZIP parity.

## What changed

1.8.1 is the hot-path patch after the 1.8.0 measurement. No key format, settings key, REST route or dashboard layout changed.

- A raw key/group map now returns the derived key for repeated forced reads and writes without sanitizing, hashing and rebuilding it again.
- The layout predicate is shared by `build_key()` and `fast_build_key()`, and the raw map clears with every existing namespace/layout reset.
- Object-bearing payloads remain signed `V2`; plain arrays and scalars use `V1` and are accepted only when they contain no object. Unsigned object payloads are rejected.

## Local checks

| Check | Result |
| --- | --- |
| PHP lint on changed files | 0 failures |
| 13 offline suites | 0 failures |
| `python tools/build-release.py` | 606 files, distribution checks passed |
| Source and ZIP parity | passed |

## CI

Run [43592044967](https://github.com/shahidirfan100/harborcache/actions/runs/43592044967) passed all 14 jobs on `227184b`, including the cross-process Relay gate (`{"ok":true,"first":"v1","second":"v2"}`).

## Measured results (30-run medians, full lane)

| Metric | HarborCache 1.8.1 |
| --- | ---: |
| PHP CPU | 105.94 ms |
| Forced-read p95 | 1.165 ms |
| Write p95 | 1.257 ms |
| Bulk-100 p95 | 0.408 ms |
| Median object key | 199 bytes |
| Estimated key share of memory | 10.4% |

The 1.8.0 release recorded its own medians. 1.8.1 adds the raw-key, verified-payload and decoded-value caches; bulk reads remain the outlier and move to 1.9.

## Limits

- A raw-key map saves repeated builds; unique cold keys still build once.
- Object-bearing values still pay the HMAC cost; plain arrays and scalars use the cheaper V1 envelope.
- Key format remains ~199 bytes. The sample put the key share at about 10.4%.
