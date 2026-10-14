# HarborCache 1.1.4 validation

This release corrects the WordPress.org submission identity and plugin display name. The dashboard and the Plugins screen identify the plugin as HarborCache; the short description identifies its Redis object-cache function.

- WordPress Plugin Check, including experimental checks: no findings for slug `harborcache-object-cache-with-redis`.
- Initialized runtime resource checks: five passed, no findings.
- WordPress.org readme validator: no errors or warnings.
- Runtime checks: 76 existing cache regression assertions and 11 submission identity, install, Redis, drop-in and translation assertions passed.
- The installable ZIP installed and activated from the submitted slug directory. The owned drop-in resolved that directory and remained byte-identical to its bundled source.
- All 611 packaged files matched the build, repository, extracted archive and installed plugin. ZIP extraction and CRC checks passed.
- The private repository acceptance workflow's authenticated download of the v1.1.2 benchmark baseline was verified against its pinned SHA-256.

Installable ZIP SHA-256: `9c5aa2758a6960abd32867ca27819a62a1bb8c92b8788892f5478f12a21581cc`.

This validation does not establish WordPress.org approval. Directory approval remains subject to manual review.
