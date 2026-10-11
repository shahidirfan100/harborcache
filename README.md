# HarborCache

Persistent Redis object caching for WordPress, with scoped invalidation, connection management, sampled analytics, diagnostics and a dashboard statistics widget. Current release: **1.1.2**.

![HarborCache](media/banner-1544x500.png)

## Install

Download [harborcache-1.1.2.zip](https://github.com/shahidirfan100/harborcache/releases/download/v1.1.2/harborcache-1.1.2.zip), then upload it in WordPress under Plugins → Add New → Upload Plugin. The installable ZIP is also in [dist](dist/). GitHub's source archive includes development documentation and media; use the release ZIP for installation.

Requires WordPress 6.6+, PHP 7.4+ and a separately operated Redis service. Disable the existing object cache manager through its own controls first. Configure a distinct prefix in Settings → HarborCache, save the connection and enable the object cache. HarborCache refuses to overwrite another plugin's drop-in. Verify connection status and Site Health before using it on production.

For upgrades, back up configuration and the owned drop-in. Upload the latest plugin, then update the HarborCache drop-in if prompted. See [configuration](docs/CONFIGURATION.md) and [release notes](docs/RELEASE-1.1.2.md).

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

[All screenshots, logos, banners and favicons](media/) are current for 1.1.2. Images show a local test installation and demonstration chart history, not production speed measurements.

## Validation and deployment gates

The 1.1.2 validation record reports 1,213 passing assertions, PHP 7.4 syntax parsing and no findings in Plugin Check plus initialized performance checks. See [validation report](docs/VALIDATION-1.1.2.md). These are the release build's recorded checks, not a claim that every environment has been certified.

Linux/MySQL, native Relay concurrency, the full TLS/ACL/socket/codec matrix, controlled native failover, production tracing agents and production load/CPU still need acceptance testing. This release does not claim Object Cache Pro feature or performance parity, WordPress.org approval, or universal production readiness. See [acceptance gates](docs/ACCEPTANCE.md).

## License and attribution

HarborCache is a GPL-3.0-only fork of Redis Object Cache 3.0.0 by Till Krüss and contributors, with original additions by Shahid Irfan. Original notices and bundled dependency licenses are preserved. See [LICENSE.md](LICENSE.md). Proprietary Object Cache Pro source and vendor services are not included. Original media has its separate GPL-2.0-or-later license described in media/README.md. Redis is a trademark of Redis Ltd.; no affiliation or endorsement is implied.
