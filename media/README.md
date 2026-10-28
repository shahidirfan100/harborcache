# HarborCache media

HarborCache interface screenshots were captured on 2026-10-28 from the exact `harborcache-1.2.9.zip` release running in GitHub Actions: WordPress 7.1.3, PHP 8.3.36, PhpRedis 6.3.0, Redis 7.4.12 and MySQL 8.0. The capture job is `.github/workflows/screenshots.yml`; it installs WordPress, activates the plugin, installs the owned drop-in, seeds the fixture, drives a headless Chromium session and uploads the PNGs. The capture run is linked from `screenshots.json`.

Screenshots 1 to 7 show Status, Analytics, Settings, Cache, Diagnostics, the expanded raw report and the dashboard widget. The two mobile files show Settings and the widget at a 375px viewport. Desktop captures clip the plugin panel (`#harborcache`) at its 1180px maximum width; the WordPress admin chrome is outside the frame. The plugin version is visible in each panel footer.

The analytics chart and sample table use demonstration history seeded into the disposable fixture so the nine screens have data to show; the images label the sampling rate and every other value (connection state, client and server versions, cache groups, entry counts, Redis timing, request table) comes from the live Redis connection. The dataset is not a production measurement, a benchmark or customer data.

Original logos, icons, favicon, banners and social artwork use teal #0b615e and ink #172b32. Media is separate from the installable plugin package. Original HarborCache media: GPL-2.0-or-later, Shahid Irfan.
