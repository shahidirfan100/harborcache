# HarborCache 1.7.0 validation

Validated code commit: this PR head. PR: pending. Release archive: `dist/harborcache-1.7.0.zip`, 606 files, SHA256 `32a036870611ac8ed6ec80d084b78d266e6ceeee79f64ff57fbf99edf2ec0b22`. The build verifier passed source and ZIP parity.

## What changed

1.7.0 is the operator-visibility release. REST routes, cache keys, settings and the dashboard layout are unchanged.

- One `Status::health_facts()` provider returns connection, Redis server, drop-in and cron checks plus their facts.
- Site Health shows four separate tests instead of one combined result.
- REST `/health` returns the same `checks`, `facts` and `issues`.
- `class-console.php` is no longer loaded on logged-in front-end requests. Admin, AJAX, cron and CLI requests keep the console.
- Added `docs/REST-API.md` with routes, error codes and examples, and `docs/SECURITY.md` with the trusted-Redis, shared slow-log, configuration-file and destructive-action boundaries.
- The package includes both new guides; `tools/build-release.py` now allowlists them.

## Local checks

| Check | Result |
| --- | --- |
| PHP lint on changed files | 0 failures |
| 13 offline suites | 0 failures |
| `python tools/build-release.py` | 606 files, distribution checks passed |
| Source and ZIP parity | passed |

## CI

Pending. The acceptance job checks the four Site Health tests, the REST health payload and the existing REST, multisite and application-password paths.

## Limits

- Front-end bootstrap was not benchmarked before and after. The code no longer loads the console for logged-in front-end requests; a CPU comparison remains open.
- The front-end admin-bar item is no longer registered because the console is admin-only. The dashboard widget, settings screens and REST remain.
- Screenshots still show the 1.6.3 capture until the 1.7.0 capture runs.
