# WordPress validation report: HarborCache 1.2.4

## Summary

- Type: WordPress object-cache plugin.
- Name: HarborCache – Object Cache with Redis.
- Version: 1.2.4.
- Review: WordPress.org manual review T2, October 18, 2026, against the submitted 1.2.3 ZIP.
- Status: Pass for the supplied review findings and submission checks. All ten Linux CI jobs passed; directory approval remains a manual WordPress.org decision.

## Reported findings and corrections

| Finding | Correction | Regression evidence |
| --- | --- | --- |
| Unneeded `vendor/colinmollenhour/credis/README.markdown` | Exclude vendor readmes and `.markdown` files from the install archive. Preserve both dependency licenses and readable PHP source. | Package builder verifies the required licenses, expected file list and source bytes. |
| Unnecessary `docs/NAME-REVIEW.md` and development output | Exclude audit/release/validation/comparison reports, CI evidence, development tests/tools and unused promotional assets. Permit only runtime roots and maintained user guides. | The submitted ZIP contains 598 files and no development reports, `.github`, test/tool folders, media pack or `.markdown` files. |
| Hardcoded legacy plugin-directory guesses | Derive the normal plugin root from `plugin_dir_path(HARBORCACHE_FILE)`. Render that actual root into the verified installed drop-in. Predis loads exclusively from `HARBORCACHE_PLUGIN_PATH`; the optional MU-loader requires an explicit root. | Fresh-process tests cover a renamed path containing a quote, a missing root and deterministic rendered contents. WordPress fixtures cover relocated content/plugins directories and a rename after installation. |
| Repeated `WP_CONTENT_DIR` references | Centralize the deliberately required canonical content filesystem location in `HarborCache\Paths::content()`. It does not infer the plugin root. Preserve the exact base value used in cache-prefix and ownership identities. | Relocated content tests locate the correct drop-in and guarded configuration; prefix identity assertions remain unchanged. See the core exception below. |
| Unescaped client diagnostics | Escape the client value and formatted size at output; handle a missing client. Escape legacy footer text and exception text, and restrict generated error-page markup with `wp_kses_post()`. Remove broad output-escaping suppressions from the engine. | Malicious HTML and missing-value probes pass; the same HTML payload is tested with real WordPress escaping functions. Targeted PHPCS `WordPress.Security.EscapeOutput` passes. |

The prior text-domain, nonce/input, action-prefix, notice-scope and dashboard-style corrections remain present. The acceptance suite retains their regression checks.

## Canonical drop-in directory exception

`WP_CONTENT_DIR` is intentionally retained in the small, documented early-bootstrap location helper. WordPress itself requires `object-cache.php` from that exact filesystem directory before normal plugins load. `plugin_dir_path()` locates the plugin, `content_url()` returns a URL, and `wp_upload_dir()` can point outside the content directory; none replaces this canonical drop-in filesystem location. The adjacent guarded configuration and recovery copy use the same directory. Returning the unchanged base also avoids changing cache-prefix and strict-ownership identities.

