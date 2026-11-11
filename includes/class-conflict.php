<?php
/**
 * Detect a second object-cache manager before HarborCache registers admin UI.
 *
 * @package HarborCache\Core
 */

namespace HarborCache\Core;

defined( 'ABSPATH' ) || exit;

final class Conflict {
	/**
	 * True when another manager owns the cache constants or the drop-in.
	 *
	 * @param string|null $redis_file  WP_REDIS_FILE, when defined.
	 * @param string|null $redis_path  WP_REDIS_PLUGIN_PATH, when defined.
	 * @param string      $own_file    HarborCache main file.
	 * @param string      $own_path    HarborCache directory.
	 * @param string|null $dropin      Drop-in text; null reads the installed file.
	 */
	public static function is_foreign( $redis_file, $redis_path, $own_file, $own_path, $dropin = null ) {
		if ( is_string( $redis_file ) && '' !== $redis_file && realpath( $redis_file ) !== realpath( $own_file ) ) {
			return true;
		}
		if ( is_string( $redis_path ) && '' !== $redis_path && realpath( $redis_path ) !== realpath( $own_path ) ) {
			return true;
		}
		if ( null === $dropin ) {
			// The drop-in only governs admin UI and writes, so skip the file read on front-end requests.
			if ( function_exists( 'is_admin' ) && ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) { return false; }
			$file = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' ) . '/object-cache.php';
			$dropin = is_readable( $file ) ? file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local drop-in header.
		}
		return is_string( $dropin ) && '' !== trim( $dropin ) && false === strpos( $dropin, 'HarborCache Drop-In' );
	}

	/** Show one actionable notice on the plugin screens only. */
	public static function notice() {
		add_action( 'admin_notices', static function () {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) ) { return; }
			if ( ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'manage_network_plugins' ) ) { return; }
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Another object-cache manager is active. Deactivate it before using HarborCache; two managers cannot share the same drop-in.', 'harborcache-object-cache-with-redis' ) . '</p></div>';
		} );
	}
}
