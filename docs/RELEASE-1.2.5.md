# HarborCache 1.2.5

This maintenance update describes HarborCache's purpose, additional administration and cache-management work, and development direction while retaining clear source attribution.

- Lead the README with HarborCache's purpose and features; keep the engine's GPL attribution in the license record.
- Describe product-specific behavior and migration guidance instead of third-party product comparisons.
- Keep the source record and historical validation evidence.
- Correct the obsolete promotional comment on a legacy no-op hook. Keep its callable name and return value for compatibility, along with existing conflict detection.
- Align plugin, drop-in and optional MU-loader version headers at 1.2.5.
- Document a gated independent-engine proposal for a future major version. No replacement engine is included in this update.

The cache engine, namespace format, saved settings, dashboard markup, colors, styles, scripts and media assets are unchanged. Back up the current installation and use the normal owned-drop-in update flow if prompted. There is no cache-format migration in this release.

See VALIDATION-1.2.5.md for the checks performed and their limits.
