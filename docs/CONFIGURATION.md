# Configuration and staging installation

1. Disable the previous object-cache plugin using its own controls. Back up its configuration and wp-content/object-cache.php. HarborCache refuses to overwrite a foreign drop-in.
2. Install the HarborCache ZIP, activate it and open Settings → HarborCache.
3. Set the Redis endpoint and a unique installation prefix in Connection. Save, then enable object caching from Overview. No licensing step exists.
4. Verify Connected status and exercise your site's options, editing, checkout, login and background tasks on staging.

Redis is an external server you operate or your host provides. HarborCache does not install Redis. Cache data from other plugins is not imported; use a fresh prefix. Switching codec or split-alloptions settings produces a separate value namespace. Old entries expire under the configured maximum TTL.

## Configuration precedence

`HARBORCACHE_CONFIG` in wp-config.php overrides the guarded wp-content/harborcache-config.php saved by the dashboard. Existing `WP_REDIS_*` connection constants also take precedence. Constants must be declared before WordPress loads. The dashboard displays effective values, so constant-controlled values cannot be changed through the form.

```php
define( 'HARBORCACHE_CONFIG', array(
    'client' => 'predis',
    'host' => '127.0.0.1',
    'port' => 6379,
    'database' => 0,
    'prefix' => 'example-production',
    'timeout' => 1.0,
    'read_timeout' => 1.0,
    'maxttl' => 86400,
    'queryttl' => 3600,
    'network_flush' => 'all',
    'split_alloptions' => false,
    'prefetch' => false,
    'analytics' => array(
        'enabled' => true,
        'sample_rate' => 10,
        'retention' => 7200,
    ),
) );
```

See ADVANCED.md for all advanced settings, factories, analytics controls and topology examples. Unknown configuration keys are rejected.

Use `tls://redis.example.com` as host with `tls_options` for a TLS context; certificate verification is enabled by default. Use `/var/run/redis/redis.sock` for a Unix socket. Configure these against your provider and validate them on staging. Predis is bundled; PhpRedis and Relay require separate PHP extensions. Compression requires standalone PHP functions: `lzf_compress`/`lzf_decompress`, `lz4_compress`/`lz4_uncompress`, or `zstd_compress`/`zstd_uncompress`. Compiling a codec only inside PhpRedis is insufficient for this adapter. See the [LZ4 extension](https://github.com/kjdev/php-ext-lz4) and [Zstandard extension](https://github.com/kjdev/php-ext-zstd) function references.

Choose cluster nodes, sentinels plus service, or replicated servers. A six-node Predis cluster, actual replica reads and Sentinel promotion have local evidence. Native extension adapters and your deployment need separate validation. Cluster requires database zero. See ADVANCED.md.

## Privacy and credentials

The guarded PHP settings file holds Redis credentials because the drop-in starts before normal WordPress option access. Its file mode is requested as 0600; enforce suitable filesystem permissions on your host. Never publish or serve this file as text. Blank password fields preserve the existing password; use Clear saved password to remove it.

Diagnostics omit credentials, endpoints, cached keys and values. Command recording stores only names and durations for core and added commands, bounded to 200 per request. Slowlog exports omit command arguments. Connection failures use a generic message; any custom logger receives only its category and exception class. No vendor account or external telemetry is used by default. Explicitly configured telemetry uses the installed provider.

## Invalidation, recovery and removal

Flush changes namespace tokens. Optional async_flush queues bounded reclamation using UNLINK on supported servers; cron, manual and CLI workers process jobs. Old entries still expire under maxttl. Every installation requires a unique prefix. See ADVANCED.md for strategies and safeguards.

For emergency recovery, declare `HARBORCACHE_DISABLED` as true in wp-config.php, or remove only the HarborCache-owned wp-content/object-cache.php. Invalid configuration falls back to WordPress's runtime cache; correct the constant or saved PHP file to restore the plugin. Missing client/codec extensions also fall back rather than using the wrong data format. Use failure_mode=silent (default), strict (initialization errors throw), or debug (backend errors throw). These modes replace the legacy graceful-error switch.

Deactivation removes only the owned drop-in and preserves settings. Uninstall removes the owned drop-in and its marked saved settings file. Neither flushes unrelated Redis data. If file modifications are disabled or filesystem credentials are unavailable, remove the owned files manually after inspecting them. Redis values expire normally.

For a renamed/custom plugin directory, define `HARBORCACHE_PLUGIN_PATH` to its absolute directory before WordPress loads. Installing as a must-use plugin requires an appropriate loader and directory constant; see DEPLOYMENT.md for the included loader and deployment procedure.

## CLI and protected administration

Run `wp harborcache status`, `diagnostics`, `enable`, `disable`, `update`, `flush`, `groups`, `commands` or `slowlog`. Flush accepts `--group=name` or `--site=ID`.

No HarborCache REST routes or optional wp_cache_remember/wp_cache_sear helpers are registered. Dashboard actions and CSV downloads require a management capability and a valid action nonce. CLI also includes analytics, digest, aggregate, watch, latency, health, relay and queries; use command help for options.

## Upgrading from the CacheHarbor developer build

Disable CacheHarbor's drop-in and deactivate CacheHarbor before activating HarborCache. Configure HarborCache from its Connection page, or migrate the reviewed values to HARBORCACHE_CONFIG. The old CACHEHARBOR_CONFIG constant and cacheharbor-config.php filename are not imported automatically. Use a fresh prefix. Keep the old package and configuration as your rollback checkpoint. Never activate both managers together.

## Deployment acceptance checks

The included cache features have local runtime evidence. Before production deployment, test the actual Redis version and topology, PHP extensions, WordPress database, multisite setup and checkout/background-job paths on staging. Passing Plugin Check is not WordPress.org approval. API differences and unverified adapters are listed in FEATURES.md.


## PHP 7.4 native TLS compatibility

On PHP 7.4, PhpRedis TLS connections default to `STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT` when no explicit `tls_options.crypto_method` is configured. This avoids the documented PHP 7.4/PhpRedis TLS 1.3 stream hang while keeping certificate and peer-name verification enabled. PHP 8+ and Predis retain their configured TLS policy. An explicit crypto_method is respected; use a current PHP runtime for reliable TLS 1.3. The same normalization applies to legacy WP_REDIS_SSL_CONTEXT inputs.

Upstream diagnosis: https://github.com/phpredis/phpredis/issues/1726.
