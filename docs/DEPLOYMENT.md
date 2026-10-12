# Deployment

HarborCache uses your existing Redis server; it does not install or restart Redis. Configure the host/port or Unix socket, database and credentials in Connection or HARBORCACHE_CONFIG. Keep TLS peer verification enabled. Only one object-cache manager can own wp-content/object-cache.php.

Install the plugin ZIP, enable it and press Connect. The owned drop-in is staged and verified after a connection check, with recovery of the previous copy if replacement fails. Remote filesystem fallback replacement is recoverable; it is not guaranteed atomic. Disconnect removes only that drop-in, retaining connection settings. Reconnect starts a fresh namespace; old entries expire under maximum TTL. Other applications' Redis keys are not flushed.

## Upgrades

Authorized administrators automatically refresh an outdated owned drop-in when auto_update_dropin is enabled. Its contents are compared with the packaged drop-in. Failed refreshes retain a working manual Update drop-in form. Foreign drop-ins and symlinks are never replaced. Headless deployments must run `wp harborcache update` after replacing plugin files. Keep the previous ZIP and configuration backup for rollback. Do not point an untrusted directory at HARBORCACHE_PLUGIN_PATH.

## Must-use setup

Keep the full plugin in wp-content/plugins/harborcache and copy deployment/harborcache-loader.php to wp-content/mu-plugins/harborcache-loader.php. The stub requires the main plugin once; there is no need to activate the normal plugin too. An absolute HARBORCACHE_PLUGIN_PATH defined in wp-config.php supports another installation directory. Run `wp harborcache enable` to install the cache drop-in. MU plugins cannot be deactivated from the normal plugin screen; remove the loader deliberately to stop loading it, and disable the owned drop-in first.

## Composer project

The included composer.json describes this package; this does not assert a public Packagist listing. Configure a project-level artifact/path repository containing HarborCache and require shahidirfan/harborcache. A project using composer/installers can map type wordpress-plugin to its WordPress plugin directory. The bundled Redis clients remain included. After Composer replaces files, run `wp harborcache update` and `wp harborcache status` in deployment automation. Do not store Redis passwords in composer.json.

## Operating limits

Default analytics samples 10% of requests and retains at most 1000 measurements for two hours. Dashboard graphs fetch at most 100 samples; analytics/export/CLI fetch at most 1000. No polling runs on website visits. Configure retention, sampling, serializer and compression from measurements on your server, not assumptions. Native extensions must be installed by the server operator; choosing an unavailable extension fails the connection check.

Cleanup uses key and cooperative wall-time budgets. A Redis command itself can take longer than the budget, subject to connection timeouts. Optional query maintenance recycles up to ten observed groups per hourly job and also expires valid query entries; it is off by default. It is not a selective implementation of Pro's stale-query discovery. Prefer normal TTL unless workload tests justify recycling. KEYS is explicitly selectable for compatibility but can block Redis; incremental/atomic strategies are preferable for large shared servers.

Failure modes: silent falls back to runtime caching; strict throws on initialization failure and falls back on later runtime failures; debug throws on failures. Prefix ownership protection remains a separate strict setting. Never enable throwing modes on production without a tested recovery path. No write is replayed after an ambiguous Redis reply. Explicitly reconnect or refresh the owned drop-in after a disconnected period to start a fresh namespace.

`harborcache_manager_capability` can return a dedicated capability granted to a trusted operations role. It protects dashboard controls, admin actions and admin-bar management. Multisite defaults to manage_network_options. The hook is an internal WordPress extension point, not an additional public helper API.

HarborCache registers no REST endpoints and adds no wp_cache_remember/wp_cache_sear helper functions. Required WordPress wp_cache_* functions remain. Admin mutations and CSV exports use nonce- and capability-protected admin-post forms.
