# HarborCache 1.2.10 validation

Validated runtime commit: `bf2840e`. Release archive: `dist/harborcache-1.2.10.zip`, 600 files, SHA256 `96fe397d95cf7ccb37dd03319cb0c119ffca2a6a8ea0000ed5cf76e41607dd2e`. The build verifier passed source and ZIP parity.

## Scope

1.2.10 changes two CSS rules and the translation template on top of the published 1.2.9. No PHP runtime file, setting, cache key or dashboard markup changed, so the 1.2.9 engine evidence in `VALIDATION-1.2.9.md` carries over unchanged.

## Release checks

- GitHub Actions run [40704819365](https://github.com/shahidirfan100/harborcache/actions/runs/40704819365) on the release commit passed all ten jobs: eight PHP/Redis/WordPress combinations, the relocated-ZIP review and the integrations/benchmark job.
- Matrix: PHP 7.4, 8.2, 8.3, 8.4 and 8.5; Redis 6, 7 and 8; WordPress 6.6 and latest.
- Plugin Check on the exact release ZIP: `Success: Checks complete. No errors found.`
- Package verifier: 600 files, distribution checks passed, SHA256 matches `dist/SHA256SUMS`.
- Local checks on PHP 8.3.36: `php -l` for `harborcache.php`, `includes/object-cache.php` and `deployment/harborcache-loader.php`; `node --check tools/capture-screenshots.mjs`.
- Translation template regenerated with WP-CLI 2.12.0: 295 strings, up from 244 in the 1.2.3 template that shipped with 1.2.9.

## Listing media

- Screenshots were captured on run [40704836977](https://github.com/shahidirfan100/harborcache/actions/runs/40704836977) from the exact 1.2.10 ZIP on WordPress 7.1.3, PHP 8.3.36, PhpRedis 6.3.0, Redis 7.4.12 and MySQL 8.0.
- `.github/workflows/screenshots.yml` installs WordPress, activates the plugin, installs the owned drop-in, warms every captured screen, reseeds the demonstration history and drives headless Chromium.
- Nine PNGs: Status, Analytics, Settings, Cache, Diagnostics, the expanded raw report and the dashboard widget at 1440px, plus Settings and the widget at 375px. The panel footer shows 1.2.10.
- The capture reported zero JavaScript errors, zero console errors and zero size failures.
- The two spacing fixes are visible in the Settings and Cache captures: the group controls no longer touch, and the save and test buttons align with the panel content.
- `media/screenshots.json` records the dimensions and the run; `media/README.md` records the environment and the demonstration-data disclosure.

## Limits

- The engine measurements are the 1.2.9 results and were not repeated, because 1.2.10 changes no engine code.
- The analytics history in the captures is seeded demonstration data. The connection state, client and server versions, cache groups, entry counts and Redis timings come from the live fixture.
- No production site or sustained soak was used; Cluster/Sentinel failover certification remains a separate gate.
