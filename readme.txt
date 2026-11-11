=== HarborCache – Object Cache with Redis ===
Contributors: shahidirfan100
Tags: redis, caching, performance, diagnostics
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.2
License: GPL-3.0-only
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Persistent Redis object caching with scoped invalidation, local analytics, connection settings and diagnostics.

== Description ==

HarborCache provides persistent Redis object caching with a dashboard, local analytics, connection settings and scoped cache controls. It was developed around the day-to-day needs of the author's websites, with its own administration and cache-management workflow.

It requires a separately operated Redis server. No account, subscription or license key is required. Request analytics stay in your configured Redis namespace. External tracing is disabled by default. See docs/FEATURES.md for implemented behavior, verified configurations and deployment acceptance gates.

Features include persistent caching through Predis, PhpRedis, Relay or Credis; multisite group scopes; bounded TTLs; atomic namespace invalidation; bulk cache operations; typed values; optional serialization and compression; optional split-alloptions storage; request key prefetching; local sampled analytics; an authenticated REST API; WP-CLI; protected administration forms; Site Health; Query Monitor; and Debug Bar support.

Advanced additions include bounded background reclamation, exact integer counters, safe-read retries/backoff, replica/Sentinel/cluster read routing, logging/tracing adapters, custom cache/connector factories, analytics retention/count/flush-persistence controls and shared-server capacity reporting. See docs/ADVANCED.md and the feature ledger for native-extension acceptance gates and API differences.

The dashboard includes Status, Analytics, Settings, Cache and Diagnostics. Configuration is saved to a guarded PHP file in wp-content. Configuration constants take precedence. Passwords and cached values are excluded from diagnostic exports.

== REST API ==

HarborCache exposes an authenticated REST API under `harborcache/v1`. Use cookie authentication with a REST nonce, or an application password. The same capability that opens Settings is required: `manage_options`, or `manage_network_options` on multisite. The `harborcache_manager_capability` filter can change it.

Read routes: `/status`, `/health`, `/analytics?window=7200&interval=300`, `/groups`, `/groups/{scope}/{group}`, `/slowlog` and `/relay`. `/groups` returns at most 1,000 registry entries and a `truncated` flag. `/analytics` returns at most 1,000 samples; when analytics are disabled it returns `enabled: false` and `summary: null`. `/slowlog` returns the latest 25 command names, timestamps and durations; it is disabled with HTTP 403 when the connection is marked as a shared Redis server.

The group route scope is `global` or `site-<id>`. The flush route accepts exactly one of `group=<name>` or `scope=site|site-<id>|global|all`. `scope=site` means the current site. `all` is accepted only when `network_flush` allows it and the caller is a network administrator on multisite; other cross-site scopes require the same. Sending both `group` and `scope` returns HTTP 400.

Responses omit credentials and connection endpoints. Status codes are 400 for invalid or ambiguous input, 401/403 for authentication or scope refusal, 404 for an unknown registered group, 503 when Redis is unavailable, and 500 when a flush is not confirmed. All routes require the management capability; unauthenticated requests are rejected by WordPress REST authentication.

HarborCache connects to the Redis endpoint you configure. It does not contact the original plugin vendor. Optional tracing must be explicitly configured and uses your host application's New Relic or OpenTelemetry integration and destination. Plugin-provided spans contain command names, timings and bounded datastore metadata; they omit cached keys, values, credentials and endpoint details. Configure retention and privacy in your provider or collector. No tracing SDK, remote account or external destination is enabled by default.

Redis is a registered trademark of Redis Ltd. No affiliation or endorsement is implied.

== Installation ==

1. Install HarborCache from Plugins > Add New, or upload the installable ZIP, then activate it.
2. Open Settings > HarborCache, or Network Admin > Settings > HarborCache.
3. Configure your Redis host, port, database and credentials on the Settings tab, then test the saved connection.
4. Save configuration, then enable caching on the Status tab.
5. Verify Connected status and Site Health. Test on staging before production.

