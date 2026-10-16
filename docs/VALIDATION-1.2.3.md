# HarborCache 1.2.3 validation

## Drop-in recovery coverage

- The interrupted-update reproduction against the prior recovery logic returned success while leaving the truncated destination in place.
- The new standalone drop-in transaction suite passed 9 checks: partial destination recovery, completed update cleanup, missing-file recovery, foreign-file preservation, failed recovery cleanup, failed first install cleanup, failed upgrade rollback, next-request restore and exact successful replacement.
- PHP 8.5 syntax checks passed for all 23 plugin and focused test PHP files. The existing conflict-notice regression also passed, and `git diff --check` passed.
- The exact 1.2.3 install ZIP was extracted into the local WordPress 7.1.3/PHP 8.5 fixture and passed WordPress Plugin Check 2.1.0 with no errors. The original fixture plugin and drop-in were restored afterward.
- With a damaged but loadable HarborCache drop-in and a verified backup, the WordPress `admin_init` recovery hook restored the saved file byte-for-byte and removed the backup. A core privacy-policy timing notice appeared because the test manually fired `admin_init` during a WP-CLI request; no HarborCache fatal occurred.
- ZIP contents were checked for the expected plugin root and current 1.2.3 metadata; development tests, `.git`, `.github` and media files are excluded from the install package.

## CI and live-site boundary

GitHub Actions [run 38869297138](https://github.com/shahidirfan100/harborcache/actions/runs/38869297138) passed all 9 jobs on commit `77619f791eb2130364777bc768b99637a0c4df17`: PHP 7.4 and 8.2–8.5, WordPress 6.6/latest, Redis 6/7/8, ACL/TLS/socket transports, outage recovery, lifecycle, multisite, WooCommerce, PHP-FPM and Relay integration coverage.

No production files on autoscareer.com were modified, and the local machine has no Redis service on port 6379. Recovery on the affected host remains unverified until the updated package is installed there. If its current drop-in prevents WordPress from loading, follow the manual recovery steps in [deployment guidance](DEPLOYMENT.md). WordPress.org approval is still the review team's decision.
