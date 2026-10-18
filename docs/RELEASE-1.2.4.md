# HarborCache 1.2.4

This maintenance release addresses the October 18 WordPress.org manual review. The install archive excludes development reports, audit notes, unused promotional images and vendor readmes. Dependency source and licenses remain included. The maintained `.distignore` and `tools/build-release.py` make these exclusions repeatable.

The plugin derives its path from `plugin_dir_path()` and the verified installer embeds that actual location into the drop-in. Predis uses this path exclusively. Renamed plugin folders, relocated plugin directories and moved content directories no longer depend on the `harborcache` or default `plugins` folder names. A missing embedded path leaves WordPress free to use its built-in request cache, and the admin can refresh the owned drop-in from the new location.

Diagnostic client text and error-page content are escaped at output. Existing cache key identities, settings, namespace formats and dashboard layout are retained. The rendered file goes through the same staged verification, lock, rollback and recovery process as previous updates.

`HarborCache\Paths::content()` deliberately retains WordPress's configured `WP_CONTENT_DIR`: core loads `object-cache.php` from this exact filesystem location before plugin APIs are available. It is not used to infer the plugin directory or an upload path. The guarded configuration and recovery copy must remain alongside that drop-in. This also preserves cache-prefix identities for existing installations.
