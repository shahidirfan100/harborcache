# HarborCache 1.2.0 validation record

The 1.2.0 release build was validated locally and in [successful Linux acceptance run 38550596065](https://github.com/shahidirfan100/harborcache/actions/runs/38550596065), branch commit `7b47d37`. The plugin implementation is at `07e7cbb`; later commits added bounded transport probes and evidence. The repository is private.

## Local regression and dashboard

The Windows fixture used WordPress 7.1.3, PHP 8.5.10, PhpRedis 6.3.0 and isolated Redis 5.0.14.1. The local server is an extra compatibility smoke test; the CI matrix covers Redis 6, 7 and 8.

The final local acceptance run passed **72 checks** with zero failures. Coverage includes cache values and expiration, bulk operations, replacement from fresh workers, stable namespace keys, concurrent counters and independent alloptions patches, bounded cleanup, WordPress options, publishing, post invalidation and cron callbacks. PHP lint passed for all 22 included PHP files and `tests/acceptance.php`; `git diff --check` passed.

Nine desktop and mobile dashboard screenshots were refreshed without visible plugin-version text. Browser review found no JavaScript errors or horizontal overflow; Connect and Disconnect measured equally at 144 × 44 pixels, and chart, settings and widget labels remained readable at tested viewports.

## GitHub Actions

The final run passed all eight WordPress integration jobs and the application integration job. The matrix covers PHP 7.4 and 8.2–8.5, Redis 6–8, WordPress 6.6 and the current WordPress release, with MySQL 8. It exercises Predis, PhpRedis and Credis, supported PHP/igbinary serializers and available LZF/LZ4/Zstandard codecs, concurrent workers, Redis outages and restarts, plugin lifecycle, cron and application flows.

Focused transport probes passed for Predis and PhpRedis ACL authentication, trusted TLS, rejection of an untrusted certificate and Unix sockets. Each successful transport also verified connect, write, fresh-client replacement and read-after-write, and delete. The focused probes keep each transport result bounded and separate from the full 72-check regression suite.

The integration job passed the real WooCommerce and multisite flows. PHP-FPM served 160 successful requests at concurrency eight, and the counter survived an FPM restart. Relay 0.50.2 passed **75 checks under its default policy and 75 under its adaptive policy**, including fresh-client namespace agreement, forced read/bulk-read bypass, concurrent option patches, four-process counters, bounded reclamation and WordPress lifecycle checks. These are short functional checks, not prolonged production traffic or Relay topology-promotion certification.

The same run produced the seven-round performance comparison and group-flush probe in [the comparison report](COMPARISON-1.2.0.md). Raw benchmark and transport logs are attached to the Actions run.

## Installable package and WordPress checks

The 1.2.0 release ZIP passed CRC and extraction checks at release time, contained 626 install files, excluded development tests and media, and matched the 1.2.0 working tree. Its SHA-256 was `d16097047da54588fdf5ac06f4b045836cdcc487a81c742b3253e1bb8054de76`. The old ZIP is no longer kept in `dist/`; that folder contains only the current install package.

Plugin Check 2.1.0 reported **no errors** for the extracted package using its actual WordPress.org slug. The official WordPress.org readme validator reported no errors or warnings; it displayed only the optional informational note that no donate link was found.

Passing these checks supports a WordPress.org submission but does not mean the plugin has been approved or guarantee every host-specific behavior. The package was not installed, upgraded or load-tested on autoscareer.com during this work.

## Remaining production gates

Run a controlled staging deployment on the intended server with its actual Redis service, PHP extensions and application plugins. Measure sustained CPU, memory, latency tails and cache churn, and test the site's payment gateway, queues, cron and upgrade/rollback path. Exercise Relay cluster/Sentinel promotion and network partitions with the real topology, and verify New Relic/OpenTelemetry delivery to the intended agents. WordPress.org review and update distribution remain external decisions.
