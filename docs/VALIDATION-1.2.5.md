# HarborCache 1.2.5: attribution cleanup validation

Date: 21 October 2026. Baseline: 1.2.4 at commit `1577279` on `codex/wordpress-review-1.2.4`. This is a documentation, comment and release-metadata update. It does not ship the proposed independent engine.

## Preservation checks

- PHP token comparison against the baseline passed for all four edited PHP files: the main plugin, drop-in, legacy plugin class and optional MU-loader. Comments and whitespace were excluded; all executable tokens remained identical. Version headers intentionally changed from 1.2.4 to 1.2.5, so WordPress and drop-in revision reporting reflect this update.
- Git blob comparisons passed for all 602 tracked files under assets, media, vendor and languages. All 40 tracked visual/media files were byte-identical. No dashboard markup, colors, CSS, JavaScript, icons, banners, screenshot files or translations were edited.
- No configuration defaults, saved settings, key formats, namespace rules, cache algorithms, public hook signatures or return values changed. The legacy empty-link hook remains callable as a no-op. Existing competitor conflict checks remain intact.
- The free upstream GPL license and source attribution remain. Historical comparison and validation documents were preserved rather than rewritten as current claims.

## Checks performed

| Check | Result |
| --- | --- |
| Syntax of the four edited PHP files | Passed on local PHP 8.5. |
| Drop-in writer recovery suite | 12 passed, 0 failed. |
| Fresh-process review regressions | 9 passed, 0 failed. |
| Conflict-notice regression | Passed. |
| Distribution input audit | Passed; 598 files. |
| Exact ZIP/source verification | Passed. |
| Final installed file parity | All 598 installed files matched the final ZIP bytes after installation and Plugin Check. |
| ZIP comparison with 1.2.4 | Same file list; 592 byte-identical files and six changed files. Changes are the main/drop-in headers, a legacy comment, readme and two user guides. |
| Whitespace/error-marker check | `git diff --check` passed. |
| Fresh local WordPress install and activation | Passed on the isolated WordPress 7.1.3 / PHP 8.4.26 SQLite fixture. |
| Local Plugin Check | Both the initial package and the final documentation revision passed: `Success: Checks complete. No errors found.` The final ZIP was installed and activated again before its check. |
| Installed engine and renamed directory checks | Seven assertions passed in each location: 14 total. Verified template matching, content paths, prefix identity, Predis loading, escaped output and typed runtime values. |
| Directory move and drop-in staging | Safe built-in-cache fallback after rename passed; restaging the owned drop-in passed. |
| Deactivation and uninstall | Commands succeeded; owned drop-in removal, foreign drop-in preservation and guarded configuration removal passed before the final debug-log assertion. |
| Full local script exit | Failed its final empty-debug-log assertion. Redis was unavailable locally; the log contains the expected redacted backend-unavailable exceptions and their stack traces. The successful checks above are not presented as a fully green script. |

## Install archive

- Path: `dist/harborcache-1.2.5.zip`.
- Root: `harborcache-object-cache-with-redis/`.
- Files: 598.
- SHA-256: `f137b1efd444acccd4dcddc5dba925da1c230d58a9d4bbadaeadc8b22f2de480`.
- The independent-engine plan and development reports are excluded from the install ZIP. Runtime source and bundled licenses remain present.
- The previous verified 1.2.4 ZIP is preserved outside the repository at `outputs/harborcache-1.2.4-before-attribution-cleanup.zip`, relative to the workspace root, for rollback. The repository's dist directory contains only the current install ZIP.
- Updated the previously stale `dist/SHA256SUMS` entry to identify the current 1.2.5 package.

## Evidence boundaries

The subsequent same-version readme refinement leads with HarborCache's purpose, its website-administration needs and its own development direction. Source credit is retained in README.md's license section and readme.txt's foundation FAQ. Only README.md, readme.txt and the release-note wording changed in that refinement; the install ZIP and its checksum were refreshed without a version bump. Previously tested executable PHP and all dashboard/branding assets are unchanged. The refreshed ZIP was installed into the isolated fixture and passed Plugin Check with no errors; all 598 installed files matched its bytes.

Local fixture: `work/runtime/harborcache-125-clean-1792561797`; its `qa-evidence/` directory retains per-step output and `content-moved/debug.log` retains the unavailable-backend diagnostics. The log was not deleted to make the script pass. Requests completed with runtime-memory caching. This is outage/fallback evidence, not proof of successful local Redis persistence. No uncaught PHP fatal, warning, notice or deprecation was identified in that log; logged exception traces are still present.

The earlier 1.2.4 Linux CI results remain historical evidence for the unchanged executable cache implementation. They are not a new 1.2.5 CI run. The targeted checks above verify the actual 1.2.5 working tree and package. No live website was modified and no new speed or CPU improvement is claimed for this cleanup.

The package was validated locally before repository publication. No upstream contribution, WordPress.org upload or developer message was sent as part of this cleanup. The proposed reply is saved separately for the author to review and send.

The independent engine requires the separate gates in INDEPENDENT-ENGINE-PLAN.md. It must not be described as implemented, production-validated or free from inherited-code attribution based on this update. WordPress.org approval remains its reviewers' decision.
