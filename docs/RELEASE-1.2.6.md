# HarborCache 1.2.6

This update prevents prefix ownership takeover, validates namespace replies, removes unnecessary startup discovery and keeps critical option reads on primary nodes. It implements the 1.2.6 stage of the approved research roadmap. Guided configuration promotion, bounded diagnostic sessions and a replacement engine remain later work.

## Behavior changes

- Startup and the enable/reconnect probe preserve foreign ownership and root tokens. A conflicting installation uses its configured fallback policy instead of invalidating the existing owner.
- Corrupt metadata and unsuccessful namespace SET replies cannot create a usable cache namespace. Existing valid key formats are preserved.
- Optional server-version information is lazy, attempted once per connection state. Denied optional INFO no longer disables otherwise valid cache startup. Predis avoids unnecessary database-zero selection.
- Options and site-options groups use primary nodes for single and bulk reads. Other configured replica reads retain their documented eventual-consistency behavior.
- Native read failures discard the connection before it can be reused with potentially pending replies. Safe reconnect tests cover nonzero database selection.
- Forced Relay reads rebuild a closed connection from the existing configuration before accessing connection-local cache options. Reconnect preserves the selected database, and the cache bypass is restored after a successful forced read. Setup failures follow the configured fallback policy.
- Normal fallback logs are concise, redacted and limited to one per request across cache instances. Explicit debug mode retains redacted exception traces. There is no cross-request circuit breaker or global log-rate guarantee.

## Upgrade

Back up the plugin and owned drop-in, install the ZIP and refresh HarborCache's drop-in. Existing connection settings and valid cache namespaces are preserved. A cloned installation that reuses another site's prefix must select a distinct prefix; an installation whose identity was deliberately changed can restore its original identity. HarborCache does not automatically reclaim another owner's namespace.

The dashboard markup, color scheme, CSS, JavaScript, icons, banners and screenshots remain unchanged. No REST endpoints, public helper APIs, server-wide tuning or proprietary implementation code were added. The documented engine attribution and bundled licenses remain.

Validation, comparative measurements and remaining deployment limits are recorded in VALIDATION-1.2.6.md. Passing the automated checks does not guarantee WordPress.org approval or performance on every production host.
