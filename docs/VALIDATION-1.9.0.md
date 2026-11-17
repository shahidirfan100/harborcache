# HarborCache 1.9.0 validation

Validated code commit: `eba8076`. PR: #20. Release archive: `dist/harborcache-1.9.0.zip`, 607 files, SHA256 `9aab315d2a7170cdf18e9c1493c5dcb99564892b0f1c743d4bcdf4f401706023`. The build verifier passed source and ZIP parity.

## What changed

1.9.0 begins the read-path refactor. No key format, setting, route or dashboard layout changed.

- `HarborCache\Key_Builder` now owns layout signatures, group/scope prefixes, request-local derived/prefix/raw/id caches and layout resets.
- `HarborCache_Advanced_Cache` delegates `build_key()`, `fast_build_key()`, cached ids, layout checks and resets to it.
- `WP_Object_Cache::get_multiple()` now calls a protected `build_keys()` seam. The advanced cache uses `Key_Builder::build_many()`.
- The runtime-hit path reads `ids`/`layout_state` inline; the callback form cost two calls per memory hit (see `docs/ARCHITECTURE-1.9.md`).
- Added `tests/key-builder.php` and the architecture note `docs/ARCHITECTURE-1.9.md` (excluded from the ZIP).

## Local checks

| Check | Result |
| --- | --- |
| PHP lint | 0 failures |
| 14 offline suites | 0 failures |
| `python tools/build-release.py` | 607 files, distribution checks passed |
| `python tools/readiness.py` | 46 checks, 0 failed |
| `tests/key-builder.php` | 8 assertions, 0 failed |

## CI

`acceptance.yml` runs 14 jobs: the 14-offline-suite review job, the exact-ZIP relocation and Plugin Check job, and the WordPress matrix plus integrations. This tree is green on all 14.

## Performance

The release gate compares this branch with the **1.8.1 release ZIP** in the same run, in rotating fresh-process blocks, and fails at +5% PHP CPU, +5% cold/write p95 or +10% bulk-100 p95.

Four runs measured the paired change against 1.8.1:

| Run | Code | PHP CPU | Cold p95 | Write p95 | Bulk-100 p95 |
| --- | --- | ---: | ---: | ---: | ---: |
| [44071186199](https://github.com/shahidirfan100/harborcache/actions/runs/44071186199) | pre-fix | +2.89% | -0.31% | +1.47% | -0.89% |
| [44072188766](https://github.com/shahidirfan100/harborcache/actions/runs/44072188766) | runtime ids inlined | +0.92% | +2.07% | +0.88% | -2.44% |
| [44072819770](https://github.com/shahidirfan100/harborcache/actions/runs/44072819770) | runtime ids inlined | -2.20% | +0.95% | -0.17% | -3.17% |
| 44073910576 | runtime ids inlined | +4.27% | +0.55% | +1.03% | -3.44% |

The three runs of identical code span 6.5 points of PHP CPU, and per-block ratios inside one run ranged from -21.6% to +37.7%. The shared runner, not the plugin, sets that spread: this harness catches a gross regression and cannot resolve a 2% difference. **No performance improvement or regression is claimed for 1.9.0.** Cold, write and bulk sit inside the same band.

What the work did change is visible on a local measurement of the hot path: the first implementation performed two nested calls per runtime hit (a builder lookup plus a layout check). Inlining the layout comparison against the builder fields measured 354.6 ns/op against 353.9 ns for the 1.8.1 inline shape, versus 401.1 ns for the call form. That is a code-path reduction, not a claim about the CI number.

Earlier 1.9.0 records compared HarborCache against a third-party cache across separate runs and reported CPU 1.240 against 1.149 and bulk 1.678 against 1.641. Those figures came from different runners with more than 40% speed drift and a 1.2.6 baseline. The paired 1.8.1 lane replaces them, and the bulk gap they implied does not exist in a same-run comparison.

## Limits

- This is the first extraction, not the completed bulk refactor. `Bulk_Reader` and `Value_Envelope` remain in 1.9.1.
- The gate detects material regressions only. A sub-5% claim would need more blocks or a quieter runner, and is not made.
- Runtime-hit ids are compared against public `ids`/`layout_state` fields, which is a documented hot-path seam rather than full encapsulation.
- Screenshots: `media/` recaptured from this ZIP in run 44061096834 (version 1.9.0).
