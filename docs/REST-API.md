# REST API

Authenticated routes live under `harborcache/v1`. Use cookie authentication with a REST nonce, or an application password. The capability that opens Settings is required: `manage_options`, or `manage_network_options` on multisite. The `harborcache_manager_capability` filter can change it.

## Routes

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/status` | Connection and readiness summary; no endpoint or credentials |
| GET | `/health` | Four health checks and their facts |
| GET | `/analytics?window=7200&interval=300` | Sampled request summary |
| GET | `/groups` | Bounded group registry |
| GET | `/groups/{scope}/{group}` | Diagnostics for one group |
| GET | `/slowlog` | Latest 25 command names; disabled on shared Redis |
| GET | `/relay` | Relay diagnostics without statistics |
| POST | `/flush` | Flush one group or one scope |

`scope` is `site`, `global`, `all`, or `site-<id>`. Send exactly one of `group` or `scope`. Group flushes never escalate to a full installation flush.

## Errors

| Status | Code | Meaning |
| --- | --- | --- |
| 400 | `harborcache_flush_required` | Neither `group` nor `scope` was sent |
| 400 | `harborcache_flush_ambiguous` | Both `group` and `scope` were sent |
| 400 | `harborcache_invalid_group` | Group name is longer than 200 bytes |
| 400 | `harborcache_invalid_scope` | Scope is not `site`, `global`, `all` or `site-<id>` |
| 401/403 | `rest_forbidden` | Missing authentication or management capability |
| 403 | `harborcache_flush_forbidden` | Network or connection policy forbids the scope |
| 403 | `harborcache_group_forbidden` | Another site was requested without network rights |
| 403 | `harborcache_slowlog_shared` | Slow log is disabled on a shared Redis |
| 404 | `harborcache_unknown_group` | Group is not in the bounded registry |
| 429 | `harborcache_flush_limited` | Flush budget exhausted; retry after `Retry-After` |
| 500 | `harborcache_flush_failed` | Redis did not confirm the flush |
| 503 | `harborcache_unavailable` | Cache is disconnected or the backend did not answer |
| 503 | `harborcache_slowlog_unavailable` | Redis did not return the slow log |

The flush limit is one per minute and ten per hour, per user and site. It is reserved atomically in Redis. A successful flush does not reset it.

## Examples

```bash
curl -u "user:application-password" \
  "https://example.com/wp-json/harborcache/v1/health"
```

```json
{
  "status": "good",
  "state": "connected",
  "issues": [],
  "checks": {
    "connection": { "label": "HarborCache connection", "status": "good", "description": "Transport, namespace, codec and drop-in are current." },
    "dropin": { "label": "HarborCache drop-in", "status": "good", "description": "The owned drop-in matches this plugin version." },
    "server": { "label": "HarborCache Redis server", "status": "good", "description": "Redis policy and capacity checks reported no issue." },
    "cron": { "label": "HarborCache cron schedules", "status": "good", "description": "Enabled cleanup and maintenance events are scheduled." }
  },
  "facts": {
    "connection": { "state": "connected", "transport": "connected", "namespace": "owned", "ready": true },
    "dropin": "current",
    "server": { "available": true, "maxmemory_policy": "allkeys-lru" },
    "cron": { "cleanup": true, "queries": true }
  }
}
```

```bash
curl -u "user:application-password" -X POST \
  -H "Content-Type: application/json" \
  -d '{"group":"posts"}' \
  "https://example.com/wp-json/harborcache/v1/flush"
```

```json
{ "flushed": true, "group": "posts" }
```
