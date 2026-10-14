# HarborCache 1.1.4

This maintenance release aligns the WordPress plugin identity with the HarborCache dashboard and the submitted directory slug. The Plugins screen uses the same short name shown in the dashboard.

The main plugin header, 258 translatable strings, generated translation catalog and Composer install folder use `harborcache-object-cache-with-redis`. The owned drop-in resolves this folder while retaining the legacy `wp-content/plugins/harborcache` path for existing installations and MU loaders. Redis connection settings, cache namespaces, management URLs and cache behavior are unchanged.

The duplicate optional Plugin URI header was removed. The author profile remains in Author URI.

Validation: WordPress Plugin Check (including experimental checks) reports no findings for the submitted slug; five initialized runtime performance checks pass; package installation and activation, Redis cache read/write, translation catalog loading, drop-in identity and 87 runtime assertions pass. WordPress.org manual review remains pending.