Disable any other object-cache plugin first. HarborCache refuses to overwrite another plugin's object-cache.php. Removing the drop-in does not flush unrelated Redis data.

For advanced connection configuration, TLS and migration guidance, see docs/CONFIGURATION.md.

== Frequently Asked Questions ==

= Does this include a Redis server? =
No. Your host must provide Redis or you must operate a Redis server.

= Is a license key required? =
No. All included local features are available without a license key.

= What is HarborCache based on? =
HarborCache's cache engine is GPLv3-compatible. Its additional dashboard, configuration and cache-management work is by Shahid Irfan. Original notices and source attribution are retained; see docs/SOURCE.md.

= Does flush delete other applications' data? =
HarborCache's flush changes its namespace tokens. Old entries expire within the maximum TTL. It does not call FLUSHDB or FLUSHALL.

= Can I reuse another plugin's cache data? =
Configure a distinct prefix and retire the previous drop-in through its owning plugin. Cache data formats and invalidation rules can differ; do not assume the stored entries are compatible.

== Screenshots ==

1. Status: connection state, request metrics and lifecycle controls.
2. Analytics: local sampled request history.
3. Settings: connection, limits and the saved-connection test.
4. Cache: groups and scoped clearing.
5. Diagnostics: server observability and on-demand checks.
6. Raw report for support.
7. WordPress dashboard widget.

== Changelog ==

= 1.6.2 =
* Stand down before registering admin UI, REST routes or drop-in writes when another object-cache manager is active, in either activation order.
* Enforce the REST network policy for admin-post global and cross-site flushes.
* Rate-limit REST cache flushes per user: one per minute, ten per hour, with Retry-After; the limit survives the flush.
* Warn when a remote Redis endpoint is unencrypted or has no password.
* Add application-password HTTP coverage and multisite REST scope tests.

= 1.6.1 =
* Harden the REST API: reject empty or ambiguous flush input, honor `network_flush`, never let a group flush escalate to a full install, and return 500/503 when Redis does not confirm.
* Return 503 instead of a PHP fatal when a REST read loses Redis; validate group names, encode them consistently and return 404 for an unknown registered group.
* Report `/analytics` as disabled or empty instead of a false 100% hit ratio; disable `/slowlog` on a shared Redis server; remove the Relay key oracle.
* Load the REST class only on `rest_api_init`, unify the management-capability filters, and warn when another Redis cache plugin is active in the reverse activation order.
* Add the drop-in `Plugin URI` header so another Redis cache plugin treats it as foreign instead of outdated.
* Extend live coverage for group diagnostics, unknown groups, invalid/ambiguous flush input, unauthenticated flush, shared slow log, analytics-disabled and Relay redaction.

= 1.6.0 =
* Add an authenticated REST API under `harborcache/v1` for status, health, analytics, groups, slow log, Relay diagnostics and scoped flushes.
* Add a bounded group-registry reader for diagnostics without scanning keys.
* No cache key format or configuration change.

= 1.5.1 =
* Keep the WordPress-version integrity check on the key-build path instead of every request-memory hit, restoring 1.4.x runtime-hit cost with no behavior change.
* Same-run benchmark against 1.4.1: CPU, bootstrap, runtime hits, Redis reads, bulk reads, writes, misses and cold reads are within +/-1.3%.
* No key-format, storage, or configuration change beyond the 1.5.0 upgrade.

= 1.5.0 =
* Relay invalidation now removes only the affected key from request memory; namespace token changes still flush the request runtime and memoized ids.
* Relay diagnostics report statistics and memory for Relay and Relay Cluster without requiring the adaptive cache option.
* Include the WordPress version in the derived-key layout, so a core upgrade rotates new keys without deleting old values.
* Report Redis `noeviction` and Relay memory pressure as Site Health issues.
* No configuration change.

= 1.4.1 =
* Store prefetch manifests only for GET and HEAD requests, excluding WP-CLI, REST and XML-RPC.
* Raise the prefetch manifest to 1,000 keys and 24 hours, skip single-key groups, and omit `alloptions` when it is split.
* Detect incomplete classes during replay, drop them from request memory and skip them in the next manifest.
* No cache key format or configuration change.

