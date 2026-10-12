# HarborCache 1.1.3

This update reduces repeated ignored-group matching and inactive command instrumentation work. Group matching uses exact lookups, compiled wildcard patterns and at most 256 remembered results per request. Registering new nonpersistent groups clears those results immediately. Namespace formats, connection settings, the teal dashboard, readable chart labels and matching connection controls are preserved.

Real Relay testing exposed two correctness problems missed by the adapter fixtures. Counter Lua replies now carry an explicit success status, preserving failure versus integer zero across native clients. Forced Relay reads bypass its local cache; forced topology reads use the primary. The previous cache mode is restored even if the read fails. No cached data format changes are required.

The repository includes repeatable Linux/MySQL acceptance, extension/transport checks, rotating performance comparisons and application tests. Development tests and their disposable endpoints are excluded from the installable ZIP. No REST or optional public helper APIs were added.

See ACCEPTANCE.md and the published validation report for exact tested versions, measurements and remaining deployment gates. A passing Plugin Check/readme validator is not WordPress.org approval. This release does not claim complete Object Cache Pro parity or lower CPU than every free Redis plugin.

Install the release ZIP, keep the existing connection settings and refresh the owned drop-in. Confirm version 1.1.3 and Connected in Diagnostics. Before changing an existing production site, retain its previous package and configuration and check its frontend, login, publishing, checkout and scheduled work after the update. HarborCache does not install or restart the Redis server.
