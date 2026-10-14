# HarborCache – Object Cache with Redis

Persistent Redis object caching for WordPress, with scoped invalidation, connection management, sampled analytics, diagnostics and a dashboard statistics widget. Current release: **1.1.5**.

![HarborCache](media/banner-1544x500.png)

## Install

Download [harborcache-1.1.5.zip](https://github.com/shahidirfan100/harborcache/releases/download/v1.1.5/harborcache-1.1.5.zip), then upload it in WordPress under Plugins → Add New → Upload Plugin. The installable ZIP is also in [dist](dist/). GitHub's source archive includes development documentation and media; use the release ZIP for installation.

Requires WordPress 6.6+, PHP 7.4+ and a separately operated Redis service. Disable the existing object cache manager through its own controls first. Configure a distinct prefix in Settings → HarborCache, save the connection and enable the object cache. HarborCache refuses to overwrite another plugin's drop-in. Verify connection status and Site Health before using it on production.

For upgrades, back up configuration and the owned drop-in. Upload the latest plugin, then update the HarborCache drop-in if prompted. See [configuration](docs/CONFIGURATION.md) and [release notes](docs/RELEASE-1.1.5.md).

## Features

- Predis, PhpRedis, Relay and Credis connection paths; standalone, replication, Sentinel and cluster configurations.
- Multisite cache group scopes, typed values, bulk operations and bounded TTLs.
- Namespace invalidation without FLUSHDB or FLUSHALL; bounded background reclamation.
- Optional serialization/compression, split autoloaded options and registered-key prefetching.
- Readable administration screens, equal-sized connection controls, responsive charts and accessible chart tables.
- Local sampled request analytics, separate runtime/store counters, WP-CLI, Site Health, Query Monitor and Debug Bar support.
- Protected configuration forms, redacted diagnostic exports, verified drop-in updates and recovery backups.

No license key or account is required. No plugin REST endpoints are included. Optional New Relic/OpenTelemetry adapters require explicit configuration and the host application's integration. See the [feature ledger](docs/FEATURES.md) for supported behavior and validation boundaries.

## Current screenshots

![Overview](media/screenshot-1.png)

![Dashboard statistics widget](media/screenshot-7.png)

[All screenshots, logos, banners and favicons](media/) include fresh 1.1.5 dashboard captures. The screenshots omit the version footer so the images remain useful across releases. They show a local test installation and demonstration chart history, not production speed measurements. Capture details are in [screenshots.json](media/screenshots.json).

## Validation and deployment gates

The 1.1.3 runtime validation covers bounded wildcard matching, inactive instrumentation bypass, explicit native counter results and forced Relay cache bypass. The 1.1.5 package passes Plugin Check with experimental checks, five initialized runtime performance checks, the official readme validator, PHP syntax checks and a local metadata upgrade comparison. See [1.1.5 validation](docs/VALIDATION-1.1.5.md). Real Linux/MySQL acceptance covers eight runtime combinations, native codecs, ACL/TLS/socket, concurrent writers, outage recovery, WooCommerce and multisite. Real Relay 0.50.2 and a short concurrent PHP-FPM smoke also pass. See [validation](docs/VALIDATION-1.1.3.md) and [measured comparison](docs/COMPARISON-1.1.3.md).

The Linux comparison measured less PHP CPU than 1.1.2, with mixed operation latency changes. Free Redis Object Cache 3.0.0 still uses less CPU in the measured workload. Sustained production load, native Relay topology/failover and production tracing agents remain acceptance gates. This release does not claim complete Object Cache Pro parity, WordPress.org approval or universal production readiness. See [acceptance gates](docs/ACCEPTANCE.md).

## License and attribution

HarborCache is a GPL-3.0-only fork of Redis Object Cache 3.0.0 by Till Krüss and contributors, with original additions by Shahid Irfan. Original notices and bundled dependency licenses are preserved. See [LICENSE.md](LICENSE.md). Proprietary Object Cache Pro source and vendor services are not included. Original media has its separate GPL-2.0-or-later license described in media/README.md. Redis is a trademark of Redis Ltd.; no affiliation or endorsement is implied.
