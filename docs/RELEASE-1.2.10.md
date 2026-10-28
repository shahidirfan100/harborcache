# HarborCache 1.2.10 release notes

## Scope

1.2.10 is an interface and translation patch on top of the published 1.2.9. The cache engine, key format, metadata protocol, settings, palette and product name are unchanged.

## Changes

- Group rows: the clear button, the include-memory option and the inspect button now have separate spacing, so the checkbox no longer touches the button border.
- Settings: the save and connection test buttons align with the panel content instead of the panel edge.
- Translation template regenerated with WP-CLI 2.12.0 for the current interface: 295 strings, up from 244 in the shipped 1.2.3 template.

## Compatibility and rollback

No option, constant or cache key changes. Rolling back to 1.2.9 needs no cache flush, and the legacy epoch keys remain valid.

## Verification

The full matrix, Plugin Check result and screenshot capture run are recorded in `VALIDATION-1.2.10.md`.
