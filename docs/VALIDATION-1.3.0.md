# HarborCache 1.3.0 validation

Runtime commit: `14fd3aa`. Release archive: `dist/harborcache-1.3.0.zip`, 602 files, SHA256 `260d0656817c6c91fc097af60365f2c3ef3a2619ed3c8249f2a692e086c81337`. The build verifier passed source and ZIP parity. GitHub Actions run [41831513936](https://github.com/shahidirfan100/harborcache/actions/runs/41831513936) passed all ten jobs.

## What changed

1.3.0 separates transport health from cache readiness. Transport, namespace ownership, value codec and drop-in currency are reported separately, and only a fully ready cache is labelled “Connected”. A foreign prefix owner is reported as “Namespace conflict” with a confirmed reclaim action; owners written by 1.2.x migrate automatically, so rotating security salts no longer disables caching. The connection check now uses the configured timeout, reads ownership without writing, round-trips a value through the active codec and reports each check separately. A new self-test exercises `wp_cache_set/get/delete` through the live drop-in.

The release also loads administration code only for admin, AJAX, cron, CLI, WP-CLI and logged-in admin-bar requests; records sampled analytics without serializing every cached value; trims analytics on the hourly maintenance event; decodes values without installing an error handler per value; and removes the inherited no-op hook layer and duplicate status paths.

## Linux CI matrix

Run [41831513936](https://github.com/shahidirfan100/harborcache/actions/runs/41831513936) passed all ten jobs: PHP 7.4, 8.2, 8.3, 8.4 and 8.5; Redis 6, 7 and 8; WordPress 6.6 and latest; Predis, PhpRedis and Credis; PHP/igbinary with none/LZF/LZ4/Zstandard; ACL, trusted TLS, rejected certificate and Unix socket; concurrent workers; Redis stop/start; WooCommerce; multisite; PHP-FPM HTTP smoke and restart; Relay default/adaptive; relocated ZIP, Plugin Check and package parity.

## Interface capture

Run [41832984245](https://github.com/shahidirfan100/harborcache/actions/runs/41832984245) captured the five screens and the widget from the exact 1.3.0 ZIP on WordPress 7.1.4, PHP 8.3, PhpRedis 6.3.0 and Redis 7.4.12. The capture reported zero console errors, zero width/size failures and no PHP notices on the served pages. The Status screen now shows the readiness checklist; Settings labels the stored result as “Last manual test” and places Save/Test/Self-test on one action row.

## Equal-workload comparison

Artifact `linux-performance-applications`, 30 rotating blocks per engine, full-plugin and engine lanes, native PhpRedis, PHP 8.3.36, WordPress 7.1.4. Median paired changes against Redis Object Cache 3.0.0:

| Measurement | Full plugin | Engine only |
| --- | ---: | ---: |
| Operation PHP CPU | **+2.6%** | +5.0% |
| Bootstrap PHP CPU | +4.0% | +2.7% |
| Bootstrap wall | +5.1% | +3.5% |
| 1,000 request-memory reads, p50 | **−15.4%** | **−14.4%** |
| 20 forced Redis reads, p50 | +0.8% | +2.8% |
| 100-key bulk read, p50 | +7.6% | +7.1% |
| 20 writes, p50 | +4.1% | +5.6% |
| 20 misses, p50 | +1.9% | +3.4% |
| Peak PHP allocation | 0% | +5.9% |

The previous 1.2.9 full-plugin operation CPU result was +12.8% against the same free plugin. This run is one shared-runner sample, so the paired direction and the large memory-read improvement are the reliable signals; the 5% engine-only target is not claimed as passed. The benchmark is CLI, so it does not measure the anonymous front-end lazy-loading change; the full-plugin lane still loads the management layer.

## Local checks (Windows, PHP 8.5.10)

| Suite | Result |
| --- | --- |
| `php tests/status-matrix.php` | 0 failures (state matrix, legacy owner migration, conflict transport) |
| `php tests/probe-parity.php` | 0 failures (configured timeout, conflict, legacy, reclaim) |
| `php tests/safety-regressions.php` | 21 passed |
| `php tests/review-regressions.php` | 9 passed |
| `php tests/dropin-writer.php` | 12 passed |
| `php tests/connection-check.php` | passed (offline checks; live Redis checks run in CI) |
| `php tests/metrics-failure.php` | passed |
| `php tests/conflict-notice.php` | passed |
| `python tools/build-release.py --check` | 602 files, distribution checks passed |
| `php -l` over changed PHP files | no syntax errors |

## CI gates

The run passed the Linux matrix (PHP 7.4–8.5, Redis 6–8, WordPress 6.6/latest, Predis/PhpRedis/Credis/Relay, ACL/TLS/socket, cluster/Sentinel, WooCommerce, multisite, FPM) plus the new status/probe suites. The live `tests/connection-check.php` scenario wrote a foreign owner, verified that the probe reported transport but not readiness, reclaimed it and confirmed a passing probe. The relocated-ZIP review job also ran Plugin Check on the exact release archive without errors.

## Limits

- The local Windows host has no Redis server, Docker or WSL, so live Redis behaviour is certified by GitHub Actions, not this machine.
- Native Cluster/Sentinel failover and sustained production traffic remain separate gates.
- The translation template is regenerated by the release workflow; new strings fall back to English until that run completes.
