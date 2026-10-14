<?php
/**
 * Loads a HarborCache installation from wp-content/mu-plugins.
 * Version: 1.2.0
 * License: GPL-3.0-only
 */
defined( 'ABSPATH' ) || exit;
$harborcache_loader_path = defined( 'HARBORCACHE_PLUGIN_PATH' ) ? HARBORCACHE_PLUGIN_PATH : WP_PLUGIN_DIR . '/harborcache-object-cache-with-redis';
if ( ! defined( 'HARBORCACHE_PLUGIN_PATH' ) && ! is_readable( $harborcache_loader_path . '/harborcache.php' ) ) { $harborcache_loader_path = WP_PLUGIN_DIR . '/harborcache'; }
if ( is_readable( $harborcache_loader_path . '/harborcache.php' ) ) { require_once $harborcache_loader_path . '/harborcache.php'; }
unset( $harborcache_loader_path );
