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

- New `tests/metadata-count.php`: warms 30 groups, creates a new cache instance, reads all 30 and asserts metadata stays at or below 8 commands and total commands at or below 40.
- Existing group flush, scope flush, cleanup, concurrent worker, ACL, outage, codec, WordPress, WooCommerce and multisite checks run unchanged.
- Add the final run ID and any limits in `VALIDATION-1.2.9.md`.

## Rollback

1.2.8 remains the previous release. The legacy epoch keys are still written, so rolling back does not require a cache flush. The hash key is additive and can remain until its TTL.
