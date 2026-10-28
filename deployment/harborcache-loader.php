<?php
/**
 * Loads a HarborCache installation from wp-content/mu-plugins.
 * Version: 1.2.9
 * License: GPL-3.0-only
 */
defined( 'ABSPATH' ) || exit;
// Set HARBORCACHE_PLUGIN_PATH in wp-config.php for an optional MU-only installation.
$harborcache_loader_path = defined( 'HARBORCACHE_PLUGIN_PATH' ) ? HARBORCACHE_PLUGIN_PATH : '';
if ( '' !== $harborcache_loader_path && is_readable( $harborcache_loader_path . '/harborcache.php' ) ) { require_once $harborcache_loader_path . '/harborcache.php'; }
unset( $harborcache_loader_path );
