<?php
/** Install the rendered engine in an isolated CI fixture, including benchmark engines. */
if ( 'cli' !== PHP_SAPI || ! getenv( 'WP_ROOT' ) ) { exit( 1 ); }
define( 'ABSPATH', rtrim( getenv( 'WP_ROOT' ), '/\\' ) . '/' );
require_once dirname( __DIR__ ) . '/includes/class-dropin-writer.php';
$source = $argv[1] ?? dirname( __DIR__ );
$text = HarborCache\Dropin_Writer::render( file_get_contents( $source . '/includes/object-cache.php' ), $source );
$destination = ABSPATH . 'wp-content/object-cache.php';
if ( ! is_string( $text ) || false === file_put_contents( $destination, $text ) || ! hash_equals( hash( 'sha256', $text ), hash_file( 'sha256', $destination ) ) ) {
	throw new RuntimeException( 'Could not verify the fixture drop-in.' );
}
echo "PASS Installed fixture drop-in with the actual source location\n";
