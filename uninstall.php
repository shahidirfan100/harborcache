<?php
/** Remove only files and schedules owned by HarborCache. Redis entries expire. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
require_once ABSPATH . 'wp-admin/includes/file.php';
if ( WP_Filesystem() ) {
 $harborcache_fs = $GLOBALS['wp_filesystem'];
 $harborcache_dropin = WP_CONTENT_DIR . '/object-cache.php';
 if ( ! is_link( $harborcache_dropin ) && $harborcache_fs->is_readable( $harborcache_dropin ) ) {
  $harborcache_contents = $harborcache_fs->get_contents( $harborcache_dropin );
  if ( false !== strpos( $harborcache_contents, 'HarborCache Drop-In' ) && false !== strpos( $harborcache_contents, 'HarborCache_Advanced_Cache' ) ) {
   $harborcache_fs->delete( $harborcache_dropin );
   wp_opcache_invalidate( $harborcache_dropin, true );
  }
 }
 $harborcache_config = WP_CONTENT_DIR . '/harborcache-config.php';
 if ( ! is_link( $harborcache_config ) && $harborcache_fs->is_readable( $harborcache_config ) && false !== strpos( $harborcache_fs->get_contents( $harborcache_config ), '// HarborCache configuration.' ) ) { $harborcache_fs->delete( $harborcache_config ); }
}
wp_clear_scheduled_hook( 'harborcache_metrics_discard' );
wp_clear_scheduled_hook( 'rediscache_discard_metrics' ); // Remove the event name used by earlier HarborCache builds.
delete_option( 'harborcache_metrics_cron_migrated' );

wp_clear_scheduled_hook( 'harborcache_cleanup' );

wp_clear_scheduled_hook( 'harborcache_queries' );
