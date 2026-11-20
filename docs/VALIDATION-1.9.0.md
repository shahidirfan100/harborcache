# HarborCache 1.9.0 validation

Validated code commit: `8db4043`. PR: #20. Release archive: `dist/harborcache-1.9.0.zip`, 607 files, SHA256 `7e1834287f47e8d898a0c1961f73af2be86a07570e8d7c2e88d1a453d2fbb0bc`. The build verifier passed source and ZIP parity.

## What changed

1.9.0 begins the read-path refactor. No key format, setting, route or dashboard layout changed.

- `HarborCache\Key_Builder` now owns layout signatures, group/scope prefixes, request-local derived/prefix/raw/id caches and layout resets.
- `HarborCache_Advanced_Cache` delegates `build_key()`, `fast_build_key()`, cached ids, layout checks and resets to it.
- `WP_Object_Cache::get_multiple()` now calls a protected `build_keys()` seam. The advanced cache uses `Key_Builder::build_many()`.
- `get()` reads the builder id map and layout snapshot inline, and returns no differently than 1.8.1.
- Added `tests/key-builder.php` and the architecture note `docs/ARCHITECTURE-1.9.md` (excluded from the ZIP).

## Security review

Three hardening changes came out of a source review of the trust boundaries:

1. **Cache namespace from a request header.** `wp_cache_init()` accepted `X-App-User` as `WP_REDIS_PREFIX` on Cloudways-style hosts. `Config::apply()` already defines the prefix from the settings file before that branch runs, so it was not reachable in a normal install, but the value is inbound. It now passes `Config::prefix_candidate()`, which accepts only `[A-Za-z0-9_-]{1,64}`.
2. **Unbounded prefetch manifests.** With `prefetch` enabled, every cacheable GET wrote a manifest keyed by user, host and URI. Anonymous requests could create one key per distinct URL for 24 hours. Manifests are now written only for signed-in visitors, expire after 3600 seconds and track at most 250 entries.
3. **Metrics growth without cron.** Retention and count trimming run on the `harborcache_metrics_discard` event. One sampled write in a hundred now calls `discard()` directly, so the sorted set stays bounded on hosts where WP-Cron never runs.

`tests/security-guards.php` covers the slug rules, the anonymous gate, the signed-in path, the manifest lifetime and the on-write trim. `tests/status-matrix.php` asserts the prefetch gate rather than the old blanket GET rule.

## Local checks

| Check | Result |
| --- | --- |
| PHP lint | 0 failures |
| 15 offline suites | 0 failures |
| `python tools/build-release.py` | 607 files, distribution checks passed |
| `python tools/readiness.py` | 46 checks, 0 failed |
| `tests/key-builder.php` | 8 assertions, 0 failed |
| `tests/security-guards.php` | 11 assertions, 0 failed |

## CI

Run [44076600511](https://github.com/shahidirfan100/harborcache/actions/runs/44076600511) on `8db4043`: **14 of 14 jobs green**, including Plugin Check on the exact ZIP, the relocated content/plugin directories, the PHP 7.4-8.5 and WordPress 6.6-latest matrix, multisite, WooCommerce, Relay, TLS and uninstall.

## Performance

The gate compares this branch with the **1.8.1 release ZIP** in the same run, in rotating fresh-process blocks on the same runner, and fails above the listed band. The 1.8.1 artifact is pinned by SHA256 in the workflow.

| Metric | 1.9.0 | 1.8.1 | Paired change | Band |
| --- | ---: | ---: | ---: | ---: |
| Memorised-hit loop p50 | 259.4 us | 254.9 us | +1.64% | +2% |
| Memorised-hit loop p95 | 276.8 us | 271.8 us | +1.43% | - |
| Bulk-100 p50 | 0.5266 ms | 0.5395 ms | -2.34% | +2% |
| Bulk-100 p95 | 0.5511 ms | 0.5609 ms | -2.58% | +10% |
| Forced-read p95 | 1.5299 ms | 1.5708 ms | -1.58% | +5% |
| Write p95 | 1.5928 ms | 1.5955 ms | +0.70% | +5% |
| Whole-request PHP CPU | 109.3 ms | 111.6 ms | -1.15% | +10% |

The memory-hit loop was the regression that mattered. The first cut called a builder lookup plus a nested layout check on every hit and measured +19% against 1.8.1; reading the id map and layout snapshot inline, hoisting the snapshot once and dropping the null ternaries brought it to +1.6% (4.5 ns per `wp_cache_get` hit). Bulk reads improved by 2.3% over the same period, which is the `build_many()` seam reusing the group sanitisation once per batch.

Whole-request PHP CPU is not precise enough for a 2% decision on a shared runner: across five runs of identical code it moved 7.4 points, so the gate uses it only to catch a gross regression. The averaged loop timings hold to about one point across runs, which is why they carry the gate.

Earlier 1.9.0 records compared HarborCache with a third-party cache across separate runs and reported CPU 1.240 against 1.149 and bulk 1.678 against 1.641. Those figures came from different runners with more than 40% speed drift and a 1.2.6 baseline. The paired 1.8.1 lane replaces them, and the bulk gap they implied does not exist.

## Limits

- This is the first extraction, not the completed bulk refactor. `Bulk_Reader` and `Value_Envelope` remain in 1.9.1.
- The 4.5 ns per-hit cost of reading another object is the residual price of the extraction; it sits inside the stated 2% target and no improvement is claimed for it.
- The gate cannot resolve differences below about 1 point on the loop metrics or below a few points on whole-request CPU. Claims stay inside that resolution.
- Screenshots: `media/` recaptured from this ZIP in run 44061096834 (version 1.9.0).
