# HarborCache 1.9.0 validation

Validated code commit: `8fa52c9`. PR: #20. Release archive: `dist/harborcache-1.9.0.zip`, 607 files, SHA256 `5f65858c16e1d90dc2e6ef79a07bd8b6c023d3f8c99c1464636ff0a25c3f914e`. The build verifier passed source and ZIP parity.

## What changed

1.9.0 begins the read-path refactor. No key format, setting, route or dashboard layout changed.

- `HarborCache\Key_Builder` now owns layout signatures, group/scope prefixes, request-local derived/prefix/raw/id caches and layout resets.
- `HarborCache_Advanced_Cache` delegates `build_key()`, `fast_build_key()`, cached ids, layout checks and resets to it.
- `WP_Object_Cache::get_multiple()` now calls a protected `build_keys()` seam. The advanced cache uses `Key_Builder::build_many()`.
- Added `tests/key-builder.php` and the architecture note `docs/ARCHITECTURE-1.9.md` (excluded from the ZIP).

## Local checks

| Check | Result |
| --- | --- |
| PHP lint | 0 failures |
| 14 offline suites | 0 failures |
| `python tools/build-release.py` | 607 files, distribution checks passed |
| Source and ZIP parity | passed |

## CI

Pending. The acceptance matrix covers runtime-hit invalidation, Relay invalidation, multisite, counters and the existing REST paths; the performance job records whether key/cold/write/bulk medians changed.

## Measured results

30-run ratios against the pinned reference:

| Metric | 1.9.0 | 1.8.1 |
| --- | ---: | ---: |
| PHP CPU | 1.240 | 1.149 |
| Forced-read p95 | 1.084 | 1.038 |
| Write p95 | 1.162 | 1.058 |
| Bulk-100 p95 | 1.678 | 1.641 |

The profile shows the new `Key_Builder` and `runtime_id` layers add measurable CPU. The architecture and readiness tests pass, but the release does not meet the no-regression gate.

## Limits

- This is the first extraction, not the completed bulk refactor. `Bulk_Reader` and `Value_Envelope` remain in 1.9.1.
- No performance improvement is claimed. The 1.9.0 extraction regressed CPU; merge is on hold until 1.9.1 restores the 1.8.1 ratio or the extraction is reverted.
- Screenshots: `media/` recaptured from this ZIP in run 44061096834 (version 1.9.0).