= 1.4.0 =
* Memoize one request-local cache id per blog, group and key, so repeated reads skip namespace and context work.
* Precompute single-node, instrumentation and read-only routing flags used on every command.
* Skip namespace metadata writes for non-persistent groups and remove a redundant group-type lookup.
* No cache key format or configuration change.

= 1.3.3 =
* Probe Redis Cluster through a concrete node and Sentinel/replica setups through the primary, so the connection check can enable and save those topologies.
* Read WP_REDIS_CLUSTER, WP_REDIS_SERVERS, WP_REDIS_SENTINEL, WP_REDIS_SCHEME and WP_REDIS_PATH into the effective configuration.
* Allow a saved Unix-socket connection to switch back to TCP from Settings.
* Keep a wp-config password out of the saved wp-content configuration file.
* Remove endpoints and raw probe host details from the copyable diagnostics report and Site Health debug info.
* Honor WP_REDIS_MANAGER_CAPABILITY in the dashboard gate, and cap group registry/epoch metadata at 1,000 entries.

= 1.3.2 =
* Remove the legacy Predis client and its public FLUSHDB path; namespace rotation now uses the selected client.
* Trim analytics every minute so the sampled sorted set cannot grow for an hour.
* Read endpoint, database, serializer, compression and TTL from the live cache on Status; the settings form keeps the saved values.
* Require a server-side confirmation for namespace reclaim, and fix the namespace and drop-in detail text.
* Rotate only query groups whose source data changed, limit the group table to 100 rows, and render chart points only on the visible panel.
* Delete the inherited no-op plugin methods, warn when the connection file is group/world readable, and make uninstall remove older config files.
* Add query-pruning, status-read and uninstall checks to CI.

= 1.3.1 =
* Refresh the readiness panel into a compact 2x2 health grid with a clear Ready/attention chip.
* Replace the manual-test checklist with compact connection details and a warning when the saved connection differs from the live request.
* Add a dashboard-widget summary for hit ratio, Redis time, commands and runtime entries, plus readable chart ticks.
* Version the CSS and JavaScript with the file modification time, so a plugin update cannot leave an old cached stylesheet in place.
* Refresh the listing screenshots and capture record for 1.3.1.

= 1.3.0 =
* Separate transport health from cache readiness, so a reachable Redis server is never reported as “Not connected” just because the namespace belongs to another installation.
* Report “Namespace conflict” with a confirmed reclaim action; migrate owners written by 1.2.x and keep the namespace stable when security salts rotate.
* Make the connection check use the configured timeout, verify namespace ownership and round-trip a value through the active serializer and compression.
* Add an end-to-end cache self-test through the WordPress cache API and a `wp harborcache doctor` command.
* Load administration code only for admin, AJAX, cron, WP-CLI and logged-in admin-bar requests.
* Record sampled analytics without serializing every cached value, and trim analytics on the hourly maintenance event instead of every sample.
* Remove inherited no-op hooks and the duplicate status implementations used by the dashboard, Query Monitor and Site Health.
* Use the readiness checklist, one primary action per state, and plain-language explanations on the Status and Settings screens.

= 1.2.10 =
* Separate the group clear button, the include-memory option and the group inspection button.
* Align the Settings save and connection test buttons with the panel content.
* Regenerate the translation template for the current interface strings.

= 1.2.9 =
* Read scope and group epochs from one Redis hash and the group registry once per request, instead of one GET per group.
* Skip per-group registration on reads and refresh metadata lifetimes on writes.
* Keep the existing cache key format, and migrate legacy epoch keys on first use.
* Add a 30-group metadata command-count test and a 1.2.9 validation record.

