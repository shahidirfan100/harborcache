# HarborCache 1.1.5 validation

This release restores the plugin's complete display name and refreshes the reusable product screenshots. Cache implementation, connection settings and namespace behavior are unchanged.

## Results

- WordPress Plugin Check, experimental checks enabled: completed with no findings.
- Initialized runtime performance checks: five checks completed with no findings. The local owned drop-in was restored and its file contents verified after the run.
- WordPress.org readme validator: zero errors and zero warnings. The optional note reports that no donation link is present.
- PHP syntax: all five changed PHP files passed `php -l`.
- Upgrade identity: the local fixture's installed 1.1.3 header and uploaded 1.1.5 header both report `HarborCache – Object Cache with Redis`; 1.1.5 is newer. A local ZIP upgrade from 1.1.4 to 1.1.5 completed successfully and kept the plugin active.
- Runtime smoke: the package-installed plugin reports Redis connected, and its owned object-cache drop-in reports version 1.1.5.
- Dashboard title: the WordPress Plugins screen and settings page both render `HarborCache – Object Cache with Redis`. The widget retains the shorter HarborCache label.
- Screenshots: nine fresh captures from the local WordPress fixture at 1440px and 375px. The settings screens had no horizontal overflow, screenshots contain no visible 1.1.5 string, and the browser reported no JavaScript errors. Chart samples are seeded fixture data.

The WordPress CLI's bundled libraries print PHP 8.5 deprecation notices during checker execution; Plugin Check itself reports no plugin findings. The screenshots use an isolated headless Chrome process. No browser version or setting was changed, and no update or load test was run on autoscareer.com.

No cache-path or database behavior changed, so this identity and media release does not claim new cache throughput, latency, or CPU measurements.

These checks verify the identity correction, package metadata and dashboard presentation. They do not represent WordPress.org approval or a production workload benchmark. See [deployment acceptance gates](ACCEPTANCE.md) for the remaining environment-specific tests.
