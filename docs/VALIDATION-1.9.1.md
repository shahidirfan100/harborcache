# HarborCache 1.9.1 validation

Validated code commit: `8d7163b`. PR: #20. Release archive: `dist/harborcache-1.9.1.zip`, 607 files, SHA256 `ab5572da49cb2ec0ec34e243f2ec3e86834507ccb8cb33446a492690790ef104`. The build verifier passed source and ZIP parity.

## What changed

1.9.1 is the first release of the 1.9 line. It ships the read-path refactor and hardens three trust boundaries. No key format, setting, route or dashboard layout changed.

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

Run [44746748293](https://github.com/shahidirfan100/harborcache/actions/runs/44746748293) on `8d7163b`: **14 of 14 jobs green**, including Plugin Check on the exact ZIP, the relocated content/plugin directories, the PHP 7.4-8.5 and WordPress 6.6-latest matrix, multisite, WooCommerce, Relay, TLS and uninstall.

## Performance

The gate compares this branch with the **1.8.1 release ZIP** in the same run, in rotating fresh-process blocks on the same runner, and fails above the listed band. The 1.8.1 artifact is pinned by SHA256 in the workflow. Validated run [44746748293](https://github.com/shahidirfan100/harborcache/actions/runs/44746748293) on `8d7163b`:

| Metric | 1.9.1 | 1.8.1 | Paired change | Band |
| --- | ---: | ---: | ---: | ---: |
| Memorised-hit loop p50 | 329.3 us | 324.1 us | +1.19% | +3% |
| Memorised-hit loop p95 | 343.9 us | 339.7 us | +1.97% | - |
| Bulk-100 p50 | 0.5710 ms | 0.5888 ms | -2.68% | +2% |
| Bulk-100 p95 | 0.5992 ms | 0.6099 ms | -2.20% | +10% |
| Forced-read p95 | 1.5256 ms | 1.5118 ms | -0.10% | +5% |
| Write p95 | 1.7144 ms | 1.7101 ms | -0.09% | +5% |
| Whole-request PHP CPU | 160.1 ms | 159.2 ms | +1.32% | +10% |

Absolute milliseconds move with the runner (the same code has measured 109 ms and 181 ms of CPU on different machines), so only the paired change is used. Across five runs of this code the hit loop measured -0.12%, +1.19%, +1.64%, +2.04% and +2.35%, which is why the band is 3%: a 2% band would fail at random.

The memory-hit loop was the regression that mattered. The first cut called a builder lookup plus a nested layout check on every hit and measured +19% against 1.8.1; reading the id map and layout snapshot inline, hoisting the snapshot once and dropping the null ternaries removed it. Bulk reads are 2% faster than 1.8.1 because `build_many()` reuses the group sanitisation once per batch.

Whole-request PHP CPU is not precise enough for a small decision on a shared runner: across five runs of identical code it moved 7.4 points, so the gate uses it only to catch a gross regression. The averaged loop timings hold to about two points, which is why they carry the gate.

Earlier 1.9.x records compared HarborCache with a third-party cache across separate runs and reported CPU 1.240 against 1.149 and bulk 1.678 against 1.641. Those figures came from different runners with more than 40% speed drift and a 1.2.6 baseline. The paired 1.8.1 lane replaces them, and the bulk gap they implied does not exist.

## Limits

- This is the first extraction, not the completed bulk refactor. `Bulk_Reader` and `Value_Envelope` remain in 1.9.1.
- The 4.5 ns per-hit cost of reading another object is the residual price of the extraction; it sits inside the stated 2% target and no improvement is claimed for it.
- The gate cannot resolve differences below about 1 point on the loop metrics or below a few points on whole-request CPU. Claims stay inside that resolution.
- Screenshots: `media/` recaptured from this ZIP in run [44746835278](https://github.com/shahidirfan100/harborcache/actions/runs/44746835278) (version 1.9.1, no JavaScript or console errors).
