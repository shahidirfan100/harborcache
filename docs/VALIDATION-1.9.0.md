# HarborCache 1.9.0 validation

Validated code commit: `c1928b7`. PR: #20. Release archive: `dist/harborcache-1.9.0.zip`, 607 files, SHA256 `5f65858c16e1d90dc2e6ef79a07bd8b6c023d3f8c99c1464636ff0a25c3f914e`. The build verifier passed source and ZIP parity.

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
| `tests/key-builder.php` | 9 assertions, 0 failed |

## CI

`acceptance.yml` runs 14 jobs: the 14-offline-suite review job, the exact-ZIP relocation and Plugin Check job, and the WordPress matrix plus integrations. This tree is green on all 14.

## Performance

The release gate compares this branch with the **1.8.1 release ZIP** in the same run, in rotating fresh-process blocks, and fails at +5% PHP CPU, +5% cold/write p95 or +10% bulk-100 p95.

Two runs of the same code measured:

| Metric | Run A | Run B | Band |
| --- | ---: | ---: | ---: |
| PHP CPU | +0.92% | −2.20% | +5% |
| Forced-read p95 | −0.31% | +0.95% | +5% |
| Write p95 | +1.47% | −0.17% | +5% |
| Bulk-100 p95 | −0.89% | −3.17% | +10% |

The spread inside one run is larger than the effect: per-block CPU ratios ranged from −21.6% to +37.7%, and identical code measured 3.1 points apart across the two runs. The harness therefore cannot resolve a 2% difference on a shared runner, and **no performance improvement or regression is claimed for 1.9.0**. Both runs sit inside the noise band of 1.8.1.

Earlier 1.9.0 records compared `HarborCache` against a third-party cache across separate runs and reported CPU 1.240 against 1.149 and bulk 1.678 against 1.641. Those figures came from different runners with more than 40% speed drift and a 1.2.6 baseline; the paired 1.8.1 comparison replaces them, and bulk is at parity.

## Limits

- This is the first extraction, not the completed bulk refactor. `Bulk_Reader` and `Value_Envelope` remain in 1.9.1.
- The gate detects material regressions only. A sub-5% claim would need more blocks or a quieter runner, and is not made.
- Runtime-hit ids are compared against public `ids`/`layout_state` fields, which is a documented hot-path seam rather than full encapsulation.
- Screenshots: `media/` recaptured from this ZIP in run 44061096834 (version 1.9.0).
