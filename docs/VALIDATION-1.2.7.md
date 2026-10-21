# HarborCache 1.2.7 validation

Work in progress. This file is updated with final package and CI evidence after acceptance. It must not be interpreted as a passing release certificate while gates remain pending.

Baseline: 1.2.6, commit `6d235793ec8ad5bcbd9885b35c8043d6915fcea9`, ZIP SHA256 `44c4483c357cfb0260c23e3e3005385c2c4a50daecbcf395128082a7984fe2e9`. Free baseline: Redis Object Cache 3.0.0. Old benchmark totals cannot be compared directly to revised checked operations and corrected bootstrap lanes.

Completed locally: 20 focused safety checks and three metrics-failure assertions. These include site-switch/global-group context invalidation, unchanged byte-statistics semantics and containing metrics Throwable failures without leaking details. Remaining gates include final syntax, package/source parity, Plugin Check, lifecycle, branded-asset parity, native pipelines/ACLs, real WordPress/Redis acceptance and repeated comparative CI results.

The CPU-only fake-transport fixture is for locating PHP work; it does not establish real Redis or WordPress speed. Local Windows timings were noisy, so performance claims require the Linux comparison and independent repetitions. Xdebug profiles are excluded from acceptance timing data.
