# Deployment acceptance and next releases

## Version 1.1.3

The Linux acceptance workflow is checked into .github/workflows/acceptance.yml in the GitHub repository; tests are excluded from the installable ZIP. It creates disposable MySQL and Redis services. The previous limitation of having no Linux staging environment has been addressed with GitHub-hosted Linux runners. These are isolated acceptance tests, not measurements of autoscareer.com.

| Gate | Recorded evidence |
| --- | --- |
| Linux/MySQL runtimes | Ubuntu 24.04, MySQL 8.0, WordPress 7.1.3; PHP 7.4 and 8.2–8.5 with Redis 7; PHP 8.3 additionally with Redis 6 and 8. WordPress 6.6 also passed with PHP 7.4/Redis 7. Eight selected combinations, not the full cross-product. |
| Clients and codecs | Real Predis, Credis and PhpRedis; PHP/igbinary serialization paired with none/LZF/LZ4/Zstandard compression. Each native codec must actually load; missing extensions cannot count as passed. |
| Transports | Predis/PhpRedis ACL credentials, trusted TLS, rejection of an untrusted certificate and Unix socket. |
| Concurrency and recovery | Four PHP processes increment one counter; independent option fields updated across a barrier; real Redis stop/start, fresh-worker recovery, activation/deactivation/reactivation and cron. |
| Applications | Real WooCommerce product/cart/checkout/order update/stock reduction and restoration; real multisite local/global groups, separate options and selected-site invalidation. These are API-level integration flows, not browser payment-gateway tests. |
| Performance | Seven rotating Linux runs compare Redis Object Cache 3.0.0, packaged HarborCache 1.1.2 and current HarborCache with identical sequential work. Batch p50/p95/p99, PHP/Redis CPU, memory, command counts and optional analytics recording are retained. They do not predict production traffic. |
| Local regression and interface | Windows WordPress/SQLite/PHP 8.5/Redis tests cover cache types, counters, bulk operations, cleanup, primary/replica/Sentinel/cluster behavior, lifecycle, failed file operations, guarded controls, first-request upgrade and responsive dashboard layouts. |

Real Relay 0.50.2 passed 65 checks both with its default cache policy and a configured adaptive policy, including concurrent option patches and explicit forced-read bypass checks. PHP-FPM served 160 successful requests at concurrency eight and preserved the shared counter across an FPM restart. These are short standalone CLI/HTTP checks, not sustained load or native Relay shared-FPM/topology certification. The final validation report retains the measurements. Native Relay topology/adaptive failover, production tracing delivery and sustained workload certification must not be inferred from standalone CLI or short HTTP tests.

## Remaining gates, in order

1. Measure sustained normal traffic on an isolated copy of the actual site: PHP-FPM CPU/RSS, Redis CPU/RSS, tail latency, cache churn, large options and analytics on/off. Short runner tests cannot establish long-running memory stability or capacity.
2. Exercise real Relay adaptive behavior, cluster/replica/Sentinel promotion and partition recovery with concurrent readers/writers. Preserve primary metadata and same-request read-after-write behavior. Real standalone extension evidence is distinct from native topology certification.
3. Verify New Relic with a real configured agent/account and OpenTelemetry against the intended production collector, including duplicate-instrumentation checks. Local real SDK/OTLP HTTP delivery has passed; account delivery cannot be tested without that integration.
4. Expand application coverage to the site's payment gateway, queues, cron, publishing plugins and deployment/rollback process. Legacy WP_REDIS_SHARDS/RedisArray paths are inherited but not certified with HarborCache's generation namespace; use the documented standalone/replication/Sentinel/cluster configurations.
5. Complete WordPress.org submission/review and verified update distribution. Plugin Check, GPL notices and readme validation support submission; they do not grant directory approval or trademark clearance.

## Version plan

| Version | Work | Acceptance gate |
| --- | --- | --- |
| 1.1.3 | Measured cache-path improvements, native counter/forced-read correctness and Linux/MySQL/transport/codec/application acceptance | Published reproducible CI and local validation; preserve namespace and existing controls |
| 1.2.0 | Complete real Relay adaptive/topology invalidation and native failover; production tracing integrations | Real agents/extensions, concurrent writers/readers, controlled promotion/partition and duplicate-span checks |
| 1.3.0 | Further hot-path and prefetch tuning; selective stale-query reclamation | Sustained workload CPU/RSS and latency evidence without premature invalidation or cross-site leakage |
| 1.4.0 | Wider site/application matrix and deployment/update/rollback distribution | Site-specific workflows, package parity and directory review |

Default analytics samples 10%, retained history is bounded and browser charts do not poll. Prefetch, query recycling, debug recording and external tracing remain off by default. On-demand node/group probes do not run on every frontend request. These controls limit optional work; they are not universal CPU guarantees.

Install 1.1.3, preserve the existing connection settings, and refresh the owned drop-in. Confirm Connected and version 1.1.3 in Diagnostics. No package was uploaded to autoscareer.com during this acceptance run. Never inject outages, failover or synthetic load into a shared production Redis service.
