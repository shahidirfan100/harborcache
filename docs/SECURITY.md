# Security boundaries

## Redis is a trusted store

Cached object values are application data, not user input. A compromised Redis server, a shared database with untrusted tenants, or an exposed endpoint can read cached values and attempt cache poisoning.

Serialized values use a `V2` HMAC and are verified before `unserialize()`. Unsigned `V1` values are rejected. The signature prevents object injection, but it does not make Redis confidential and does not stop an attacker from replaying a valid signed value into another cache key.

Use TLS and a password for any non-loopback endpoint. HarborCache warns when either is missing. Diagnostic exports leave out passwords, endpoints and cached values.

The HMAC key comes from `AUTH_KEY`, then `wp_salt( 'auth' )`. Rotating salts rebuilds the cache. Define WordPress salts; without them the fallback only detects corruption.

## Shared Redis

Mark a shared server in Settings. The `/slowlog` REST route returns 403 on a shared server because the slow log is server-wide. Namespace prefixes keep installations apart; they are not an authorization boundary. Use Redis ACLs or a dedicated database for tenant separation.

## Configuration file

Settings are saved to `wp-content/harborcache-config.php` with mode `0600`, or supplied through `HARBORCACHE_CONFIG` and WordPress constants. The dashboard warns when the saved file is readable by group or other users. Prefer `wp-config.php` or environment-provided constants for credentials.

## Destructive actions

Flushes and drop-in writes require the management capability and a nonce. Multisite global and cross-site flushes require network rights. REST flushes are limited to one per minute and ten per hour per user and site, reserved atomically in Redis.

## Reporting

Report a vulnerability through the repository's security contact rather than a public issue. Include the plugin version, the affected route or hook, and a minimal reproduction.
