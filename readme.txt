=== HarborCache – Object Cache with Redis ===
Contributors: shahidirfan100
Tags: redis, object cache, caching, performance, diagnostics
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.3
License: GPL-3.0-only
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Persistent Redis object caching with scoped invalidation, local analytics, connection settings and diagnostics.

== Description ==

HarborCache is a GPLv3 fork of Redis Object Cache 3.0.0 by Till Krüss and contributors, with original cache management and administration features. It requires a separately operated Redis server. No account, subscription or license key is required. Request analytics stay in your configured Redis namespace. External tracing is disabled by default.

This is the stable HarborCache release. It does not claim complete Object Cache Pro feature parity. See docs/FEATURES.md for verified configurations and deployment acceptance gates.

Features include persistent caching through Predis, PhpRedis, Relay or Credis; multisite group scopes; bounded TTLs; atomic namespace invalidation; bulk cache operations; typed values; optional serialization and compression; optional split-alloptions storage; request key prefetching; local sampled analytics; WP-CLI; protected administration forms; Site Health; Query Monitor; and Debug Bar support.

Advanced additions include bounded background reclamation, exact integer counters, safe-read retries/backoff, replica/Sentinel/cluster read routing, logging/tracing adapters, custom cache/connector factories, analytics retention/count/flush-persistence controls and shared-server capacity reporting. See docs/ADVANCED.md and the feature ledger for native-extension acceptance gates and API differences.

The dashboard includes Overview, Analytics, Connection, Cache Groups, Tools and Diagnostics. Configuration is saved to a guarded PHP file in wp-content. Configuration constants take precedence. Passwords and cached values are excluded from diagnostic exports.

HarborCache connects to the Redis endpoint you configure. It does not contact the original plugin vendor. Optional tracing must be explicitly configured and uses your host application's New Relic or OpenTelemetry integration and destination. Plugin-provided spans contain command names, timings and bounded datastore metadata; they omit cached keys, values, credentials and endpoint details. Configure retention and privacy in your provider or collector. No tracing SDK, remote account or external destination is enabled by default.

Redis is a registered trademark of Redis Ltd. No affiliation or endorsement is implied.

== Installation ==

1. Upload the harborcache folder to your plugins directory and activate it.
2. Open Settings > HarborCache, or Network Admin > Settings > HarborCache.
3. Configure your Redis host, port, database and credentials on the Connection tab.
4. Save configuration, then choose Enable Object Cache on the Overview tab.
5. Verify Connected status and Site Health. Test on staging before production.

Disable any other object-cache plugin first. HarborCache refuses to overwrite another plugin's object-cache.php. Removing the drop-in does not flush unrelated Redis data.

For advanced connection configuration, TLS and migration guidance, see docs/CONFIGURATION.md.

== Frequently Asked Questions ==

= Does this include a Redis server? =
No. Your host must provide Redis or you must operate a Redis server.

= Is a license key required? =
No. All included local features are available without a license key.

= Is this Object Cache Pro? =
No. It uses the separate GPL Redis Object Cache engine with original additions. Proprietary Pro code, vendor services and support are not included.

= Does flush delete other applications' data? =
HarborCache's flush changes its namespace tokens. Old entries expire within the maximum TTL. It does not call FLUSHDB or FLUSHALL.

= Can I move an existing Pro cache into this plugin? =
Do not reuse its keys. Configure a distinct prefix and retire the previous drop-in through its owning plugin. The cache data formats are different.

== Screenshots ==

1. Overview: cache status, request metrics and lifecycle controls.
2. Analytics: local sampled request history.
3. Connection and cache configuration.
4. Cache groups and scoped invalidation.
5. Tools and server observability.
6. Redacted diagnostics.
7. WordPress dashboard charts and statistics widget.

== Changelog ==

