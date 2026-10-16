# HarborCache 1.2.1

This maintenance release addresses the WordPress.org pre-review findings from the HarborCache 1.1.5 submission and narrows adjacent admin behavior.

The release removes the unused legacy `roc_flush_cache` and `roc_dismiss_notice` AJAX actions, including the handler that passed an unsanitized POST value to nonce verification. The active cache-management action remains protected by a capability check and WordPress nonce. Request paths are unslashed and sanitized before they are used to derive a prefetch identifier. When another Redis cache manager is already active, HarborCache limits its conflict notice to plugin-management screens and users who can manage plugins.

Dashboard charts load only on the HarborCache settings page or when the HarborCache dashboard widget is visible. HarborCache chart and console selectors are rooted under the plugin UI. Drop-in recovery notices are restricted to HarborCache settings screens so an unrelated dashboard page is not interrupted. The metrics-retention cron uses a HarborCache-specific event name and removes the earlier event during migration.

The object-cache API, Redis key format, connection settings, owned drop-in update/recovery behavior and existing cache namespace are preserved. Existing installations do not need to flush Redis or change their connection settings.

After upgrading, verify the drop-in version and connection status under **Settings → HarborCache**. Use a staging site to exercise the cache lifecycle before production deployment. The release does not claim WordPress.org approval or universal performance results.