Reference: [WordPress core's object-cache bootstrap](https://developer.wordpress.org/reference/functions/wp_start_object_cache/), particularly its `WP_CONTENT_DIR . '/object-cache.php'` load and built-in-cache fallback.

## Local checks

- 12 standalone drop-in transaction checks passed, including interrupted writes, foreign-file preservation, failed rollback, rendered staging and completed rendered-update recovery.
- Nine fresh-process path/output/TLS-policy checks passed, including a quoted renamed path, null-byte rejection, missing-path fallback and diagnostic HTML escaping.
- The existing conflict-notice scope regression passed.
- PHP 8.5 syntax and targeted output-escaping analysis passed.
- The exact committed ZIP passed Plugin Check 2.1.0 on WordPress 7.1.3/PHP 8.4.26 in a separate local SQLite fixture: `Success: Checks complete. No errors found.`
- Fourteen real WordPress assertions passed before and after a plugin-folder rename. The fixture uses separate relocated content and plugin directories. Deactivation removed the owned drop-in; uninstall removed its guarded configuration and preserved a foreign drop-in.
- Redis is unavailable on the local test PC. Deliberate outage requests continued successfully with request-memory caching and expected redacted backend-unavailable diagnostics. Those logs contain no uncaught errors, PHP warnings, notices or deprecations. They are retained as outage evidence, rather than being discarded to claim an empty outage log.
- The local PHP 8.5 WP-CLI bundle emits its own deprecation messages. The complete local CLI fixture therefore ran on verified PHP 8.4.26; plugin source syntax/standalone checks and Linux CI cover PHP 8.5. Initial tooling logs were preserved separately.
- The final ZIP installed and activated through WordPress itself, then passed Plugin Check with a new empty debug log.
- The official online Readme Validator reported only the optional note: `No donate link was found`.

## Linux CI and Code Review

The pull request adds an exact-committed-ZIP layout job alongside the existing native-client, transport, lifecycle, outage, multisite, WooCommerce, Relay and PHP-FPM acceptance jobs. The layout job uses MySQL and Redis, relocates both content and plugins, renames the plugin, verifies safe fallback, refreshes it through the real `wp harborcache update` command, and checks deactivation/uninstall with `WP_DEBUG` enabled.

The Code Review plugin was used for PR check diagnostics and failure logs. Source inspection additionally covered runtime directory references and dynamic output sites. CI fixture paths and exclusion of generated CI evidence were corrected before the final acceptance result; these test-tooling corrections do not change the installed plugin's runtime source.

The gate reproduced a PHP 7.4/PhpRedis TLS 1.3 hang also documented in https://github.com/phpredis/phpredis/issues/1726. A scoped compatibility default selects verified TLS 1.2 for PHP 7.4 PhpRedis when the caller has not supplied crypto_method. Explicit policy, certificate verification, newer PHP and Predis are preserved. The matrix includes five additional fresh-worker TLS rounds for each PHP 7.4 case and retains invalid-certificate rejection.

Final CI result: **all 10 jobs passed** on runtime commit `18c4b82fb38d04b7f1ec024e0dfa5640884b99ae`, [run 39123458186](https://github.com/shahidirfan100/harborcache/actions/runs/39123458186). Coverage includes PHP 7.4 and 8.2–8.5, WordPress 6.6/latest, Redis 6/7/8, ACL/TLS/socket transports, client/codec/concurrency coverage, outage recovery, lifecycle, WooCommerce, multisite, Relay and PHP-FPM. Both PHP 7.4 jobs passed all five additional trusted-TLS rounds plus invalid-certificate rejection.

## Install package

- Archive: `dist/harborcache-1.2.4.zip`.
- Root: `harborcache-object-cache-with-redis/`.
- Files: 598.
- SHA-256: `4736bb077d2a2d4ed2f84c97dcf1619501396974e61958de83227dff7e44c295`.
- Builder: `python tools/build-release.py`; existing-package verification: `python tools/build-release.py --verify`.
- The package is verified byte-for-byte against included runtime source, including root and dependency licenses. Version, stable tag and submitted text domain are checked. Older install ZIPs are excluded from the current source tree.

## Unresolved findings and checklist

No unresolved critical or high findings from the supplied review remain. The missing donation link is optional and is not a submission blocker.

- [x] Security: reported dynamic output and prior input/nonce regressions checked.
- [x] Performance: no new queries, key scans, polling or per-cache-operation work introduced.
- [x] Compatibility: supported PHP/WordPress/Redis CI matrix passed.
- [x] Code quality: syntax, targeted output-escaping analysis and Plugin Check passed.
- [x] Scope: package/path/output corrections and the observed PHP 7.4 TLS reliability fix.

## Remaining boundaries

No known findings from the supplied T2 review remain unaddressed. WordPress.org's manual approval remains its reviewers' decision. No production files on autoscareer.com were modified, and deployment on that specific host is not certified by these isolated fixtures. Existing feature-ledger acceptance boundaries remain in force; this maintenance release does not add proprietary Object Cache Pro implementation or claim universal performance leadership.

## Referenced WordPress documentation

- [Determining plugin and content directories](https://developer.wordpress.org/plugins/plugin-basics/determining-plugin-and-content-directories/).
- [Escaping data, including late escaping and numeric/HTML examples](https://developer.wordpress.org/apis/security/escaping/).
- [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).
- [Plugin Check](https://wordpress.org/plugins/plugin-check/).
- [Distribution archives and `.distignore`](https://developer.wordpress.org/cli/commands/dist-archive/).
