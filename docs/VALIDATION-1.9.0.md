# HarborCache 1.9.0 validation

Validated code commit: this PR head. PR: pending. Release archive: `dist/harborcache-1.9.0.zip`, 607 files, SHA256 `bb970c830ba27690b6f174f39c66d1aee511f7be9e9ee6a3a044330937b8c510`. The build verifier passed source and ZIP parity.

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

## Limits

- This is the first extraction, not the completed bulk refactor. `Bulk_Reader` and `Value_Envelope` remain in 1.9.1.
- No performance improvement is claimed until the same-harness results are in.
- Screenshots: `media/` recaptured from this ZIP in run 44061096834 (version 1.9.0).
