# HarborCache 1.8.1 validation

Validated code commit: `227184b`. PR: #19. Release archive: `dist/harborcache-1.8.1.zip`, 606 files, SHA256 `cf062a0386e83274cfd9d8bd797823e555a8580fe6f5d437ccd72fa47d12a4d2`. The build verifier passed source and ZIP parity.

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

| Metric | Free baseline | HarborCache 1.8.1 | Ratio |
| --- | ---: | ---: | ---: |
| PHP CPU | 92.18 ms | 105.94 ms | 1.149 |
| Forced-read p95 | 1.122 ms | 1.165 ms | 1.038 |
| Write p95 | 1.188 ms | 1.257 ms | 1.058 |
| Bulk-100 p95 | 0.249 ms | 0.408 ms | 1.641 |
| Median object key | 46 bytes | 199 bytes | 4.3x |

The 1.8.0 run was 1.283 CPU, 1.122 cold and 1.148 write ratios. The 1.8.1 raw-key, verified-payload and decoded-value caches cut CPU to 1.149 and bring cold/write within 6%. Bulk reads remain the outlier at 1.641 and move to 1.9.

## Commercial comparison

No licensed commercial artifact is available in the repository or CI, so no same-run commercial comparison was run. Public third-party results are directional and are not a substitute for the licensed lane defined in the roadmap.

## Limits

- A raw-key map saves repeated builds; unique cold keys still build once.
- The HMAC verification path is unchanged; any remaining gap against the unsigned free baseline includes that security cost.
- Key format remains ~199 bytes. The 1.8.0 sample put the key share at about 10%, below the 20% migration gate.
