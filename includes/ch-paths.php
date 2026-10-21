<?php
/** Locations needed by the early-loading WordPress object-cache drop-in. */
namespace HarborCache;

defined( 'ABSPATH' ) || exit;

final class Paths {
	/**
	 * WordPress loads object-cache.php from this exact filesystem directory before
	 * plugin APIs are available. Upload paths and content_url() cannot locate it.
	 * Keep the unchanged base for existing cache-prefix and ownership identities.
	 * @see https://developer.wordpress.org/reference/functions/wp_start_object_cache/
	 */
	public static function content( $file = '' ) {
		return '' === $file ? WP_CONTENT_DIR : WP_CONTENT_DIR . '/' . ltrim( $file, '/\\' );
	}
}
