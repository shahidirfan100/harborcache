# HarborCache 1.2.2

This patch separates HarborCache's own runtime identity from globals used by Redis Cache. HarborCache now uses `HARBORCACHE_*` constants for its main file, plugin path, version and assets. Its drop-in locates bundled files from the HarborCache installation path and no longer relies on a competing plugin's path constant.

Existing `WP_REDIS_*` connection values remain accepted as configuration inputs, preserving established Redis settings. The Linux comparison harness now sets Redis Cache identity values only for the Redis Cache and HarborCache 1.1.5 comparison runs; current HarborCache uses its own constants. The WordPress cache API, Redis key format and saved configuration are preserved.

The release includes the WordPress.org review fixes in 1.2.1 for sanitization, legacy action removal, admin asset scoping and notice scope. See [validation evidence](VALIDATION-1.2.2.md) for exact package checks and environment limits. A successful automated check does not guarantee WordPress.org approval.