= 1.2.8 =
* Save and test now run one connection check with the selected client, a five second budget and a specific failure reason.
* Status, Settings, Cache, Analytics and Diagnostics replace the Overview, Connection, Cache groups and Tools tabs.
* Installed, loaded, connected and disabled are separate states, so a new connection no longer reports a bare "Not active".
* A failed save keeps the submitted connection values visible instead of replacing them with the saved ones.
* New installs use PhpRedis when the extension is available; existing Predis installs can switch from the notice.
* Exact runtime-cache memory is measured only in Diagnostics; the dashboard and widget show the entry count instead of serializing the request cache.
* Restore the dashboard widget request table, sampled summary, chart and links, with separate request-memory and Redis counters.
* Compute one status snapshot per Status page, register group metadata with one Redis command on a single node, cache configuration defaults and load the connection probe only when an admin action needs it.
* Clear actions name their scope, and clearing request memory no longer asks for a cache invalidation.
* The teal palette, product name and existing key format are unchanged.

= 1.2.7 =
* Reuse key contexts within a site and invalidate them when global groups or the current site change.
* Batch independent metadata commands on supported single-node clients without changing cache key formats.
* Skip drop-in inspection for unsampled requests and reduce byte-statistics allocations.
* Contain metrics failures with concise redacted logs and reuse corruption-handling callbacks.
* Reduce bootstrap work while retaining compatibility methods and the existing dashboard.
* Add matched full-plugin and engine benchmarks, CPU profiling and regression coverage.

= 1.2.6 =
* Preserve another installation's cache-prefix ownership during startup and enable/reconnect checks.
* Reject invalid namespace metadata and failed initialization/rotation replies without silently creating a replacement namespace.
* Fetch optional Redis server information lazily and avoid unnecessary SELECT 0 in Predis connections.
* Keep options and site-options reads on primary nodes, including bulk reads and Relay option strings.
* Discard native connections after failed reads and preserve nonzero database selection during safe reconnects.
* Log concise, redacted fallback errors once per request by default; keep detailed redacted traces available in debug mode.
* Preserve dashboard styling, media, configuration defaults and existing cache key formats.

= 1.2.5 =
* Clarify source attribution and describe HarborCache's own features directly.
* Remove unnecessary product comparisons from current descriptions and clarify cache migration guidance.
* Preserve cache behavior, compatibility hooks, dashboard styling and media assets.

= 1.2.4 =
* Resolve the plugin directory from its actual installation and install a verified drop-in with that path.
* Remove legacy directory guesses from Predis and preserve recovery when a plugin folder moves.
* Escape diagnostic and error output at rendering time.
* Default PHP 7.4 PhpRedis TLS connections to verified TLS 1.2 to avoid a known TLS 1.3 stream hang; preserve explicit TLS policies.
* Exclude development reports and unused vendor documentation from the install ZIP while retaining source and licenses.

= 1.2.3 =
* Recover verified backups when an interrupted update leaves a partial drop-in at the destination.
* Remove incomplete drop-in files after failed first installs or failed rollback copies so WordPress can start without persistent caching.
* Preserve foreign cache drop-ins and show a specific recovery message when HarborCache cannot safely replace a file.
* Add automated coverage for interrupted writes, backup recovery and failed filesystem operations.

= 1.2.2 =
* Give HarborCache-owned plugin metadata and drop-in paths distinct HARBORCACHE-prefixed constants.
* Preserve WP_REDIS_* connection configuration as an input compatibility layer without claiming Redis Cache plugin identity.
* Resolve the drop-in path from HarborCache’s own installation directory and update the comparison harness to use the correct engine constants.

= 1.2.1 =
* Scope chart styles and scripts to HarborCache’s admin page and visible dashboard widget.
* Sanitize request paths used to build prefetch identifiers.
* Remove unused legacy AJAX handlers and their unsanitized nonce path.
* Namespace the metrics cleanup cron event and migrate the earlier HarborCache event.
* Keep the cache API, Redis key format and saved connection settings compatible.

= 1.2.0 =
* Reduce temporary allocations in the repeated cache-key layout check without changing key or namespace semantics.
* Format dashboard counters for quick reading and increase dashboard, chart, settings and widget text sizes.
* Add fresh-worker replacement, ignored-group storage and group-flush scaling coverage.
* Compare group invalidation and cache workloads against the previous HarborCache release in Linux CI.

