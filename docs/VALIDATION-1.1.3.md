# HarborCache 1.1.3 validation

1,225 local assertions and 8,632 Linux assertions passed with zero failures, plus 160 successful HTTP requests. Counts include repeated client/codec/transport configurations; they are not a code-coverage percentage.

Linux source: `7f43458ba64c39b5a7f5786c9862648f606f3424`. [Nine-job CI evidence](https://github.com/shahidirfan100/harborcache/actions/runs/38213789404). Runtime and application cases are listed in ACCEPTANCE.md. Real Relay default/adaptive policies each passed 65 checks. PHP-FPM requests at concurrency eight measured p50 195.77 ms, p95 259.04 ms and p99 273.87 ms; these short fixture timings are not production predictions. The 160 counter value survived FPM restart.

The official readme validator returned no errors or warnings, with only the optional donation-link note. Plugin Check, experimental checks and five initialized runtime performance checks reported no findings. WP-CLI dependencies emit PHP 8.5 deprecations outside the plugin.

The installable package contains 610 files. ZIP CRC and extracted bytes passed; source, local installed plugin, extraction and repository product files match. Owned drop-in bytes match. The package was installed through WordPress's normal ZIP installer. The old owned 1.1.2 drop-in loaded the new module and refreshed correctly. Nine current desktop/mobile screenshots have no browser JavaScript errors. Layout, heading, notice, chart and matching-control regressions pass.

SHA256: `7c7ce2dc187c2dc1f7175a8de4b0ea21ba68a077bb9908ccc0a2ba31ecf79803`

See COMPARISON-1.1.3.md for all seven operation timings, including regressions, CPU/memory/command counts, and remaining gates. Native Relay shared-FPM/topology/failover, sustained traffic and production tracing still need acceptance. WordPress.org approval and complete Pro parity are not claimed.

Antislop remains active during development: the existing teal/system-font design is retained, controls are functional, copy reflects real evidence, and no decorative redesign or fabricated speed claim was added.
