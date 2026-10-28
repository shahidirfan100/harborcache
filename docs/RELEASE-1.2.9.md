# HarborCache 1.2.9 release notes

## Scope

1.2.9 changes the namespace metadata protocol. The key format, token format, cleanup guards, settings, dashboard, palette and product name are unchanged.

## Namespace metadata

- One `HGETALL` loads all scope and group epochs for the request.
- One `SMEMBERS` loads the group registry.
- Known groups register nothing on reads. Writes refresh the registry and epoch lifetimes once per group per request.
- A group with no registry entry creates only its epoch field on a read; the first write registers it.
- Missing hash fields migrate from the legacy `scope:epoch` and `group:epoch` keys on first use, preserving the token and existing entries.
- Legacy epoch keys remain for the cleanup guards and older workers.
- The hash key includes the root. A full flush starts a new hash; a scope or group flush updates the matching field and the legacy key.

## Expected cost

For a request touching 30 groups, the steady-state metadata cost is 2 commands after prefix ownership and root initialization (one `HGETALL`, one `SMEMBERS`) instead of 1 GET and 1 EVAL per group. The first request after upgrading pays the legacy migration per used field.

## Verification

The `Linux acceptance` workflow passed all ten jobs on run [40692090577](https://github.com/shahidirfan100/harborcache/actions/runs/40692090577): eight PHP/Redis/WordPress combinations, the relocated-ZIP review and the integrations/benchmark job. Plugin Check reported no errors or warnings for the exact 1.2.9 ZIP.

`tests/metadata-count.php` warmed 30 groups, created a new cache instance and read all 30. Metadata inside the read loop was 2 commands (one `HGETALL`, one `SMEMBERS`) and total commands were 32. The prefix owner and root add 2 before the loop. The previous protocol used about 63 metadata commands for the same groups.

A rejected `SADD` now disables persistent writes on the first write attempt and falls back to request memory. Read-only requests are not disabled by a registry denial.

See `VALIDATION-1.2.9.md` for the command count, engine table and limits.

## Rollback

1.2.8 remains the previous release. The legacy epoch keys are still written, so rolling back does not require a cache flush. The hash key is additive and can remain until its TTL.
