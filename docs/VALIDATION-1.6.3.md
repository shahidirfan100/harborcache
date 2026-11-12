# HarborCache 1.6.3 validation

Validated code commit: `0086a5e`. PR: #16. Release archive: `dist/harborcache-1.6.3.zip`, 604 files, SHA256 `0255d0258771e265dcd1bfd678ebc9197678971ee56a317c9794f316097ee00d`. The build verifier passed source and ZIP parity.

## What changed

1.6.3 fixes the two findings from the source security audit. Cache routes, keys, settings and the dashboard layout are unchanged.

- Serialized cache values now carry a V2 HMAC. `Codec::decode()` verifies it before `unserialize()` and rejects unsigned V1 values. A writable Redis namespace can therefore add, remove or corrupt values but cannot inject a PHP object. Existing values are treated as misses and rebuilt.
- REST flushes reserve their slot atomically with one Redis script: INCR both counters, set their expiry, return the counters and the blocking TTL. Parallel requests can no longer pass the 1/minute check together. The old user-meta check remains only for Redis ACLs that deny EVAL.
- The HMAC uses `AUTH_KEY`, then `wp_salt('auth')`, then a non-secret installation hash. Rotating salts cools the cache; it does not corrupt stored data.

## Local checks

| Check | Result |
| --- | --- |
| PHP lint on changed files | 0 failures |
| 13 offline suites, including the new codec signature test | 0 failures |
| `python tools/build-release.py` | 604 files, distribution checks passed |
| Source and ZIP parity | passed |

## CI

Run [43163620395](https://github.com/shahidirfan100/harborcache/actions/runs/43163620395) passed all 14 jobs on `0086a5e`, including the live REST limiter, multisite scope, application-password access and cache reads with signed values.

## Limits

- The HMAC protects integrity and object injection; it does not make a compromised Redis confidential. An attacker with Redis read access still sees cached values.
- Sites without `AUTH_KEY` and without `wp_salt()` fall back to a non-secret integrity key. Define WordPress salts on those sites.
- Screenshots: `media/` recaptured from this ZIP in run 43163654793 (version 1.6.3).
