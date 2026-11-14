# HarborCache 1.8.1 validation

Validated code commit: this PR head. PR: pending. Release archive: `dist/harborcache-1.8.1.zip`, 606 files, SHA256 `5619cdba83e295cd04260853358ec866b7b7745424ac99173a1a9d2c9f4e870b`. The build verifier passed source and ZIP parity.

## What changed

1.8.1 is the hot-path patch after the 1.8.0 measurement. No key format, settings key, REST route or dashboard layout changed.

- A raw key/group map now returns the derived key for repeated forced reads and writes without sanitizing, hashing and rebuilding it again.
- The layout predicate is shared by `build_key()` and `fast_build_key()`, and the raw map clears with every existing namespace/layout reset.
- The security signature path is unchanged: values remain signed `V2`, and unsigned values are still rejected.

## Local checks

| Check | Result |
| --- | --- |
| PHP lint on changed files | 0 failures |
| 13 offline suites | 0 failures |
| `python tools/build-release.py` | 606 files, distribution checks passed |
| Source and ZIP parity | passed |

## CI

Pending. The performance job will report whether the raw-key fast path changes the 30-run PHP CPU and cold/write medians.

## Limits

- A raw-key map saves repeated builds; unique cold keys still build once.
- The HMAC verification path is unchanged; any remaining gap against the unsigned free baseline includes that security cost.
- Key format remains ~199 bytes. The 1.8.0 sample put the key share at about 10%, below the 20% migration gate.
