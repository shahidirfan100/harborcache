# HarborCache 1.2.3 validation

## Drop-in recovery coverage

- The interrupted-update reproduction against the prior recovery logic returned success while leaving the truncated destination in place.
- The new standalone drop-in transaction suite passed 9 checks: partial destination recovery, completed update cleanup, missing-file recovery, foreign-file preservation, failed recovery cleanup, failed first install cleanup, failed upgrade rollback, next-request restore and exact successful replacement.
- PHP 8.5 syntax checks passed for all 23 plugin and focused test PHP files. The existing conflict-notice regression also passed, and `git diff --check` passed.
- The exact 1.2.3 install ZIP was extracted into the local WordPress 7.1.3/PHP 8.5 fixture and passed WordPress Plugin Check 2.1.0 with no errors. The original fixture plugin and drop-in were restored afterward.
- With a damaged but loadable HarborCache drop-in and a verified backup, the WordPress `admin_init` recovery hook restored the saved file byte-for-byte and removed the backup. A core privacy-policy timing notice appeared because the test manually fired `admin_init` during a WP-CLI request; no HarborCache fatal occurred.
- ZIP contents were checked for the expected plugin root and current 1.2.3 metadata; development tests, `.git`, `.github` and media files are excluded from the install package.

## Remaining release checks

The Linux acceptance workflow still needs to run on GitHub after this source change. No production files on autoscareer.com were modified, and the local machine has no Redis service on port 6379. WordPress.org approval and recovery behavior on the affected host remain unverified until the updated package is installed there.
