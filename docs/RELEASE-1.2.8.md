# HarborCache 1.2.8 release notes

## Scope

1.2.8 fixes the connection setup path and the dashboard structure. It does not change the cache key format, the namespace layout, the product name or the teal palette.

## Connection setup

- One check path, shared by Save, Test saved connection and Enable.
- The check uses the selected client for a single node. Predis, PhpRedis, Relay and Credis are exercised with their own driver. Cluster, Sentinel and replica configurations are checked with Predis, and the result says so.
- A five second minimum budget, independent of the one second runtime connect timeout.
- A temporary key is written, read and deleted inside the installation namespace.
- Specific results for refused, timed out, authentication, ACL, TLS, DNS, Unix socket and database failures.
- A failed save keeps the submitted values and shows the reason next to the form.

## Status and screens

- States: not installed, installed and waiting for reload, not connected, connected, disabled by configuration.
- Screens: Status, Settings, Cache, Analytics, Diagnostics.
- The saved-connection test has a visible pending state and an `aria-live` result.
- Clear actions name their scope. Clearing request memory no longer asks for an invalidation.
- The dashboard widget is one state line, one metric line and one link.
- The raw diagnostics report stays available behind a summary.

## Dashboard and widget

- The widget keeps the request table, sampled summary, chart and both dashboard links, with separate request-memory and Redis counters.
- The Status page builds one status snapshot per request instead of two.
- A single-node first-use group registration is one Lua command instead of SADD plus three EXPIRE commands.
- Configuration defaults are cached per request, and the connection probe class loads only when an admin action needs it.
- The widget does not serialize the runtime cache to show its numbers.

## Client selection

- New installs and configurations without a saved client use PhpRedis when `\Redis` exists, matching the free plugin.
- Saved Predis installations keep Predis.
- A notice on the Settings screen offers the switch. The next request uses the new client.
- A string fast path was implemented, measured and reverted: the benchmark lane showed no improvement outside run noise.

## Verification

The `Linux acceptance` workflow passed all nine jobs on run [40675868347](https://github.com/shahidirfan100/harborcache/actions/runs/40675868347): eight PHP/Redis/WordPress combinations, the relocated-ZIP review and the integrations/benchmark job. Plugin Check reported no errors or warnings.

Local checks used while preparing the release:

- PHP 8.3.36 `php -l` over every PHP file outside `vendor/`.
- `tests/dropin-writer.php` (12), `tests/review-regressions.php` (9), `tests/safety-regressions.php` (21), `tests/metrics-failure.php`, `tests/conflict-notice.php`.
- `tests/connection-check.php` against a refused endpoint and against a local RESP fixture.
- A WordPress-free render harness for Status, Settings, Cache and Diagnostics that reported no notices, warnings or em dashes.

Measured in the same acceptance run: operation CPU was 10.7% above Redis Object Cache 3.0.0 and 5.6% below 1.2.6. The 5% target was not met. See `VALIDATION-1.2.8.md` for the table and limits.

A string fast path was implemented, measured in run 40677015431 and reverted. It did not improve the measured workload, so the value format is unchanged.
