# HarborCache 1.2.3

This maintenance release fixes an interrupted object-cache drop-in update that could leave a partial `wp-content/object-cache.php` in place. HarborCache now checks the active file against the saved previous copy and the packaged drop-in, restores a verified HarborCache backup when the destination is damaged, and keeps unrecognized foreign drop-ins untouched.

If a filesystem write or rollback fails, HarborCache removes an incomplete canonical file when possible and preserves the backup. WordPress can then start with its request-local cache while recovery is retried. The dashboard reports a specific recovery failure, and the PHP error log receives a short message without Redis credentials or endpoint details.

No Redis connection settings, key formats, namespaces, cache behavior or dashboard layout changed.
