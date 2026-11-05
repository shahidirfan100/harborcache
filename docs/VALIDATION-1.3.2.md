# HarborCache 1.3.2 validation

Runtime commit: `dcd7764`. Release archive: `dist/harborcache-1.3.2.zip`, 601 files, SHA256 `2098b99b2674735089f8123a7aa0dd33fe986067b3c6df32854a7423a3593aef`. The build verifier passed source and ZIP parity. GitHub Actions run [42033643274](https://github.com/shahidirfan100/harborcache/actions/runs/42033643274) passed all fourteen jobs.

## What changed

1.3.2 closes the audit findings. It deletes the legacy Predis wrapper and its public `FLUSHDB` methods. Namespace rotation on first enable now uses the selected client and fails the enable if rotation fails, instead of silently reusing old entries. Analytics retention is checked every minute instead of every hour. Status reads endpoint, database, serializer, compression and TTL from the live cache, so a saved connection cannot be mistaken for the live one. Namespace reclaim requires a server-side `confirm=1`. Query maintenance rotates only groups whose `wp_cache_get_last_changed()` moved. The group table is capped at 100 rows, chart points render only on the visible panel, and the inherited no-op plugin methods are gone. The connection file gets a group/world-readable warning, uninstall removes older config files, and CI now includes query-pruning, status-read and uninstall checks.

## Linux CI matrix

Run [42033643274](https://github.com/shahidirfan100/harborcache/actions/runs/42033643274) passed all fourteen jobs: PHP 7.4, 8.2, 8.3, 8.4 and 8.5; Redis 6, 7 and 8; WordPress 6.6, 6.7, 6.8, 7.0, 7.1 and latest; Predis, PhpRedis and Credis; PHP/igbinary with none/LZF/LZ4/Zstandard; ACL, trusted TLS, rejected certificate and Unix socket; concurrent workers; Redis stop/start; WooCommerce; multisite; PHP-FPM HTTP smoke and restart; Relay default/adaptive; relocated ZIP; package parity; and uninstall cleanup. Plugin Check 2.1.0 on the exact ZIP reported `Success: Checks complete. No errors found.`

## Interface capture

Run [42034457600](https://github.com/shahidirfan100/harborcache/actions/runs/42034457600) captured the five screens and the widget from the exact 1.3.2 ZIP on WordPress 7.1.4, PHP 8.3, PhpRedis 6.3.0 and Redis 7.4.12. The capture reported zero console errors, zero size failures and no PHP notices. It checked four readiness items, three settings actions, no horizontal overflow, clicked Test saved connection, ran the cache self-test and tabbed to an interactive control. The PNGs in `media/` and `media-capture/` and the record in `media/screenshots.json` are refreshed.

## Local checks (Windows, PHP 8.5.10)

| Suite | Result |
| --- | --- |
| `php tests/status-matrix.php` | 0 failures, including live-config fields |
| `php tests/query-pruning.php` | 0 failures, changed-only rotation |
| `php tests/probe-parity.php` | 0 failures |
| `php tests/safety-regressions.php` | 21 passed |
| `php tests/review-regressions.php` | 9 passed |
| `php tests/dropin-writer.php` | 12 passed |
| `php tests/connection-check.php` | passed |
| `php tests/metrics-failure.php` | passed |
| `php tests/conflict-notice.php` | passed |
| `python tools/build-release.py --verify` | 601 files, source/ZIP parity passed |

## Performance

The cache engine is unchanged from 1.3.0. The measured table still applies: full-plugin operation PHP CPU **+2.6%** against Redis Object Cache 3.0.0, request-memory reads **−15.4%**, engine-only CPU **+5.0%**, bootstrap CPU **+4.0%**. See `VALIDATION-1.3.0.md` for the full table and limits. The analytics trim now runs every minute, so the sampled set is bounded sooner than in 1.3.1.

## Limits

- The local Windows host has no Redis server, Docker or WSL, so live behaviour is certified by GitHub Actions.
- Native Cluster/Sentinel failover and sustained production traffic remain separate gates.
- Query maintenance still rotates whole query groups. It no longer touches unchanged groups, but a group with any data change is rebuilt.
- `harborcache-config.php` permissions depend on the host. The warning helps, but `wp-config.php` constants remain the strongest production choice.
