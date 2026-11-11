# HarborCache 1.6.2 validation

Code commit: this PR head. PR: pending. Release archive: `dist/harborcache-1.6.2.zip`, 604 files, SHA256 `238d4895037ec8414775b89af03efbd7f2288fe2a3a7be1bf514adffa762e660`. The build verifier passed source and ZIP parity.

## What changed

1.6.2 is the safety follow-up to 1.6.1. No cache key format, configuration key, REST route or dashboard layout changed.

- Another object-cache manager's constants or drop-in stop HarborCache before it registers admin UI, REST routes or drop-in writes, in either activation order.
- Admin-post `flush-global` and cross-site `flush-site` now use the same network check as REST.
- REST cache flushes are limited per user to one per minute and ten per hour, with `Retry-After`; the budget lives in user meta and survives the flush it protects.
- A warning appears when a remote Redis endpoint is unencrypted or has no password, because object values are unserialized.
- Application-password HTTP access and multisite REST scope checks are covered in CI.

## Local checks

| Check | Result |
| --- | --- |
| PHP lint on `includes/` | 0 failures |
| 12 offline suites, including the new arbitration test | 0 failures |
| `python tools/build-release.py` | 604 files, distribution checks passed |
| Source and ZIP parity | passed |

## CI

Pending on this PR head. The matrix runs the 14 jobs, including application-password HTTP access, multisite REST scope, the flush limiter and the package checks.

## Limits

- The both-order test simulates a second manager with its constants and drop-in marker. It does not bundle the other plugin or prove its activation sequence; that remains a live compatibility check.
- The dashboard and screenshots are unchanged. `media/` still shows the 1.6.1 capture; recapture only when the dashboard changes.
- The limiter is per user per site. On multisite, user meta is site-scoped, so a network administrator receives a separate budget on each site.