= 1.1.5 =
* Restore the complete HarborCache – Object Cache with Redis name used by the installed plugin.
* Show the complete product name in the HarborCache settings page title and describe its purpose below the logo.
* Refresh the plugin screenshots without a visible version footer.

= 1.1.4 =
* Standardize the WordPress plugin identity as HarborCache across plugin listings and the dashboard.
* Match the translation domain and installation folder to the WordPress.org submission slug.
* Preserve the legacy HarborCache installation path for existing drop-ins and MU-loader setups.

= 1.1.3 =
* Reduce repeated wildcard-group matching and inactive command instrumentation overhead.
* Preserve late group registration, optional tracing and existing namespace formats.
* Fix native counter failure replies and bypass Relay local caching for forced reads.
* Add Linux/MySQL, native codecs, ACL/TLS/socket, concurrency, WooCommerce and multisite acceptance tests.
* Publish reproducible CPU, memory and latency comparisons with the previous HarborCache release.

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
* Published same-workload benchmark coverage and explicit remaining deployment gates.

= 1.1.0 =
* Removed plugin REST routes; management and exports use protected WordPress admin actions. The authenticated `harborcache/v1` API returned in 1.6.0.
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
* Renamed the initial developer build.
* Original dashboard, scoped invalidation, typed cache values, configuration, diagnostics and REST controls.

== Upgrade Notice ==

= 1.2.10 =
Interface spacing fixes and a refreshed translation template. Cache behavior, key format and settings are unchanged.

= 1.2.9 =
Refresh the owned drop-in. The first request after upgrading migrates used legacy epoch keys into the shared hash; existing cached entries stay valid. Key format and settings are preserved.

= 1.2.8 =
Refresh the owned drop-in after upgrading. Save the connection once to run the new selected-client check. Existing settings, key formats and the teal dashboard palette are preserved.

= 1.2.7 =
Refresh the owned drop-in after upgrading. Existing settings, key formats, dashboard styling and prefix safety are preserved. This update reduces repeated PHP work and batches supported Redis commands.

= 1.2.6 =
Refresh the owned drop-in after upgrading. Prefix conflicts now use request-memory fallback. Configure a distinct prefix for a cloned site or restore its original identity. Existing cache key formats and dashboard styling are preserved.

= 1.2.2 =
Separate HarborCache metadata from Redis Cache globals. Existing connection settings and cache data are preserved; update the HarborCache drop-in after upgrading.

= 1.2.1 =
Fix WordPress.org review findings around request sanitization and dashboard style isolation. Existing connection settings and the cache namespace are preserved.

= 1.2.0 =
Performance and dashboard readability improvements with expanded correctness and Linux comparison coverage. Existing connection settings and key namespace are preserved.

= 1.1.5 =
Restore the full plugin identity so WordPress recognizes this as an update to HarborCache – Object Cache with Redis. Refresh the owned drop-in after upgrading.

= 1.1.4 =
Correct plugin identity and WordPress.org translation-domain metadata. Refresh the owned drop-in after upgrading. Redis settings and cache namespace are preserved.

= 1.1.3 =
Cache-path optimizations and expanded Linux acceptance. Refresh the owned drop-in after installing. Connection settings and namespace format are preserved.

= 1.1.2 =
Correctness, update recovery and dashboard readability fixes. Refresh the owned drop-in after installing. Existing connection settings and key namespace format are preserved.

= 1.1.1 =
Performance and native-client fixes. Update the owned object-cache drop-in after installing. No server settings or key namespace format changes are required.

= 1.1.0 =
Consolidated operational update. The legacy plugin REST routes were removed at that time; the authenticated `harborcache/v1` API returned in 1.6.0. The owned drop-in refreshes automatically for authorized administrators, or use Update drop-in.

= 1.0.2 =
Larger widget text and Connect/Disconnect controls. Update the HarborCache drop-in after upgrading.

= 1.0.1 =
Stable release with a WordPress dashboard statistics widget and bounded analytics reads. Update the HarborCache drop-in after upgrading.