= 1.1.2 =
* Added verified drop-in staging, update locking, backup restoration and interrupted-update recovery.
* Fixed per-option TTL coordination, missing-field recovery and primary pinning for multi-key writes.
* Reset cached topology discovery on safe-read reconnects without discarding request write history.
* Added cleanup leases, abandoned-job recovery, bounded deletion batches and on-demand maintenance scheduling.
* Fixed dashboard notice placement, settings section headings, readable typography and matching 44px controls.
* Kept chart labels readable across widths; added an accessible data table, sampling rate and last sample time.
* Separated runtime and Redis-store hit counters, fixed cached false/null bulk-hit accounting, and rejected malformed telemetry.
* Explained unavailable extensions and reduced repeated admin file checks and unrelated-page scripts.

= 1.1.1 =
* Reduced runtime-hit, derived-key and disabled-instrumentation overhead with bounded request caches.
* Preserved namespace formats and invalidation; added memoization boundary checks.
* Fixed native pipeline namespace/filter isolation and unintended TLS for TCP cluster connections.
* Added native PhpRedis/Relay primary-replica and Sentinel discovery connectors.
* Tested real PhpRedis standalone, cluster, replica and Sentinel behavior, and real OpenTelemetry OTLP HTTP export.
* Published same-workload Redis Cache 3.0.0 comparisons and explicit remaining deployment gates.

= 1.1.0 =
* Removed plugin protected admin actions; management and exports use protected WordPress admin actions.
* Fixed the obsolete drop-in update notice/action and added safe owned-drop-in refresh.
* Added time-window analytics, percentiles, interval summaries and CSV export.
* Added on-demand group memory/counts, node latency, operational health and Relay adaptive diagnostics.
* Added Relay option strings, explicit failure modes, optional query recycling and cooperative cleanup budgets.
* Cached configuration per request and added native New Relic datastore instrumentation.
* Added CLI analytics, digest, aggregate, watch, health and latency commands; included an MU loader and Composer package metadata.
* Standardized connected status and Connect/Disconnect button dimensions.

= 1.0.2 =
* Enlarged dashboard widget text and responsive chart axes.
* Added protected Disconnect and Connect controls beside the widget and plugin connection status.
* Disconnect disables this installation’s persistent cache while retaining connection settings and leaving Redis running.
* Reconnecting rotates only this installation’s cache namespace to avoid reusing entries made stale while disconnected. Old entries expire under the configured maximum TTL.

= 1.0.1 =
* Added teal area charts for Time, Bytes, Ratio and Calls, plus latest/average/peak values in the home dashboard widget and Overview.
* Removed the account/license sentence from the dashboard footer.

= 1.0.0 =
* Added a dashboard statistics widget with request metrics and a bounded recent-sample summary; no background polling.
* Limited dashboard analytics transfer to the latest 100 samples and connection discovery to Redis server information.
* Hardened configuration, namespace failures, atomic drop-in replacement, corrupt payload reads, exact integer counters and concurrent split-alloptions updates.
* Fixed Credis loading/commands and Predis cluster routing; verified single-server and three-node cluster operation.
* Added background cleanup, selectable flush strategies, safe-read retries, primary/replica routing, Sentinel promotion tests and Relay native-option adapters.
* Added analytics persistence/count controls, strict ownership, shared capacity reporting, extension factories and redacted logging/tracing.
* Renamed the initial developer build based on Redis Object Cache 3.0.0.
* Original dashboard, scoped invalidation, typed cache values, configuration, diagnostics and REST controls.

== Upgrade Notice ==

= 1.1.2 =
Correctness, update recovery and dashboard readability fixes. Refresh the owned drop-in after installing. Existing connection settings and key namespace format are preserved.

= 1.1.1 =
Performance and native-client fixes. Update the owned object-cache drop-in after installing. No server settings or key namespace format changes are required.

= 1.1.0 =
Consolidated operational update. Plugin REST routes are removed. The owned drop-in refreshes automatically for authorized administrators, or use Update drop-in.

= 1.0.2 =
Larger widget text and Connect/Disconnect controls. Update the HarborCache drop-in after upgrading.

= 1.0.1 =
Stable release with a WordPress dashboard statistics widget and bounded analytics reads. Update the HarborCache drop-in after upgrading.
