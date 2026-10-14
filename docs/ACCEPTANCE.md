# HarborCache 1.2.0 MVP acceptance and release plan

## Existing Linux acceptance baseline

The Linux acceptance workflow in `.github/workflows/acceptance.yml` creates disposable MySQL and Redis services. These historical 1.1.3 gates remain active for the 1.2.0 MVP; they are isolated acceptance tests, not measurements of autoscareer.com.

| Gate | Recorded evidence |
| --- | --- |
| Linux/MySQL runtimes | Ubuntu 24.04, MySQL 8.0, WordPress 7.1.3; PHP 7.4 and 8.2–8.5 with Redis 7; PHP 8.3 additionally with Redis 6 and 8. WordPress 6.6 also passed with PHP 7.4/Redis 7. Eight selected combinations, not the full cross-product. |
| Clients and codecs | Real Predis, Credis and PhpRedis; PHP/igbinary serialization paired with none/LZF/LZ4/Zstandard compression. Each native codec must actually load; missing extensions cannot count as passed. |
| Transports | Predis/PhpRedis ACL credentials, trusted TLS, rejection of an untrusted certificate and Unix socket. |
| Concurrency and recovery | Four PHP processes increment one counter; independent option fields updated across a barrier; real Redis stop/start, fresh-worker recovery, activation/deactivation/reactivation and cron. |
| Applications | Real WooCommerce product/cart/checkout/order update/stock reduction and restoration; real multisite local/global groups, separate options and selected-site invalidation. These are API-level integration flows, not browser payment-gateway tests. |
| Performance baseline | Seven rotating Linux runs compare Redis Object Cache 3.0.0, HarborCache 1.1.5 and HarborCache 1.2.0 with identical sequential work. Batch p50/p95/p99, PHP/Redis CPU, memory, command counts and optional analytics recording are retained. They do not predict production traffic. |
| Local regression and interface | Windows WordPress/SQLite/PHP 8.5/Redis tests cover cache types, counters, bulk operations, cleanup, primary/replica/Sentinel/cluster behavior, lifecycle, failed file operations, guarded controls, first-request upgrade and responsive dashboard layouts. |

The earlier 1.1.3 Relay run passed 65 checks under its default and adaptive policies. During the 1.2.0 fresh-worker test, Relay's shared cache exposed a stale miss for HarborCache namespace metadata, which could make newly created cache objects use different roots. HarborCache now reads root, generation and cleanup guard tokens with Relay's local cache temporarily bypassed. The final 1.2.0 run passed 75 checks under each Relay policy, including fresh-client key agreement, concurrent option patches, bounded cleanup and forced-read bypass. PHP-FPM served 160 successful requests at concurrency eight and retained the counter after a restart. These are short functional checks, not sustained traffic or Relay topology-promotion certification. See the [validation report](VALIDATION-1.2.0.md) for the final run and limits.

## 1.2.0 MVP gates

The approved MVP preserves the serialized cache-key format and WordPress cache API while adding fresh-worker replacement, stable namespace agreement, non-persistent wildcard-group and independent alloptions-patch checks. Its Linux performance job compares Redis Object Cache 3.0.0, the packaged 1.1.5 baseline and the 1.2.0 release build with seven rotating workload rounds. A second probe times group invalidation with 100 and 5,000 keys. It records tail timings and Redis command counts; runner timings are evidence for those workloads, not promises about another server.

The hot-path change removes a temporary layout array from repeated cache-key checks. Keep it only if the benchmark does not show a repeatable regression. Dashboard changes make counts locale-formatted and raise type sizes across the overview, chart, settings form and dashboard widget while retaining the established HarborCache colors and equal connection-control dimensions. The UI release gate includes keyboard focus, 200% zoom, narrow layout, legible labels and version-free screenshots.

## Remaining gates, in order

1. Measure sustained normal traffic on an isolated copy of the actual site: PHP-FPM CPU/RSS, Redis CPU/RSS, tail latency, cache churn, large options and analytics on/off. Short runner tests cannot establish long-running memory stability or capacity.
2. Exercise Relay adaptive behavior, cluster/replica/Sentinel promotion and partition recovery with concurrent readers/writers. Preserve primary metadata and same-request read-after-write behavior. Standalone extension evidence is distinct from native topology certification.
3. Verify New Relic with a real configured agent/account and OpenTelemetry against the intended production collector, including duplicate-instrumentation checks. Local real SDK/OTLP HTTP delivery has passed; account delivery cannot be tested without that integration.
4. Expand application coverage to the site's payment gateway, queues, cron, publishing plugins and deployment/rollback process. Legacy WP_REDIS_SHARDS/RedisArray paths are inherited but not certified with HarborCache's generation namespace; use the documented standalone/replication/Sentinel/cluster configurations.
5. Complete WordPress.org submission/review and verified update distribution. Plugin Check, GPL notices and readme validation support submission; they do not grant directory approval or trademark clearance.

## Version plan

| Version | Work | Acceptance gate |
| --- | --- | --- |
| 1.2.0 MVP | Fresh-worker and ignored-group correctness, Relay metadata consistency, measured hot-path change, group-invalidation comparison, readable dashboard and refreshed assets | Linux matrix and real Relay default/adaptive acceptance pass; workload comparison shows no material regression; package, Plugin Check and readme validation pass |
| 1.3.0 | Relay adaptive behavior under shared PHP-FPM; native cluster/Sentinel/replica promotion and failover; production tracing integrations | Real agents/extensions, concurrent writers/readers, controlled promotion/partition and duplicate-span checks |
| 1.4.0 | Further hot-path and prefetch tuning; selective stale-query reclamation | Sustained workload CPU/RSS and latency evidence without premature invalidation or cross-site leakage |
| 1.5.0 | Wider site/application matrix and deployment/update/rollback distribution | Site-specific workflows, package parity and directory review |

Default analytics samples 10%, retained history is bounded and browser charts do not poll. Prefetch, query recycling, debug recording and external tracing remain off by default. On-demand node/group probes do not run on every frontend request. These controls limit optional work; they are not universal CPU guarantees.

For the 1.2.0 release, install first on an isolated staging copy, preserve the existing connection settings, and refresh the owned drop-in. Confirm Connected and the drop-in version in Diagnostics before exercising application flows. No package was uploaded to autoscareer.com during this acceptance run. Never inject outages, failover or synthetic load into a shared production Redis service.
