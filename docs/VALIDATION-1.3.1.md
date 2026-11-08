# HarborCache 1.3.1 validation

Runtime commit: `d4c4253`. Release archive: `dist/harborcache-1.3.1.zip`, 602 files, SHA256 `bc169a026c8244bc42de6695cd066a32c5d5686bef032cddb44465ddc2af0bf6`. The build verifier passed source and ZIP parity. GitHub Actions run [41863046060](https://github.com/shahidirfan100/harborcache/actions/runs/41863046060) passed all fourteen jobs.

## What changed

1.3.1 is a presentation and cache-busting release on top of 1.3.0. The Status page now uses a compact 2x2 readiness grid instead of full-width rows with large empty space. The manual-test card no longer repeats the readiness table; it shows client, endpoint, database, Redis version and timeout, then one line for namespace and codec. The dashboard widget adds a hit-ratio/time/commands/runtime-entries summary and uses readable chart ticks.

The plugin kept the same `1.3.0` version through several UI revisions, so browsers could keep the old `console.css` and `chart.css` cached under `?ver=1.3.0`. That produced merged labels and values and an unstyled widget. 1.3.1 versions every stylesheet and script with the file modification time as well as the plugin version, so a plugin update cannot leave stale assets in place.

## Linux CI matrix

Run [41863046060](https://github.com/shahidirfan100/harborcache/actions/runs/41863046060) passed all fourteen jobs: PHP 7.4, 8.2, 8.3, 8.4 and 8.5; Redis 6, 7 and 8; WordPress 6.6, 6.7, 6.8, 7.0, 7.1 and latest; Predis, PhpRedis and Credis; PHP/igbinary with none/LZF/LZ4/Zstandard; ACL, trusted TLS, rejected certificate and Unix socket; concurrent workers; Redis stop/start; WooCommerce; multisite; PHP-FPM HTTP smoke and restart; Relay default/adaptive; relocated ZIP; and package parity. Plugin Check 2.1.0 on the exact ZIP reported `Success: Checks complete. No errors found.`

## Interface capture

Run [41863077701](https://github.com/shahidirfan100/harborcache/actions/runs/41863077701) captured the five screens and the widget from the exact 1.3.1 ZIP on WordPress 7.1.4, PHP 8.3, PhpRedis 6.3.0 and Redis 7.4.12. The capture reported zero console errors, zero width/size failures and no PHP notices. It also checked four readiness items, three settings actions, no horizontal overflow, clicked Test saved connection, ran the cache self-test and tabbed to an interactive control.

## Local checks (Windows, PHP 8.5.10)

| Suite | Result |
| --- | --- |
| `php tests/status-matrix.php` | 0 failures |
| `php tests/probe-parity.php` | 0 failures |
| `php tests/safety-regressions.php` | 21 passed |
| `php tests/review-regressions.php` | 9 passed |
| `php tests/dropin-writer.php` | 12 passed |
| `php tests/connection-check.php` | passed (offline checks; live Redis checks run in CI) |
| `php tests/metrics-failure.php` | passed |
| `php tests/conflict-notice.php` | passed |
| `python tools/build-release.py --verify` | 602 files, source/ZIP parity passed |

## Performance

The cache engine is unchanged from 1.3.0, so the 1.3.0 benchmark applies: full-plugin operation PHP CPU **+2.6%** against baseline build, request-memory reads **−15.4%**, engine-only CPU **+5.0%**, bootstrap CPU **+4.0%**. See `VALIDATION-1.3.0.md` and the `linux-performance-applications` artifact for the measured table and limits.

## Limits

- The local Windows host has no Redis server, Docker or WSL, so live behaviour is certified by GitHub Actions.
- Native Cluster/Sentinel failover and sustained production traffic remain separate gates.
- Existing installations must replace the 1.3.0 ZIP with 1.3.1 (or install the new package) to receive the new assets; 1.3.1 also changes the asset URLs, so a hard refresh is not required after the update.
