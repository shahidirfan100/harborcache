# 1.9 architecture

This release starts the read-path refactor. Behavior is frozen; the goal is to give bulk reads one owner instead of adding another branch to the inherited cache class.

## Shipped in 1.9.0, hardened in 1.9.1

### `HarborCache\Key_Builder`

`includes/class-key-builder.php` owns:

- layout signature and invalidation;
- group/scope prefix construction;
- token and group-registration callbacks;
- request-local derived, prefix, raw-key and id caches;
- `build()`, `build_sanitized()`, `build_many()` and `cached_id()`.

`HarborCache_Advanced_Cache` now delegates `build_key()`, `fast_build_key()`, layout checks, cached ids and every namespace/layout reset to this class. The cache still supplies Redis token, scope, registration, ignored-group and fallback callbacks. The key bytes are unchanged.

The cache's runtime-hit path reads the `ids` and `layout_state` fields directly instead of calling back into the builder, because that check runs on every memory hit. The callback form measured +19% on the memorised-hit loop against 1.8.1; the inline form with the snapshot hoisted once measures +1.6% (4.5 ns per hit), which is the residual cost of reading another object. `tests/status-matrix.php` still asserts that a layout change bypasses the runtime hit, and `layout_current()` remains the single description of the layout; keep the inline comparison in sync with it.

### Bulk key seam

`WP_Object_Cache::get_multiple()` now calls a protected `build_keys()` seam. The inherited implementation keeps the old loop; the advanced cache overrides it with `Key_Builder::build_many()`. This is the first split between batch key preparation and batch fetching.

### Tests

`tests/key-builder.php` covers stable keys, cached ids, batch maps, layout rotation, compression changes and root rotation. `tests/status-matrix.php` continues to cover runtime-hit invalidation and Relay behavior.

## Next extractions

These are deliberately not bolted into 1.9.0 or 1.9.1:

1. `Bulk_Reader`
   - owns runtime-hit selection, `MGET`, decode, metrics and backend failure mapping;
   - `get_multiple()` delegates; behavior tests stay in acceptance.
2. `Value_Envelope`
   - split V1/V2, HMAC and the request-local signature caches from serializer/compression.
3. `Admin_Controller`
   - split settings, nonce handling and save-config from the 1,188-line `Console` class before new UI work.
4. `Flush_Service`
   - split group/scope rotation and async reclaim from the cache subclass.

Each extraction keeps public keys, routes, hooks and settings unchanged and lands with its own regression tests. No extraction is mixed with a performance claim.
